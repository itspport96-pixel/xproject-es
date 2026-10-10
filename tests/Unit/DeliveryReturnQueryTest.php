<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\DeliveryQueryService;
use App\Modules\Inventory\Application\Services\ReturnQueryService;
use App\Modules\Inventory\Domain\Entities\ReturnOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * GET /api/inventory/deliveries y GET /api/inventory/returns (v1.8.0).
 *
 * Listado paginado de las dos consultas del Sprint 8: paginación, tope de
 * 100 por página, filtros de estado/fechas/envío/unidad/motivo, resumen de
 * conteos, aislamiento entre tenants y 400 con `field` ante filtros
 * desconocidos.
 */
final class DeliveryReturnQueryTest extends InventorySqliteTestCase
{
    private const U1 = 'unit-q-1';
    private const U2 = 'unit-q-2';
    private const U3 = 'unit-q-3';
    private const UT2 = 'unit-q-t2';

    private const S1 = 'ship-q-1';
    private const S2 = 'ship-q-2';
    private const ST2 = 'ship-q-t2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedQueryFixtures();
    }

    // ====================================================================
    // FIXTURES
    // ====================================================================

    private function seedQueryFixtures(): void
    {
        $now = '2026-10-03 12:00:00';

        foreach ([
            [self::U1, self::T1, 'BC-Q1', 'LOC-01'],
            [self::U2, self::T1, 'BC-Q2', 'LOC-01'],
            [self::U3, self::T1, 'BC-Q3', 'LOC-01'],
            [self::UT2, self::T2, 'BC-QT2', 'LOC-T2'],
        ] as [$id, $tenant, $barcode, $loc]) {
            $this->pdo
                ->prepare(
                    'INSERT INTO stock_units (id, tenant_id, producto_id, codigo_barcode, cantidad, estado, ubicacion_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 3, ?, ?, ?, ?)'
                )
                ->execute([
                    $id,
                    $tenant,
                    'prod-sku-alfa',
                    $barcode,
                    'DISPONIBLE',
                    'loc-' . strtolower($loc) . '-' . substr($tenant, 0, 5),
                    $now,
                    $now,
                ]);
        }

        foreach ([
            [self::S1, self::T1, 'TR-1', 'EN_RUTA', self::T1],
            [self::S2, self::T1, 'TR-2', 'ENTREGADO', self::T1],
            [self::ST2, self::T2, 'TR-T2', 'EN_RUTA', self::T2],
        ] as [$id, $tenant, $ref, $estado, $origenTenant]) {
            $this->pdo
                ->prepare(
                    'INSERT INTO shipments (id, tenant_id, referencia_externa, estado, origen_ubicacion_id, destinatario_nombre, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )
                ->execute([
                    $id,
                    $tenant,
                    $ref,
                    $estado,
                    'loc-loc-01-' . substr($origenTenant, 0, 5),
                    'ACME SA',
                    $now,
                    $now,
                ]);
        }

        $deliveries = [
            ['del-q-1', self::T1, self::S1, self::U1, 'ENTREGADA', '2026-10-01 10:00:00'],
            ['del-q-2', self::T1, self::S1, self::U2, 'RECHAZADA', '2026-10-02 10:00:00'],
            ['del-q-3', self::T1, self::S2, self::U3, 'PENDIENTE', '2026-10-03 10:00:00'],
            ['del-q-t2', self::T2, self::ST2, self::UT2, 'ENTREGADA', '2026-10-04 10:00:00'],
        ];

        foreach ($deliveries as [$id, $tenant, $shipment, $unit, $estado, $ts]) {
            $this->pdo
                ->prepare(
                    'INSERT INTO deliveries (id, tenant_id, shipment_id, unidad_fisica_id, cantidad, estado, timestamp_entrega, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 3, ?, ?, ?, ?)'
                )
                ->execute([$id, $tenant, $shipment, $unit, $estado, $ts, $ts, $ts]);
        }

        $returns = [
            ['ret-q-1', self::T1, 'del-q-1', self::U1, ReturnOperation::MOTIVO_FALLO_ENTREGA, ReturnOperation::ESTADO_OK, 'loc-loc-01-' . substr(self::T1, 0, 5), '2026-10-01 12:00:00'],
            ['ret-q-2', self::T1, 'del-q-2', self::U2, ReturnOperation::MOTIVO_DANO, ReturnOperation::ESTADO_DANADO, 'loc-loc-dam-' . substr(self::T1, 0, 5), '2026-10-02 12:00:00'],
            ['ret-q-3', self::T1, null, self::U3, ReturnOperation::MOTIVO_OTRO, ReturnOperation::ESTADO_PERDIDO, null, '2026-10-03 12:00:00'],
            ['ret-q-4', self::T1, null, self::U1, ReturnOperation::MOTIVO_CAMBIO_CLIENTE, ReturnOperation::ESTADO_DETERIORADO, 'loc-loc-dam-' . substr(self::T1, 0, 5), '2026-10-04 12:00:00'],
            ['ret-q-t2', self::T2, 'del-q-t2', self::UT2, ReturnOperation::MOTIVO_NO_APLICA, ReturnOperation::ESTADO_OK, 'loc-loc-t2-' . substr(self::T2, 0, 5), '2026-10-04 12:00:00'],
        ];

        foreach ($returns as [$id, $tenant, $delivery, $unit, $motivo, $estado, $destino, $ts]) {
            $this->pdo
                ->prepare(
                    'INSERT INTO return_operations (id, tenant_id, delivery_id, unidad_fisica_id, cantidad, motivo, estado_al_devolver, ubicacion_destino_id, timestamp_devolucion, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 3, ?, ?, ?, ?, ?, ?)'
                )
                ->execute([$id, $tenant, $delivery, $unit, $motivo, $estado, $destino, $ts, $ts, $ts]);
        }
    }

    // ====================================================================
    // ENTREGAS
    // ====================================================================

    public function testListaLasEntregasDelTenantConLosDatosEnriquecidos(): void
    {
        $result = (new DeliveryQueryService($this->pdo))->list(self::T1);

        $this->assertSame(
            ['total' => 3, 'entregadas' => 1, 'rechazadas' => 1, 'pendientes' => 1, 'parciales' => 0],
            $result['summary']
        );
        $this->assertCount(3, $result['data']);
        $this->assertSame(3, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertFalse($result['pagination']['has_next']);

        // Orden: timestamp_entrega DESC
        $this->assertSame('del-q-3', $result['data'][0]['delivery_id']);
        $this->assertSame('del-q-1', $result['data'][2]['delivery_id']);

        $row = $result['data'][2];
        $this->assertSame(self::S1, $row['shipment_id']);
        $this->assertSame('TR-1', $row['referencia_externa']);
        $this->assertSame('EN_RUTA', $row['shipment_estado']);
        $this->assertSame('BC-Q1', $row['codigo_barcode']);
        $this->assertSame(3.0, $row['cantidad']);
        $this->assertSame('ENTREGADA', $row['estado']);
    }

    public function testPaginaElListadoDeEntregas(): void
    {
        $service = new DeliveryQueryService($this->pdo);

        $page1 = $service->list(self::T1, [], 1, 2);
        $this->assertCount(2, $page1['data']);
        $this->assertTrue($page1['pagination']['has_next']);
        $this->assertSame(2, $page1['pagination']['per_page']);

        $page2 = $service->list(self::T1, [], 2, 2);
        $this->assertCount(1, $page2['data']);
        $this->assertFalse($page2['pagination']['has_next']);
        $this->assertSame('del-q-1', $page2['data'][0]['delivery_id']);
    }

    public function testElTopeDeFilasPorPaginaEs100(): void
    {
        $result = (new DeliveryQueryService($this->pdo))->list(self::T1, [], 1, 500);

        $this->assertSame(100, $result['pagination']['per_page']);
    }

    public function testFiltraEntregasPorEstadoEnvioYUnidad(): void
    {
        $service = new DeliveryQueryService($this->pdo);

        $porEstado = $service->list(self::T1, ['estado' => 'rechazada']);
        $this->assertSame(1, $porEstado['summary']['total']);
        $this->assertSame('del-q-2', $porEstado['data'][0]['delivery_id']);

        $porEnvio = $service->list(self::T1, ['shipment_id' => self::S2]);
        $this->assertSame(1, $porEnvio['summary']['total']);
        $this->assertSame('del-q-3', $porEnvio['data'][0]['delivery_id']);

        $porUnidad = $service->list(self::T1, ['unidad_fisica_id' => self::U1]);
        $this->assertSame(1, $porUnidad['summary']['total']);
        $this->assertSame(self::U1, $porUnidad['data'][0]['unidad_fisica_id']);
    }

    public function testFiltraEntregasPorRangoDeFechas(): void
    {
        $service = new DeliveryQueryService($this->pdo);

        $desde = $service->list(self::T1, ['fecha_desde' => '2026-10-02']);
        $this->assertSame(2, $desde['summary']['total']);

        $hasta = $service->list(self::T1, ['fecha_hasta' => '2026-10-01']);
        $this->assertSame(1, $hasta['summary']['total']);
        $this->assertSame('del-q-1', $hasta['data'][0]['delivery_id']);

        $rango = $service->list(self::T1, ['fecha_desde' => '2026-10-02', 'fecha_hasta' => '2026-10-02']);
        $this->assertSame(1, $rango['summary']['total']);
    }

    public function testEstadoDesconocidoDeEntregaDevuelve400(): void
    {
        try {
            (new DeliveryQueryService($this->pdo))->list(self::T1, ['estado' => 'VOLANDO']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    public function testFechaInvalidaDeEntregaDevuelve400(): void
    {
        try {
            (new DeliveryQueryService($this->pdo))->list(self::T1, ['fecha_desde' => 'ayer']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('fecha_desde', $e->getField());
        }
    }

    public function testLasEntregasDeOtroTenantNoAparecen(): void
    {
        $result = (new DeliveryQueryService($this->pdo))->list(self::T2);

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame('del-q-t2', $result['data'][0]['delivery_id']);
    }

    // ====================================================================
    // DEVOLUCIONES
    // ====================================================================

    public function testListaLasDevolucionesDelTenantConElResumen(): void
    {
        $result = (new ReturnQueryService($this->pdo))->list(self::T1);

        $this->assertSame(
            ['total' => 4, 'ok' => 1, 'danadas' => 1, 'deterioradas' => 1, 'perdidas' => 1],
            $result['summary']
        );
        $this->assertCount(4, $result['data']);
        $this->assertFalse($result['pagination']['has_next']);

        $row = $result['data'][3];
        $this->assertSame('ret-q-1', $row['return_id']);
        $this->assertSame('del-q-1', $row['delivery_id']);
        $this->assertSame(self::S1, $row['shipment_id']);
        $this->assertSame('TR-1', $row['referencia_externa']);
        $this->assertSame('BC-Q1', $row['codigo_barcode']);
        $this->assertSame(ReturnOperation::MOTIVO_FALLO_ENTREGA, $row['motivo']);
        $this->assertSame(ReturnOperation::ESTADO_OK, $row['estado_al_devolver']);
        $this->assertSame('LOC-01', $row['ubicacion_destino']);
    }

    public function testFiltraDevolucionesPorEstadoConTildeEnMinusculas(): void
    {
        $service = new ReturnQueryService($this->pdo);

        // Los estados con ñ se aceptan en minúsculas (mb_strtoupper)
        $danadas = $service->list(self::T1, ['estado' => 'dañado']);
        $this->assertSame(1, $danadas['summary']['total']);
        $this->assertSame('ret-q-2', $danadas['data'][0]['return_id']);

        $perdidas = $service->list(self::T1, ['estado' => ReturnOperation::ESTADO_PERDIDO]);
        $this->assertSame(1, $perdidas['summary']['total']);
        $this->assertSame('ret-q-3', $perdidas['data'][0]['return_id']);
    }

    public function testFiltraDevolucionesPorMotivoEnvioYUnidad(): void
    {
        $service = new ReturnQueryService($this->pdo);

        $porMotivo = $service->list(self::T1, ['motivo' => ReturnOperation::MOTIVO_DANO]);
        $this->assertSame(1, $porMotivo['summary']['total']);
        $this->assertSame('ret-q-2', $porMotivo['data'][0]['return_id']);

        $porEnvio = $service->list(self::T1, ['shipment_id' => self::S1]);
        $this->assertSame(2, $porEnvio['summary']['total']);
        $this->assertSame(
            ['ret-q-2', 'ret-q-1'],
            array_column($porEnvio['data'], 'return_id')
        );

        $porUnidad = $service->list(self::T1, ['unidad_fisica_id' => self::U1]);
        $this->assertSame(2, $porUnidad['summary']['total']);
    }

    public function testFiltraDevolucionesPorRangoDeFechas(): void
    {
        $service = new ReturnQueryService($this->pdo);

        $this->assertSame(3, $service->list(self::T1, ['fecha_desde' => '2026-10-02'])['summary']['total']);
        $this->assertSame(2, $service->list(self::T1, ['fecha_hasta' => '2026-10-02'])['summary']['total']);
    }

    public function testPaginaYLimitaElListadoDeDevoluciones(): void
    {
        $service = new ReturnQueryService($this->pdo);

        $page1 = $service->list(self::T1, [], 1, 3);
        $this->assertCount(3, $page1['data']);
        $this->assertTrue($page1['pagination']['has_next']);

        $page2 = $service->list(self::T1, [], 2, 3);
        $this->assertCount(1, $page2['data']);
        $this->assertFalse($page2['pagination']['has_next']);

        $this->assertSame(100, $service->list(self::T1, [], 1, 500)['pagination']['per_page']);
    }

    public function testFiltrosDesconocidosDeDevolucionDevuelven400(): void
    {
        $service = new ReturnQueryService($this->pdo);

        try {
            $service->list(self::T1, ['estado' => 'ROTO']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        try {
            $service->list(self::T1, ['motivo' => 'SIN_MOTIVO']);
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('motivo', $e->getField());
        }
    }

    public function testLasDevolucionesDeOtroTenantNoAparecen(): void
    {
        $result = (new ReturnQueryService($this->pdo))->list(self::T2);

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame('ret-q-t2', $result['data'][0]['return_id']);
    }
}
