<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;

/**
 * Sprint 4 (productos): validación de creación, unicidad de SKU por tenant,
 * filtros/paginación, ciclo de vida activo-borrado y aislamiento de tenant.
 *
 * Usa un repositorio en memoria con la misma semántica que el adaptador MySQL.
 */
final class ProductServiceTest extends TestCase
{
    private const TENANT = 'tenant-1';

    private InMemoryProductRepository $repository;
    private ProductService $service;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->repository = new InMemoryProductRepository(self::TENANT);

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        $this->service = new ProductService($this->repository, $tenantContext, new InputSanitizer());
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ==================================================================
    // CREACIÓN
    // ==================================================================

    public function testCreateSanitizesAndPersistsProduct(): void
    {
        $result = $this->service->create($this->validData([
            'sku' => ' ab c-01 ',
            'nombre' => '  Tornillo   3/8  ',
            'unidad_medida' => 'kg',
            'peso' => 0.5,
            'descripcion' => 'Tornillo zincado',
        ]));

        $this->assertSame('ABC-01', $result['sku']);
        $this->assertSame('Tornillo 3/8', $result['nombre']);
        $this->assertSame('KG', $result['unidad_medida']);
        $this->assertSame(0.5, $result['peso']);
        $this->assertTrue($result['activo']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['id']
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['created_at']);

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

    public function testCreateRequiresSku(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['sku' => '   '])),
            400,
            'sku'
        );
    }

    public function testCreateRequiresNombre(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['nombre' => '   '])),
            400,
            'nombre'
        );
    }

    public function testCreateRequiresUnidadMedida(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['unidad_medida' => ''])),
            400,
            'unidad_medida'
        );
    }

    public function testCreateRequiresCategoriaByBusinessRule(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['categoria' => null])),
            400,
            'categoria'
        );
    }

    public function testCreateRequiresPesoForWeightUnit(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['unidad_medida' => 'kg'])),
            400,
            'peso'
        );
    }

    public function testCreateRequiresVolumenForVolumeUnit(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['unidad_medida' => 'lt'])),
            400,
            'volumen'
        );
    }

    public function testCreateRejectsInvalidUnitOfMeasure(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['unidad_medida' => 'PIE'])),
            400,
            'unidad_medida'
        );
    }

    public function testCreateRejectsDuplicateSku(): void
    {
        $this->seedProduct(['sku' => 'DUP-1']);

        $this->assertInventoryError(
            fn() => $this->service->create($this->validData(['sku' => 'dup-1'])),
            409,
            'sku'
        );
        $this->assertCount(1, $this->repository->findAll()['data']);
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function testFindBySkuNormalizesInput(): void
    {
        $this->seedProduct(['sku' => 'AB-01']);

        $found = $this->service->findBySku(' ab-01 ');

        $this->assertNotNull($found);
        $this->assertSame('AB-01', $found['sku']);
        $this->assertNull($this->service->findBySku('   '));
        $this->assertNull($this->service->findBySku('NO-EXISTE'));
    }

    public function testFindBySkuHidesOtherTenantAndDeletedProducts(): void
    {
        $this->seedProduct(['id' => 'other', 'tenant_id' => 'tenant-2', 'sku' => 'OTHER-1']);
        $this->assertNull($this->service->findBySku('OTHER-1'));

        $this->seedProduct(['sku' => 'DEL-1']);
        $this->repository->softDelete('prod-1');
        $this->assertNull($this->service->findBySku('DEL-1'));
    }

    public function testFindAllAppliesFilters(): void
    {
        $this->seedProduct(['sku' => 'TORN-1', 'nombre' => 'Tornillo', 'categoria' => 'Fijaciones', 'created_at' => '2026-01-01 10:00:00']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'CABLE-1', 'nombre' => 'Cable 2mm', 'categoria' => 'Electricidad', 'unidad_medida' => 'M', 'created_at' => '2026-01-01 11:00:00']);
        $this->seedProduct(['id' => 'prod-3', 'sku' => 'TAPA-1', 'nombre' => 'Tapa plastica', 'categoria' => 'Fijaciones', 'activo' => 0, 'created_at' => '2026-01-01 09:00:00']);

        $activos = $this->service->findAll(['activo' => true]);
        $this->assertSame(2, $activos['pagination']['total']);
        $this->assertSame(['CABLE-1', 'TORN-1'], array_column($activos['data'], 'sku'));

        $porCategoria = $this->service->findAll(['categoria' => 'Fijaciones']);
        $this->assertSame(2, $porCategoria['pagination']['total']);

        $porUnidad = $this->service->findAll(['unidad_medida' => 'm']);
        $this->assertSame(['CABLE-1'], array_column($porUnidad['data'], 'sku'));

        $busqueda = $this->service->findAll(['search' => 'torn']);
        $this->assertSame(['TORN-1'], array_column($busqueda['data'], 'sku'));

        $vacios = $this->service->findAll(['search' => 'inexistente']);
        $this->assertSame([], $vacios['data']);
        $this->assertSame(0, $vacios['pagination']['total']);
    }

    public function testFindAllPaginates(): void
    {
        $this->seedProduct(['sku' => 'P-1', 'created_at' => '2026-01-01 10:00:00']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'P-2', 'created_at' => '2026-01-01 11:00:00']);
        $this->seedProduct(['id' => 'prod-3', 'sku' => 'P-3', 'created_at' => '2026-01-01 12:00:00']);

        $primera = $this->service->findAll([], 1, 2);
        $this->assertCount(2, $primera['data']);
        $this->assertSame(['P-3', 'P-2'], array_column($primera['data'], 'sku'));
        $this->assertTrue($primera['pagination']['has_next']);
        $this->assertSame(3, $primera['pagination']['total']);

        $segunda = $this->service->findAll([], 2, 2);
        $this->assertCount(1, $segunda['data']);
        $this->assertSame(['P-1'], array_column($segunda['data'], 'sku'));
        $this->assertFalse($segunda['pagination']['has_next']);
        $this->assertSame(2, $segunda['pagination']['page']);
    }

    public function testFindActivosAndCountExcludeInactiveAndOtherTenants(): void
    {
        $this->seedProduct(['sku' => 'A-1', 'nombre' => 'Activo uno']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'A-2', 'nombre' => 'Activo dos']);
        $this->seedProduct(['id' => 'prod-3', 'sku' => 'I-1', 'nombre' => 'Inactivo', 'activo' => 0]);
        $this->seedProduct(['id' => 'prod-4', 'tenant_id' => 'tenant-2', 'sku' => 'X-1', 'nombre' => 'Otro tenant']);

        // ORDER BY nombre ASC: "Activo dos" antes que "Activo uno"
        $activos = $this->service->findActivos();
        $this->assertSame(['A-2', 'A-1'], array_column($activos, 'sku'));

        $this->assertSame(3, $this->service->count());
        $this->assertSame(1, $this->service->count(['activo' => false]));
        $this->assertSame(0, $this->service->count(['categoria' => 'No existe']));
    }

    public function testFindByCategoriaAndUnidadMedidaRequireActiveProducts(): void
    {
        $this->seedProduct(['sku' => 'ON-1', 'categoria' => 'Fijaciones', 'unidad_medida' => 'UNIDAD']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'OFF-1', 'categoria' => 'Fijaciones', 'unidad_medida' => 'UNIDAD', 'activo' => 0]);

        $this->assertSame(['ON-1'], array_column($this->service->findByCategoria('Fijaciones'), 'sku'));
        $this->assertSame(['ON-1'], array_column($this->service->findByUnidadMedida('unidad'), 'sku'));
        $this->assertSame([], $this->service->findByCategoria('   '));
        $this->assertSame([], $this->service->findByUnidadMedida('  '));
    }

    // ==================================================================
    // ACTUALIZACIÓN
    // ==================================================================

    public function testUpdateModifiesFields(): void
    {
        $id = $this->seedProduct();

        $updated = $this->service->update($id, [
            'nombre' => 'Tornillo 1/4',
            'descripcion' => null,
            'unidad_medida' => 'kg',
            'peso' => 0.2,
        ]);

        $this->assertSame('Tornillo 1/4', $updated['nombre']);
        $this->assertSame('KG', $updated['unidad_medida']);
        $this->assertSame(0.2, $updated['peso']);
        $this->assertNull($updated['descripcion']);

        $stored = $this->repository->stored($id);
        $this->assertNotNull($stored);
        $this->assertSame('Tornillo 1/4', $stored->nombre());
    }

    public function testUpdateRejectsUnknownId(): void
    {
        $this->assertInventoryError(
            fn() => $this->service->update('no-existe', ['nombre' => 'Nuevo']),
            404,
            null
        );
    }

    public function testUpdateRejectsDuplicateSku(): void
    {
        $this->seedProduct(['sku' => 'SKU-001']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'SKU-002']);

        $this->assertInventoryError(
            fn() => $this->service->update('prod-1', ['sku' => 'sku-002']),
            409,
            'sku'
        );
        $this->assertSame('SKU-001', $this->service->findById('prod-1')['sku']);
    }

    public function testUpdateRejectsEmptyNombre(): void
    {
        $id = $this->seedProduct();

        $this->assertInventoryError(
            fn() => $this->service->update($id, ['nombre' => '  ']),
            400,
            'nombre'
        );
        $this->assertSame('Tornillo 3/8', $this->service->findById($id)['nombre']);
    }

    // ==================================================================
    // CICLO DE VIDA
    // ==================================================================

    public function testActivateAndDeactivateToggleProduct(): void
    {
        $id = $this->seedProduct();

        $this->assertFalse($this->service->deactivate($id)['activo']);
        $this->assertSame(1, $this->service->findAll(['activo' => false])['pagination']['total']);

        $this->assertTrue($this->service->activate($id)['activo']);
        $this->assertSame(0, $this->service->findAll(['activo' => false])['pagination']['total']);
    }

    public function testDeleteRejectsUnknownId(): void
    {
        $this->assertInventoryError(fn() => $this->service->delete('no-existe'), 404, null);
    }

    public function testDeleteBlockedWhenProductHasStockUnits(): void
    {
        $id = $this->seedProduct();
        $this->repository->withStockUnits($id);

        $this->assertInventoryError(fn() => $this->service->delete($id), 409, null);
        $this->assertFalse($this->repository->isSoftDeleted($id));
        $this->assertNotNull($this->service->findById($id));
    }

    public function testDeleteHidesProductAndSameSkuCanBeRecreated(): void
    {
        $id = $this->seedProduct(['sku' => 'LIFE-1']);

        $this->assertTrue($this->service->delete($id));
        $this->assertTrue($this->repository->isSoftDeleted($id));
        $this->assertNull($this->service->findById($id));
        $this->assertNull($this->service->findBySku('LIFE-1'));
        $this->assertSame(0, $this->service->count());

        // Politica de borrado: no hay restore; el SKU del producto borrado
        // queda libre y un producto nuevo puede reutilizarlo (id nuevo).
        $nuevo = $this->service->create($this->validData(['sku' => 'LIFE-1']));
        $this->assertNotSame($id, $nuevo['id']);
        $this->assertNotNull($this->service->findById((string) $nuevo['id']));
        $this->assertNotNull($this->service->findBySku('LIFE-1'));
        $this->assertTrue($this->repository->isSoftDeleted($id));
        $this->assertSame(1, $this->service->count());
    }

    public function testFindIncludingDeletedReturnsSoftDeletedRow(): void
    {
        $id = $this->seedProduct();

        $this->service->delete($id);

        $raw = $this->service->findIncludingDeleted($id);
        $this->assertIsArray($raw);
        $this->assertSame($id, $raw['id']);
        $this->assertNotNull($raw['deleted_at']);
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
            'sku' => 'SKU-001',
            'nombre' => 'Tornillo 3/8',
            'unidad_medida' => 'UNIDAD',
            'categoria' => 'Fijaciones',
        ], $overrides);
    }

    private function seedProduct(array $overrides = []): string
    {
        $product = Product::fromArray(array_merge([
            'id' => 'prod-1',
            'tenant_id' => self::TENANT,
            'sku' => 'SKU-001',
            'nombre' => 'Tornillo 3/8',
            'unidad_medida' => 'UNIDAD',
            'categoria' => 'Fijaciones',
            'activo' => 1,
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ], $overrides));

        $this->repository->seed($product);

        return $product->id();
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
