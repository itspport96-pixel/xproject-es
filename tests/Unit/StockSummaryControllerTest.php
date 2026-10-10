<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Http\StockController;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * GET /api/inventory/stock/summary (Sprint 6, 0b).
 *
 * La capa HTTP sólo añade claims y normaliza `producto_id` (array o escalar).
 */
final class StockSummaryControllerTest extends InventorySqliteTestCase
{
    private StockController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new StockController(new \App\Modules\Inventory\Application\Services\StockQueryService($this->pdo));
    }

    private function claims(): array
    {
        return ['user_id' => 'user-1', 'tenant_id' => self::T1];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(array $query = [], ?array $user = null): array
    {
        return [
            'user' => $user ?? $this->claims(),
            'headers' => [],
            'body' => [],
            'query' => $query,
        ];
    }

    private function receive(string $sku, int $cantidad, string $key): array
    {
        return $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => 'LOC-01',
        ], $key)['data'];
    }

    public function testSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->summary([
                'user' => null,
                'headers' => [],
                'body' => [],
                'query' => [],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testSinFiltroDevuelveTodosLosProductosDelTenant(): void
    {
        $this->receive('SKU-ALFA', 8, 'sum-1');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 2, 'sum-2');

        $result = $this->controller->summary($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(3, $result['data']['summary']['productos']);
        $this->assertSame(10.0, $result['data']['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-ALFA', 'SKU-BETA', 'SKU-OFF'], array_column($result['data']['data'], 'sku'));
    }

    public function testAceptaProductoIdComoArray(): void
    {
        $this->receive('SKU-ALFA', 8, 'sum-a1');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 2, 'sum-a2');

        $idAlfa = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-ALFA']);
        $idBeta = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-BETA']);

        $result = $this->controller->summary($this->request(['producto_id' => [$idAlfa, $idBeta]]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['data']['summary']['productos']);
        $this->assertSame(['SKU-ALFA', 'SKU-BETA'], array_column($result['data']['data'], 'sku'));
    }

    public function testAceptaProductoIdComoEscalar(): void
    {
        $this->receive('SKU-ALFA', 8, 'sum-e1');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 2, 'sum-e2');

        $idBeta = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-BETA']);

        $result = $this->controller->summary($this->request(['producto_id' => $idBeta]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['summary']['productos']);
        $this->assertSame(['SKU-BETA'], array_column($result['data']['data'], 'sku'));
        $this->assertSame(2.0, $result['data']['summary']['cantidad_fisica']);
    }

    public function testSoloVeElTenantDeLosClaims(): void
    {
        $this->receive('SKU-ALFA', 5, 'sum-t1');

        $this->useTenant(self::T2);
        $this->useCase->execute([
            'sku' => 'SKU-T2',
            'cantidad_esperada' => 9,
            'ubicacion_codigo' => 'LOC-T2',
        ], 'sum-t2');
        $this->useTenant(self::T1);

        $result = $this->controller->summary($this->request());

        $this->assertSame(5.0, $result['data']['summary']['cantidad_fisica']);
        $this->assertNotContains('SKU-T2', array_column($result['data']['data'], 'sku'));
    }
}
