<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\LocationService;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryLocationRepository;

/**
 * Sprint 4 (ubicaciones): validación de creación, unicidad de código,
 * jerarquía, capacidad, filtros, ciclo de vida y aislamiento de tenant.
 *
 * Usa un repositorio en memoria con la misma semántica que el adaptador MySQL.
 */
final class LocationServiceTest extends TestCase
{
    private const TENANT = 'tenant-1';

    private InMemoryLocationRepository $repository;
    private LocationService $service;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->repository = new InMemoryLocationRepository(self::TENANT);

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        $this->service = new LocationService($this->repository, $tenantContext, new InputSanitizer());
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ==================================================================
    // CREACIÓN
    // ==================================================================

    public function testCreateNormalizesCodigoAndTipo(): void
    {
        $result = $this->service->create($this->validData([
            'codigo' => ' a-01 ',
            'tipo' => 'picking',
            'nombre' => '  Pasillo   A  ',
            'jerarquia' => 'RAIZ/1',
            'capacidad' => 10,
        ]));

        $this->assertSame('A-01', $result['codigo']);
        $this->assertSame('PICKING', $result['tipo']);
        $this->assertSame('Pasillo A', $result['nombre']);
        $this->assertSame(10, $result['capacidad']);
        $this->assertSame(0, $result['capacidad_usada']);
        $this->assertSame(10, $result['capacidad_restante']);
        $this->assertTrue($result['tiene_disponibilidad']);
        $this->assertTrue($result['activa']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['id']
        );

        $stored = $this->repository->stored($result['id']);
        $this->assertNotNull($stored);
        $this->assertSame(self::TENANT, $stored->tenantId());
    }

    /**
     * El tenant siempre sale del contexto de sesión: nunca del payload del cliente.
     */
    public function testCreateIgnoresClientProvidedTenant(): void
    {
        $result = $this->service->create($this->validData(['tenant_id' => 'tenant-9']));

        $this->assertArrayNotHasKey('tenant_id', $result);
        $this->assertSame(self::TENANT, $this->repository->stored($result['id'])?->tenantId());
    }

    public function testCreateRequiresCodigo(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['codigo' => '   '])),
            400,
            'codigo'
        );
    }

    public function testCreateRequiresTipo(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['tipo' => ''])),
            400,
            'tipo'
        );
    }

    public function testCreateRejectsInvalidTipo(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['tipo' => 'NARNIA'])),
            400,
            'tipo'
        );
    }

    public function testCreateRejectsCapacityOnVirtualLocation(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['tipo' => 'TRANSITO'])),
            400,
            'capacidad'
        );

        $transito = $this->service->create($this->validData([
            'codigo' => 'TR-01',
            'tipo' => 'TRANSITO',
            'capacidad' => null,
        ]));

        $this->assertSame('TRANSITO', $transito['tipo']);
        $this->assertArrayNotHasKey('capacidad_restante', $transito);
    }

    public function testCreateRejectsNonPositiveCapacity(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['capacidad' => 0])),
            400,
            'capacidad'
        );
    }

    public function testCreateRejectsDuplicateCodigo(): void
    {
        $this->seedLocation(['codigo' => 'DUP-1']);

        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['codigo' => 'dup-1'])),
            409,
            'codigo'
        );
        $this->assertCount(1, $this->repository->findAll()['data']);
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function testFindByCodigoNormalizesInput(): void
    {
        $this->seedLocation(['codigo' => 'AB-01']);

        $found = $this->service->findByCodigo(' ab-01 ');

        $this->assertNotNull($found);
        $this->assertSame('AB-01', $found['codigo']);
        $this->assertNull($this->service->findByCodigo('   '));
        $this->assertNull($this->service->findByCodigo('NO-EXISTE'));
    }

    public function testFindByCodigoHidesOtherTenantAndDeletedLocations(): void
    {
        $this->seedLocation(['id' => 'other', 'tenant_id' => 'tenant-2', 'codigo' => 'OTHER-1']);
        $this->assertNull($this->service->findByCodigo('OTHER-1'));

        $this->seedLocation(['codigo' => 'DEL-1']);
        $this->repository->softDelete('loc-1');
        $this->assertNull($this->service->findByCodigo('DEL-1'));
    }

    public function testFindAllAppliesFiltersAndOrdersByCodigo(): void
    {
        $this->seedLocation(['codigo' => 'A-01', 'tipo' => 'ALMACENAMIENTO', 'nombre' => 'Pasillo A', 'jerarquia' => 'RAIZ/1', 'created_at' => '2026-01-01 10:00:00']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'B-01', 'tipo' => 'PICKING', 'nombre' => 'Puesto B', 'jerarquia' => 'RAIZ/2', 'created_at' => '2026-01-01 11:00:00']);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'C-01', 'tipo' => 'ALMACENAMIENTO', 'nombre' => 'Inactiva', 'jerarquia' => 'OTRA/1', 'activa' => 0, 'created_at' => '2026-01-01 09:00:00']);
        $this->seedLocation(['id' => 'loc-4', 'tenant_id' => 'tenant-2', 'codigo' => 'X-01', 'tipo' => 'PICKING', 'nombre' => 'Otro tenant']);

        $porTipo = $this->service->findAll(['tipo' => 'almacenamiento']);
        $this->assertSame(['A-01', 'C-01'], array_column($porTipo['data'], 'codigo'));

        $activas = $this->service->findAll(['activa' => true]);
        $this->assertSame(['A-01', 'B-01'], array_column($activas['data'], 'codigo'));

        $busqueda = $this->service->findAll(['search' => 'puesto']);
        $this->assertSame(['B-01'], array_column($busqueda['data'], 'codigo'));

        $jerarquia = $this->service->findAll(['jerarquia' => 'RAIZ/']);
        $this->assertSame(['A-01', 'B-01'], array_column($jerarquia['data'], 'codigo'));

        $sinResultados = $this->service->findAll(['search' => 'inexistente']);
        $this->assertSame([], $sinResultados['data']);
        $this->assertSame(0, $sinResultados['pagination']['total']);
    }

    public function testFindAllPaginates(): void
    {
        $this->seedLocation(['codigo' => 'P-1', 'created_at' => '2026-01-01 10:00:00']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'P-2', 'created_at' => '2026-01-01 11:00:00']);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'P-3', 'created_at' => '2026-01-01 12:00:00']);

        $primera = $this->service->findAll([], 1, 2);
        $this->assertSame(['P-1', 'P-2'], array_column($primera['data'], 'codigo'));
        $this->assertTrue($primera['pagination']['has_next']);
        $this->assertSame(3, $primera['pagination']['total']);

        $segunda = $this->service->findAll([], 2, 2);
        $this->assertSame(['P-3'], array_column($segunda['data'], 'codigo'));
        $this->assertFalse($segunda['pagination']['has_next']);
    }

    public function testFindActivasFindTipoAndCountExcludeOtherTenants(): void
    {
        $this->seedLocation(['codigo' => 'A-01', 'tipo' => 'ALMACENAMIENTO']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'B-01', 'tipo' => 'PICKING']);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'C-01', 'tipo' => 'ALMACENAMIENTO', 'activa' => 0]);
        $this->seedLocation(['id' => 'loc-4', 'tenant_id' => 'tenant-2', 'codigo' => 'X-01', 'tipo' => 'PICKING']);

        $this->assertSame(['A-01', 'B-01'], array_column($this->service->findActivas(), 'codigo'));
        $this->assertSame(['A-01', 'C-01'], array_column($this->service->findByTipo('almacenamiento'), 'codigo'));
        $this->assertSame([], $this->service->findByTipo('  '));

        $this->assertSame(3, $this->service->count());
        $this->assertSame(1, $this->service->count(['activa' => false]));
        // El único PICKING del tenant es B-01; X-01 pertenece a tenant-2
        $this->assertSame(1, $this->service->count(['tipo' => 'picking']));
    }

    public function testFindWithCapacityAvailableExcludesFullInactiveAndOtherTenant(): void
    {
        $this->seedLocation(['codigo' => 'CAP-1', 'capacidad' => 10, 'capacidad_usada' => 3]);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'CAP-2', 'capacidad' => 1, 'capacidad_usada' => 1]);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'CAP-3', 'capacidad' => null]);
        $this->seedLocation(['id' => 'loc-4', 'codigo' => 'CAP-4', 'capacidad' => null, 'activa' => 0]);
        $this->seedLocation(['id' => 'loc-5', 'tenant_id' => 'tenant-2', 'codigo' => 'CAP-X', 'capacidad' => null]);

        $disponibles = $this->service->findWithCapacityAvailable();

        $this->assertSame(['CAP-1', 'CAP-3'], array_column($disponibles, 'codigo'));
    }

    public function testFindChildrenMatchesHierarchyPrefix(): void
    {
        $this->seedLocation(['codigo' => 'H-1', 'jerarquia' => 'RAIZ/1']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'H-2', 'jerarquia' => 'RAIZ/2']);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'H-3', 'jerarquia' => 'RAIZ/2/A']);
        $this->seedLocation(['id' => 'loc-4', 'codigo' => 'H-4', 'jerarquia' => 'OTRA']);
        $this->seedLocation(['id' => 'loc-5', 'codigo' => 'H-5', 'jerarquia' => null]);

        $this->assertSame(['H-1', 'H-2', 'H-3'], array_column($this->service->findChildren('RAIZ/'), 'codigo'));
        $this->assertSame(['H-2', 'H-3'], array_column($this->service->findChildren('RAIZ/2'), 'codigo'));
        $this->assertSame([], $this->service->findChildren('   '));
    }

    // ==================================================================
    // ACTUALIZACIÓN
    // ==================================================================

    public function testUpdateModifiesFields(): void
    {
        $id = $this->seedLocation();

        $updated = $this->service->update($id, [
            'nombre' => 'Estanteria Renovada',
            'jerarquia' => null,
            'capacidad' => 50,
            'tipo' => 'picking',
        ]);

        $this->assertSame('Estanteria Renovada', $updated['nombre']);
        $this->assertSame(50, $updated['capacidad']);
        $this->assertSame('PICKING', $updated['tipo']);
        $this->assertNull($updated['jerarquia']);

        $stored = $this->repository->stored($id);
        $this->assertNotNull($stored);
        $this->assertSame('Estanteria Renovada', $stored->nombre());
    }

    public function testUpdateRejectsUnknownId(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->update('no-existe', ['nombre' => 'Nuevo']),
            404,
            null
        );
    }

    public function testUpdateRejectsDuplicateCodigo(): void
    {
        $this->seedLocation(['codigo' => 'COD-001']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'COD-002']);

        $this->assertInventoryError(
            fn() => $this->service->update('loc-1', ['codigo' => 'cod-002']),
            409,
            'codigo'
        );
        $this->assertSame('COD-001', $this->service->findById('loc-1')['codigo']);
    }

    public function testUpdateRejectsEmptyCodigo(): void
    {
        $id = $this->seedLocation();

        $this->assertInventoryError(
            fn() => $this->service->update($id, ['codigo' => '  ']),
            400,
            'codigo'
        );
        $this->assertSame('LOC-001', $this->service->findById($id)['codigo']);
    }

    public function testUpdateRejectsNonPositiveCapacity(): void
    {
        $id = $this->seedLocation();

        $this->assertInventoryError(
            fn() => $this->service->update($id, ['capacidad' => 0]),
            400,
            'capacidad'
        );
    }

    // ==================================================================
    // CICLO DE VIDA Y CAPACIDAD
    // ==================================================================

    public function testActivateAndDeactivateToggleLocation(): void
    {
        $id = $this->seedLocation();

        $this->assertFalse($this->service->deactivate($id)['activa']);
        $this->assertSame(1, $this->service->findAll(['activa' => false])['pagination']['total']);

        $this->assertTrue($this->service->activate($id)['activa']);
        $this->assertSame(0, $this->service->findAll(['activa' => false])['pagination']['total']);
    }

    public function testDeleteRejectsUnknownId(): void
    {
        $this->assertInventoryError(fn() => $this->service->delete('no-existe'), 404, null);
    }

    public function testDeleteBlockedWhenLocationHasStockUnits(): void
    {
        $id = $this->seedLocation();
        $this->repository->withStockUnits($id);

        $this->assertInventoryError(fn() => $this->service->delete($id), 409, null);
        $this->assertFalse($this->repository->isSoftDeleted($id));
        $this->assertNotNull($this->service->findById($id));
    }

    public function testDeleteBlockedWhenLocationHasMovements(): void
    {
        $id = $this->seedLocation();
        $this->repository->withMovements($id);

        $this->assertInventoryError(fn() => $this->service->delete($id), 409, null);
        $this->assertFalse($this->repository->isSoftDeleted($id));
    }

    public function testDeleteHidesLocationAndRestoreBringsItBack(): void
    {
        $id = $this->seedLocation(['codigo' => 'LIFE-1']);

        $this->assertTrue($this->service->delete($id));
        $this->assertTrue($this->repository->isSoftDeleted($id));
        $this->assertNull($this->service->findById($id));
        $this->assertNull($this->service->findByCodigo('LIFE-1'));
        $this->assertSame(0, $this->service->count());

        $this->assertTrue($this->repository->existsById($id));
        $this->assertTrue($this->service->restore($id));

        $restored = $this->service->findById($id);
        $this->assertNotNull($restored);
        $this->assertTrue($restored['activa']);
        $this->assertSame(1, $this->service->count());
    }

    public function testRestoreRejectsUnknownId(): void
    {
        $this->assertInventoryError(fn() => $this->service->restore('no-existe'), 404, null);
    }

    public function testCapacityUsageIsIncrementedAndBoundedByCapacity(): void
    {
        $id = $this->seedLocation(['capacidad' => 2, 'capacidad_usada' => 0]);

        $primera = $this->service->incrementarCapacidadUsada($id, 1);
        $this->assertSame(1, $primera['capacidad_usada']);
        $this->assertSame(1, $primera['capacidad_restante']);
        $this->assertTrue($primera['tiene_disponibilidad']);

        $segunda = $this->service->incrementarCapacidadUsada($id);
        $this->assertSame(2, $segunda['capacidad_usada']);
        $this->assertSame(0, $segunda['capacidad_restante']);
        $this->assertFalse($segunda['tiene_disponibilidad']);

        $this->assertInventoryError(
            fn() => $this->service->incrementarCapacidadUsada($id),
            422,
            'capacidad'
        );
        $this->assertSame(2, $this->repository->stored($id)?->capacidadUsada());
    }

    public function testCapacityUsageDecrementNeverGoesBelowZero(): void
    {
        $id = $this->seedLocation(['capacidad' => 10, 'capacidad_usada' => 1]);

        $resultado = $this->service->decrementarCapacidadUsada($id, 5);

        $this->assertSame(0, $resultado['capacidad_usada']);
        $this->assertSame(10, $resultado['capacidad_restante']);
    }

    public function testCapacityOperationsRejectUnknownId(): void
    {
        $this->assertInventoryError(fn() => $this->service->incrementarCapacidadUsada('no-existe'), 404, null);
        $this->assertInventoryError(fn() => $this->service->decrementarCapacidadUsada('no-existe'), 404, null);
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'LOC-001',
            'tipo' => 'ALMACENAMIENTO',
            'nombre' => 'Estanteria 1',
            'capacidad' => 10,
        ], $overrides);
    }

    private function seedLocation(array $overrides = []): string
    {
        $location = Location::fromArray(array_merge([
            'id' => 'loc-1',
            'tenant_id' => self::TENANT,
            'codigo' => 'LOC-001',
            'tipo' => 'ALMACENAMIENTO',
            'nombre' => 'Estanteria 1',
            'jerarquia' => 'RAIZ/1',
            'capacidad' => 10,
            'capacidad_usada' => 0,
            'activa' => 1,
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ], $overrides));

        $this->repository->seed($location);

        return $location->id();
    }

    private function assertInventoryError(callable $callback, int $code, ?string $field): void
    {
        try {
            $callback();
        } catch (InventoryException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            $this->assertSame($field, $e->getField());

            return;
        }

        $this->fail('Se esperaba un InventoryException que no se produjo.');
    }
}
