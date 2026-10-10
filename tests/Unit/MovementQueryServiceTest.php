<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\MovementQueryService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * v1.10.0 (R3): GET /api/inventory/movements.
 *
 * Listado paginado de `movements` con filtros (unidad, ubicaciones, motivo,
 * es_correccion, fechas), summary, aislamiento de tenant y visibilidad de
 * las columnas nuevas E8 (es_correccion / movimiento_referencia_id).
 */
final class MovementQueryServiceTest extends InventorySqliteTestCase
{
    private MovementQueryService $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(self::T1, 'LOC-02');
        $this->query = new MovementQueryService($this->pdo);
    }

    private function receive(string $ubicacionCodigo = 'LOC-01', int $cantidad = 3): array
    {
        return $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacionCodigo,
        ])['data'];
    }

    private function move(string $unitId, string $destino, string $key): array
    {
        return $this->moveUseCase->execute([
            'unidad_fisica_id' => $unitId,
            'ubicacion_destino_codigo' => $destino,
        ], $key, 'user-1')['data'];
    }

    public function testListadoConResumenYFiltros(): void
    {
        $data = $this->receive('LOC-01', 3);
        $unitId = $data['unidad_fisica_id'];
        $this->move($unitId, 'LOC-02', 'mv-q-1');

        $result = $this->query->list(self::T1, [], 1, 20);

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame(0, $result['summary']['correcciones']);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertFalse($result['pagination']['has_next']);

        $row = $result['data'][0];
        $this->assertSame($unitId, $row['unidad_fisica_id']);
        $this->assertSame('LOC-01', $row['ubicacion_origen_codigo']);
        $this->assertSame('LOC-02', $row['ubicacion_destino_codigo']);
        $this->assertSame('REUBICACION', $row['motivo']);
        $this->assertFalse($row['es_correccion']);
        $this->assertNull($row['movimiento_referencia_id']);
        $this->assertNotEmpty($row['hash_integridad']);
        $this->assertNotEmpty($row['timestamp_movimiento']);

        // Filtro por unidad
        $porUnidad = $this->query->list(self::T1, ['unidad_fisica_id' => $unitId], 1, 20);
        $this->assertSame(1, $porUnidad['summary']['total']);

        $otraUnidad = $this->query->list(self::T1, ['unidad_fisica_id' => 'unit-otra'], 1, 20);
        $this->assertSame(0, $otraUnidad['summary']['total']);

        // Filtro por ubicación destino
        $porDestino = $this->query->list(
            self::T1,
            ['ubicacion_destino_id' => $this->loc('LOC-02')],
            1,
            20
        );
        $this->assertSame(1, $porDestino['summary']['total']);
    }

    public function testMovimientoInversoSeFiltraComoCorreccionE8(): void
    {
        $data = $this->receive('LOC-01', 3);
        $unitId = $data['unidad_fisica_id'];
        $this->move($unitId, 'LOC-02', 'mv-q-2');
        $this->move($unitId, 'LOC-01', 'mv-q-3');

        $correcciones = $this->query->list(self::T1, ['es_correccion' => 1], 1, 20);
        $this->assertSame(1, $correcciones['summary']['total']);
        $this->assertSame(1, $correcciones['summary']['correcciones']);

        $normales = $this->query->list(self::T1, ['es_correccion' => 0], 1, 20);
        $this->assertSame(1, $normales['summary']['total']);

        $fila = $correcciones['data'][0];
        $this->assertTrue($fila['es_correccion']);
        $this->assertNotNull($fila['movimiento_referencia_id']);

        // El movimiento referenciado es el ida original
        $referenciado = $this->row('SELECT * FROM movements WHERE id = ?', [$fila['movimiento_referencia_id']]);
        $this->assertNotNull($referenciado);
        $this->assertSame('LOC-01', $this->codigo($referenciado['ubicacion_origen_id']));
        $this->assertSame('LOC-02', $this->codigo($referenciado['ubicacion_destino_id']));
    }

    public function testFiltroEsCorreccionInvalidoDevuelve400(): void
    {
        try {
            $this->query->list(self::T1, ['es_correccion' => 'quizas'], 1, 20);
            $this->fail('es_correccion no booleano debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('es_correccion', $e->getField());
        }
    }

    public function testFiltroDeFechaInvalidoDevuelve400(): void
    {
        try {
            $this->query->list(self::T1, ['fecha_desde' => 'ayer'], 1, 20);
            $this->fail('fecha_desde no parseable debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('fecha_desde', $e->getField());
        }
    }

    public function testSoloVeMovimientosDelTenant(): void
    {
        $data = $this->receive('LOC-01', 3);
        $this->move($data['unidad_fisica_id'], 'LOC-02', 'mv-q-4');

        $this->assertSame(1, $this->query->list(self::T1, [], 1, 20)['summary']['total']);
        $this->assertSame(0, $this->query->list(self::T2, [], 1, 20)['summary']['total']);
    }

    private function codigo(string $locId): ?string
    {
        return $this->scalar('SELECT codigo FROM locations WHERE id = ?', [$locId]);
    }

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }
}
