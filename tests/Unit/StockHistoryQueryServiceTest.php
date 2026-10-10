<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\StockHistoryQueryService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * v1.10.0 (R3): GET /api/inventory/stock/{id}/history.
 *
 * Línea de tiempo de UNA unidad: fusión de stock_events + movements
 * ordenada por fecha descendente, paginada, con aislamiento de tenant y
 * 404 si la unidad no existe.
 */
final class StockHistoryQueryServiceTest extends InventorySqliteTestCase
{
    private StockHistoryQueryService $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(self::T1, 'LOC-02');
        $this->history = new StockHistoryQueryService($this->pdo);
    }

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    private function receive(string $ubicacionCodigo = 'LOC-01', int $cantidad = 3): array
    {
        return $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacionCodigo,
        ])['data'];
    }

    public function testFusionaEventosYMovimientosOrdenadosPorFecha(): void
    {
        $data = $this->receive('LOC-01', 3);
        $unitId = $data['unidad_fisica_id'];

        $this->moveUseCase->execute([
            'unidad_fisica_id' => $unitId,
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-hist-1', 'user-1');

        $result = $this->history->history(self::T1, $unitId);

        $this->assertSame($unitId, $result['unidad']['unidad_fisica_id']);
        $this->assertSame('DISPONIBLE', $result['unidad']['estado']);
        $this->assertSame($this->loc('LOC-02'), $result['unidad']['ubicacion_id']);

        // RECIBIDO (evento) + MOVIDO (evento) + movimiento REUBICACION
        $this->assertSame(3, $result['pagination']['total']);
        $fuentes = array_column($result['data'], 'fuente');
        $this->assertContains('evento', $fuentes);
        $this->assertContains('movimiento', $fuentes);

        // Orden descendente por timestamp
        $timestamps = array_column($result['data'], 'timestamp');
        $ordenado = $timestamps;
        rsort($ordenado);
        $this->assertSame($ordenado, $timestamps);

        $tipos = array_column($result['data'], 'tipo');
        $this->assertContains('RECIBIDO', $tipos);
        $this->assertContains('MOVIDO', $tipos);
        $this->assertContains('REUBICACION', $tipos);

        // El movimiento lleva su hash de integridad (append-only)
        $mov = array_values(array_filter($result['data'], static fn(array $e): bool => $e['fuente'] === 'movimiento'))[0];
        $this->assertNotEmpty($mov['hash_integridad']);
        $this->assertFalse($mov['es_correccion']);
        $this->assertSame($this->loc('LOC-01'), $mov['ubicacion_origen_id']);
        $this->assertSame($this->loc('LOC-02'), $mov['ubicacion_destino_id']);
    }

    public function testIncluyeElBloqueoYDesbloqueoE4(): void
    {
        $data = $this->receive('LOC-01', 2);
        $unitId = $data['unidad_fisica_id'];

        $this->blockUseCase->execute(
            $unitId,
            ['accion' => 'BLOQUEAR'],
            'blk-hist-1',
            'user-1'
        );
        $this->blockUseCase->execute(
            $unitId,
            ['accion' => 'DESBLOQUEAR'],
            'unblk-hist-1',
            'user-1'
        );

        $result = $this->history->history(self::T1, $unitId);
        $tipos = array_column($result['data'], 'tipo');

        $this->assertContains('BLOQUEADO', $tipos);
        $this->assertContains('DESBLOQUEADO', $tipos);
        $this->assertSame('DISPONIBLE', $result['unidad']['estado']);
    }

    public function testPaginacion(): void
    {
        $data = $this->receive('LOC-01', 3);
        $unitId = $data['unidad_fisica_id'];

        $this->moveUseCase->execute([
            'unidad_fisica_id' => $unitId,
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-hist-2', 'user-1');

        $page1 = $this->history->history(self::T1, $unitId, 1, 2);
        $page2 = $this->history->history(self::T1, $unitId, 2, 2);

        $this->assertSame(2, count($page1['data']));
        $this->assertTrue($page1['pagination']['has_next']);
        $this->assertSame(1, count($page2['data']));
        $this->assertFalse($page2['pagination']['has_next']);

        $idsPage1 = array_column($page1['data'], 'id');
        $idsPage2 = array_column($page2['data'], 'id');
        $this->assertSame([], array_intersect($idsPage1, $idsPage2));
    }

    public function testUnidadInexistenteDevuelve404(): void
    {
        try {
            $this->history->history(self::T1, 'unit-que-no-existe');
            $this->fail('Unidad inexistente debe devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testNoVeUnidadesDeOtroTenant(): void
    {
        $data = $this->receive('LOC-01', 3);

        try {
            $this->history->history(self::T2, $data['unidad_fisica_id']);
            $this->fail('La unidad del tenant 1 no debe verse desde el tenant 2');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
