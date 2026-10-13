<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\LocationRepository;
use App\Modules\Inventory\Adapters\Persistence\StockEventRepository;
use App\Modules\Inventory\Adapters\Persistence\StockLedgerRepository;
use App\Modules\Inventory\Adapters\Persistence\StockUnitRepository;
use App\Modules\Inventory\Application\Services\ReceiveDocumentUseCase;
use App\Modules\Inventory\Application\Services\ResolveIncidentUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Services\IdempotencyService;
use Tests\Support\InventoryMysqlTestCase;

/**
 * BLOQUE R2: comportamiento de ReceiveDocumentUseCase contra MySQL real.
 *
 * Principios que se verifican (uno a uno):
 *  1. El esperado nunca lo introduce quien recibe: el payload NO admite
 *     cantidad_esperada (ni en la raiz ni por linea) -> 422.
 *  2. Quien planifica no recibe: el creador del documento -> 422.
 *  3. Ciego: al primer discrepancia se responde "recuenta" SIN revelar el
 *     esperado ni la diferencia.
 *  4. Un solo reconteo: en el segundo fallo se registra la cantidad contada
 *     (unidad fisica) y se abre una incidencia DISCREPANCIA_CANTIDAD cuya
 *     resolucion esta restringida a un tercero (ni receptor ni planificador).
 */
final class ReceiveDocumentUseCaseTest extends InventoryMysqlTestCase
{
    private const IDEMPOTENCY_KEY = 'key-test-001';

    // ====================================================================
    // 1. CANTIDAD ESPERADA NO ADMITIDA
    // ====================================================================

    public function testElPayloadNoAdmiteCantidadEsperadaEnLaRaiz(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'cantidad_esperada' => 10,
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por cantidad_esperada en la raiz.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('cantidad_esperada', $e->getMessage());
        }
    }

    public function testElPayloadNoAdmiteCantidadEsperadaEnUnaLinea(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [[
                        'sku' => 'SKU-ALFA',
                        'cantidad_recibida' => 10,
                        'cantidad_esperada' => 10,
                    ]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por cantidad_esperada en una linea.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('cantidad_esperada', $e->getMessage());
        }
    }

    // ====================================================================
    // 2. CREADOR NO PUEDE RECIBIR
    // ====================================================================

    public function testElCreadorDelDocumentoNoPuedeRecibirlo(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $planificador
            );
            $this->fail('Se esperaba InventoryException por recepcion del creador.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('planific', $e->getMessage());
        }
    }

    // ====================================================================
    // 3. COINCIDENCIA A CIEGAS
    // ====================================================================

    public function testLaCoincidenciaCreaUnidadLedgerYEvento(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $this->assertSame('RECIBIDO', $resultado['data']['resultado']);
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);
        $this->assertFalse($resultado['data']['requiere_reconteo']);

        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('SKU-ALFA', $linea['sku']);
        $this->assertSame('RECIBIDA', $linea['estado_linea']);
        $this->assertSame(10.0, (float) $linea['cantidad_recibida']);
        $this->assertNotNull($linea['unidad_fisica_id']);

        $unidadId = $linea['unidad_fisica_id'];
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_units
                  WHERE tenant_id = ? AND id = ? AND estado = 'DISPONIBLE' AND ubicacion_id IS NOT NULL",
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND unidad_fisica_id = ?',
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_events
                  WHERE tenant_id = ? AND unidad_fisica_id = ? AND tipo_evento = 'RECIBIDO'",
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            'RECIBIDA',
            $this->scalar('SELECT estado FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );

        $auditoria = $resultado['_auditoria'];
        $this->assertSame($doc['numero_documento'], $auditoria['numero_documento']);
        $this->assertSame('LOC-01', $auditoria['ubicacion_codigo']);
        $this->assertSame(10.0, (float) $auditoria['lineas'][0]['cantidad_esperada']);
        $this->assertSame(10.0, (float) $auditoria['lineas'][0]['cantidad_recibida']);
        $this->assertSame(1, (int) $auditoria['lineas'][0]['intentos']);
        $this->assertArrayNotHasKey('cantidad_esperada', $linea);
    }

    // ====================================================================
    // 4. PRIMER MISMATCH: RECONTEO SIN REVELAR
    // ====================================================================

    public function testElPrimerMismatchPideReconteoSinRevelar(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $this->assertTrue($resultado['data']['requiere_reconteo']);
        $this->assertSame('RECONTEO_REQUERIDO', $resultado['data']['resultado']);

        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('RECONTEO', $linea['estado_linea']);
        $this->assertNull($linea['cantidad_recibida']);
        $this->assertNull($linea['unidad_fisica_id']);
        $this->assertNull($linea['incidencia_id']);
        $this->assertStringNotContainsString('10', $resultado['data']['mensaje']);
        $this->assertStringNotContainsString('7', $resultado['data']['mensaje']);
        $this->assertStringNotContainsString('10', $linea['mensaje'] ?? '');

        $this->assertSame(
            'PENDIENTE',
            $this->scalar('SELECT estado FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT intentos FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );
        $this->assertSame(
            'EN_RECEPCION',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );
        $this->assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM incidents WHERE tenant_id = ? AND tipo = ?',
                [$this->tenantId, 'DISCREPANCIA_CANTIDAD']
            )
        );

        $this->assertNull($linea['cantidad_recibida']);
        $this->assertArrayNotHasKey('cantidad_esperada', $linea);
        $this->assertSame(1, (int) $resultado['_auditoria']['lineas'][0]['intentos']);
    }

    // ====================================================================
    // 5. SEGUNDO MISMATCH: UNIDAD + INCIDENCIA RESTRINGIDA
    // ====================================================================

    public function testElSegundoMismatchCreaUnidadEIncidenciaRestringida(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p2',
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('RECIBIDA_CON_INCIDENCIA', $linea['estado_linea']);
        $this->assertSame(7.0, (float) $linea['cantidad_recibida']);
        $this->assertNotNull($linea['unidad_fisica_id']);
        $this->assertNotNull($linea['incidencia_id']);
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);

        $unidadId = $linea['unidad_fisica_id'];
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ? AND id = ? AND cantidad = 7',
                [$this->tenantId, $unidadId]
            )
        );

        $incidenciaId = $linea['incidencia_id'];
        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [$incidenciaId]);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $incidencia['tipo']);
        // Faltante 7/10 = 30 % >= umbral por defecto (5 %) => prioridad ALTA.
        $this->assertSame('ALTA', $incidencia['prioridad']);
        $restriccion = json_decode((string) $incidencia['restriccion_resolucion'], true);
        $this->assertSame($receptor, $restriccion['receptor']);
        $this->assertSame($planificador, $restriccion['planificador']);
    }

    // ====================================================================
    // 6. SOBRE-RECEPCION: PRIORIDAD ALTA
    // ====================================================================

    public function testLaSobreRecepcionAbreIncidenciaConPrioridadAlta(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 15]],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 15]],
            ],
            self::IDEMPOTENCY_KEY . '-p2',
            $receptor
        );

        $incidenciaId = $resultado['data']['lineas'][0]['incidencia_id'];
        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [$incidenciaId]);
        $this->assertSame('ALTA', $incidencia['prioridad']);
    }

    // ====================================================================
    // 7. RESOLUCION RESTRINGIDA A UN TERCERO
    // ====================================================================

    public function testNiElReceptorNiElPlanificadorPuedenResolverLaIncidencia(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );
        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p2',
            $receptor
        );
        $incidenciaId = $resultado['data']['lineas'][0]['incidencia_id'];

        $payload = [
            'estado' => 'RESUELTA',
            'descripcion_resolucion' => 'Cierre tras revisar el faltante.',
            'acciones_tomadas' => 'Se recuenta con el proveedor.',
        ];

        try {
            $this->resolveIncidentUseCase->execute(
                $incidenciaId,
                $payload,
                'res-1',
                $receptor
            );
            $this->fail('Se esperaba 422 por restriccion de resolucion (receptor).');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('receptor', $e->getMessage());
        }

        try {
            $this->resolveIncidentUseCase->execute(
                $incidenciaId,
                $payload,
                'res-2',
                $planificador
            );
            $this->fail('Se esperaba 422 por restriccion de resolucion (planificador).');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('receptor', $e->getMessage());
        }

        $tercero = $this->seedUser($this->tenantId);
        $respuesta = $this->resolveIncidentUseCase->execute(
            $incidenciaId,
            $payload,
            'res-3',
            $tercero
        );

        $this->assertSame(200, $respuesta['status']);
        $this->assertSame(
            'RESUELTA',
            $this->scalar('SELECT estado FROM incidents WHERE id = ?', [$incidenciaId])
        );
    }

    // ====================================================================
    // 8. REGLAS DE LINEA Y DOCUMENTO
    // ====================================================================

    public function testUnSkuNoPerteneceAlDocumento(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-NO-EXISTE', 'cantidad_recibida' => 1]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por SKU no perteneciente.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no es una l', $e->getMessage());
        }
    }

    public function testUnaLineaYaRecibidaNoVuelveASerRecibida(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );
        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY . '-p2',
            $receptor
        );

        // La linea queda RECIBIDA (con incidencia por el 2º fallo) y el
        // documento CERRADA (toda RECIBIDA). El siguiente receive debe fallar
        // por estado del documento (no recibible), antes de llegar a la linea.
        $this->assertSame(
            'CERRADA',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY . '-p3',
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento no recibible.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('PLANIFICADA', $e->getMessage());
        }
    }

    public function testUnaLineaYaRecibidaNoAdmiteUnSegundoIntento(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $this->seedProduct($this->tenantId, 'SKU-BETA');
        $doc = $this->createReceivingDocument($planificador, 'PO-DOSLINEAS', [
            ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10.0],
            ['sku' => 'SKU-BETA', 'cantidad_esperada' => 5.0],
        ]);

        // ALFA coincide (RECIBIDA); BETA discrepa una vez (RECONTEO, sigue
        // PENDIENTE). El documento queda EN_RECEPCION: ALFA ya fue recibida.
        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [
                    ['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10],
                    ['sku' => 'SKU-BETA', 'cantidad_recibida' => 3],
                ],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );

        $this->assertSame(
            'EN_RECEPCION',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY . '-p3',
                $receptor
            );
            $this->fail('Se esperaba InventoryException por linea ya recibida.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('ya fue recibida', $e->getMessage());
        }
    }

    public function testUnDocumentoCerradoNoSePuedeRecibir(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador, 'PO-CERRADA-001', estado: 'CERRADA');

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento no recibible.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('PLANIFICADA', $e->getMessage());
        }
    }

    // ====================================================================
    // 9. IDEMPOTENCIA
    // ====================================================================

    public function testLaClaveDeIdempotenciaHaceReplayDeLaRespuesta(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $payload = [
            'ubicacion_codigo' => 'LOC-01',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
        ];

        $primera = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            $payload,
            self::IDEMPOTENCY_KEY,
            $receptor
        );
        $replay = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            $payload,
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertArrayHasKey('_replay', $replay);
        $this->assertTrue($replay['_replay']);
        $this->assertSame(
            $primera['data']['documento_id'],
            $replay['data']['documento_id']
        );
        $this->assertSame(
            $primera['data']['lineas'][0]['unidad_fisica_id'],
            $replay['data']['lineas'][0]['unidad_fisica_id']
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?',
                [$this->tenantId]
            )
        );
    }

    // ====================================================================
    // 10. AISLAMIENTO ENTRE TENANTS
    // ====================================================================

    public function testUnDocumentoDeOtroTenantNoSePuedeRecibir(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

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

        // El contexto de tenant es el que aísla: con el tenant activo cambiado
        // al ajeno, el documento no existe para ese contexto.
        $otroContexto = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $otroContexto->setTenantId($otroTenant);

        $useCaseAjeno = new ReceiveDocumentUseCase(
            $this->db,
            new IdempotencyService($this->control),
            new LocationRepository($this->db, $otroContexto),
            new StockUnitRepository($this->db, $otroContexto),
            new StockLedgerRepository($this->db, $otroContexto),
            new StockEventRepository($this->db, $otroContexto),
            new IncidentRepository($this->db, $otroContexto),
            $this->calculation,
            $this->barcodeService,
            $otroContexto
        );

        try {
            $useCaseAjeno->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento de otro tenant.');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(
            'PLANIFICADA',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );
    }
}
