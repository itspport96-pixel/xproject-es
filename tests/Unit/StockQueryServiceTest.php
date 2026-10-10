<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\StockQueryService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * GET /api/inventory/stock (R1): listado y resumen de stock actual.
 */
final class StockQueryServiceTest extends InventorySqliteTestCase
{
    private StockQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockQueryService($this->pdo);
    }

    private function receive(string $sku, int $cantidad, string $ubicacion, string $key): void
    {
        $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacion,
        ], $key);
    }

    // ====================================================================
    // LISTADO
    // ====================================================================

    public function testListadoDevuelveUnidadesConCantidadesR1(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 'q-1');
        $this->receive('SKU-ALFA', 5, 'LOC-01', 'q-2');

        $result = $this->service->list(self::T1);

        $this->assertSame(2, $result['summary']['unidades']);
        $this->assertSame(15.0, $result['summary']['cantidad_fisica']);
        $this->assertSame(0.0, $result['summary']['cantidad_reservada']);
        $this->assertSame(0.0, $result['summary']['cantidad_en_transito']);
        $this->assertSame(15.0, $result['summary']['cantidad_disponible']);

        $this->assertCount(2, $result['data']);

        foreach ($result['data'] as $row) {
            $this->assertSame('SKU-ALFA', $row['sku']);
            $this->assertSame('LOC-01', $row['ubicacion_codigo']);
            $this->assertSame('DISPONIBLE', $row['estado']);
            $this->assertNotEmpty($row['codigo_barcode']);
            $this->assertSame(
                $row['cantidad_fisica'] - $row['cantidad_reservada'] - $row['cantidad_en_transito'],
                $row['cantidad_disponible']
            );
        }

        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertFalse($result['pagination']['has_next']);
    }

    public function testListadoSoloIncluyeElTenantConsultado(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 'q-t1');

        $this->useTenant(self::T2);
        $this->receive('SKU-T2', 3, 'LOC-T2', 'q-t2');

        $t1 = $this->service->list(self::T1);
        $t2 = $this->service->list(self::T2);

        $this->assertSame(1, $t1['summary']['unidades']);
        $this->assertSame(10.0, $t1['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-ALFA'], array_column($t1['data'], 'sku'));

        $this->assertSame(1, $t2['summary']['unidades']);
        $this->assertSame(3.0, $t2['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-T2'], array_column($t2['data'], 'sku'));
    }

    public function testFiltraPorProductoYEstadoYSearch(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 'q-f1');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 2, 'LOC-01', 'q-f2');
        $productoAlfa = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-ALFA']);

        $porProducto = $this->service->list(self::T1, ['producto_id' => $productoAlfa]);
        $this->assertSame(1, $porProducto['summary']['unidades']);
        $this->assertSame(['SKU-ALFA'], array_column($porProducto['data'], 'sku'));

        $porEstado = $this->service->list(self::T1, ['estado' => 'DISPONIBLE']); // se normaliza a mayúsculas
        $this->assertSame(2, $porEstado['summary']['unidades']);

        $porEstadoMinusculas = $this->service->list(self::T1, ['estado' => 'disponible']);
        $this->assertSame(2, $porEstadoMinusculas['summary']['unidades']);

        $porEstadoInvalido = $this->service->list(self::T1, ['estado' => 'PERDIDO']);
        $this->assertSame(0, $porEstadoInvalido['summary']['unidades']);

        $barcode = (string) $this->scalar(
            'SELECT codigo_barcode FROM stock_units WHERE tenant_id = ? LIMIT 1',
            [self::T1]
        );
        $porSearch = $this->service->list(self::T1, ['search' => $barcode]);
        $this->assertSame(1, $porSearch['summary']['unidades']);
        $this->assertSame($barcode, $porSearch['data'][0]['codigo_barcode']);
    }

    public function testPaginacion(): void
    {
        $this->receive('SKU-ALFA', 1, 'LOC-01', 'q-p1');
        $this->receive('SKU-ALFA', 2, 'LOC-01', 'q-p2');
        $this->receive('SKU-ALFA', 3, 'LOC-01', 'q-p3');

        $page1 = $this->service->list(self::T1, [], 1, 2);
        $page2 = $this->service->list(self::T1, [], 2, 2);

        $this->assertCount(2, $page1['data']);
        $this->assertTrue($page1['pagination']['has_next']);
        $this->assertCount(1, $page2['data']);
        $this->assertFalse($page2['pagination']['has_next']);
        $this->assertSame(3, $page2['pagination']['total']);
        // El resumen siempre mira TODAS las filas filtradas, no sólo la página
        $this->assertSame(6.0, $page2['summary']['cantidad_fisica']);
    }

    public function testListadoVacioDevuelveCero(): void
    {
        $result = $this->service->list(self::T1);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['summary']['unidades']);
        $this->assertSame(0.0, $result['summary']['cantidad_disponible']);
        $this->assertFalse($result['pagination']['has_next']);
    }

    public function testFiltroPorUbicacion(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $this->receive('SKU-ALFA', 4, 'LOC-01', 'q-u1');
        $this->receive('SKU-ALFA', 6, 'LOC-02', 'q-u2');

        $loc02 = (string) $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-02']);
        $result = $this->service->list(self::T1, ['ubicacion_id' => $loc02]);

        $this->assertSame(1, $result['summary']['unidades']);
        $this->assertSame(6.0, $result['summary']['cantidad_fisica']);
        $this->assertSame($loc02, $result['data'][0]['ubicacion_id']);
    }

    // ====================================================================
    // RESUMEN POR PRODUCTO (Sprint 6, 0b - GET /stock/summary)
    // ====================================================================

    public function testResumenPorProductoAgrupaElStockEnUnaConsulta(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 's-1');
        $this->receive('SKU-ALFA', 5, 'LOC-01', 's-2');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 3, 'LOC-01', 's-3');

        $result = $this->service->summaryByProduct(self::T1);

        // SKU-OFF tambien aparece (sin stock) porque es un LEFT JOIN
        $this->assertSame(3, $result['summary']['productos']);
        $this->assertSame(3, $result['summary']['unidades']);
        $this->assertSame(18.0, $result['summary']['cantidad_fisica']);
        $this->assertSame(0.0, $result['summary']['cantidad_reservada']);
        $this->assertSame(18.0, $result['summary']['cantidad_disponible']);

        $this->assertSame(
            ['SKU-ALFA', 'SKU-BETA', 'SKU-OFF'],
            array_column($result['data'], 'sku')
        );

        $alfa = $result['data'][0];
        $this->assertSame('SKU-ALFA', $alfa['sku']);
        $this->assertSame(2, $alfa['unidades']);
        $this->assertSame(15.0, $alfa['cantidad_fisica']);
        $this->assertSame(15.0, $alfa['cantidad_disponible']);

        $off = $result['data'][2];
        $this->assertSame(0, $off['unidades']);
        $this->assertSame(0.0, $off['cantidad_fisica']);
    }

    public function testResumenFiltradoPorIdentificadoresDeProducto(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 's-f1');
        $this->seedProduct(self::T1, 'SKU-BETA');
        $this->receive('SKU-BETA', 3, 'LOC-01', 's-f2');

        $idAlfa = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-ALFA']);
        $idBeta = (string) $this->scalar('SELECT id FROM products WHERE sku = ?', ['SKU-BETA']);

        $uno = $this->service->summaryByProduct(self::T1, [$idAlfa]);
        $this->assertSame(1, $uno['summary']['productos']);
        $this->assertSame(10.0, $uno['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-ALFA'], array_column($uno['data'], 'sku'));

        $dos = $this->service->summaryByProduct(self::T1, [$idAlfa, $idBeta, '', '  ']);
        $this->assertSame(2, $dos['summary']['productos']);
        $this->assertSame(13.0, $dos['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-ALFA', 'SKU-BETA'], array_column($dos['data'], 'sku'));

        // Un id inexistente simplemente no devuelve filas
        $nada = $this->service->summaryByProduct(self::T1, ['no-existe']);
        $this->assertSame(0, $nada['summary']['productos']);
        $this->assertSame([], $nada['data']);
    }

    public function testResumenReflejaLasReservasDelSprint6(): void
    {
        $this->receive('SKU-ALFA', 10, 'LOC-01', 's-r1');
        $unidadId = (string) $this->scalar(
            'SELECT id FROM stock_units WHERE tenant_id = ? LIMIT 1',
            [self::T1]
        );

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unidadId, 'cantidad' => 3],
            's-res-1',
            'user-1'
        );

        $result = $this->service->summaryByProduct(self::T1);

        $alfa = $result['data'][0];
        $this->assertSame(10.0, $alfa['cantidad_fisica']);
        $this->assertSame(3.0, $alfa['cantidad_reservada']);
        $this->assertSame(7.0, $alfa['cantidad_disponible']);
        $this->assertSame(3.0, $result['summary']['cantidad_reservada']);
        $this->assertSame(7.0, $result['summary']['cantidad_disponible']);
    }

    public function testResumenAgrupaLasUnidadesMovidas(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $this->receive('SKU-ALFA', 6, 'LOC-01', 's-m1');
        $unidadId = (string) $this->scalar(
            'SELECT id FROM stock_units WHERE tenant_id = ? LIMIT 1',
            [self::T1]
        );

        $this->moveUseCase->execute([
            'unidad_fisica_id' => $unidadId,
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 's-mv-1', 'user-1');

        $result = $this->service->summaryByProduct(self::T1);

        // El resumen es por PRODUCTO: la ubicacion no cambia los totales
        $alfa = $result['data'][0];
        $this->assertSame(1, $alfa['unidades']);
        $this->assertSame(6.0, $alfa['cantidad_fisica']);
        $this->assertSame(6.0, $alfa['cantidad_disponible']);
    }

    public function testResumenNoIncluyeStockDeOtrosTenants(): void
    {
        $this->receive('SKU-ALFA', 7, 'LOC-01', 's-t1');

        $this->useTenant(self::T2);
        $this->receive('SKU-T2', 4, 'LOC-T2', 's-t2');

        $t1 = $this->service->summaryByProduct(self::T1);
        $t2 = $this->service->summaryByProduct(self::T2);

        $this->assertSame(7.0, $t1['summary']['cantidad_fisica']);
        $this->assertNotContains('SKU-T2', array_column($t1['data'], 'sku'));

        $this->assertSame(4.0, $t2['summary']['cantidad_fisica']);
        $this->assertSame(['SKU-T2'], array_column($t2['data'], 'sku'));
    }

    public function testResumenRechazaMasDe500Identificadores(): void
    {
        $ids = array_map(static fn(int $i): string => "id-{$i}", range(1, 501));

        try {
            $this->service->summaryByProduct(self::T1, $ids);
            $this->fail('Debía rechazar más de 500 identificadores');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('producto_id', $e->getField());
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }
}
