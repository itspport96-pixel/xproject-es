<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-004: INICIAR PICKING (Sprint 7, v1.7.0).
 *
 * Cubre: aislamiento de tenants, unidad no RESERVADO (422), cantidad
 * distinta a la unidad (422), lote duplicado (409), zona de preparación no
 * PICKING/inactiva (422), capacidad (422), unidades repetidas (409),
 * transición RESERVADO → EN_PREPARACION con ledger intacto (R1), replay
 * idempotente (R7) y atomicidad de la tanda (R5).
 */
final class StartPickingUseCaseTest extends InventorySqliteTestCase
{
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
    private function receive(int $cantidad = 3, string $ubicacion = 'LOC-01', string $sku = 'SKU-ALFA'): array
    {
        $result = $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacion,
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiveAndReserve(
        int $cantidad = 3,
        string $ubicacion = 'LOC-01',
        ?string $key = null,
        string $sku = 'SKU-ALFA'
    ): array {
        $unit = $this->receive($cantidad, $ubicacion, $sku);

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            $key,
            'user-9'
        );

        return $unit;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function start(array $overrides = [], ?string $key = 'pk-1', ?string $actor = 'selector-1'): array
    {
        return $this->startPickingUseCase->execute(
            array_merge([
                'id_lote_picking' => 'LOTE-001',
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
                'unidades' => [],
            ], $overrides),
            $key,
            $actor
        );
    }

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testIniciaPickingMueveLaUnidadYRefrescaElLedger(): void
    {
        $unit = $this->receiveAndReserve(3);

        $result = $this->start([
            'id_lote_picking' => 'LOTE-FELIZ',
            'unidades' => [
                ['unidad_fisica_id' => $unit['unidad_fisica_id'], 'cantidad' => 3],
            ],
            'referencia_orden' => 'SO-100',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];
        $this->assertSame('Picking iniciado correctamente.', $data['mensaje']);
        $this->assertSame('LOTE-FELIZ', $data['id_lote_picking']);
        $this->assertSame('SO-100', $data['referencia_orden']);
        $this->assertCount(1, $data['operaciones']);
        $this->assertSame('EN_CURSO', $data['operaciones'][0]['estado']);
        $this->assertSame('selector-1', $data['operaciones'][0]['actor_id_selector']);
        $this->assertSame('EN_PREPARACION', $data['unidades'][0]['estado']);
        $this->assertSame('EN_PREPARACION', $data['eventos'][0]['tipo_evento']);

        // picking_operations: una fila EN_CURSO por unidad (spec 3.12)
        $this->assertSame(1, $this->countRows('picking_operations'));
        $op = $this->row('SELECT * FROM picking_operations');
        $this->assertSame('EN_CURSO', $op['estado']);
        $this->assertSame('LOTE-FELIZ', $op['id_lote_picking']);
        $this->assertSame($this->loc('LOC-01'), $op['ubicacion_origen_id']);
        $this->assertSame($this->loc('LOC-PICK'), $op['ubicacion_preparacion_id']);
        $this->assertSame('selector-1', $op['actor_id_selector']);
        $this->assertNull($op['actor_id_supervisor']);

        // Unidad en la zona de preparación, estado EN_PREPARACION
        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_PREPARACION', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-PICK'), $unitRow['ubicacion_id']);

        // R1: cantidades intactas, sólo cambia estado_actual
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_PREPARACION', $ledger['estado_actual']);
        $this->assertSame(3.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledger['cantidad_disponible']);
        $this->assertSame(3.0, (float) $ledger['cantidad_fisica']);

        // Evento con hash de integridad
        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'EN_PREPARACION'");
        $this->assertNotNull($event);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $event['hash_integridad']);
        $this->assertSame('RESERVADO', $event['estado_anterior']);
        $this->assertSame('EN_PREPARACION', $event['estado_posterior']);

        // Capacidad: la zona de preparación gana 1 slot por unidad (ceil)
        $preparacion = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(3, (int) $preparacion['capacidad_usada']);
    }

    public function testLaCapacidadDelOrigenSeLibera(): void
    {
        $this->seedLocation(self::T1, 'LOC-SRC', capacidad: 10, capacidadUsada: 3);
        $unit = $this->receiveAndReserve(3, 'LOC-SRC');

        $this->start([
            'id_lote_picking' => 'LOTE-SRC',
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], 'pk-src');

        $origen = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-SRC')]);
        $this->assertSame(3, (int) $origen['capacidad_usada']);

        $preparacion = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(3, (int) $preparacion['capacidad_usada']);
    }

    // ====================================================================
    // AISLAMIENTO Y VALIDACIONES (spec CU-004)
    // ====================================================================

    public function testUnidadDeOtroTenantDevuelve404(): void
    {
        $this->useTenant(self::T2);
        $unitT2 = $this->receiveAndReserve(3, 'LOC-T2', null, 'SKU-T2');
        $this->useTenant(self::T1);

        try {
            $this->start([
                'unidades' => [['unidad_fisica_id' => $unitT2['unidad_fisica_id']]],
            ]);
            $this->fail('Debía devolver 404 para una unidad de otro tenant');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
    }

    public function testUnidadNoReservadaDevuelve422SinEfectos(): void
    {
        $unit = $this->receive(3); // DISPONIBLE

        try {
            $this->start(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]]);
            $this->fail('Debía rechazar una unidad que no está RESERVADO');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('RESERVADO', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
        $this->assertSame(0, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'EN_PREPARACION'"));
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);
    }

    public function testTransicionInvalidaNoProduceEnPreparacion(): void
    {
        $unit = $this->receive(3);
        // DISPONIBLE → EN_PREPARACION no está en la matriz §4: se rechaza
        // antes de llegar a la transición porque exige RESERVADO.
        try {
            $this->start(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]]);
            $this->fail('Debía rechazar la transición');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'EN_PREPARACION'"));
    }

    public function testCantidadDistintaALaUnidadDevuelve422(): void
    {
        $unit = $this->receiveAndReserve(3);

        try {
            $this->start(['unidades' => [
                ['unidad_fisica_id' => $unit['unidad_fisica_id'], 'cantidad' => 2],
            ]]);
            $this->fail('Debía rechazar una cantidad que divide la unidad');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('cantidad', $e->getField());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
    }

    public function testLoteDuplicadoDevuelve409(): void
    {
        $unitA = $this->receiveAndReserve(3, 'LOC-01');
        $this->start([
            'id_lote_picking' => 'LOTE-DUP',
            'unidades' => [['unidad_fisica_id' => $unitA['unidad_fisica_id']]],
        ], 'pk-a');

        $unitB = $this->receiveAndReserve(2, 'LOC-01');
        try {
            $this->start([
                'id_lote_picking' => 'LOTE-DUP',
                'unidades' => [['unidad_fisica_id' => $unitB['unidad_fisica_id']]],
            ], 'pk-b');
            $this->fail('Debía rechazar un lote repetido en el tenant');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('id_lote_picking', $e->getField());
        }

        $this->assertSame(1, $this->countRows('picking_operations'));
        $unitBRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unitB['unidad_fisica_id']]);
        $this->assertSame('RESERVADO', $unitBRow['estado']);
    }

    public function testZonaDePreparacionNoPickingDevuelve422(): void
    {
        $unit = $this->receiveAndReserve(3);

        try {
            $this->start([
                'ubicacion_preparacion_id' => $this->loc('LOC-01'), // ALMACENAMIENTO
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]);
            $this->fail('Debía exigir una zona de tipo PICKING');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_preparacion_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
    }

    public function testZonaDePreparacionInactivaDevuelve422(): void
    {
        $this->seedLocation(self::T1, 'LOC-PICK-OFF', capacidad: 5, tipo: 'PICKING', activa: false);
        $unit = $this->receiveAndReserve(3);

        try {
            $this->start([
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK-OFF'),
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]);
            $this->fail('Debía rechazar una zona inactiva');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_preparacion_id', $e->getField());
        }
    }

    public function testCapacidadExcedidaEnPreparacionDevuelve422(): void
    {
        $unit = $this->receiveAndReserve(3);

        try {
            $this->start([
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK-FULL'), // capacidad 1 llena
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]);
            $this->fail('Debía rechazar por capacidad excedida');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('RESERVADO', $unitRow['estado']);
    }

    public function testUnidadRepetidaEnLaRequestDevuelve409(): void
    {
        $unit = $this->receiveAndReserve(3);

        try {
            $this->start(['unidades' => [
                ['unidad_fisica_id' => $unit['unidad_fisica_id']],
                ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            ]]);
            $this->fail('Debía rechazar unidades repetidas');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('unidades', $e->getField());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayIdempotenteDevuelveLaMismaRespuestaSinEfectos(): void
    {
        $unit = $this->receiveAndReserve(3);
        $payload = [
            'id_lote_picking' => 'LOTE-REPLAY',
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ];

        $first = $this->start($payload, 'pk-replay');
        $second = $this->start($payload, 'pk-replay');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['operaciones'][0]['picking_id'],
            $second['data']['operaciones'][0]['picking_id']
        );
        $this->assertSame(1, $this->countRows('picking_operations'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));

        // La capacidad sólo se aplicó UNA vez (3 slots, no 6)
        $preparacion = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(3, (int) $preparacion['capacidad_usada']);
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $unit = $this->receiveAndReserve(3);
        $this->start(['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]], 'pk-clave');

        try {
            $this->start([
                'id_lote_picking' => 'LOTE-OTRO',
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ], 'pk-clave');
            $this->fail('Debía devolver 409 por payload distinto');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('picking_operations'));
    }

    public function testSinIdempotencyKeyNoSeCreaClave(): void
    {
        $unit = $this->receiveAndReserve(3);

        $result = $this->start(
            ['unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]]],
            null
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(1, $this->countRows('picking_operations'));
    }

    /**
     * v1.7.1 (A2): iniciar picking cumple la reserva de la unidad.
     *
     * Con la reserva en estado final, el comando E7 (expiración) no la vuelve
     * a tocar ni intenta la transición prohibida EN_PREPARACION → DISPONIBLE.
     */
    public function testIniciarPickingCumpleLaReservaYExpiracionNoLaVuelveATocar(): void
    {
        $unit = $this->receiveAndReserve(3);

        $previa = $this->row('SELECT * FROM reservations WHERE unidad_fisica_id = ?', [
            $unit['unidad_fisica_id'],
        ]);
        $this->assertSame('ACTIVA', $previa['estado']);

        $result = $this->start([
            'id_lote_picking' => 'LOTE-A2',
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ]);
        $this->assertSame(201, $result['status']);

        $reserva = $this->row('SELECT estado FROM reservations WHERE id = ?', [$previa['id']]);
        $this->assertSame('COMPLETADA', $reserva['estado']);

        // Reserva vencida en el tiempo pero ya consumida: E7 no la procesa
        $this->pdo
            ->prepare('UPDATE reservations SET fecha_expiracion = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', $previa['id']]);

        $expiry = $this->expirationService->expire(500, new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->assertSame(0, $expiry['expiradas']);
        $this->assertSame(0, $expiry['omitidas'], 'No hay nada que omitir: la reserva no está ACTIVA');
        $this->assertSame(
            'COMPLETADA',
            $this->row('SELECT estado FROM reservations WHERE id = ?', [$previa['id']])['estado']
        );
        $this->assertSame(
            'EN_PREPARACION',
            $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']])['estado']
        );
        $this->assertSame(0, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'LIBERADO'"));
    }

    // ====================================================================
    // ATOMICIDAD (R5)
    // ====================================================================

    public function testFalloEnLaSegundaUnidadReverteLaOperacionCompleta(): void
    {
        $unitA = $this->receiveAndReserve(3, 'LOC-01');
        $unitB = $this->receive(2); // queda DISPONIBLE: hará fallar la tanda

        try {
            $this->start([
                'id_lote_picking' => 'LOTE-ATOMIC',
                'unidades' => [
                    ['unidad_fisica_id' => $unitA['unidad_fisica_id']],
                    ['unidad_fisica_id' => $unitB['unidad_fisica_id']],
                ],
            ], 'pk-atomic');
            $this->fail('Debía propagar el fallo de la segunda unidad');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Rollback total: nada de picking, ni eventos, ni claves
        $this->assertSame(0, $this->countRows('picking_operations'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'EN_PREPARACION'"
        ));

        $unitARow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unitA['unidad_fisica_id']]);
        $this->assertSame('RESERVADO', $unitARow['estado']);
        $this->assertSame($this->loc('LOC-01'), $unitARow['ubicacion_id']);

        $ledger = $this->row(
            'SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?',
            [$unitA['unidad_fisica_id']]
        );
        $this->assertSame('RESERVADO', $ledger['estado_actual']);

        $preparacion = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(0, (int) $preparacion['capacidad_usada']);
    }
}
