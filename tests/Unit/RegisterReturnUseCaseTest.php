<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Entities\ReturnOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-009: REGISTRAR DEVOLUCIÓN (Sprint 8, v1.8.0).
 *
 * Cubre: devolución OK (DISPONIBLE en el destino indicado o por defecto),
 * devolución desde ENTREGADO (transición intermedia + ítem ENTREGADA →
 * DEVUELTA), DAÑADO/DETERIORADO → zona DAÑADOS con incidencia DAÑO,
 * PERDIDO sin ubicación con incidencia PERDIDA, capacidad validada,
 * replay idempotente (R7) y validaciones (400/404/422).
 */
final class RegisterReturnUseCaseTest extends InventorySqliteTestCase
{
    private const SUPERVISOR = 'supervisor-9';
    private const ACTOR = 'almacen-5';
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
     * Unidad → EN_RUTA en un envío con origen LOC-01.
     *
     * @return array<string, mixed>
     */
    private function onRoute(string $lote): array
    {
        $unit = $this->receive();

        $this->reserveUseCase->execute(['unidad_fisica_id' => $unit['unidad_fisica_id']], null, 'user-9');
        $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SUPERVISOR);
        $this->completePickingUseCase->execute($lote);

        $loaded = $this->loadShipmentUseCase->execute([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => [
                'referencia_externa' => 'TR-' . $lote,
                'destinatario_nombre' => 'ACME SA',
                'origen_ubicacion_id' => $this->loc(self::ORIGEN),
            ],
        ], null, self::SUPERVISOR);
        $this->assertSame(201, $loaded['status']);
        $unit['shipment_id'] = $loaded['data']['shipment']['shipment_id'];

        $authorized = $this->authorizeShipmentUseCase->execute(
            ['shipment_id' => $unit['shipment_id']],
            'az-' . $lote,
            self::SUPERVISOR
        );
        $this->assertSame(200, $authorized['status']);

        return $unit;
    }

    /**
     * Unidad DEVUELTO en LOC-01 (vía CU-008).
     *
     * @return array<string, mixed>
     */
    private function failedUnit(string $lote): array
    {
        $unit = $this->onRoute($lote);

        $failed = $this->failureUseCase->execute([
            'shipment_id' => $unit['shipment_id'],
            'motivo' => 'NO_DISPONIBLE',
        ], 'fl-' . $lote, self::ACTOR);
        $this->assertSame(200, $failed['status']);

        return $unit;
    }

    /**
     * Unidad ENTREGADO fuera del almacén (vía CU-007).
     *
     * @return array<string, mixed>
     */
    private function deliveredUnit(string $lote): array
    {
        $unit = $this->onRoute($lote);

        $confirmed = $this->confirmDeliveryUseCase->execute([
            'shipment_id' => $unit['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], 'cf-' . $lote, self::ACTOR);
        $this->assertSame(200, $confirmed['status']);

        return $unit;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function registerReturn(array $input, ?string $key = 'rt-1'): array
    {
        return $this->registerReturnUseCase->execute($input, $key, self::ACTOR);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testDevolucionOkDevuelveLaUnidadADisponible(): void
    {
        $unit = $this->failedUnit('LOTE-R1');
        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $antes = (int) $zona['capacidad_usada'];

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_FALLO_ENTREGA,
            'estado_al_devolver' => ReturnOperation::ESTADO_OK,
            'ubicacion_destino_id' => $this->loc('LOC-PICK'),
            'observaciones' => 'Revisado y correcto.',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];
        $this->assertSame('Devolución registrada correctamente.', $data['mensaje']);
        $this->assertSame(ReturnOperation::ESTADO_OK, $data['devolucion']['estado_al_devolver']);
        $this->assertSame(ReturnOperation::MOTIVO_FALLO_ENTREGA, $data['devolucion']['motivo']);
        $this->assertSame('DISPONIBLE', $data['unidad']['estado']);
        $this->assertSame($this->loc('LOC-PICK'), $data['unidad']['ubicacion_id']);
        $this->assertNull($data['incidencia']);
        $this->assertSame('DISPONIBLE', $data['eventos'][0]['tipo_evento']);
        $this->assertNotEmpty($data['eventos'][0]['hash_integridad']);

        // Persistencia (spec 3.16)
        $this->assertSame(1, $this->countRows('return_operations'));
        $return = $this->row('SELECT * FROM return_operations');
        $this->assertSame(ReturnOperation::ESTADO_OK, $return['estado_al_devolver']);
        $this->assertSame(self::ACTOR, $return['actor_id_recibe_devolucion']);
        $this->assertNotNull($return['delivery_id'], 'Se enlaza con la última entrega de la unidad');

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-PICK'), $unitRow['ubicacion_id']);

        // Capacidad: el destino gana ceil(cantidad) = 3 slots
        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame($antes + 3, (int) $zona['capacidad_usada']);

        // Ledger (R1): cantidades intactas, sólo cambia el estado
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(3.0, (float) $ledger['cantidad_fisica']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame('DISPONIBLE', $ledger['estado_actual']);
    }

    public function testDevolucionOkSinDestinoUsaLaUbicacionActual(): void
    {
        $unit = $this->failedUnit('LOTE-R2');

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
            'estado_al_devolver' => ReturnOperation::ESTADO_OK,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame($this->loc(self::ORIGEN), $result['data']['unidad']['ubicacion_id']);
    }

    public function testDevolucionDesdeEntregadoUsaElOrigenDelUltimoEnvio(): void
    {
        $unit = $this->deliveredUnit('LOTE-R3');

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('ENTREGADO', $unitRow['estado']);
        $this->assertNull($unitRow['ubicacion_id']);

        $eventosAntes = $this->countRows('stock_events');

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_CAMBIO_CLIENTE,
            'estado_al_devolver' => ReturnOperation::ESTADO_OK,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('DISPONIBLE', $result['data']['unidad']['estado']);
        $this->assertSame($this->loc(self::ORIGEN), $result['data']['unidad']['ubicacion_id']);

        // Transición intermedia ENTREGADO → DEVUELTO + final DEVUELTO → DISPONIBLE
        $this->assertSame($eventosAntes + 2, $this->countRows('stock_events'));
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM stock_events WHERE tipo_evento = 'DEVUELTO'")
        );
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM stock_events WHERE tipo_evento = 'DISPONIBLE'")
        );

        // El ítem del envío pasa de ENTREGADA a DEVUELTA (matriz §3.14)
        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame('DEVUELTA', $item['estado']);
    }

    public function testDevolucionDanadoVaALaZonaDanados(): void
    {
        $unit = $this->failedUnit('LOTE-R4');

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_DANO,
            'estado_al_devolver' => ReturnOperation::ESTADO_DANADO,
            'ubicacion_destino_id' => $this->loc(self::ORIGEN),
            'observaciones' => 'Caja abollada.',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];

        // El destino indicado se IGNORA: manda la zona DAÑADOS (decisión)
        $this->assertSame($this->loc('LOC-DAM'), $data['unidad']['ubicacion_id']);
        $this->assertSame('DAÑADO', $data['unidad']['estado']);
        $this->assertSame('DAÑADOS', $data['ubicacion_destino']['tipo']);
        $this->assertNotNull($data['incidencia']);
        $this->assertSame('DAÑO', $data['incidencia']['tipo']);

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DAÑADO', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-DAM'), $unitRow['ubicacion_id']);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'DAÑADO'");
        $this->assertNotEmpty($event['hash_integridad']);
    }

    public function testDevolucionDeterioradoTambienVaADanados(): void
    {
        $unit = $this->failedUnit('LOTE-R5');

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_DANO,
            'estado_al_devolver' => ReturnOperation::ESTADO_DETERIORADO,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('DAÑADO', $result['data']['unidad']['estado']);
        $this->assertSame($this->loc('LOC-DAM'), $result['data']['unidad']['ubicacion_id']);
        $this->assertSame('DAÑO', $result['data']['incidencia']['tipo']);
    }

    public function testDevolucionPerdidoNoTieneUbicacion(): void
    {
        $unit = $this->failedUnit('LOTE-R6');

        $result = $this->registerReturn([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_OTRO,
            'estado_al_devolver' => ReturnOperation::ESTADO_PERDIDO,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('PERDIDO', $result['data']['unidad']['estado']);
        $this->assertNull($result['data']['unidad']['ubicacion_id']);
        $this->assertNull($result['data']['ubicacion_destino']);
        $this->assertSame('PERDIDA', $result['data']['incidencia']['tipo']);
        $this->assertSame('PERDIDO', $result['data']['eventos'][0]['tipo_evento']);

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('PERDIDO', $unitRow['estado']);
        $this->assertNull($unitRow['ubicacion_id']);
    }

    public function testReplayIdempotenteNoRepiteEscrituras(): void
    {
        $unit = $this->failedUnit('LOTE-R7');
        $input = [
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
            'estado_al_devolver' => ReturnOperation::ESTADO_OK,
        ];

        $first = $this->registerReturn($input, 'rt-replay');
        $eventos = $this->countRows('stock_events');

        $second = $this->registerReturn($input, 'rt-replay');

        $this->assertTrue($second['_replay'] ?? false);
        $this->assertSame($first['data'], $second['data']);
        $this->assertSame(1, $this->countRows('return_operations'));
        $this->assertSame($eventos, $this->countRows('stock_events'));
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM idempotency_keys WHERE idempotency_key = 'rt-replay'"
            )
        );
    }

    // ====================================================================
    // VALIDACIONES (spec CU-009)
    // ====================================================================

    public function testUnidadInexistenteDevuelve404(): void
    {
        try {
            $this->registerReturn([
                'unidad_fisica_id' => 'unidad-x',
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testUnidadQueNoAdmiteDevolucionDevuelve422(): void
    {
        $unit = $this->receive();

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('return_operations'));
    }

    public function testSinDestinoUtilizableDevuelve400(): void
    {
        $unit = $this->onRoute('LOTE-R8');

        $this->confirmDeliveryUseCase->execute([
            'shipment_id' => $unit['shipment_id'],
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], 'cf-LOTE-R8', self::ACTOR);

        // La unidad está ENTREGADO (fuera del almacén) y el envío no tiene
        // origen: no hay destino por defecto → 400 requiredField.
        $this->pdo
            ->prepare('UPDATE shipments SET origen_ubicacion_id = NULL WHERE id = ?')
            ->execute([$unit['shipment_id']]);

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
            ]);
            $this->fail('Debía exigir ubicacion_destino_id');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('ubicacion_destino_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('return_operations'));
    }

    public function testZonaDanadosInactivaDevuelve404(): void
    {
        $unit = $this->failedUnit('LOTE-R9');
        $this->pdo->prepare('UPDATE locations SET activa = 0 WHERE id = ?')
            ->execute([$this->loc('LOC-DAM')]);

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_DANO,
                'estado_al_devolver' => ReturnOperation::ESTADO_DANADO,
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('return_operations'));
    }

    public function testDestinoInactivoDevuelve422(): void
    {
        $unit = $this->failedUnit('LOTE-R10');

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
                'ubicacion_destino_id' => $this->loc('LOC-OFF'),
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testDestinoVirtualDevuelve422(): void
    {
        $unit = $this->failedUnit('LOTE-R11');

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
                'ubicacion_destino_id' => $this->loc('LOC-TRN'),
            ]);
            $this->fail('Debía devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testMotivoYEstadoSonObligatorios(): void
    {
        $unit = $this->failedUnit('LOTE-R12');

        try {
            $this->registerReturn(['unidad_fisica_id' => $unit['unidad_fisica_id']]);
            $this->fail('Debía exigir el motivo');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('motivo', $e->getField());
        }

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
            ], 'rt-sin-estado');
            $this->fail('Debía exigir estado_al_devolver');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado_al_devolver', $e->getField());
        }
    }

    public function testEstadoAlDevolverDesconocidoDevuelve400(): void
    {
        $unit = $this->failedUnit('LOTE-R13');

        try {
            $this->registerReturn([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => 'ROTO',
            ]);
            $this->fail('Debía rechazar el estado');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testSinActorDevuelve400(): void
    {
        $unit = $this->failedUnit('LOTE-R14');

        try {
            $this->registerReturnUseCase->execute([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'motivo' => ReturnOperation::MOTIVO_NO_APLICA,
                'estado_al_devolver' => ReturnOperation::ESTADO_OK,
            ], 'rt-sin-actor', null);
            $this->fail('Debía exigir actor_id_recibe_devolucion');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }
}
