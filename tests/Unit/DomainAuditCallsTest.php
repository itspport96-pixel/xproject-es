<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Http\ProductController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\RecordingAuditLogger;

/**
 * Auditoría de dominio en controllers (sólo éxitos): los fallos los aporta
 * el hook del Router, verificado en RouterAuditHookTest.
 */
final class DomainAuditCallsTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const USER = 'u-1';

    private InMemoryProductRepository $repository;
    private ProductController $controller;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->repository = new InMemoryProductRepository(self::TENANT);

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        $service = new ProductService($this->repository, $tenantContext, new InputSanitizer());
        $this->audit = new RecordingAuditLogger();
        $this->controller = new ProductController($service, $this->audit);
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
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
            'user' => ['user_id' => self::USER, 'tenant_id' => self::TENANT],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'request_id' => 'req-dom-1',
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

    // ==================================================================
    // TESTS
    // ==================================================================

    public function testStoreRegistraProductCreate(): void
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
        $this->assertCount(1, $this->audit->entries);

        $entry = $this->audit->entries[0];
        $this->assertSame('product.create', $entry['operacion']);
        $this->assertSame(self::TENANT, $entry['tenant_id']);
        $this->assertSame(self::USER, $entry['usuario_id']);
        $this->assertSame('inventory', $entry['modulo']);
        $this->assertSame('product', $entry['recurso']);
        $this->assertSame('CREATE', $entry['tipo_cambio']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame('127.0.0.1', $entry['ip_address']);
        $this->assertSame('req-dom-1', $entry['request_id']);
        $this->assertSame($result['data']['product']['id'], $entry['entidad_id']);
        $this->assertSame('SKU-NUEVO', $entry['valores_nuevos']['sku']);
        $this->assertArrayHasKey('id', $entry['valores_nuevos']);
        $this->assertArrayNotHasKey('valores_anteriores', $entry);
    }

    public function testUpdateRegistraAntesYDespues(): void
    {
        $id = $this->seedProduct();

        $result = $this->controller->update(
            $this->request(['body' => ['nombre' => 'Tornillo actualizado']]),
            ['id' => $id]
        );

        $this->assertSame(200, $result['status']);

        $entry = $this->audit->byOperacion('product.update');
        $this->assertNotNull($entry, 'Falta product.update');
        $this->assertSame('UPDATE', $entry['tipo_cambio']);
        $this->assertSame($id, $entry['entidad_id']);
        $this->assertSame('Tornillo 3/8', $entry['valores_anteriores']['nombre']);
        $this->assertSame('Tornillo actualizado', $entry['valores_nuevos']['nombre']);
    }

    public function testDestroyRegistraLosValoresAnteriores(): void
    {
        $id = $this->seedProduct();

        $result = $this->controller->destroy($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);

        $entry = $this->audit->byOperacion('product.delete');
        $this->assertNotNull($entry, 'Falta product.delete');
        $this->assertSame('DELETE', $entry['tipo_cambio']);
        $this->assertSame($id, $entry['entidad_id']);
        $this->assertSame('SKU-001', $entry['valores_anteriores']['sku']);
        $this->assertArrayNotHasKey('valores_nuevos', $entry);
    }

    public function testAccionesDeEstadoSeAuditanComoUpdate(): void
    {
        $id = $this->seedProduct();

        $this->controller->deactivate($this->request(), ['id' => $id]);
        $this->controller->activate($this->request(), ['id' => $id]);

        $this->assertNotNull($this->audit->byOperacion('product.deactivate'));
        $this->assertNotNull($this->audit->byOperacion('product.activate'));

        $this->assertSame('UPDATE', $this->audit->byOperacion('product.deactivate')['tipo_cambio']);
        $this->assertSame('inventory', $this->audit->byOperacion('product.activate')['modulo']);
    }

    public function testSinLoggerLasEscriturasSiguenFuncionando(): void
    {
        $this->repository = new InMemoryProductRepository(self::TENANT);
        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);
        $service = new ProductService($this->repository, $tenantContext, new InputSanitizer());

        // Sin logger inyectado y sin binding en el Container: no debe fallar.
        $controller = new ProductController($service);

        $result = $controller->store($this->request([
            'body' => [
                'sku' => 'SKU-X',
                'nombre' => 'X',
                'unidad_medida' => 'UNIDAD',
                'categoria' => 'Fijaciones',
            ],
        ]));

        $this->assertSame(201, $result['status']);
    }

    public function testUnaOperacionFallidaNoGeneraFilaDeDominio(): void
    {
        try {
            $this->controller->store($this->request(['body' => ['sku' => '', 'nombre' => '']]));
            $this->fail('Debía fallar la validación');
        } catch (HttpException) {
            // esperado (validación de negocio → 400)
        }

        $this->assertSame([], $this->audit->entries, 'Los fallos los audita el hook, no el controller');
    }
}
