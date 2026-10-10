<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\ProductController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;

/**
 * HTTP de productos: guardia de claims, envoltorios {status,data},
 * parseo de query string y propagación de errores de negocio.
 */
final class ProductControllerTest extends TestCase
{
    private const TENANT = 'tenant-1';

    private InMemoryProductRepository $repository;
    private ProductController $controller;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->repository = new InMemoryProductRepository(self::TENANT);

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        $service = new ProductService($this->repository, $tenantContext, new InputSanitizer());
        $this->controller = new ProductController($service);
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function testIndexRejectsRequestWithoutClaims(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->index([]);
    }

    public function testIndexRejectsRequestWithoutTenantClaim(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->index(['user' => ['user_id' => 'u-1']]);
    }

    public function testIndexReturnsEnvelopeWithPagination(): void
    {
        $this->seedProduct(['sku' => 'SKU-A', 'created_at' => '2026-01-01 11:00:00']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'SKU-B', 'activo' => 0, 'created_at' => '2026-01-01 09:00:00']);

        $result = $this->controller->index($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['data']['pagination']['total']);
        $this->assertSame(['SKU-A', 'SKU-B'], array_column($result['data']['data'], 'sku'));
    }

    public function testIndexMapsQueryFiltersAndPagination(): void
    {
        $this->seedProduct(['sku' => 'SKU-A']);
        $this->seedProduct(['id' => 'prod-2', 'sku' => 'SKU-B', 'activo' => 0]);

        $result = $this->controller->index($this->request([
            'query' => [
                'activo' => 'true',
                'per_page' => '1',
                'page' => '1',
                'search' => 'SKU',
            ],
        ]));

        $this->assertSame(1, $result['data']['pagination']['total']);
        $this->assertSame(1, $result['data']['pagination']['per_page']);
        $this->assertSame(['SKU-A'], array_column($result['data']['data'], 'sku'));
    }

    public function testShowRejectsMissingId(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->show($this->request(), []);
    }

    public function testShowReturnsNotFoundForUnknownId(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller->show($this->request(), ['id' => 'no-existe']);
    }

    public function testShowReturnsProductEnvelope(): void
    {
        $id = $this->seedProduct(['sku' => 'SKU-A']);

        $result = $this->controller->show($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('SKU-A', $result['data']['product']['sku']);
    }

    public function testFindBySkuRejectsMissingSku(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->findBySku($this->request(), []);
    }

    public function testFindBySkuReturnsNotFoundForUnknownSku(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller->findBySku($this->request(), ['sku' => 'NO-EXISTE']);
    }

    public function testFindBySkuReturnsProductEnvelope(): void
    {
        $this->seedProduct(['sku' => 'SKU-A']);

        $result = $this->controller->findBySku($this->request(), ['sku' => 'sku-a']);

        $this->assertSame(200, $result['status']);
        $this->assertSame('SKU-A', $result['data']['product']['sku']);
    }

    // ==================================================================
    // ESCRITURA
    // ==================================================================

    public function testStoreCreatesProductWithCreatedStatus(): void
    {
        $result = $this->controller->store($this->request([
            'body' => [
                'sku' => 'SKU-NUEVO',
                'nombre' => 'Producto nuevo',
                'unidad_medida' => 'UNIDAD',
                'categoria' => 'Fijaciones',
            ],
        ]));

        $this->assertSame(201, $result['status']);
        $this->assertSame('SKU-NUEVO', $result['data']['product']['sku']);
        $this->assertSame(1, $this->repository->findAll()['pagination']['total']);
    }

    public function testStorePropagatesBusinessValidation(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->store($this->request(['body' => ['sku' => '', 'nombre' => 'Sin SKU']]));
    }

    public function testUpdateRejectsMissingId(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->update($this->request(['body' => ['nombre' => 'X']]), []);
    }

    public function testUpdateReturnsUpdatedProduct(): void
    {
        $id = $this->seedProduct();

        $result = $this->controller->update($this->request(['body' => ['nombre' => 'Renombrado']]), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('Renombrado', $result['data']['product']['nombre']);
    }

    public function testUpdatePropagatesNotFound(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(404);

        $this->controller->update($this->request(['body' => ['nombre' => 'X']]), ['id' => 'no-existe']);
    }

    public function testDestroyPropagatesConflictWhenProductHasStock(): void
    {
        $id = $this->seedProduct();
        $this->repository->withStockUnits($id);

        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(409);

        $this->controller->destroy($this->request(), ['id' => $id]);
    }

    public function testDestroySoftDeletesProduct(): void
    {
        $id = $this->seedProduct();

        $result = $this->controller->destroy($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('message', $result['data']);
        $this->assertTrue($this->repository->isSoftDeleted($id));
    }

    public function testShowHidesDeletedProduct(): void
    {
        $id = $this->seedProduct();
        $this->controller->destroy($this->request(), ['id' => $id]);

        try {
            $this->controller->show($this->request(), ['id' => $id]);
            $this->fail('El producto eliminado no debe ser visible');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    // ==================================================================
    // ACCIONES DE ESTADO
    // ==================================================================

    public function testActivateAndDeactivateReturnUpdatedProduct(): void
    {
        $id = $this->seedProduct();

        $desactivado = $this->controller->deactivate($this->request(), ['id' => $id]);
        $this->assertFalse($desactivado['data']['product']['activo']);

        $activado = $this->controller->activate($this->request(), ['id' => $id]);
        $this->assertTrue($activado['data']['product']['activo']);
    }

    public function testStateActionsRejectMissingId(): void
    {
        foreach (['activate', 'deactivate', 'restore'] as $accion) {
            try {
                $this->controller->{$accion}($this->request(), []);
                $this->fail("{$accion} debió exigir id");
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
    }

    public function testRestoreBringsDeletedProductBack(): void
    {
        $id = $this->seedProduct(['sku' => 'SKU-R']);
        $this->controller->destroy($this->request(), ['id' => $id]);
        $this->assertTrue($this->repository->isSoftDeleted($id));

        $result = $this->controller->restore($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($this->controller->show($this->request(), ['id' => $id])['data']['product']['activo']);
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'user' => ['user_id' => 'u-1', 'tenant_id' => self::TENANT],
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
}
