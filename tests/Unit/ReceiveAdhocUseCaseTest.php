<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\LocationRepository;
use App\Modules\Inventory\Adapters\Persistence\ProductRepository;
use App\Modules\Inventory\Adapters\Persistence\StockEventRepository;
use App\Modules\Inventory\Adapters\Persistence\StockLedgerRepository;
use App\Modules\Inventory\Adapters\Persistence\StockUnitRepository;
use App\Modules\Inventory\Application\Services\BarcodeService;
use App\Modules\Inventory\Application\Services\ConfirmAdhocUseCase;
use App\Modules\Inventory\Application\Services\ReceiveAdhocUseCase;
use App\Modules\Inventory\Application\Services\RejectAdhocUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Services\IdempotencyService;
use Tests\Support\InventoryMysqlTestCase;

/**
 * BLOQUE R3 (v1.13.30): recepcion adhoc contra MySQL real.
 *
 * Principios que se verifican (uno a uno):
 *  1. Referencia del albaran OBLIGATORIA: sin `referencia_documento` -> 422;
 *     con referencia duplicada en el mismo tenant -> 409.
 *  2. Unidades nacen en BLOQUEADO (no DISPONIBLE) hasta confirmar.
 *  3. Separacion de funciones: quien registra NO puede confirmar -> 422.
 *  4. Confirmar (otro usuario) pasa BLOQUEADO -> DISPONIBLE via matriz.
 *  5. Rechazar (otro usuario) deja unidades en BLOQUEADO y abre incidencia
 *     ALTA restringida (ni creador ni rechazador la resuelven).
 *  6. Idempotencia: misma clave + mismo cuerpo -> replay.
 *  7. Aislamiento por tenant.
 */
final class ReceiveAdhocUseCaseTest extends InventoryMysqlTestCase
{
    private const KEY_REGISTER = 'key-adhoc-001';
    private const KEY_CONFIRM = 'key-adhoc-confirm-001';
    private const KEY_REJECT = 'key-adhoc-reject-001';

    // ====================================================================
    // 1. REFERENCIA OBLIGATORIA
    // ====================================================================

    public function testLaReferenciaDelAlbaranEsObligatoria(): void
    {
        $receptor = $this->seedUser($this->tenantId);

        try {
            $this->receiveAdhocUseCase->execute(
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
                ],
                self::KEY_REGISTER,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por referencia_documento ausente.');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('referencia_documento', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?', [$this->tenantId])
        );
    }

    public function testLaReferenciaDuplicadaEnElMismoTenantDevuelve409(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $payload = [
            'referencia_documento' => 'ALB-001',
            'ubicacion_codigo' => 'LOC-01',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
        ];

        $this->receiveAdhocUseCase->execute($payload, self::KEY_REGISTER, $receptor);

        try {
            $this->receiveAdhocUseCase->execute($payload, 'key-adhoc-002', $receptor);
            $this->fail('Se esperaba InventoryException por referencia duplicada.');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('ALB-001', $e->getMessage());
        }

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM receiving_adhoc WHERE tenant_id = ?', [$this->tenantId])
        );
    }

    // ====================================================================
    // 2. UNIDADES NACEN EN BLOQUEADO
    // ====================================================================

    public function testLasUnidadesNacenEnBloqueadoYAdhocEnPendiente(): void
    {
        $receptor = $this->seedUser($this->tenantId);

        $respuesta = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-BLOQ-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );

        $this->assertSame(201, $respuesta['status']);
        $this->assertSame('PENDIENTE', $respuesta['data']['estado']);
        $this->assertSame('ALB-BLOQ-001', $respuesta['data']['referencia_documento']);

        $unidadId = $respuesta['data']['lineas'][0]['unidad_fisica_id'];
        $this->assertSame('BLOQUEADO', $respuesta['data']['lineas'][0]['estado_unidad']);

        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('BLOQUEADO', $fila['estado']);

        $adhocFila = $this->row(
            'SELECT estado, creado_por FROM receiving_adhoc WHERE id = ?',
            [$respuesta['data']['adhoc_id']]
        );
        $this->assertSame('PENDIENTE', $adhocFila['estado']);
        $this->assertSame($receptor, $adhocFila['creado_por']);
    }

    // ====================================================================
    // 3. SEPARACION DE FUNCIONES: QUIEN REGISTRA NO CONFIRMA
    // ====================================================================

    public function testQuienRegistraNoPuedeConfirmar(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $registro = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-SEP-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );

        try {
            $this->confirmAdhocUseCase->execute(
                $registro['data']['adhoc_id'],
                [],
                self::KEY_CONFIRM,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por creador intentando confirmar.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('confirmar', $e->getMessage());
        }

        $unidadId = $registro['data']['lineas'][0]['unidad_fisica_id'];
        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('BLOQUEADO', $fila['estado']);
    }

    // ====================================================================
    // 4. CONFIRMAR PASA BLOQUEADO -> DISPONIBLE
    // ====================================================================

    public function testConfirmarConOtroUsuarioPasaBloqueadoADisponible(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $confirmador = $this->seedUser($this->tenantId);

        $registro = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-CONF-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );
        $unidadId = $registro['data']['lineas'][0]['unidad_fisica_id'];

        $respuesta = $this->confirmAdhocUseCase->execute(
            $registro['data']['adhoc_id'],
            [],
            self::KEY_CONFIRM,
            $confirmador
        );

        $this->assertSame(200, $respuesta['status']);
        $this->assertSame('CONFIRMADA', $respuesta['data']['estado']);
        $this->assertSame(
            StockState::DISPONIBLE,
            $respuesta['data']['lineas'][0]['estado_posterior']
        );

        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('DISPONIBLE', $fila['estado']);

        $adhocFila = $this->row(
            'SELECT estado, creado_por_confirma FROM receiving_adhoc WHERE id = ?',
            [$registro['data']['adhoc_id']]
        );
        $this->assertSame('CONFIRMADA', $adhocFila['estado']);
        $this->assertSame($confirmador, $adhocFila['creado_por_confirma']);
    }

    // ====================================================================
    // 5. RECHAZAR DEJA BLOQUEADO Y ABRE INCIDENCIA ALTA
    // ====================================================================

    public function testRechazarDejaBloqueadoYAbreIncidenciaAlta(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $rechazador = $this->seedUser($this->tenantId);

        $registro = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-RECH-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );
        $unidadId = $registro['data']['lineas'][0]['unidad_fisica_id'];

        $respuesta = $this->rejectAdhocUseCase->execute(
            $registro['data']['adhoc_id'],
            [],
            self::KEY_REJECT,
            $rechazador
        );

        $this->assertSame(200, $respuesta['status']);
        $this->assertSame('RECHAZADA', $respuesta['data']['estado']);

        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('BLOQUEADO', $fila['estado']);

        $adhocFila = $this->row(
            'SELECT estado FROM receiving_adhoc WHERE id = ?',
            [$registro['data']['adhoc_id']]
        );
        $this->assertSame('RECHAZADA', $adhocFila['estado']);

        $incidenciaId = $respuesta['data']['incidencia']['incidencia_id'];
        $inc = $this->row('SELECT prioridad, restriccion_resolucion FROM incidents WHERE id = ?', [$incidenciaId]);
        $this->assertSame('ALTA', $inc['prioridad']);

        $restriccion = json_decode((string) $inc['restriccion_resolucion'], true);
        $this->assertIsArray($restriccion);
        $this->assertSame($receptor, $restriccion['creador']);
        $this->assertSame($rechazador, $restriccion['rechazador']);
    }

    public function testQuienRegistraNoPuedeRechazar(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $registro = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-RECH-SEP-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );

        try {
            $this->rejectAdhocUseCase->execute(
                $registro['data']['adhoc_id'],
                [],
                self::KEY_REJECT,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por creador intentando rechazar.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $unidadId = $registro['data']['lineas'][0]['unidad_fisica_id'];
        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('BLOQUEADO', $fila['estado']);
    }

    // ====================================================================
    // 6. IDEMPOTENCIA
    // ====================================================================

    public function testMismaClaveMismoCuerpoDevuelveReplay(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $payload = [
            'referencia_documento' => 'ALB-IDEM-001',
            'ubicacion_codigo' => 'LOC-01',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
        ];

        $primera = $this->receiveAdhocUseCase->execute($payload, self::KEY_REGISTER, $receptor);
        $replay = $this->receiveAdhocUseCase->execute($payload, self::KEY_REGISTER, $receptor);

        $this->assertArrayHasKey('_replay', $replay);
        $this->assertTrue($replay['_replay']);
        $this->assertSame($primera['data']['adhoc_id'], $replay['data']['adhoc_id']);
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM receiving_adhoc WHERE tenant_id = ?', [$this->tenantId])
        );
    }

    // ====================================================================
    // 7. AISLAMIENTO ENTRE TENANTS
    // ====================================================================

    public function testUnAdhocDeOtroTenantNoSePuedeConfirmar(): void
    {
        $receptor = $this->seedUser($this->tenantId);
        $registro = $this->receiveAdhocUseCase->execute(
            [
                'referencia_documento' => 'ALB-TENANT-001',
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad' => 10]],
            ],
            self::KEY_REGISTER,
            $receptor
        );

        $otroTenant = self::uuid('tenant');
        $ownerOtro = self::uuid('user');
        $this->control->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado, plan, owner_user_id, created_at, updated_at)
             VALUES (:id, :np, :ni, :estado, :plan, :owner, :created, :updated)'
        )->execute([
            'id' => $otroTenant,
            'np' => 'Otro ' . $otroTenant,
            'ni' => 'otro-' . $otroTenant,
            'estado' => 'ACTIVO',
            'plan' => 'FREE',
            'owner' => $ownerOtro,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ]);
        $this->control->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, password, estado, created_at, updated_at)
             VALUES (:id, :tenant, :email, :nombre, :pass, :estado, :created, :updated)'
        )->execute([
            'id' => $ownerOtro,
            'tenant' => $otroTenant,
            'email' => $ownerOtro . '@test.local',
            'nombre' => 'Owner otro',
            'pass' => password_hash('x', PASSWORD_BCRYPT),
            'estado' => 'ACTIVO',
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ]);

        $otroContexto = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $otroContexto->setTenantId($otroTenant);

        $confirmAjeno = new ConfirmAdhocUseCase(
            $this->db,
            new IdempotencyService($this->control),
            new LocationRepository($this->db, $otroContexto),
            new StockUnitRepository($this->db, $otroContexto),
            new StockLedgerRepository($this->db, $otroContexto),
            new StockEventRepository($this->db, $otroContexto),
            $this->calculation,
            $this->states,
            $otroContexto
        );

        try {
            $confirmAjeno->execute(
                $registro['data']['adhoc_id'],
                [],
                self::KEY_CONFIRM,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por adhoc de otro tenant.');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $unidadId = $registro['data']['lineas'][0]['unidad_fisica_id'];
        $fila = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
        $this->assertSame('BLOQUEADO', $fila['estado']);
    }
}
