<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-007: CONFIRMAR ENTREGA (Sprint 8, v1.8.0).
 *
 * Cubre: entrega completa (200), entrega mixta con CU-008 en la MISMA
 * transacción, replay idempotente sin escrituras repetidas (R7), rollback
 * ante una unidad inválida (R5) y las validaciones del prompt: 404/403/409
 * de envío, 422 de unidad fuera del envío / no EN_TRANSITO / cantidad
 * distinta / sin unidades / sin actor / firma que no es URL http(s).
 */
final class ConfirmDeliveryUseCaseTest extends InventorySqliteTestCase
{
    private const SUPERVISOR = 'supervisor-9';
    private const ENTREGADOR = 'repartidor-3';
    private const ORIGEN = 'LOC-01';

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
     * Unidad RESERVADO → EN_PREPARACION → CARGADA (sin autorizar).
     *
     * @param array<string, mixed> $shipmentData datos comunes del envío
     *                                            (p. ej. origen_ubicacion_id)
     *
     * @return array<string, mixed> unidad con la clave shipment_id añadida
     */
    private function loadUnit(
        string $lote,
        int $cantidad = 3,
        ?string $shipmentId = null,
        array $shipmentData = []
    ): array {
        $unit = $this->receive($cantidad);

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SUPERVISOR);

        $this->completePickingUseCase->execute($lote);

        $load = [
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => $shipmentData + [
                'referencia_externa' => 'TR-' . $lote,
                'destinatario_nombre' => 'ACME SA',
                'conductor_nombre' => 'Chofer Uno',
            ],
        ];

        if ($shipmentId !== null) {
            $load['shipment_id'] = $shipmentId;
            $load['shipment'] = $shipmentData;
        }

        $loaded = $this->loadShipmentUseCase->execute($load, null, self::SUPERVISOR);
        $this->assertSame(201, $loaded['status']);
        $unit['shipment_id'] = $loaded['data']['shipment']['shipment_id'];

        return $unit;
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeShipment(string $shipmentId, string $key): array
    {
        $authorized = $this->authorizeShipmentUseCase->execute(
            ['shipment_id' => $shipmentId],
            $key,
            self::SUPERVISOR
        );
        $this->assertSame(200, $authorized['status']);

        return $authorized['data'];
    }

    /**
     * Unidad RESERVADO → EN_PREPARACION → CARGADA → EN_RUTA.
     *
     * @param array<string, mixed> $shipmentData datos comunes del envío
     *
     * @return array<string, mixed> unidad con la clave shipment_id añadida
     */
    private function onRoute(
        string $lote,
        int $cantidad = 3,
        ?string $shipmentId = null,
        array $shipmentData = []
    ): array {
        $unit = $this->loadUnit($lote, $cantidad, $shipmentId, $shipmentData);
        $this->authorizeShipment($unit['shipment_id'], 'az-' . $lote);

        return $unit;
    }

    /**
     * Dos unidades en el MISMO envío ya EN_RUTA (la carga exige que el envío
     * siga en PREPARACION, así que ambas se cargan antes de autorizar).
     *
     * @param array<string, mixed> $shipmentData
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function twoUnitsOnRoute(string $loteA, string $loteB, array $shipmentData = []): array
    {
        $unitA = $this->loadUnit($loteA, 3, null, $shipmentData);
        $unitB = $this->loadUnit($loteB, 3, $unitA['shipment_id'], $shipmentData);
        $this->authorizeShipment($unitA['shipment_id'], 'az-' . $loteA);

        return [$unitA, $unitB];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function confirm(array $input, ?string $key = 'cf-1'): array
    {
        return $this->confirmDeliveryUseCase->execute($input, $key, self::ENTREGADOR);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testConfirmaLaEntregaCompletaYFuerzaElEnvioAEntregado(): void
    {
        $unit = $this->onRoute('LOTE-C1');

        $result = $this->confirm([
            'shipment_id' => $unit['shipment_id'],
            'unidades' => [[
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'cantidad_entregada' => 3,
            ]],
            'destinatario_real' => 'Cliente Final',
            'firma_comprobante' => 'https://acme.local/firmas/c1.png',
        ]);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame('Entrega confirmada correctamente.', $data['mensaje']);
        $this->assertSame('ENTREGADO', $data['shipment']['estado']);
        $this->assertSame('ENTREGADA', $data['entregas'][0]['estado']);
        $this->assertSame('ENTREGADO', $data['unidades'][0]['estado']);
        $this->assertSame([], $data['no_entregadas']);
        $this->assertNull($data['incidencia']);
        $this->assertSame('ENTREGADO', $data['eventos'][0]['tipo_evento']);
        $this->assertNotEmpty($data['eventos'][0]['hash_integridad']);

        // Persistencia (spec 3.15)
        $delivery = $this->row('SELECT * FROM deliveries');
        $this->assertSame('ENTREGADA', $delivery['estado']);
        $this->assertSame(self::ENTREGADOR, $delivery['actor_id_entrega']);
        $this->assertSame('Cliente Final', $delivery['destinatario_real']);
        $this->assertSame('https://acme.local/firmas/c1.png', $delivery['firma_comprobante']);
        $this->assertNotNull($delivery['timestamp_entrega']);

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('ENTREGADO', $unitRow['estado']);
        $this->assertNull($unitRow['ubicacion_id'], 'La unidad sale del almacén: sin ubicación');

        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame('ENTREGADA', $item['estado']);

        $shipment = $this->row('SELECT * FROM shipments WHERE id = ?', [$unit['shipment_id']]);
        $this->assertSame('ENTREGADO', $shipment['estado']);
        $this->assertNotNull($shipment['hora_entrega_real']);

        // Ledger (R1, v1.8.3): al entregar sólo sale el tránsito y, como la
        // unidad queda en estado ENTREGADO, `cantidad_disponible` pasa a 0
        // (sólo el estado DISPONIBLE suma disponible).
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame(0.0, (float) $ledger['cantidad_disponible']);
        $this->assertSame('ENTREGADO', $ledger['estado_actual']);

        // Evento de dominio con hash (auditoría de eventos)
        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'ENTREGADO'");
        $this->assertNotNull($event);
        $this->assertNotEmpty($event['hash_integridad']);
        $this->assertSame('ENTREGADO', $event['estado_posterior']);

        // TRANSITO es virtual: su contador sigue a cero
        $transito = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-TRN')]);
        $this->assertSame(0, (int) $transito['capacidad_usada']);
    }

    public function testReplayIdempotenteNoRepiteEscrituras(): void
    {
        $unit = $this->onRoute('LOTE-C2');
        $input = [
            'shipment_id' => $unit['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ];

        $first = $this->confirm($input, 'cf-replay');
        $this->assertSame(200, $first['status']);
        $this->assertArrayNotHasKey('_replay', $first);

        $eventosTrasPrimera = $this->countRows('stock_events');
        $incidenciasTrasPrimera = $this->countRows('incidents');

        $second = $this->confirm($input, 'cf-replay');

        $this->assertTrue($second['_replay'] ?? false, 'La segunda llamada debe ser replay');
        $this->assertSame($first['data'], $second['data']);

        $this->assertSame(1, $this->countRows('deliveries'));
        $this->assertSame($eventosTrasPrimera, $this->countRows('stock_events'), 'Replay sin eventos repetidos');
        $this->assertSame($incidenciasTrasPrimera, $this->countRows('incidents'));
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM idempotency_keys WHERE idempotency_key = 'cf-replay'"
            ),
            'La clave idempotente se crea una sola vez'
        );
        $this->assertSame(1, $this->countRows('shipments'));
    }

    public function testEntregaMixtaDejaElEnvioEntregadoYProcesaLasFaltantesConCu008(): void
    {
        [$first, $second] = $this->twoUnitsOnRoute(
            'LOTE-C3',
            'LOTE-C4',
            ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]
        );

        $result = $this->confirm([
            'shipment_id' => $first['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $first['unidad_fisica_id']]],
            'motivo_fallo' => 'NO_DISPONIBLE',
            'observaciones' => 'No había nadie en el destino.',
        ]);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];

        // Regla de decisión: mixto → ENTREGADO
        $this->assertSame('ENTREGADO', $data['shipment']['estado']);
        $this->assertCount(1, $data['no_entregadas']);
        $this->assertNotNull($data['incidencia']);
        $this->assertSame('ENTREGA_RECHAZADA', $data['incidencia']['tipo']);

        $this->assertSame('DEVUELTO', $data['unidades'][1]['estado']);

        // Persistencia de las faltantes (CU-008 en la MISMA transacción)
        $this->assertSame(2, $this->countRows('deliveries'));
        $faltante = $this->row(
            'SELECT * FROM deliveries WHERE unidad_fisica_id = ?',
            [$second['unidad_fisica_id']]
        );
        $this->assertSame('RECHAZADA', $faltante['estado']);

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$second['unidad_fisica_id']]);
        $this->assertSame('DEVUELTO', $unitRow['estado']);
        $this->assertSame($this->loc(self::ORIGEN), $unitRow['ubicacion_id']);

        $itemRow = $this->row(
            'SELECT * FROM shipment_items WHERE unidad_fisica_id = ?',
            [$second['unidad_fisica_id']]
        );
        $this->assertSame('DEVUELTA', $itemRow['estado']);

        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM incidents WHERE tipo = 'ENTREGA_RECHAZADA'"));

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$second['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame('DEVUELTO', $ledger['estado_actual']);
    }

    // ====================================================================
    // VALIDACIONES (spec CU-007)
    // ====================================================================

    public function testEnvioInexistenteDevuelve404(): void
    {
        try {
            $this->confirm([
                'shipment_id' => 'ship-que-no-existe',
                'unidades' => [['unidad_fisica_id' => 'unidad-x']],
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testEnvioDeOtroTenantNoEsVisibleYDevuelve404(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo
            ->prepare(
                'INSERT INTO shipments (id, tenant_id, estado, destinatario_nombre, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute(['ship-t2', self::T2, 'EN_RUTA', 'Otro cliente', $now, $now]);

        try {
            $this->confirm([
                'shipment_id' => 'ship-t2',
                'unidades' => [['unidad_fisica_id' => 'unidad-x']],
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            // El repositorio ya filtra por tenant: el envío de otro tenant no
            // existe para el cliente (404, no 403) — misma regla que el resto
            // de lecturas del módulo.
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testEnvioQueNoEstaEnRutaDevuelve409(): void
    {
        $unit = $this->onRoute('LOTE-C5');

        // Primer confirm OK y segundo intento sobre el envío ya ENTREGADO
        $this->confirm([
            'shipment_id' => $unit['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ]);

        try {
            $this->confirm(
                [
                    'shipment_id' => $unit['shipment_id'],
                    'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
                ],
                'cf-segundo'
            );
            $this->fail('Debía devolver 409');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('deliveries'));
    }

    public function testUnidadQueNoPerteneceAlEnvioDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-C6');
        $otra = $this->receive();

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [['unidad_fisica_id' => $otra['unidad_fisica_id']]],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testUnidadQueNoEstaEnTransitoDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-C7');

        $this->pdo
            ->prepare("UPDATE stock_units SET estado = 'DISPONIBLE' WHERE id = ?")
            ->execute([$unit['unidad_fisica_id']]);

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testCantidadEntregadaDistintaDeLaCargadaDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-C8');

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [[
                    'unidad_fisica_id' => $unit['unidad_fisica_id'],
                    'cantidad_entregada' => 2,
                ]],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testSinUnidadesDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-C9');

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [],
            ]);
            $this->fail('Debía exigir unidades');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testSinActorDevuelve400(): void
    {
        $unit = $this->onRoute('LOTE-C10');

        try {
            $this->confirmDeliveryUseCase->execute(
                [
                    'shipment_id' => $unit['shipment_id'],
                    'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
                ],
                'cf-sin-actor',
                '  '
            );
            $this->fail('Debía exigir actor_id_entrega');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testFirmaComprobanteQueNoEsUrlHttpDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-C11');

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [[
                    'unidad_fisica_id' => $unit['unidad_fisica_id'],
                    'firma_comprobante' => 'no-es-una-url',
                ]],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testUnaUnidadInvalidaAbortaTodaLaTransaccion(): void
    {
        [$first, $second] = $this->twoUnitsOnRoute(
            'LOTE-C12',
            'LOTE-C13',
            ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]
        );

        try {
            $this->confirm([
                'shipment_id' => $first['shipment_id'],
                'unidades' => [
                    ['unidad_fisica_id' => $first['unidad_fisica_id']],
                    ['unidad_fisica_id' => 'unidad-inexistente'],
                ],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Nada de lo anterior se persistió (R5)
        $this->assertSame(0, $this->countRows('deliveries'));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM stock_events WHERE tipo_evento = 'ENTREGADO'"));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM incidents WHERE tipo = 'ENTREGA_RECHAZADA'"));

        $shipment = $this->row('SELECT estado FROM shipments WHERE id = ?', [$first['shipment_id']]);
        $this->assertSame('EN_RUTA', $shipment['estado']);

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$first['unidad_fisica_id']]);
        $this->assertSame('EN_TRANSITO', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-TRN'), $unitRow['ubicacion_id']);

        // La entrega mixta sigue siendo posible después del error
        $result = $this->confirm([
            'shipment_id' => $first['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $first['unidad_fisica_id']]],
        ]);
        $this->assertSame(200, $result['status']);
        $this->assertSame('ENTREGADO', $result['data']['shipment']['estado']);
        $this->assertSame('DEVUELTO', $result['data']['unidades'][1]['estado']);
        $this->assertSame(2, $this->countRows('deliveries'), 'Una ENTREGADA y una RECHAZADA (CU-008)');
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM deliveries WHERE estado = 'ENTREGADA'")
        );
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM deliveries WHERE estado = 'RECHAZADA'")
        );
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM incidents WHERE tipo = 'ENTREGA_RECHAZADA'")
        );
        $this->assertNotNull($second);
    }

    public function testUnidadDuplicadaEnLaMismaPeticionDevuelve409(): void
    {
        $unit = $this->onRoute('LOTE-C14');

        try {
            $this->confirm([
                'shipment_id' => $unit['shipment_id'],
                'unidades' => [
                    ['unidad_fisica_id' => $unit['unidad_fisica_id']],
                    ['unidad_fisica_id' => $unit['unidad_fisica_id']],
                ],
            ]);
            $this->fail('Debía devolver 409');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
