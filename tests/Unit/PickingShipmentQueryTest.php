<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * Consultas paginadas de picking y transporte (Sprint 7, v1.7.0).
 *
 * GET /api/inventory/picking y GET /api/inventory/shipments: campos,
 * resumen, filtros, paginación (tope 100), 400 de filtro inválido y
 * aislamiento de tenants.
 */
final class PickingShipmentQueryTest extends InventorySqliteTestCase
{
    private const SELECTOR = 'selector-1';
    private const SUPERVISOR = 'supervisor-9';

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function receive(int $cantidad = 3, string $sku = 'SKU-ALFA', string $ubicacion = 'LOC-01'): array
    {
        $result = $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacion,
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    private function lot(string $lote, string $estado = 'EN_CURSO', string $sku = 'SKU-ALFA'): string
    {
        $esT2 = $sku === 'SKU-T2';
        $unit = $this->receive(3, $sku, $esT2 ? 'LOC-T2' : 'LOC-01');

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $esT2
                ? $this->seedLocation(self::T2, 'LOC-PICK', capacidad: 10, tipo: 'PICKING')
                : $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SELECTOR);

        if ($estado === 'COMPLETADA') {
            $this->completePickingUseCase->execute($lote);
        } elseif ($estado === 'VERIFICADA') {
            $this->completePickingUseCase->execute($lote);
            $this->verifyPickingUseCase->execute($lote, [], null, self::SUPERVISOR);
        }

        return $unit['unidad_fisica_id'];
    }

    /**
     * @return string shipment_id
     */
    private function loadedShipment(string $lote): string
    {
        $unitId = $this->lot($lote, 'COMPLETADA');

        $result = $this->loadShipmentUseCase->execute([
            'unidades' => [['unidad_fisica_id' => $unitId]],
            'shipment' => [
                'referencia_externa' => 'TR-300',
                'destinatario_nombre' => 'ACME SA',
            ],
        ], null, self::SELECTOR);

        $this->assertSame(201, $result['status']);

        return (string) $result['data']['shipment']['shipment_id'];
    }

    // ====================================================================
    // PICKING
    // ====================================================================

    public function testListaPickingConResumenYCampos(): void
    {
        $unitA = $this->lot('LOTE-Q1'); // EN_CURSO
        $this->lot('LOTE-Q2', 'COMPLETADA');

        $result = $this->pickingQuery->list(self::T1);

        $this->assertSame(2, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['en_curso']);
        $this->assertSame(1, $result['summary']['completadas']);
        $this->assertSame(0, $result['summary']['verificadas']);
        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertFalse($result['pagination']['has_next']);

        $row = null;
        foreach ($result['data'] as $candidate) {
            if ($candidate['id_lote_picking'] === 'LOTE-Q1') {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row, 'Falta LOTE-Q1 en el listado');
        $this->assertArrayHasKey('picking_id', $row);
        $this->assertArrayHasKey('id_lote_picking', $row);
        $this->assertArrayHasKey('barcode', $row);
        $this->assertArrayHasKey('producto', $row);
        $this->assertSame('Producto SKU-ALFA', $row['producto']);
        $this->assertSame(3.0, (float) $row['cantidad']);
        $this->assertSame('LOC-01', $row['ubicacion_origen']);
        $this->assertSame('LOC-PICK', $row['ubicacion_preparacion']);
        $this->assertSame(self::SELECTOR, $row['selector']);
        $this->assertNull($row['supervisor']);
        $this->assertSame($unitA, $row['stock_unit_id']);
    }

    public function testFiltraPickingPorEstadoYLote(): void
    {
        $this->lot('LOTE-F1');
        $this->lot('LOTE-F2', 'VERIFICADA');

        $enCurso = $this->pickingQuery->list(self::T1, ['estado' => 'EN_CURSO']);
        $this->assertSame(1, $enCurso['summary']['total']);
        $this->assertSame('LOTE-F1', $enCurso['data'][0]['id_lote_picking']);

        $porLote = $this->pickingQuery->list(self::T1, ['id_lote_picking' => 'LOTE-F2']);
        $this->assertSame(1, $porLote['summary']['total']);
        $this->assertSame('VERIFICADA', $porLote['data'][0]['estado']);
        $this->assertSame(self::SUPERVISOR, $porLote['data'][0]['supervisor']);

        $hoy = $this->pickingQuery->list(self::T1, [
            'fecha_desde' => date('Y-m-d'),
            'fecha_hasta' => date('Y-m-d'),
        ]);
        $this->assertSame(2, $hoy['summary']['total']);
    }

    public function testPaginacionDePickingYTopeDe100(): void
    {
        $this->lot('LOTE-P1');

        $page = $this->pickingQuery->list(self::T1, [], 2, 1);
        $this->assertSame(2, $page['pagination']['page']);
        $this->assertSame(1, $page['pagination']['total']);
        $this->assertCount(0, $page['data']);
        $this->assertFalse($page['pagination']['has_next']);

        $topado = $this->pickingQuery->list(self::T1, [], 1, 500);
        $this->assertSame(100, $topado['pagination']['per_page']);
    }

    public function testEstadoDePickingInvalidoDevuelve400(): void
    {
        try {
            $this->pickingQuery->list(self::T1, ['estado' => 'VOLANDO']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    public function testFechaInvalidaDePickingDevuelve400(): void
    {
        try {
            $this->pickingQuery->list(self::T1, ['fecha_desde' => 'ayer']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('fecha_desde', $e->getField());
        }
    }

    public function testPickingAisladoPorTenant(): void
    {
        $this->lot('LOTE-T1');
        $this->useTenant(self::T2);
        $this->lot('LOTE-T2', 'COMPLETADA', 'SKU-T2');
        $this->useTenant(self::T1);

        $t1 = $this->pickingQuery->list(self::T1);
        $this->assertSame(1, $t1['summary']['total']);
        $this->assertSame('LOTE-T1', $t1['data'][0]['id_lote_picking']);

        $this->useTenant(self::T2);
        $t2 = $this->pickingQuery->list(self::T2);
        $this->assertSame(1, $t2['summary']['total']);
        $this->assertSame('LOTE-T2', $t2['data'][0]['id_lote_picking']);
        $this->useTenant(self::T1);
    }

    // ====================================================================
    // SHIPMENTS
    // ====================================================================

    public function testListaEnviosConResumenYTotalItems(): void
    {
        $this->loadedShipment('LOTE-S1');

        $result = $this->shipmentQuery->list(self::T1);

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['en_preparacion']);
        $this->assertSame(0, $result['summary']['en_ruta']);
        $this->assertCount(1, $result['data']);

        $row = $result['data'][0];
        $this->assertArrayHasKey('shipment_id', $row);
        $this->assertSame('TR-300', $row['referencia_externa']);
        $this->assertSame('ACME SA', $row['destinatario_nombre']);
        $this->assertSame('PREPARACION', $row['estado']);
        $this->assertSame(1, $row['total_items']);
        $this->assertNull($row['hora_salida']);
    }

    public function testFiltraEnviosPorEstado(): void
    {
        $this->loadedShipment('LOTE-S2');

        $preparacion = $this->shipmentQuery->list(self::T1, ['estado' => 'PREPARACION']);
        $this->assertSame(1, $preparacion['summary']['total']);

        $enRuta = $this->shipmentQuery->list(self::T1, ['estado' => 'EN_RUTA']);
        $this->assertSame(0, $enRuta['summary']['total']);
        $this->assertSame([], $enRuta['data']);

        try {
            $this->shipmentQuery->list(self::T1, ['estado' => 'VOLANDO']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    public function testMuestraUnEnvioConSusItems(): void
    {
        $shipmentId = $this->loadedShipment('LOTE-S3');

        $result = $this->shipmentQuery->show(self::T1, $shipmentId);

        $this->assertSame($shipmentId, $result['shipment']['shipment_id']);
        $this->assertSame('PREPARACION', $result['shipment']['estado']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('CARGADA', $result['items'][0]['estado']);
        $this->assertSame('Producto SKU-ALFA', $result['items'][0]['producto']);
        $this->assertSame(3.0, (float) $result['items'][0]['cantidad']);
        $this->assertArrayHasKey('barcode', $result['items'][0]);
    }

    public function testMostrarEnvioInexistenteDevuelve404(): void
    {
        try {
            $this->shipmentQuery->show(self::T1, 'no-existe');
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('shipment_id', $e->getField());
        }
    }

    public function testEnviosAisladosPorTenant(): void
    {
        $shipmentT1 = $this->loadedShipment('LOTE-S4');

        $this->useTenant(self::T2);
        $this->assertFalse(
            (bool) $this->scalar(
                'SELECT id FROM shipments WHERE id = ? AND tenant_id = ?',
                [$shipmentT1, self::T2]
            ),
            'El envío del tenant T1 no debe verse desde T2'
        );

        try {
            $this->shipmentQuery->show(self::T2, $shipmentT1);
            $this->fail('Un envío de otro tenant no debe ser visible');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->shipmentQuery->list(self::T2)['summary']['total']);
        $this->useTenant(self::T1);
    }
}
