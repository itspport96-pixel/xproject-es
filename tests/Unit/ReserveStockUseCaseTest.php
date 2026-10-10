<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Modules\Inventory\Application\Services\ReleaseReservationUseCase;
use App\Modules\Inventory\Application\Services\ReservationExpirationService;
use App\Modules\Inventory\Application\Services\ReserveStockUseCase;
use App\Modules\Inventory\Application\Services\StockUnitSplitService;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\IdempotencyService;
use DateTimeImmutable;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-003: reservas, liberación y expiración (Sprint 6.3).
 *
 * Cubre: reserva + ledger (R1), doble reserva 409, stock insuficiente 422,
 * transición inválida 422, aislamiento de tenants, replay idempotente (R7),
 * liberación (404/409/422), expiración E7 y atomicidad de la tanda (R5).
 */
final class ReserveStockUseCaseTest extends InventorySqliteTestCase
{
    // ====================================================================
    // HELPERS
    // ====================================================================

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
    private function reserve(array $overrides = [], ?string $key = 'rs-key-1', ?string $actor = 'user-9'): array
    {
        return $this->reserveUseCase->execute(
            array_merge(['unidad_fisica_id' => ''], $overrides),
            $key,
            $actor
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $reservaId, array $payload = [], ?string $key = null): array
    {
        return $this->releaseUseCase->execute($reservaId, $payload, $key, 'user-9');
    }

    private function setUnitState(string $unidadId, string $estado): void
    {
        $this->pdo
            ->prepare('UPDATE stock_units SET estado = ? WHERE id = ?')
            ->execute([$estado, $unidadId]);
    }

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }

    // ====================================================================
    // RESERVA
    // ====================================================================

    public function testReservaCreaReservaLedgerEstadoYEvento(): void
    {
        $unit = $this->receive(3);

        $result = $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'referencia_orden' => 'SO-42',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];
        $this->assertSame('Reserva registrada correctamente.', $data['mensaje']);
        $this->assertSame('ACTIVA', $data['reserva']['estado']);
        $this->assertSame(3.0, (float) $data['reserva']['cantidad']);
        $this->assertSame('SO-42', $data['reserva']['referencia_orden']);
        $this->assertSame('RESERVADO', $data['estado']);
        $this->assertSame(3.0, (float) $data['ledger']['cantidad_reservada']);
        $this->assertSame(0.0, (float) $data['ledger']['cantidad_disponible']);
        $this->assertSame('RESERVADO', $data['ledger']['estado_actual']);
        $this->assertSame('RESERVADO', $data['evento']['tipo_evento']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['evento']['hash_integridad']);

        // Persistencia
        $this->assertSame(1, $this->countRows('reservations'));
        $reservaRow = $this->row('SELECT * FROM reservations');
        $this->assertSame('ACTIVA', $reservaRow['estado']);
        $this->assertSame($unit['unidad_fisica_id'], $reservaRow['unidad_fisica_id']);
        $this->assertSame('user-9', $reservaRow['actor_id']);

        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('RESERVADO', $unitRow['estado']);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'RESERVADO'");
        $this->assertNotNull($event);
        // v1.8.2: cantidad_anterior/cantidad_posterior son la CANTIDAD DE LA
        // UNIDAD (no cambia al reservar); reservado/disponible/en tránsito
        // van en datos_operacion (valores del ledger antes y después).
        $this->assertSame(3.0, (float) $event['cantidad_anterior']);
        $this->assertSame(3.0, (float) $event['cantidad_posterior']);
        $this->assertSame('DISPONIBLE', $event['estado_anterior']);
        $this->assertSame('RESERVADO', $event['estado_posterior']);
        $this->assertNotEmpty($event['hash_integridad']);

        $datos = json_decode((string) $event['datos_operacion'], true);
        $this->assertSame(3.0, (float) $datos['cantidad_reservada']);
        $this->assertSame(0.0, (float) $datos['cantidad_reservada_anterior']);
        $this->assertSame(0.0, (float) $datos['cantidad_disponible']);
        $this->assertSame(3.0, (float) $datos['cantidad_disponible_anterior']);
        $this->assertSame(0.0, (float) $datos['cantidad_en_transito']);
        $this->assertSame(0.0, (float) $datos['cantidad_en_transito_anterior']);
        $this->assertArrayHasKey('reserva_id', $datos);

        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testReservaParcialDivideLaUnidadYDejaElRestoDisponible(): void
    {
        $unit = $this->receive(5);
        $capacidadAntes = (int) $this->scalar(
            'SELECT capacidad_usada FROM locations WHERE id = ?',
            [$unit['ubicacion_id']]
        );

        $result = $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'cantidad' => 2,
        ]);

        $this->assertSame(201, $result['status']);

        $division = $result['data']['division'];
        $this->assertNotNull($division, 'La reserva parcial debía dividir la unidad');
        $this->assertSame($unit['unidad_fisica_id'], $division['unidad_madre_id']);
        $this->assertNotSame($division['unidad_madre_id'], $division['unidad_hija_id']);

        // La reserva recae sobre la HIJA (2): reservada 2, disponible 0
        $this->assertSame(2.0, (float) $result['data']['ledger']['cantidad_reservada']);
        $this->assertSame(0.0, (float) $result['data']['ledger']['cantidad_disponible']);
        $this->assertSame(2.0, (float) $result['data']['reserva']['cantidad']);
        $this->assertSame(
            $division['unidad_hija_id'],
            $result['data']['reserva']['unidad_fisica_id']
        );

        // MADRE: 3 unidades, DISPONIBLE, misma ubicación y barcode original
        $madre = $this->row('SELECT * FROM stock_units WHERE id = ?', [$division['unidad_madre_id']]);
        $this->assertSame(3.0, (float) $madre['cantidad']);
        $this->assertSame('DISPONIBLE', $madre['estado']);
        $this->assertSame($unit['codigo_barcode'], $madre['codigo_barcode']);
        $this->assertSame($unit['ubicacion_id'], $madre['ubicacion_id']);

        // HIJA: fila nueva con barcode propio y el resto de la cantidad
        $hija = $this->row('SELECT * FROM stock_units WHERE id = ?', [$division['unidad_hija_id']]);
        $this->assertSame(2.0, (float) $hija['cantidad']);
        $this->assertSame('RESERVADO', $hija['estado']);
        $this->assertSame($madre['producto_id'], $hija['producto_id']);
        $this->assertSame($madre['ubicacion_id'], $hija['ubicacion_id']);
        $this->assertNotSame($madre['codigo_barcode'], $hija['codigo_barcode']);
        $this->assertMatchesRegularExpression('/^SU-/', (string) $hija['codigo_barcode']);
        $this->assertSame(1, $this->countQuery('SELECT id FROM stock_units WHERE codigo_barcode = ?', [
            $hija['codigo_barcode'],
        ]));

        // Ledgers: madre 5→3 (disponible 3), hija 2 (reservada 2, disponible 0)
        $ledgerMadre = $this->row(
            'SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?',
            [$division['unidad_madre_id']]
        );
        $this->assertSame(3.0, (float) $ledgerMadre['cantidad_fisica']);
        $this->assertSame(0.0, (float) $ledgerMadre['cantidad_reservada']);
        $this->assertSame(3.0, (float) $ledgerMadre['cantidad_disponible']);

        $ledgerHija = $this->row(
            'SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?',
            [$division['unidad_hija_id']]
        );
        $this->assertSame(2.0, (float) $ledgerHija['cantidad_fisica']);
        $this->assertSame(2.0, (float) $ledgerHija['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledgerHija['cantidad_disponible']);

        // Un evento DIVIDIDO por cada mitad, con hash de integridad
        $this->assertSame(2, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO'"
        ));
        foreach ([$division['unidad_madre_id'], $division['unidad_hija_id']] as $id) {
            $evento = $this->row(
                "SELECT * FROM stock_events WHERE tipo_evento = 'DIVIDIDO' AND unidad_fisica_id = ?",
                [$id]
            );
            $this->assertNotNull($evento);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $evento['hash_integridad']);
        }

        // La capacidad usada de la ubicación NO cambia al dividir (la
        // presencia física de la unidad es la misma, sólo se parte en dos)
        $loc = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$unit['ubicacion_id']]);
        $this->assertSame($capacidadAntes, (int) $loc['capacidad_usada']);
        $this->assertSame(5, (int) $loc['capacidad_usada']);
    }

    public function testReservaIgualALaUnidadNoDivide(): void
    {
        $unit = $this->receive(5);

        $result = $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'cantidad' => 5,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertNull($result['data']['division']);
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO'"
        ));
        $this->assertSame(
            $unit['unidad_fisica_id'],
            $result['data']['reserva']['unidad_fisica_id']
        );
    }

    public function testTambienAceptaCodigoDeBarras(): void
    {
        $unit = $this->receive(3);

        $result = $this->reserve([
            'codigo_barcode' => $unit['codigo_barcode'],
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame($unit['unidad_fisica_id'], $result['data']['unidad_fisica_id']);
    }

    public function testDobleReservaDevuelve409(): void
    {
        $unit = $this->receive(3);
        $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-1');

        try {
            $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-2');
            $this->fail('Debía rechazar la doble reserva');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(1, $this->countRows('reservations'));
    }

    public function testCantidadMayorQueDisponibleDevuelve422(): void
    {
        $unit = $this->receive(3);

        try {
            $this->reserve([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'cantidad' => 5,
            ], 'rs-insuf');
            $this->fail('Debía fallar por stock insuficiente');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('cantidad', $e->getField());
            $this->assertStringContainsString('3', $e->getMessage());
        }

        // Sin reserva, sin ledger reservado, sin clave (rollback completo)
        $this->assertSame(0, $this->countRows('reservations'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function estadosNoReservables(): array
    {
        return [
            [StockState::RESERVADO],
            [StockState::EN_PREPARACION],
            [StockState::DEVUELTO],
            [StockState::EN_TRANSITO],
            [StockState::PERDIDO],
        ];
    }

    #[DataProvider('estadosNoReservables')]
    public function testEstadosNoReservablesDevuelven422(string $estado): void
    {
        $unit = $this->receive(3);
        $this->setUnitState($unit['unidad_fisica_id'], $estado);

        try {
            $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-estado-' . $estado);
            $this->fail("Debía rechazar la reserva desde {$estado}");
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString($estado, $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    public function testUnidadInexistenteDevuelve404(): void
    {
        try {
            $this->reserve(['unidad_fisica_id' => 'no-existe'], 'rs-404');
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    public function testSinUnidadDevuelve400(): void
    {
        try {
            $this->reserve([], 'rs-400');
            $this->fail('Debía pedir la unidad');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }
    }

    public function testNoReservaUnidadesDeOtroTenant(): void
    {
        $this->useTenant(self::T2);
        $unitT2 = $this->receive(2, 'LOC-T2', 'SKU-T2');
        $this->useTenant(self::T1);

        try {
            $this->reserve(['unidad_fisica_id' => $unitT2['unidad_fisica_id']], 'rs-aisla');
            $this->fail('La unidad de otro tenant no debe ser visible (404)');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    public function testFechaExpiracionPasadaDevuelve400(): void
    {
        $unit = $this->receive(3);

        try {
            $this->reserve([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'fecha_expiracion' => '2020-01-01T00:00:00',
            ], 'rs-exp-pasada');
            $this->fail('Debía rechazar una fecha de expiración pasada');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('fecha_expiracion', $e->getField());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayConLaMismaClaveDevuelveLaMismaReserva(): void
    {
        $unit = $this->receive(3);
        $payload = ['unidad_fisica_id' => $unit['unidad_fisica_id'], 'referencia_orden' => 'SO-1'];

        $first = $this->reserve($payload, 'rs-replay');
        $second = $this->reserve($payload, 'rs-replay');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['reserva']['reserva_id'],
            $second['data']['reserva']['reserva_id']
        );
        $this->assertSame(1, $this->countRows('reservations'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $unit = $this->receive(3);
        $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-clave-1');

        try {
            $this->reserve([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'cantidad' => 1,
            ], 'rs-clave-1');
            $this->fail('Debía devolver 409 por payload distinto');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('reservations'));
    }

    // ====================================================================
    // LIBERACIÓN (cancelación manual)
    // ====================================================================

    public function testLiberarReservaDevuelveElStock(): void
    {
        $unit = $this->receive(3);
        $created = $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-lib');
        $reservaId = (string) $created['data']['reserva']['reserva_id'];

        $result = $this->release($reservaId, ['motivo' => 'Orden cancelada']);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame('Reserva cancelada correctamente.', $data['mensaje']);
        $this->assertSame('CANCELADA', $data['reserva']['estado']);
        $this->assertSame('DISPONIBLE', $data['estado']);
        $this->assertSame(0.0, (float) $data['ledger']['cantidad_reservada']);
        $this->assertSame(3.0, (float) $data['ledger']['cantidad_disponible']);
        $this->assertSame('LIBERADO', $data['evento']['tipo_evento']);

        $reservaRow = $this->row('SELECT * FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('CANCELADA', $reservaRow['estado']);
        $this->assertSame('Orden cancelada', $reservaRow['motivo_cancelacion']);

        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'LIBERADO'");
        $this->assertNotNull($event);
        // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de la unidad
        $this->assertSame(3.0, (float) $event['cantidad_anterior']);
        $this->assertSame(3.0, (float) $event['cantidad_posterior']);

        $datos = json_decode((string) $event['datos_operacion'], true);
        $this->assertSame(0.0, (float) $datos['cantidad_reservada']);
        $this->assertSame(3.0, (float) $datos['cantidad_reservada_anterior']);
        $this->assertSame(3.0, (float) $datos['cantidad_disponible']);
        $this->assertSame(0.0, (float) $datos['cantidad_disponible_anterior']);

        // La unidad vuelve a ser reservable
        $again = $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-lib-2');
        $this->assertSame(201, $again['status']);
    }

    public function testLiberarReservaInexistenteDevuelve404(): void
    {
        try {
            $this->release('no-existe');
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('reserva_id', $e->getField());
        }
    }

    public function testLiberarReservaNoActivaDevuelve409(): void
    {
        $unit = $this->receive(3);
        $created = $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-409');
        $reservaId = (string) $created['data']['reserva']['reserva_id'];

        $this->release($reservaId, [], 'rel-1');

        try {
            $this->release($reservaId, [], 'rel-2');
            $this->fail('Debía rechazar una reserva ya cancelada');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('CANCELADA', $e->getMessage());
        }
    }

    public function testLiberarConTransicionInvalidaDevuelve422SinEscribir(): void
    {
        $unit = $this->receive(3);
        $created = $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-tr');
        $reservaId = (string) $created['data']['reserva']['reserva_id'];

        // EN_PREPARACION no puede volver a DISPONIBLE (matriz §4)
        $this->setUnitState($unit['unidad_fisica_id'], StockState::EN_PREPARACION);
        $eventsBefore = $this->countRows('stock_events');

        try {
            $this->release($reservaId, [], 'rel-invalid');
            $this->fail('Debía rechazar la transición a DISPONIBLE');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        // Rollback: la reserva sigue ACTIVA y no hay evento LIBERADO
        $reservaRow = $this->row('SELECT estado FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('ACTIVA', $reservaRow['estado']);
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(StockState::EN_PREPARACION, $unitRow['estado']);
        $this->assertSame($eventsBefore, $this->countRows('stock_events'));
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(3.0, (float) $ledger['cantidad_reservada']);
    }

    public function testLiberarReplayConClaveDevuelveLaMismaRespuesta(): void
    {
        $unit = $this->receive(3);
        $created = $this->reserve(['unidad_fisica_id' => $unit['unidad_fisica_id']], 'rs-rp');
        $reservaId = (string) $created['data']['reserva']['reserva_id'];

        $first = $this->release($reservaId, ['motivo' => 'x'], 'rel-replay');
        $second = $this->release($reservaId, ['motivo' => 'x'], 'rel-replay');

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(
            $first['data']['reserva']['reserva_id'],
            $second['data']['reserva']['reserva_id']
        );
        $this->assertSame(1, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'LIBERADO'"));
    }

    // ====================================================================
    // EXPIRACIÓN (spec E7)
    // ====================================================================

    /**
     * Crea una reserva ya vencida (fechas forzadas en BD para el test).
     *
     * @return string id de la reserva
     */
    private function createExpiredReservation(string $unidadId, float $cantidad): string
    {
        $result = $this->reserve([
            'unidad_fisica_id' => $unidadId,
            'cantidad' => $cantidad,
            'fecha_expiracion' => '+1 day',
        ], 'rs-exp-' . substr($unidadId, -6));

        $reservaId = (string) $result['data']['reserva']['reserva_id'];

        $this->pdo
            ->prepare('UPDATE reservations SET fecha_reserva = ?, fecha_expiracion = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', '2020-01-02 00:00:00', $reservaId]);

        return $reservaId;
    }

    public function testExpiracionDeReservasVencidasDevuelveElStock(): void
    {
        $unit = $this->receive(4);
        $reservaId = $this->createExpiredReservation($unit['unidad_fisica_id'], 4);

        $result = $this->expirationService->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));

        $this->assertSame(1, $result['expiradas']);
        $this->assertSame($reservaId, $result['detalle'][0]['reserva_id']);
        $this->assertSame('RESERVADO', $result['detalle'][0]['estado_unidad_anterior']);
        $this->assertSame('DISPONIBLE', $result['detalle'][0]['estado_unidad_nuevo']);

        $reservaRow = $this->row('SELECT * FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('CANCELADA', $reservaRow['estado']);
        $this->assertSame(ReservationExpirationService::MOTIVO_EXPIRACION, $reservaRow['motivo_cancelacion']);

        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(4.0, (float) $ledger['cantidad_disponible']);
        $this->assertSame('DISPONIBLE', $ledger['estado_actual']);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'LIBERADO'");
        $this->assertNotNull($event);
        $this->assertStringContainsString('E7', $event['descripcion']);

        // Idempotente: una segunda pasada no encuentra nada
        $again = $this->expirationService->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));
        $this->assertSame(0, $again['expiradas']);
    }

    public function testExpiracionNoTocaReservasVigentes(): void
    {
        $unit = $this->receive(3);
        $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'fecha_expiracion' => '+1 day',
        ], 'rs-vigente');

        $result = $this->expirationService->expire();

        $this->assertSame(0, $result['expiradas']);
        $this->assertSame(1, $this->countQuery("SELECT id FROM reservations WHERE estado = 'ACTIVA'"));
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('RESERVADO', $unitRow['estado']);
    }

    public function testExpiracionNoFuerzaTransicionInvalida(): void
    {
        $unit = $this->receive(3);
        $reservaId = $this->createExpiredReservation($unit['unidad_fisica_id'], 3);

        // La unidad salió de RESERVADO mientras la reserva seguía activa
        $this->setUnitState($unit['unidad_fisica_id'], StockState::EN_PREPARACION);

        $result = $this->expirationService->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));

        // v1.7.1 (A2): la unidad ya no está en RESERVADO → se OMITE y se
        // registra; no se fuerza EN_PREPARACION → DISPONIBLE ni se toca nada.
        $this->assertSame(0, $result['expiradas']);
        $this->assertSame(1, $result['omitidas']);
        $this->assertCount(1, $result['omitidas_detalle']);
        $this->assertSame(
            ReservationExpirationService::RESULTADO_OMITIDA,
            $result['omitidas_detalle'][0]['resultado']
        );
        $this->assertSame($reservaId, $result['omitidas_detalle'][0]['reserva_id']);
        $this->assertSame('EN_PREPARACION', $result['omitidas_detalle'][0]['estado_unidad_anterior']);
        $this->assertNotEmpty($result['omitidas_detalle'][0]['motivo_omision']);

        $reservaRow = $this->row('SELECT estado FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('ACTIVA', $reservaRow['estado']);
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_PREPARACION', $unitRow['estado']);

        // La cantidad reservada queda intacta (sin evento LIBERADO)
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(3.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'LIBERADO'"));

        // Repetir la pasada no cambia nada (sigue omitida, no expirada)
        $again = $this->expirationService->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));
        $this->assertSame(0, $again['expiradas']);
        $this->assertSame(1, $again['omitidas']);
    }

    public function testExpiracionNoProcesaReservasDeOtroTenant(): void
    {
        $this->useTenant(self::T2);
        $unitT2 = $this->receive(2, 'LOC-T2', 'SKU-T2');
        $this->createExpiredReservation($unitT2['unidad_fisica_id'], 2);
        $this->useTenant(self::T1);

        $result = $this->expirationService->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));

        $this->assertSame(0, $result['expiradas']);

        $this->useTenant(self::T2);
        $this->assertSame(1, $this->countQuery("SELECT id FROM reservations WHERE estado = 'ACTIVA'"));
        $this->useTenant(self::T1);
    }

    // ====================================================================
    // ATOMICIDAD (R5)
    // ====================================================================

    public function testReplayDeReservaParcialNoVuelveADividir(): void
    {
        $unit = $this->receive(10);

        $primera = $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'cantidad' => 5,
        ], 'rs-split-replay');

        $segunda = $this->reserve([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'cantidad' => 5,
        ], 'rs-split-replay');

        $this->assertSame($primera['status'], $segunda['status']);
        $this->assertSame(
            $primera['data']['division']['unidad_hija_id'],
            $segunda['data']['division']['unidad_hija_id'],
            'El replay devuelve la misma división (no crea otra hija)'
        );

        $this->assertSame(2, $this->countRows('stock_units'));
        $this->assertSame(2, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO'"
        ));
        $this->assertSame(1, $this->countRows('reservations'));

        $madre = $this->row('SELECT * FROM stock_units WHERE id = ?', [
            $primera['data']['division']['unidad_madre_id'],
        ]);
        $this->assertSame(5.0, (float) $madre['cantidad']);
        $this->assertSame('DISPONIBLE', $madre['estado']);
    }

    public function testReservaParcialAtomicaSiFallaElEventoNoEscribeNada(): void
    {
        $unit = $this->receive(10);

        $useCase = $this->buildReserveWith($this->failingEvents());

        try {
            $useCase->execute(
                ['unidad_fisica_id' => $unit['unidad_fisica_id'], 'cantidad' => 5],
                'rs-split-fail',
                'user-9'
            );
            $this->fail('Debía propagar el fallo del evento');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        // Nada de la división ni de la reserva sobrevive al rollback
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO'"
        ));
        $this->assertSame(0, $this->countRows('reservations'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));

        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(10.0, (float) $unitRow['cantidad']);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(10.0, (float) $ledger['cantidad_fisica']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(10.0, (float) $ledger['cantidad_disponible']);
    }

    public function testFalloAlEscribirElEventoRevientaLaReserva(): void
    {
        $unit = $this->receive(3);

        $useCase = $this->buildReserveWith($this->failingEvents());

        try {
            $useCase->execute(
                ['unidad_fisica_id' => $unit['unidad_fisica_id']],
                'rs-fail',
                'user-9'
            );
            $this->fail('Debía propagar el fallo del evento');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('reservations'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
    }

    public function testFalloEnLaTandaDeExpiracionNoCancelaNada(): void
    {
        $unitA = $this->receive(3);
        $unitB = $this->receive(2);
        $this->createExpiredReservation($unitA['unidad_fisica_id'], 3);
        $this->createExpiredReservation($unitB['unidad_fisica_id'], 2);

        // Falla al escribir el SEGUNDO evento: toda la tanda debe revertirse
        $failingEvents = new class implements StockEventRepositoryInterface {
            private int $calls = 0;

            public function save(StockEvent $event): void
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Fallo simulado al guardar el evento');
                }
            }

            public function findByUnidadFisica(string $unidadFisicaId, int $limit = 100): array
            {
                return [];
            }
        };

        $service = new ReservationExpirationService(
            $this->db,
            $this->stockUnits,
            $this->ledgers,
            $failingEvents,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        try {
            $service->expire(500, new DateTimeImmutable('2026-01-01 00:00:00'));
            $this->fail('Debía propagar el fallo del segundo evento');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        $this->assertSame(2, $this->countQuery("SELECT id FROM reservations WHERE estado = 'ACTIVA'"));
        $this->assertSame(0, $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'LIBERADO'"));

        foreach ([$unitA, $unitB] as $unit) {
            $unidadId = (string) $unit['unidad_fisica_id'];
            $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unidadId]);
            $this->assertSame('RESERVADO', $unitRow['estado']);

            $reservaRow = $this->row(
                "SELECT cantidad FROM reservations WHERE unidad_fisica_id = ? AND estado = 'ACTIVA'",
                [$unidadId]
            );
            $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unidadId]);
            $this->assertNotNull($reservaRow);
            $this->assertSame((float) $reservaRow['cantidad'], (float) $ledger['cantidad_reservada']);
        }
    }

    // ====================================================================
    // HELPERS INTERNOS
    // ====================================================================

    private function failingEvents(): StockEventRepositoryInterface
    {
        return new class implements StockEventRepositoryInterface {
            public function save(StockEvent $event): void
            {
                throw new \RuntimeException('Fallo simulado al guardar el evento');
            }

            public function findByUnidadFisica(string $unidadFisicaId, int $limit = 100): array
            {
                return [];
            }
        };
    }

    private function buildReserveWith(StockEventRepositoryInterface $events): ReserveStockUseCase
    {
        // El servicio de división debe usar MISMO repositorio de eventos que
        // el use case: si falla el evento, la transacción (incluida la
        // división) tiene que revertirse.
        $split = new StockUnitSplitService(
            $this->db,
            $this->stockUnits,
            $this->ledgers,
            $events,
            $this->calculation,
            $this->states,
            $this->barcodeService
        );

        return new ReserveStockUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->ledgers,
            $events,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext,
            $split
        );
    }
}

