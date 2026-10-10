<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-005: CARGAR unidades en un envío (Sprint 7, v1.7.0).
 *
 * Cubre: envío nuevo/existente, unidad no EN_PREPARACION (422), picking sin
 * completar (422), unidad fuera de zona PICKING (422), cantidad distinta
 * (422), envío inexistente (404) / no PREPARACION (409), capacidad de la
 * zona, reserva cumplida, replay idempotente (R7) y atomicidad (R5).
 */
final class LoadShipmentUseCaseTest extends InventorySqliteTestCase
{
    private const SELECTOR = 'selector-1';

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
    private function receive(int $cantidad = 3): array
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    /**
     * Unidad RESERVADO → EN_PREPARACION con su lote COMPLETADA.
     *
     * @return array<string, mixed>
     */
    private function preparedUnit(
        string $lote,
        int $cantidad = 3,
        bool $completar = true,
        string $zona = 'LOC-PICK'
    ): array {
        $unit = $this->receive($cantidad);

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $this->loc($zona),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SELECTOR);

        if ($completar) {
            $this->completePickingUseCase->execute($lote);
        }

        return $unit;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function load(array $overrides = [], ?string $key = 'ld-1'): array
    {
        return $this->loadShipmentUseCase->execute(
            array_merge([
                'unidades' => [],
                'shipment' => [
                    'referencia_externa' => 'TR-100',
                    'destinatario_nombre' => 'ACME SA',
                    'conductor_nombre' => 'Chofer Uno',
                ],
            ], $overrides),
            $key,
            self::SELECTOR
        );
    }

    /**
     * Carga un lote completo en un envío nuevo y devuelve el shipment_id.
     */
    private function loadLot(array $unidades, ?string $shipmentId = null, ?string $key = 'ld-lot'): string
    {
        $overrides = [
            'unidades' => array_map(
                static fn(array $u): array => ['unidad_fisica_id' => $u['unidad_fisica_id']],
                $unidades
            ),
        ];

        if ($shipmentId !== null) {
            $overrides['shipment_id'] = $shipmentId;
            $overrides['shipment'] = [];
        }

        $result = $this->load($overrides, $key);

        $this->assertSame(201, $result['status']);

        return (string) $result['data']['shipment']['shipment_id'];
    }

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testCargaCreaEnvioItemUnidadLedgerYCumpleLaReserva(): void
    {
        $unit = $this->preparedUnit('LOTE-L1');

        $result = $this->load([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id'], 'cantidad' => 3]],
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];
        $this->assertSame('Unidades cargadas correctamente.', $data['mensaje']);
        $this->assertSame('PREPARACION', $data['shipment']['estado']);
        $this->assertSame('TR-100', $data['shipment']['referencia_externa']);
        $this->assertSame('ACME SA', $data['shipment']['destinatario_nombre']);
        $this->assertSame('CARGADA', $data['items'][0]['estado']);
        $this->assertSame('CARGADO', $data['unidades'][0]['estado']);
        $this->assertSame('CARGADO', $data['eventos'][0]['tipo_evento']);

        // Persistencia (spec 3.13 / 3.14)
        $this->assertSame(1, $this->countRows('shipments'));
        $this->assertSame(1, $this->countRows('shipment_items'));
        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame('CARGADA', $item['estado']);
        $this->assertSame($unit['unidad_fisica_id'], $item['unidad_fisica_id']);

        // Unidad CARGADO, sigue en la zona de picking hasta autorizar
        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-PICK'), $unitRow['ubicacion_id']);

        // Ledger: reservada → en_transito, disponible intacto (R1)
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $ledger['estado_actual']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(3.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame(0.0, (float) $ledger['cantidad_disponible']);

        // Reserva cumplida (E7 ya no intentará devolverla a DISPONIBLE)
        $reserva = $this->row('SELECT * FROM reservations');
        $this->assertSame('COMPLETADA', $reserva['estado']);

        // v1.8.2: la carga NO libera slots: la unidad sigue físicamente en la
        // zona de picking hasta que se autoriza la salida
        $zona = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(3, (int) $zona['capacidad_usada']);
    }

    public function testCargaEnUnShipmentExistenteAcumulaItems(): void
    {
        $unitA = $this->preparedUnit('LOTE-L2A');
        $unitB = $this->preparedUnit('LOTE-L2B', 2);

        $shipmentId = $this->loadLot([$unitA]);
        $this->loadLot([$unitB], $shipmentId, 'ld-lot-2');

        $this->assertSame(1, $this->countRows('shipments'));
        $this->assertSame(2, $this->countRows('shipment_items'));
        $this->assertSame(2, $this->countQuery(
            "SELECT id FROM shipment_items WHERE shipment_id = ? AND estado = 'CARGADA'",
            [$shipmentId]
        ));
    }

    // ====================================================================
    // VALIDACIONES (spec CU-005)
    // ====================================================================

    public function testShipmentInexistenteDevuelve404(): void
    {
        $unit = $this->preparedUnit('LOTE-L3');

        try {
            $this->load([
                'shipment_id' => 'no-existe',
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('shipment_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipment_items'));
    }

    public function testShipmentQueNoEstaEnPreparacionDevuelve409(): void
    {
        $unit = $this->preparedUnit('LOTE-L4');
        $shipmentId = $this->loadLot([$unit]);
        $this->pdo
            ->prepare("UPDATE shipments SET estado = 'EN_RUTA' WHERE id = ?")
            ->execute([$shipmentId]);

        $unitB = $this->preparedUnit('LOTE-L4B', 2);
        try {
            $this->load([
                'shipment_id' => $shipmentId,
                'unidades' => [['unidad_fisica_id' => $unitB['unidad_fisica_id']]],
            ], 'ld-409');
            $this->fail('Debía rechazar un envío que no está en PREPARACION');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $this->assertSame(1, $this->countRows('shipment_items'));
    }

    public function testUnidadNoPreparadaDevuelve422(): void
    {
        $unit = $this->receive(3); // DISPONIBLE

        try {
            $this->load(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]]);
            $this->fail('Debía exigir unidades EN_PREPARACION');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertSame(0, $this->countRows('shipment_items'));
    }

    public function testPickingSinCompletarDevuelve422(): void
    {
        $unit = $this->preparedUnit('LOTE-L5', 3, completar: false);

        try {
            $this->load(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]]);
            $this->fail('Debía exigir el picking COMPLETADA o VERIFICADA');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertSame(0, $this->countRows('shipment_items'));
    }

    public function testUnidadFueraDeZonaPickingDevuelve422(): void
    {
        $unit = $this->preparedUnit('LOTE-L6');
        // Fuera del flujo: alguien devuelve la unidad a ALMACENAMIENTO
        $this->pdo
            ->prepare('UPDATE stock_units SET ubicacion_id = ? WHERE id = ?')
            ->execute([$this->loc('LOC-01'), $unit['unidad_fisica_id']]);

        try {
            $this->load(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]]);
            $this->fail('Debía exigir que la unidad esté en zona PICKING');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
    }

    public function testCantidadDistintaALaUnidadDevuelve422(): void
    {
        $unit = $this->preparedUnit('LOTE-L7');

        try {
            $this->load(['unidades' => [
                ['unidad_fisica_id' => $unit['unidad_fisica_id'], 'cantidad' => 1],
            ]]);
            $this->fail('Debía rechazar una cantidad que divide la unidad');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('cantidad', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipment_items'));
    }

    public function testUnidadRepetidaDevuelve409(): void
    {
        $unit = $this->preparedUnit('LOTE-L8');

        try {
            $this->load(['unidades' => [
                ['unidad_fisica_id' => $unit['unidad_fisica_id']],
                ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            ]]);
            $this->fail('Debía rechazar unidades repetidas');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('unidades', $e->getField());
        }
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayIdempotenteNoDuplicaElEnvio(): void
    {
        $unit = $this->preparedUnit('LOTE-L9');
        $payload = [
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => ['destinatario_nombre' => 'ACME SA'],
        ];

        $first = $this->load($payload, 'ld-replay');
        $second = $this->load($payload, 'ld-replay');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['shipment']['shipment_id'],
            $second['data']['shipment']['shipment_id']
        );
        $this->assertSame(1, $this->countRows('shipments'));
        $this->assertSame(1, $this->countRows('shipment_items'));

        // La unidad sólo se cargó una vez
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $unitRow['estado']);
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $unit = $this->preparedUnit('LOTE-L10');
        $this->load(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]], 'ld-clave');

        $unitB = $this->preparedUnit('LOTE-L10B', 2);
        try {
            $this->load(['unidades' => [['unidad_fisica_id' => $unitB['unidad_fisica_id']]]], 'ld-clave');
            $this->fail('Debía devolver 409 por payload distinto');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('shipment_items'));
    }

    // ====================================================================
    // ATOMICIDAD (R5)
    // ====================================================================

    public function testFalloEnLaSegundaUnidadReverteElEnvio(): void
    {
        $unitA = $this->preparedUnit('LOTE-L11A');
        $unitB = $this->preparedUnit('LOTE-L11B', 2);
        // La segunda unidad deja de estar preparada (fallo simulado)
        $this->pdo
            ->prepare('UPDATE stock_units SET estado = ? WHERE id = ?')
            ->execute(['DISPONIBLE', $unitB['unidad_fisica_id']]);

        try {
            $this->load([
                'unidades' => [
                    ['unidad_fisica_id' => $unitA['unidad_fisica_id']],
                    ['unidad_fisica_id' => $unitB['unidad_fisica_id']],
                ],
            ], 'ld-atomic');
            $this->fail('Debía propagar el fallo de la segunda unidad');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertSame(0, $this->countRows('shipment_items'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'CARGADO'"
        ));

        $unitARow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unitA['unidad_fisica_id']]);
        $this->assertSame('EN_PREPARACION', $unitARow['estado']);

        $ledger = $this->row(
            'SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?',
            [$unitA['unidad_fisica_id']]
        );
        $this->assertSame('EN_PREPARACION', $ledger['estado_actual']);
        $this->assertSame(3.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);

        $reserva = $this->row('SELECT * FROM reservations WHERE unidad_fisica_id = ?', [
            $unitA['unidad_fisica_id'],
        ]);
        // v1.7.1 (A2): el picking la pasó a COMPLETADA al iniciar; el fallo del
        // load revierte el envío, no el estado de la reserva.
        $this->assertSame('COMPLETADA', $reserva['estado']);

        // Las dos unidades preparadas ocupaban 5 slots; el fallo las conserva
        $zona = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(5, (int) $zona['capacidad_usada']);
    }

    // ====================================================================
    // TAREA 0 (v1.8.0): hora_entrega_esperada
    // ====================================================================

    public function testLaHoraEntregaEsperadaDeFormatoDatetimeLocalSeGuardaEnUtc(): void
    {
        $unit = $this->preparedUnit('LOTE-H1');

        $result = $this->load([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => [
                'referencia_externa' => 'TR-H1',
                'destinatario_nombre' => 'ACME SA',
                'hora_entrega_esperada' => '2026-10-05T12:30',
            ],
        ]);

        $this->assertSame(201, $result['status']);

        $row = $this->row('SELECT hora_entrega_esperada FROM shipments WHERE id = ?', [
            $result['data']['shipment']['shipment_id'],
        ]);
        $this->assertSame('2026-10-05 12:30:00', $row['hora_entrega_esperada']);
    }

    public function testLaHoraEntregaEsperadaConOffsetSeConvierteAUtc(): void
    {
        $unit = $this->preparedUnit('LOTE-H2');

        $result = $this->load([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => [
                'referencia_externa' => 'TR-H2',
                'destinatario_nombre' => 'ACME SA',
                'hora_entrega_esperada' => '2026-10-05T14:30:00+02:00',
            ],
        ]);

        $this->assertSame(201, $result['status']);

        $row = $this->row('SELECT hora_entrega_esperada FROM shipments WHERE id = ?', [
            $result['data']['shipment']['shipment_id'],
        ]);
        $this->assertSame('2026-10-05 12:30:00', $row['hora_entrega_esperada']);
    }

    public function testLaHoraEntregaEsperadaInvalidaDevuelve400SinCrearElEnvio(): void
    {
        $unit = $this->preparedUnit('LOTE-H3');

        try {
            $this->load([
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
                'shipment' => [
                    'referencia_externa' => 'TR-H3',
                    'destinatario_nombre' => 'ACME SA',
                    'hora_entrega_esperada' => 'mañana por la tarde',
                ],
            ], 'ld-h3');
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('hora_entrega_esperada', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertSame(0, $this->countRows('shipment_items'));
    }

    public function testLaHoraEntregaEsperadaNoAdmiteDiasDesbordados(): void
    {
        $unit = $this->preparedUnit('LOTE-H4');

        try {
            $this->load([
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
                'shipment' => [
                    'referencia_externa' => 'TR-H4',
                    'destinatario_nombre' => 'ACME SA',
                    'hora_entrega_esperada' => '2026-02-31',
                ],
            ], 'ld-h4');
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('hora_entrega_esperada', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
    }

    public function testLaHoraEntregaEsperadaExcediendo40CaracteresDevuelve400(): void
    {
        $unit = $this->preparedUnit('LOTE-H5');

        try {
            $this->load([
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
                'shipment' => [
                    'referencia_externa' => 'TR-H5',
                    'destinatario_nombre' => 'ACME SA',
                    'hora_entrega_esperada' => str_repeat('9', 41),
                ],
            ], 'ld-h5');
            $this->fail('Debía devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('hora_entrega_esperada', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
    }

    // ====================================================================
    // ORIGEN DEL ENVIO (v1.8.3)
    // ====================================================================

    public function testLaCargaDerivaElOrigenDesdeLaZonaDeLasUnidades(): void
    {
        $unit = $this->preparedUnit('LOTE-O1');

        $result = $this->load([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], 'ld-o1');

        $this->assertSame(201, $result['status']);

        $row = $this->row(
            'SELECT origen_ubicacion_id FROM shipments WHERE id = ?',
            [$result['data']['shipment']['shipment_id']]
        );
        $this->assertSame($this->loc('LOC-PICK'), $row['origen_ubicacion_id']);
    }

    public function testLaCargaConservaElOrigenExplicitoDelCliente(): void
    {
        $unit = $this->preparedUnit('LOTE-O2');

        $result = $this->load([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => [
                'destinatario_nombre' => 'ACME SA',
                'origen_ubicacion_id' => $this->loc('LOC-01'),
            ],
        ], 'ld-o2');

        $this->assertSame(201, $result['status']);

        $row = $this->row(
            'SELECT origen_ubicacion_id FROM shipments WHERE id = ?',
            [$result['data']['shipment']['shipment_id']]
        );
        $this->assertSame($this->loc('LOC-01'), $row['origen_ubicacion_id']);
    }

    public function testUnidadesEnDosZonasSinOrigenExplicitoDevuelve422(): void
    {
        $this->seedLocation(self::T1, 'LOC-PICK2', 10, 0, true, null, 'PICKING');

        $unitA = $this->preparedUnit('LOTE-O3A');
        $unitB = $this->preparedUnit('LOTE-O3B', 3, true, 'LOC-PICK2');

        try {
            $this->load([
                'unidades' => [
                    ['unidad_fisica_id' => $unitA['unidad_fisica_id']],
                    ['unidad_fisica_id' => $unitB['unidad_fisica_id']],
                ],
            ], 'ld-o3');
            $this->fail('Debía devolver 422: el origen no es determinable');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('origen_ubicacion_id', $e->getField());
        }

        // La transacción se revierte: nada de envío, ítems ni unidades cargadas
        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertSame(0, $this->countRows('shipment_items'));

        foreach ([$unitA, $unitB] as $unit) {
            $row = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
            $this->assertSame('EN_PREPARACION', $row['estado']);
        }
    }
}
