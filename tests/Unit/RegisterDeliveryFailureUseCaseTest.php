<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-008: REGISTRAR FALLO DE ENTREGA (Sprint 8, v1.8.0).
 *
 * Cubre: fallo total (envío → FALLO_ENTREGA), fallo parcial (envío sigue
 * EN_RUTA), destino por defecto (origen del envío) y destino explícito con
 * capacidad validada, una sola incidencia ENTREGA_RECHAZADA por operación,
 * replay idempotente (R7) y validaciones: 404/409 de envío, 400 de motivo,
 * 422 sin origen/destino y 422 de unidad que no está en el envío.
 */
final class RegisterDeliveryFailureUseCaseTest extends InventorySqliteTestCase
{
    private const SUPERVISOR = 'supervisor-9';
    private const ACTOR = 'repartidor-3';
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
     * @param array<string, mixed> $shipmentData
     *
     * @return array<string, mixed>
     */
    private function loadUnit(
        string $lote,
        ?string $shipmentId = null,
        array $shipmentData = []
    ): array {
        $unit = $this->receive();

        $this->reserveUseCase->execute(['unidad_fisica_id' => $unit['unidad_fisica_id']], null, 'user-9');
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
     * @param array<string, mixed> $shipmentData
     *
     * @return array<string, mixed>
     */
    private function onRoute(string $lote, array $shipmentData = []): array
    {
        $unit = $this->loadUnit($lote, null, $shipmentData);

        $authorized = $this->authorizeShipmentUseCase->execute(
            ['shipment_id' => $unit['shipment_id']],
            'az-' . $lote,
            self::SUPERVISOR
        );
        $this->assertSame(200, $authorized['status']);

        return $unit;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function failDelivery(array $input, ?string $key = 'fl-1'): array
    {
        return $this->failureUseCase->execute($input, $key, self::ACTOR);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testRegistraElFalloTotalYDevuelveLasUnidadesAlOrigen(): void
    {
        $unit = $this->onRoute('LOTE-F1', ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]);

        $result = $this->failDelivery([
            'shipment_id' => $unit['shipment_id'],
            'motivo' => 'NO_DISPONIBLE',
            'observaciones' => 'No había nadie en el destino.',
        ]);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame('Fallo de entrega registrado correctamente.', $data['mensaje']);
        $this->assertSame('FALLO_ENTREGA', $data['shipment']['estado']);
        $this->assertSame('NO_DISPONIBLE', $data['motivo']);
        $this->assertSame($this->loc(self::ORIGEN), $data['ubicacion_destino']['ubicacion_id']);
        $this->assertSame('RECHAZADA', $data['entregas'][0]['estado']);
        $this->assertSame('DEVUELTO', $data['unidades'][0]['estado']);
        $this->assertSame('ENTREGA_RECHAZADA', $data['incidencia']['tipo']);
        $this->assertSame('DEVUELTO', $data['eventos'][0]['tipo_evento']);
        $this->assertNotEmpty($data['eventos'][0]['hash_integridad']);

        // Persistencia (spec 3.15/3.17)
        $this->assertSame(1, $this->countRows('deliveries'));
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM incidents WHERE tipo = 'ENTREGA_RECHAZADA'")
        );
        $this->assertStringContainsString(
            'NO_DISPONIBLE',
            (string) $this->scalar('SELECT descripcion FROM incidents')
        );

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DEVUELTO', $unitRow['estado']);
        $this->assertSame($this->loc(self::ORIGEN), $unitRow['ubicacion_id']);

        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame('DEVUELTA', $item['estado']);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame('DEVUELTO', $ledger['estado_actual']);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'DEVUELTO'");
        $this->assertNotEmpty($event['hash_integridad']);
        $this->assertStringContainsString('NO_DISPONIBLE', (string) $event['datos_operacion']);
    }

    public function testDestinoExplicitoAcumulaCapacidad(): void
    {
        $unit = $this->onRoute('LOTE-F2');
        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $antes = (int) $zona['capacidad_usada'];

        $result = $this->failDelivery([
            'shipment_id' => $unit['shipment_id'],
            'motivo' => 'ACCESO_DENEGADO',
            'ubicacion_destino_id' => $this->loc('LOC-PICK'),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame($this->loc('LOC-PICK'), $result['data']['ubicacion_destino']['ubicacion_id']);

        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        // Los slots se calculan con ceil(cantidad) = 3 (misma regla que CU-007)
        $this->assertSame($antes + 3, (int) $zona['capacidad_usada']);

        $unitRow = $this->row('SELECT ubicacion_id FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($this->loc('LOC-PICK'), $unitRow['ubicacion_id']);
    }

    public function testFalloDeSoloAlgunasUnidadesDejaElEnvioEnRuta(): void
    {
        $unitA = $this->loadUnit('LOTE-F3', null, ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]);
        $unitB = $this->loadUnit('LOTE-F4', $unitA['shipment_id'], ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]);

        $this->authorizeShipmentUseCase->execute(
            ['shipment_id' => $unitA['shipment_id']],
            'az-LOTE-F3',
            self::SUPERVISOR
        );

        $result = $this->failDelivery([
            'shipment_id' => $unitA['shipment_id'],
            'motivo' => 'RECHAZO',
            'unidades' => [['unidad_fisica_id' => $unitA['unidad_fisica_id']]],
        ]);

        $this->assertSame(200, $result['status']);
        // Queda una unidad EN_TRANSITO: el envío NO pasa a FALLO_ENTREGA
        $this->assertSame('EN_RUTA', $result['data']['shipment']['estado']);

        $this->assertSame(1, $this->countRows('deliveries'));

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unitB['unidad_fisica_id']]);
        $this->assertSame('EN_TRANSITO', $unitRow['estado']);

        // El fallo total de la restante sí cierra el envío
        $second = $this->failDelivery(
            [
                'shipment_id' => $unitA['shipment_id'],
                'motivo' => 'RECHAZO',
            ],
            'fl-segundo'
        );
        $this->assertSame('FALLO_ENTREGA', $second['data']['shipment']['estado']);
        $this->assertSame(2, $this->countRows('deliveries'));
        $this->assertSame(
            2,
            (int) $this->scalar("SELECT COUNT(*) FROM incidents WHERE tipo = 'ENTREGA_RECHAZADA'"),
            'Una incidencia por operación (spec 3.17)'
        );
    }

    // ====================================================================
    // VALIDACIONES (spec CU-008)
    // ====================================================================

    public function testEnvioInexistenteDevuelve404(): void
    {
        try {
            $this->failDelivery(['shipment_id' => 'no-existe', 'motivo' => 'OTRO']);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testEnvioQueNoEstaEnRutaDevuelve409(): void
    {
        $unit = $this->onRoute('LOTE-F5');
        $this->failDelivery([
            'shipment_id' => $unit['shipment_id'],
            'motivo' => 'OTRO',
            'ubicacion_destino_id' => $this->loc(self::ORIGEN),
        ]);

        try {
            $this->failDelivery(
                ['shipment_id' => $unit['shipment_id'], 'motivo' => 'OTRO'],
                'fl-segundo'
            );
            $this->fail('Debía devolver 409');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function testSinDestinoNiOrigenDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-F6');

        // v1.8.3: la carga deriva `origen_ubicacion_id` de las unidades, así
        // que se anula para cubrir un envío sin origen (no determinable).
        $this->pdo
            ->prepare('UPDATE shipments SET origen_ubicacion_id = NULL WHERE id = ?')
            ->execute([$unit['shipment_id']]);

        try {
            $this->failDelivery(['shipment_id' => $unit['shipment_id'], 'motivo' => 'OTRO']);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('ubicacion_destino_id', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
        $this->assertSame(0, $this->countRows('incidents'));

        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_TRANSITO', $unitRow['estado'], 'La transacción se revoca entera');
    }

    public function testMotivoObligatorioDevuelve400(): void
    {
        $unit = $this->onRoute('LOTE-F7');

        try {
            $this->failDelivery(['shipment_id' => $unit['shipment_id']]);
            $this->fail('Debía exigir el motivo');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('motivo', $e->getField());
        }
    }

    public function testMotivoDesconocidoDevuelve400(): void
    {
        $unit = $this->onRoute('LOTE-F8');

        try {
            $this->failDelivery(['shipment_id' => $unit['shipment_id'], 'motivo' => 'CUALQUIERA']);
            $this->fail('Debía rechazar el motivo');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testUnidadQueNoEstaEnElEnvioDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-F9');
        $otra = $this->receive();

        try {
            $this->failDelivery([
                'shipment_id' => $unit['shipment_id'],
                'motivo' => 'OTRO',
                'unidades' => [['unidad_fisica_id' => $otra['unidad_fisica_id']]],
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testSinActorDevuelve400(): void
    {
        $unit = $this->onRoute('LOTE-F10');

        try {
            $this->failureUseCase->execute(
                ['shipment_id' => $unit['shipment_id'], 'motivo' => 'OTRO'],
                'fl-sin-actor',
                ' '
            );
            $this->fail('Debía exigir actor_id_reporta');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testReplayIdempotenteNoRepiteEscrituras(): void
    {
        $unit = $this->onRoute('LOTE-F11', ['origen_ubicacion_id' => $this->loc(self::ORIGEN)]);
        $input = ['shipment_id' => $unit['shipment_id'], 'motivo' => 'RECHAZO'];

        $first = $this->failDelivery($input, 'fl-replay');
        $eventos = $this->countRows('stock_events');
        $incidencias = $this->countRows('incidents');

        $second = $this->failDelivery($input, 'fl-replay');

        $this->assertTrue($second['_replay'] ?? false);
        $this->assertSame($first['data'], $second['data']);
        $this->assertSame(1, $this->countRows('deliveries'));
        $this->assertSame($eventos, $this->countRows('stock_events'));
        $this->assertSame($incidencias, $this->countRows('incidents'));
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM idempotency_keys WHERE idempotency_key = 'fl-replay'"
            )
        );
    }

    public function testDestinoVirtualDevuelve422(): void
    {
        $unit = $this->onRoute('LOTE-F12');

        try {
            $this->failDelivery([
                'shipment_id' => $unit['shipment_id'],
                'motivo' => 'OTRO',
                'ubicacion_destino_id' => $this->loc('LOC-TRN'),
            ]);
            $this->fail('Debía rechazar una ubicación virtual');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }

    public function testDestinoDeOtroTenantNoEsVisibleYDevuelve404(): void
    {
        $unit = $this->onRoute('LOTE-F13');

        try {
            $this->failDelivery([
                'shipment_id' => $unit['shipment_id'],
                'motivo' => 'OTRO',
                'ubicacion_destino_id' => $this->loc('LOC-T2', self::T2),
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            // El repositorio filtra por tenant: la ubicación de otro tenant
            // no existe para el cliente (404, no 403).
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('deliveries'));
    }
}
