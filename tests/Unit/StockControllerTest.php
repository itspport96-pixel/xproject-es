<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\StockQueryService;
use App\Modules\Inventory\Http\StockController;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * GET /api/inventory/stock (capa HTTP).
 */
final class StockControllerTest extends InventorySqliteTestCase
{
    private StockController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new StockController(new StockQueryService($this->pdo));
    }

    private function request(array $query = [], ?array $user = null): array
    {
        return [
            'user' => $user ?? ['user_id' => 'user-1', 'tenant_id' => self::T1],
            'headers' => [],
            'body' => [],
            'query' => $query,
        ];
    }

    public function testSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->index([
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

    public function testDevuelve200ConResumenListadoYPaginacion(): void
    {
        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 12,
            'ubicacion_codigo' => 'LOC-01',
        ], 'stock-http-1');

        $result = $this->controller->index($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['summary']['unidades']);
        $this->assertSame(12.0, $result['data']['summary']['cantidad_disponible']);
        $this->assertSame('SKU-ALFA', $result['data']['data'][0]['sku']);
        $this->assertSame(1, $result['data']['pagination']['total']);
    }

    public function testTraduceLosFiltrosDeQueryString(): void
    {
        $locationId = (string) $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);
        $productoId = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-ALFA']);

        $result = $this->controller->index($this->request([
            'producto_id' => $productoId,
            'ubicacion_id' => $locationId,
            'estado' => 'DISPONIBLE',
            'page' => '2',
            'per_page' => '50',
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $result['data']['summary']['unidades']);
        $this->assertSame(2, $result['data']['pagination']['page']);
        $this->assertSame(50, $result['data']['pagination']['per_page']);
    }

    public function testLosLimitesDePaginacionSeRecortan(): void
    {
        $result = $this->controller->index($this->request(['page' => '0', 'per_page' => '9999']));

        $this->assertSame(1, $result['data']['pagination']['page']);
        $this->assertSame(100, $result['data']['pagination']['per_page']);
    }
}
