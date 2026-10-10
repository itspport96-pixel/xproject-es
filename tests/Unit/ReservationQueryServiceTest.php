<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\ReservationQueryService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;
use Tests\Support\InventorySqliteTestCase;

/**
 * GET /api/inventory/reservations (v1.6.2): listado paginado de reservas.
 *
 * Cubre: aislamiento entre tenants, filtros (estado, producto_id,
 * stock_unit_id, vencimiento_desde/hasta), paginación con tope de 100 y que
 * la respuesta incluya `reserva_id` y `fecha_expiracion` (campos que la
 * pantalla Reservas necesita para habilitar "Liberar").
 */
final class ReservationQueryServiceTest extends InventorySqliteTestCase
{
    private ReservationQueryService $query;

    private string $reservaConExpiracion;
    private string $reservaSinExpiracion;
    private string $reservaCancelada;
    private string $reservaT2;
    private string $productoBravo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = new ReservationQueryService($this->pdo);
        $this->seedReservations();
    }

    // ====================================================================
    // SEMILLA
    // ====================================================================

    private function receive(int $cantidad, string $sku, string $ubicacion): array
    {
        $result = $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacion,
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    private function reserve(string $unidadId, array $overrides, string $key): string
    {
        $result = $this->reserveUseCase->execute(
            array_merge(['unidad_fisica_id' => $unidadId], $overrides),
            $key,
            'user-9'
        );

        $this->assertSame(201, $result['status']);

        return (string) $result['data']['reserva']['reserva_id'];
    }

    private function seedReservations(): void
    {
        // T1: tres reservas con estados y vencimientos distintos
        $this->productoBravo = $this->seedProduct(self::T1, 'SKU-BRAVO');

        $u1 = $this->receive(3, 'SKU-ALFA', 'LOC-01');
        $u2 = $this->receive(4, 'SKU-ALFA', 'LOC-01');
        $u3 = $this->receive(2, 'SKU-BRAVO', 'LOC-01');

        $this->reservaConExpiracion = $this->reserve($u1['unidad_fisica_id'], [
            'fecha_expiracion' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ], 'rq-key-1');

        $this->reservaSinExpiracion = $this->reserve($u2['unidad_fisica_id'], [], 'rq-key-2');

        $this->reservaCancelada = $this->reserve($u3['unidad_fisica_id'], [], 'rq-key-3');
        $this->releaseUseCase->execute($this->reservaCancelada, [], null, 'user-9');

        // Orden determinista para paginar: una fila por día
        $this->pdo->exec("UPDATE reservations SET created_at = '2026-10-01 09:00:00' WHERE id = '{$this->reservaConExpiracion}'");
        $this->pdo->exec("UPDATE reservations SET created_at = '2026-10-02 09:00:00' WHERE id = '{$this->reservaSinExpiracion}'");
        $this->pdo->exec("UPDATE reservations SET created_at = '2026-10-03 09:00:00' WHERE id = '{$this->reservaCancelada}'");

        // T2: una reserva del otro tenant
        $this->useTenant(self::T2);
        $u4 = $this->receive(2, 'SKU-T2', 'LOC-T2');
        $this->reservaT2 = $this->reserve($u4['unidad_fisica_id'], [], 'rq-key-4');
        $this->pdo->exec("UPDATE reservations SET created_at = '2026-10-04 09:00:00' WHERE id = '{$this->reservaT2}'");
        $this->useTenant(self::T1);
    }

    private function ids(array $result): array
    {
        return array_column($result['data'], 'reserva_id');
    }

    // ====================================================================
    // AISLAMIENTO DE TENANTS
    // ====================================================================

    public function testListaSoloLasReservasDelTenantActual(): void
    {
        $t1 = $this->query->list(self::T1);
        $this->assertSame(3, $t1['pagination']['total']);
        $this->assertNotContains($this->reservaT2, $this->ids($t1));

        $t2 = $this->query->list(self::T2);
        $this->assertSame(1, $t2['pagination']['total']);
        $this->assertSame([$this->reservaT2], $this->ids($t2));
        $this->assertNotContains($this->reservaConExpiracion, $this->ids($t2));
    }

    // ====================================================================
    // FORMA DE LA RESPUESTA
    // ====================================================================

    public function testLaRespuestaIncluyeReservaIdYFechaExpiracion(): void
    {
        $result = $this->query->list(self::T1);

        $row = null;
        foreach ($result['data'] as $candidate) {
            if ($candidate['reserva_id'] === $this->reservaConExpiracion) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row, 'Falta la reserva con vencimiento en el listado');
        $this->assertSame(
            ['reserva_id', 'stock_unit_id', 'barcode', 'producto', 'ubicacion',
             'cantidad', 'estado', 'fecha_expiracion', 'creado_por', 'fecha_creacion'],
            array_keys($row)
        );
        $this->assertNotNull($row['fecha_expiracion'], 'La reserva con plazo debe devolver fecha_expiracion');
        $this->assertSame('ACTIVA', $row['estado']);
        $this->assertSame('Producto SKU-ALFA', $row['producto']);
        $this->assertNotEmpty($row['barcode']);
        $this->assertSame('LOC-01', $row['ubicacion']);
        $this->assertSame(3.0, $row['cantidad']);
        $this->assertSame('user-9', $row['creado_por']);

        $canceladaId = $this->reservaCancelada;
        $cancelada = array_values(array_filter(
            $result['data'],
            static fn(array $r): bool => $r['reserva_id'] === $canceladaId
        ))[0];
        $this->assertNull($cancelada['fecha_expiracion']);
        $this->assertSame('CANCELADA', $cancelada['estado']);
    }

    public function testElResumenCuentaActivasYConVencimiento(): void
    {
        $result = $this->query->list(self::T1);

        $this->assertSame(3, $result['summary']['total']);
        $this->assertSame(2, $result['summary']['activas']);
        $this->assertSame(1, $result['summary']['con_expiracion']);
    }

    // ====================================================================
    // FILTROS
    // ====================================================================

    public function testFiltroPorEstado(): void
    {
        $activas = $this->query->list(self::T1, ['estado' => 'activa']);
        $this->assertSame(2, $activas['pagination']['total']);
        $this->assertNotContains($this->reservaCancelada, $this->ids($activas));

        $canceladas = $this->query->list(self::T1, ['estado' => 'CANCELADA']);
        $this->assertSame([$this->reservaCancelada], $this->ids($canceladas));
    }

    public function testEstadoDesconocidoDevuelve400ConElCampo(): void
    {
        try {
            $this->query->list(self::T1, ['estado' => 'VOLADORA']);
            $this->fail('Debía rechazar un estado desconocido');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->toArray()['field']);
        }
    }

    public function testFiltroPorProductoYPorUnidad(): void
    {
        $porProducto = $this->query->list(self::T1, ['producto_id' => $this->productoBravo]);
        $this->assertSame([$this->reservaCancelada], $this->ids($porProducto));

        $porUnidad = $this->query->list(self::T1, ['stock_unit_id' => $this->unitOf($this->reservaConExpiracion)]);
        $this->assertSame([$this->reservaConExpiracion], $this->ids($porUnidad));

        $otroTenant = $this->query->list(self::T1, ['producto_id' => 'no-existe']);
        $this->assertSame(0, $otroTenant['pagination']['total']);
    }

    public function testFiltroDeVencimientoDesdeHasta(): void
    {
        // Rango futuro (a partir de mañana) → no incluye la reserva de +1 día
        $desde = $this->query->list(self::T1, [
            'vencimiento_desde' => (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame(0, $desde['pagination']['total']);

        // Rango que cubre la reserva de +1 día
        $hasta = $this->query->list(self::T1, [
            'vencimiento_hasta' => (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame([$this->reservaConExpiracion], $this->ids($hasta));

        // Las reservas sin fecha_expiracion nunca entran en el rango
        $sinExpiracion = $this->query->list(self::T1, [
            'vencimiento_hasta' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
        ]);
        $this->assertSame([$this->reservaConExpiracion], $this->ids($sinExpiracion));

        // Fecha no parseable → 400 con el campo
        try {
            $this->query->list(self::T1, ['vencimiento_desde' => 'ayer']);
            $this->fail('Debía rechazar una fecha no parseable');
        } catch (InventoryException $e) {
            $this->assertSame('vencimiento_desde', $e->toArray()['field']);
        }
    }

    // ====================================================================
    // PAGINACIÓN
    // ====================================================================

    public function testPaginaElListado(): void
    {
        $page1 = $this->query->list(self::T1, [], 1, 2);
        $this->assertCount(2, $page1['data']);
        $this->assertTrue($page1['pagination']['has_next']);
        $this->assertSame(3, $page1['pagination']['total']);
        // Orden: created_at DESC
        $this->assertSame($this->reservaCancelada, $page1['data'][0]['reserva_id']);
        $this->assertSame($this->reservaSinExpiracion, $page1['data'][1]['reserva_id']);

        $page2 = $this->query->list(self::T1, [], 2, 2);
        $this->assertCount(1, $page2['data']);
        $this->assertSame($this->reservaConExpiracion, $page2['data'][0]['reserva_id']);
        $this->assertFalse($page2['pagination']['has_next']);
    }

    public function testRecortaPerPageAlMaximoDe100(): void
    {
        $result = $this->query->list(self::T1, [], 1, 500);

        $this->assertSame(100, $result['pagination']['per_page']);
        $this->assertFalse($result['pagination']['has_next']);

        $minus = $this->query->list(self::T1, [], 0, 0);
        $this->assertSame(1, $minus['pagination']['page']);
        $this->assertSame(1, $minus['pagination']['per_page']);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function unitOf(string $reservaId): string
    {
        return (string) $this->scalar(
            'SELECT unidad_fisica_id FROM reservations WHERE id = ?',
            [$reservaId]
        );
    }
}
