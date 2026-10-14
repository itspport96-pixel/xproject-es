<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Modules\Inventory\Application\Services\ReceivingDocumentService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PDO;
use Tests\Support\InventorySqliteTestCase;

/**
 * BLOQUE R1 (v1.13.28): documentos de recepción planificada.
 *
 * Monta el servicio REAL sobre SQLite en memoria (mismo patrón que el resto
 * del módulo) y usa AuthorizationService con la caché de permisos
 * precargada (fail-open de Redis simulado, como DeliveryReturnRoutesTest).
 */
final class ReceivingDocumentServiceTest extends InventorySqliteTestCase
{
    private const CREATOR = 'user-creator-0001';
    private const OTHER = 'user-other-00001';

    private ReceivingDocumentService $service;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        parent::setUp();

        $this->service = new ReceivingDocumentService(
            $this->db,
            $this->tenantContext,
            $this->authWithPermissions(['inventory.receiving.create'])
        );

        $this->seedUser(self::T1, self::CREATOR);
        $this->seedUser(self::T1, self::OTHER);
        $this->seedProduct(self::T1, 'SKU-BETA');
    }

    protected function tearDown(): void
    {
        Container::resetInstance();

        parent::tearDown();
    }

    // ====================================================================
    // CREACIÓN
    // ====================================================================

    public function testCreateDocumentoPlanificadaConLineas(): void
    {
        $doc = $this->service->create([
            'numero_documento' => 'PO-001',
            'proveedor' => 'Acme',
            'referencia' => 'Albarán 42',
            'lineas' => [
                ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10],
                ['sku' => 'SKU-BETA', 'cantidad_esperada' => 5.5],
            ],
        ], self::CREATOR);

        $this->assertSame('PO-001', $doc['numero_documento']);
        $this->assertSame('PLANIFICADA', $doc['estado']);
        $this->assertSame(self::CREATOR, $doc['creado_por']);
        $this->assertCount(2, $doc['lineas']);

        // El orden de las líneas no está garantizado (UUIDs aleatorios).
        $porSku = array_column($doc['lineas'], null, 'sku');
        $this->assertSame('PENDIENTE', $porSku['SKU-ALFA']['estado']);
        $this->assertSame(10.0, $porSku['SKU-ALFA']['cantidad_esperada']);
        $this->assertSame(5.5, $porSku['SKU-BETA']['cantidad_esperada']);
    }

    public function testCreateFallasiElNumeroYaExiste(): void
    {
        $this->service->create([
            'numero_documento' => 'PO-DUP',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 1]],
        ], self::CREATOR);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('ya está registrado');

        $this->service->create([
            'numero_documento' => 'PO-DUP',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 2]],
        ], self::OTHER);
    }

    public function testCreateFallasiNoHayOtroReceptorActivo(): void
    {
        // Sólo el creador en el tenant: sin segundo receptor posible.
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([self::OTHER]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Se requiere otro usuario con permiso de recepción');

        $this->service->create([
            'numero_documento' => 'PO-SINSEGUNDO',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 1]],
        ], self::CREATOR);
    }

    public function testCreateFallasiLosOtrosUsuariosNoTienenPermisoDeRecepcion(): void
    {
        // Otro usuario existe pero SIN receiving.create (caché vacía).
        $service = new ReceivingDocumentService(
            $this->db,
            $this->tenantContext,
            $this->authWithPermissions([])
        );

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Se requiere otro usuario con permiso de recepción');

        $service->create([
            'numero_documento' => 'PO-SINPERMISO',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 1]],
        ], self::CREATOR);
    }

    public function testCreateFallasiElSkuNoExiste(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("El producto con SKU 'SKU-NULO' no existe");

        $this->service->create([
            'numero_documento' => 'PO-NOSKU',
            'lineas' => [['sku' => 'SKU-NULO', 'cantidad_esperada' => 1]],
        ], self::CREATOR);
    }

    public function testCreateFallasiNoHayLineas(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('al menos una línea');

        $this->service->create([
            'numero_documento' => 'PO-SINLINEAS',
            'lineas' => [],
        ], self::CREATOR);
    }

    public function testCreateFallasiElSkuEstaRepetido(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('repetido');

        $this->service->create([
            'numero_documento' => 'PO-DUPSKU',
            'lineas' => [
                ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 1],
                ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 2],
            ],
        ], self::CREATOR);
    }

    // ====================================================================
    // ACTUALIZACIÓN
    // ====================================================================

    public function testUpdateEditaMetadatosYLineas(): void
    {
        $doc = $this->createSampleDocument('PO-UPD');

        $updated = $this->service->update($doc['id'], [
            'numero_documento' => 'PO-UPD-2',
            'proveedor' => 'Nuevo proveedor',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 99]],
        ]);

        $this->assertSame('PO-UPD-2', $updated['numero_documento']);
        $this->assertSame('Nuevo proveedor', $updated['proveedor']);
        $this->assertCount(1, $updated['lineas']);
        $this->assertSame(99.0, $updated['lineas'][0]['cantidad_esperada']);
    }

    public function testUpdateFallasiElDocumentoNoEstaPlanificada(): void
    {
        $doc = $this->createSampleDocument('PO-ENR');
        $this->pdo->prepare("UPDATE receiving_documents SET estado = 'EN_RECEPCION' WHERE id = ?")
            ->execute([$doc['id']]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('sólo se puede editar si está PLANIFICADA');

        $this->service->update($doc['id'], ['proveedor' => 'X']);
    }

    public function testUpdateFallasiYaHayLineasRecibidas(): void
    {
        $doc = $this->createSampleDocument('PO-REC');
        $linea = $this->row(
            'SELECT id FROM receiving_document_lines WHERE document_id = :d',
            ['d' => $doc['id']]
        );
        $this->pdo->prepare("UPDATE receiving_document_lines SET estado = 'RECIBIDA' WHERE id = ?")
            ->execute([$linea['id']]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('sólo se puede editar si está PLANIFICADA');

        $this->service->update($doc['id'], ['proveedor' => 'X']);
    }

    // ====================================================================
    // ANULACIÓN
    // ====================================================================

    public function testCancelAnulaUnDocumentoPlanificada(): void
    {
        $doc = $this->createSampleDocument('PO-CANCEL');

        $cancelled = $this->service->cancel($doc['id']);

        $this->assertSame('ANULADA', $cancelled['estado']);
        // Política de borrado: no se borra, se anula (regla 11).
        $this->assertNotNull($this->row(
            'SELECT id FROM receiving_documents WHERE id = :id',
            ['id' => $doc['id']]
        ));
    }

    public function testCancelFallasiElDocumentoNoEstaPlanificada(): void
    {
        $doc = $this->createSampleDocument('PO-CANCEL2');
        $this->pdo->prepare("UPDATE receiving_documents SET estado = 'ANULADA' WHERE id = ?")
            ->execute([$doc['id']]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('sólo se puede anular si está PLANIFICADA');

        $this->service->cancel($doc['id']);
    }

    // ====================================================================
    // AISLAMIENTO DE TENANT
    // ====================================================================

    public function testFindByIdNoVeDocumentosDeOtroTenant(): void
    {
        $doc = $this->createSampleDocument('PO-T2');
        $this->pdo->prepare('UPDATE receiving_documents SET tenant_id = ? WHERE id = ?')
            ->execute([self::T2, $doc['id']]);

        $this->assertNull($this->service->findById($doc['id']));
    }

    // ====================================================================
    // LISTADO
    // ====================================================================

    public function testFindAllDevuelveDocumentosDelTenantConPaginacion(): void
    {
        $this->createSampleDocument('PO-LIST-1');
        $this->createSampleDocument('PO-LIST-2');

        $result = $this->service->findAll();

        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertFalse($result['pagination']['has_next']);
    }

    public function testFindAllFiltraPorEstado(): void
    {
        $doc = $this->createSampleDocument('PO-EST-ANUL');
        $this->service->cancel($doc['id']);
        $this->createSampleDocument('PO-EST-PLAN');

        $result = $this->service->findAll(['estado' => 'PLANIFICADA']);

        $this->assertCount(1, $result['data']);
        $this->assertSame('PO-EST-PLAN', $result['data'][0]['numero_documento']);
    }

    // ====================================================================
    // PENDIENTES PARA EL RECEPTOR (v1.13.32)
    // ====================================================================

    public function testFindPendingExcluyeLosDocumentosCreadosPorElPropioUsuario(): void
    {
        $this->createSampleDocument('PO-PEND-PROPIO');

        // Creados por CREATOR: para CREATOR no son recibibles (se excluyen).
        $result = $this->service->findPendingForReceiver(self::CREATOR);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['pagination']['total']);
    }

    public function testFindPendingDevuelveLosDeOtrosConLineasYEsperada(): void
    {
        $this->createSampleDocument('PO-PEND-1');

        $result = $this->service->findPendingForReceiver(self::OTHER);

        $this->assertCount(1, $result['data']);
        $doc = $result['data'][0];
        $this->assertSame('PO-PEND-1', $doc['numero_documento']);
        $this->assertSame('PLANIFICADA', $doc['estado']);
        $this->assertSame(self::CREATOR, $doc['creado_por']);
        $this->assertNotEmpty($doc['lineas']);
        $this->assertSame('SKU-ALFA', $doc['lineas'][0]['sku']);
        $this->assertSame(10.0, $doc['lineas'][0]['cantidad_esperada']);
        $this->assertSame('PENDIENTE', $doc['lineas'][0]['estado']);
    }

    public function testFindPendingIncluyeEnRecepcionPeroNoAnuladasNiCerradas(): void
    {
        $enRecepcion = $this->createSampleDocument('PO-PEND-ENR');
        $this->pdo->prepare("UPDATE receiving_documents SET estado = 'EN_RECEPCION' WHERE id = ?")
            ->execute([$enRecepcion['id']]);

        $anulada = $this->createSampleDocument('PO-PEND-ANUL');
        $this->service->cancel($anulada['id']);

        $cerrada = $this->createSampleDocument('PO-PEND-CERR');
        $this->pdo->prepare("UPDATE receiving_documents SET estado = 'CERRADA' WHERE id = ?")
            ->execute([$cerrada['id']]);

        $result = $this->service->findPendingForReceiver(self::OTHER);

        $this->assertCount(1, $result['data']);
        $this->assertSame('PO-PEND-ENR', $result['data'][0]['numero_documento']);
    }

    public function testFindPendingNoVeDocumentosDeOtroTenant(): void
    {
        $doc = $this->createSampleDocument('PO-PEND-T2');
        $this->pdo->prepare('UPDATE receiving_documents SET tenant_id = ? WHERE id = ?')
            ->execute([self::T2, $doc['id']]);

        $result = $this->service->findPendingForReceiver(self::OTHER);

        $this->assertSame([], $result['data']);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param array<int, string> $permissions
     */
    private function authWithPermissions(array $permissions): AuthorizationService
    {
        $redis = new class(json_encode($permissions)) extends RedisService {
            public function __construct(private readonly string $cached)
            {
                parent::__construct([]);
            }

            public function get(string $key): mixed
            {
                return $this->cached;
            }
        };

        $permissionRepo = $this->createStub(PermissionRepositoryInterface::class);
        $permissionRepo->method('findByIdentificador')->willReturn(null);

        return new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $permissionRepo,
            new DatabaseService([]),
            $redis
        );
    }

    private function seedUser(string $tenantId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, estado, created_at, updated_at)
             VALUES (:id, :tenant_id, :email, :nombre, :estado, :now, :now)'
        );
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $userId . '@test.local',
            'nombre' => 'Usuario ' . $userId,
            'estado' => 'ACTIVO',
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createSampleDocument(string $numero): array
    {
        return $this->service->create([
            'numero_documento' => $numero,
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10]],
        ], self::CREATOR);
    }
}
