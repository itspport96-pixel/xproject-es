<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Modules\Inventory\Application\Services\MoveStockUseCase;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\IdempotencyService;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-002: movimiento interno de inventario (Sprint 6.2).
 *
 * Cubre: camino feliz (unidad + capacidades + fila movements + ledger +
 * evento), capacidad excedida, transición/estado inválido, aislamiento entre
 * tenants, replay idempotente, 409 con payload distinto y atomicidad ante un
 * fallo a mitad de transacción.
 */
final class MoveStockUseCaseTest extends InventorySqliteTestCase
{
    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<string, mixed> datos públicos de la recepción
     */
    private function receive(string $ubicacionCodigo, int $cantidad, string $sku = 'SKU-ALFA'): array
    {
        $result = $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacionCodigo,
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function move(array $overrides = [], ?string $key = 'mv-key-1', ?string $actor = 'user-9'): array
    {
        return $this->moveUseCase->execute(
            array_merge([
                'unidad_fisica_id' => '',
                'ubicacion_destino_codigo' => '',
            ], $overrides),
            $key,
            $actor
        );
    }

    private function locationUsed(string $codigo): int
    {
        return (int) $this->scalar(
            'SELECT capacidad_usada FROM locations WHERE codigo = ? AND tenant_id = ?',
            [$codigo, $this->tenantContext->getTenantId()]
        );
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testMueveUnidadActualizandoUbicacionesLedgerYEvento(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);
        $destinoId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-02']);

        $result = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_id' => $destinoId,
            'motivo' => 'reubicacion',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];
        $this->assertSame('Movimiento registrado correctamente.', $data['mensaje']);
        $this->assertSame($unit['unidad_fisica_id'], $data['unidad_fisica_id']);
        $this->assertSame('DISPONIBLE', $data['estado']);
        $this->assertSame($origenId, $data['ubicacion_origen']['ubicacion_id']);
        $this->assertSame($destinoId, $data['ubicacion_destino']['ubicacion_id']);
        $this->assertSame(3, $data['ubicacion_destino']['capacidad_usada']);
        $this->assertSame('REUBICACION', $data['movimiento']['motivo']);
        $this->assertSame('user-9', $data['movimiento']['actor_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['evento']['hash_integridad']);

        // Fila inmutable movements + hash de integridad verificable
        $this->assertSame(1, $this->countRows('movements'));
        $movement = $this->movements->findById((string) $data['movimiento']['movimiento_id']);
        $this->assertNotNull($movement);
        $this->assertTrue($movement->esIntegro());
        $this->assertSame($origenId, $movement->ubicacionOrigenId());
        $this->assertSame($destinoId, $movement->ubicacionDestinoId());
        $this->assertSame($unit['unidad_fisica_id'], $movement->unidadFisicaId());
        $this->assertSame('user-9', $movement->actorId());

        // Unidad en la nueva ubicación, estado intacto
        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($destinoId, $unitRow['ubicacion_id']);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);

        // Capacidad: origen -3, destino +3
        $this->assertSame(0, $this->locationUsed('LOC-01'));
        $this->assertSame(3, $this->locationUsed('LOC-02'));

        // Ledger (R1): cantidades intactas, se refresca la actualización
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame(3.0, (float) $ledger['cantidad_fisica']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(3.0, (float) $ledger['cantidad_disponible']);
        $this->assertSame('DISPONIBLE', $ledger['estado_actual']);

        // Evento MOVIDO (spec §3.9; antes TRASLADO, renombrado en v1.6.2)
        $event = $this->row(
            "SELECT * FROM stock_events WHERE tipo_evento = 'MOVIDO' ORDER BY created_at DESC"
        );
        $this->assertNotNull($event);
        $this->assertNotContains(
            'TRASLADO',
            \App\Modules\Inventory\Domain\Entities\StockEvent::TIPOS_VALIDOS,
            'El alias legado TRASLADO ya no es un tipo válido'
        );
        $this->assertContains(
            'MOVIDO',
            \App\Modules\Inventory\Domain\Entities\StockEvent::TIPOS_VALIDOS
        );
        $this->assertSame($unit['unidad_fisica_id'], $event['unidad_fisica_id']);
        $this->assertSame('DISPONIBLE', $event['estado_anterior']);
        $this->assertSame('DISPONIBLE', $event['estado_posterior']);
        $this->assertNotEmpty($event['hash_integridad']);

        // Idempotencia registrada
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testTambienAceptaCodigoDeBarras(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $unit = $this->receive('LOC-01', 2);

        $result = $this->move([
            'codigo_barcode' => $unit['codigo_barcode'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame($unit['unidad_fisica_id'], $result['data']['unidad_fisica_id']);
        $this->assertSame('LOC-02', $result['data']['ubicacion_destino']['codigo']);
    }

    // ====================================================================
    // VALIDACIONES
    // ====================================================================

    public function testCapacidadExcedidaNoEscribeNada(): void
    {
        $this->seedLocation(self::T1, 'LOC-CHICA', capacidad: 1, capacidadUsada: 1);
        $unit = $this->receive('LOC-01', 3);
        $destinoId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-CHICA']);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_id' => $destinoId,
            ], 'mv-cap-1');
            $this->fail('Debía fallar por capacidad excedida');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('capacidad', $e->getField());
        }

        // ROLLBACK total: ni movimiento, ni unidad, ni capacidad, ni clave
        $this->assertSame(0, $this->countRows('movements'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(1, $this->locationUsed('LOC-CHICA'));
        $this->assertSame(3, $this->locationUsed('LOC-01'));
        $unitRow = $this->row('SELECT ubicacion_id FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);
        $this->assertSame($origenId, $unitRow['ubicacion_id']);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function estadosNoMovibles(): array
    {
        return [
            [StockState::EN_TRANSITO],
            [StockState::ENTREGADO],
            [StockState::PERDIDO],
        ];
    }

    #[DataProvider('estadosNoMovibles')]
    public function testEstadoQueNoAdmiteMovimientoDevuelve422(string $estado): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $unit = $this->receive('LOC-01', 2);

        $this->pdo
            ->prepare('UPDATE stock_units SET estado = ? WHERE id = ?')
            ->execute([$estado, $unit['unidad_fisica_id']]);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-02',
            ], 'mv-estado-' . $estado);
            $this->fail("Debía rechazar la unidad en estado {$estado}");
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString($estado, $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('movements'));
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);
        $unitRow = $this->row('SELECT ubicacion_id FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($origenId, $unitRow['ubicacion_id']);
    }

    public function testDestinoIgualAlOrigenSeRechaza(): void
    {
        $unit = $this->receive('LOC-01', 2);
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_id' => $origenId,
            ]);
            $this->fail('Debía rechazar origen = destino');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_destino_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testDestinoInactivoSeRechaza(): void
    {
        $unit = $this->receive('LOC-01', 2);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-OFF',
            ]);
            $this->fail('Debía rechazar la ubicación inactiva');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testDestinoVirtualTransitoSeRechaza(): void
    {
        $this->seedLocation(self::T1, 'LOC-VIRTUAL', capacidad: null, id: 'loc-virtual-t1', tipo: 'TRANSITO');
        $unit = $this->receive('LOC-01', 2);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-VIRTUAL',
            ]);
            $this->fail('Debía rechazar una ubicación virtual de destino');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_destino_id', $e->getField());
            $this->assertStringContainsString('TRANSITO', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testOrigenVirtualTransitoSeRechaza(): void
    {
        $this->seedLocation(self::T1, 'LOC-VIRTUAL', capacidad: null, id: 'loc-virtual-t1', tipo: 'TRANSITO');
        $this->seedLocation(self::T1, 'LOC-02');
        $unit = $this->receive('LOC-VIRTUAL', 2);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-02',
            ]);
            $this->fail('Debía rechazar una ubicación virtual de origen');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('ubicacion_origen_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testUnidadInexistenteDevuelve404(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');

        try {
            $this->move([
                'unidad_fisica_id' => 'no-existe',
                'ubicacion_destino_codigo' => 'LOC-02',
            ]);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testSinDestinoDevuelve400(): void
    {
        $unit = $this->receive('LOC-01', 2);

        try {
            $this->move(['unidad_fisica_id' => $unit['unidad_fisica_id']]);
            $this->fail('Debía pedir la ubicación destino');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('ubicacion_destino_id', $e->getField());
        }
    }

    public function testMotivoConCaracteresInvalidosDevuelve400(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $unit = $this->receive('LOC-01', 2);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-02',
                'motivo' => 'RE UBICACION!',
            ]);
            $this->fail('Debía rechazar el motivo');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('motivo', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    // ====================================================================
    // AISLAMIENTO ENTRE TENANTS
    // ====================================================================

    public function testNoMueveUnidadesDeOtroTenant(): void
    {
        $this->useTenant(self::T2);
        $unitT2 = $this->receive('LOC-T2', 2, 'SKU-T2');
        $this->useTenant(self::T1);

        $this->seedLocation(self::T1, 'LOC-02');

        try {
            $this->move([
                'unidad_fisica_id' => $unitT2['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-02',
            ], 'mv-aisla-1');
            $this->fail('La unidad de otro tenant no debe ser visible (404)');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testNoMueveHaciaUbicacionesDeOtroTenant(): void
    {
        $unit = $this->receive('LOC-01', 2);

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_id' => 'loc-loc-t2-' . substr(self::T2, 0, 5),
            ], 'mv-aisla-2');
            $this->fail('La ubicación de otro tenant no debe ser visible (404)');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertStringContainsString('no existe', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('movements'));

        // La unidad sigue en su ubicación original
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);
        $unitRow = $this->row('SELECT ubicacion_id FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($origenId, $unitRow['ubicacion_id']);
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayConLaMismaClaveDevuelveElMismoMovimiento(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $unit = $this->receive('LOC-01', 2);
        $payload = [
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
            'motivo' => 'REUBICACION',
        ];

        $first = $this->move($payload, 'mv-replay-1');
        $second = $this->move($payload, 'mv-replay-1');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['movimiento']['movimiento_id'],
            $second['data']['movimiento']['movimiento_id']
        );
        $this->assertSame(
            $first['data']['evento']['hash_integridad'],
            $second['data']['evento']['hash_integridad']
        );
        $this->assertSame(1, $this->countRows('movements'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
        $this->assertSame(2, $this->locationUsed('LOC-02'));
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $this->seedLocation(self::T1, 'LOC-02');
        $this->seedLocation(self::T1, 'LOC-03');
        $unit = $this->receive('LOC-01', 2);

        $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-clave-unica');

        try {
            $this->move([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-03',
            ], 'mv-clave-unica');
            $this->fail('Debía devolver 409 por payload distinto');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('movements'));
        $destinoId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-02']);
        $unitRow = $this->row('SELECT ubicacion_id FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($destinoId, $unitRow['ubicacion_id']);
    }

    // ====================================================================
    // ATOMICIDAD (R5)
    // ====================================================================

    public function testFalloAlEscribirElEventoRevientaTodaLaTransaccion(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);
        $origenId = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);

        $failingEvents = new class implements StockEventRepositoryInterface {
            public function save(StockEvent $event): void
            {
                throw new \RuntimeException('Fallo simulado al guardar el evento');
            }

            public function findByUnidadFisica(string $unidadFisicaId, int $limit = 100): array
            {
                return [];
            }
        };

        $useCase = new MoveStockUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $failingEvents,
            $this->movements,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        try {
            $useCase->execute([
                'unidad_fisica_id' => $unit['unidad_fisica_id'],
                'ubicacion_destino_codigo' => 'LOC-02',
            ], 'mv-fail-1');
            $this->fail('Debía propagar el fallo del evento');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        // Nada quedó escrito: movimiento, capacidades, unidad y clave revertidos
        $this->assertSame(0, $this->countRows('movements'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(3, $this->locationUsed('LOC-01'));
        $this->assertSame(0, $this->locationUsed('LOC-02'));
        $unitRow = $this->row('SELECT ubicacion_id, estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame($origenId, $unitRow['ubicacion_id']);
        $this->assertSame('DISPONIBLE', $unitRow['estado']);
        $this->assertSame(1, $this->countRows('stock_events'));
    }

    // ====================================================================
    // v1.10.0 (CU-002): WARNINGS AL MOVER UNIDAD RESERVADA
    // ====================================================================

    public function testMoverUnidadReservadaDevuelveWarningConLaOrden(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);

        $this->reserveUseCase->execute([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'referencia_orden' => 'ORD-77',
        ], 'res-warn-1', 'user-9');

        $result = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-warn-1');

        $this->assertSame(201, $result['status']);
        $warnings = $result['data']['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertSame('UNIDAD_RESERVADA', $warnings[0]['codigo']);
        $this->assertStringContainsString('ORD-77', $warnings[0]['mensaje']);

        // El aviso no bloquea: el movimiento se registra y el estado queda intacto
        $this->assertSame('RESERVADO', $result['data']['estado']);
        $this->assertSame(1, $this->countRows('movements'));
        $this->assertSame(
            'RESERVADO',
            $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']])['estado']
        );
    }

    public function testMoverUnidadSinReservaDevuelveWarningsVacios(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);

        $result = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-warn-2');

        $this->assertSame(201, $result['status']);
        $this->assertSame([], $result['data']['warnings']);
    }

    // ====================================================================
    // v1.10.0 (E8): ES_CORRECCION + MOVIMIENTO_REFERENCIA_ID
    // ====================================================================

    public function testMovimientoInversoSeMarcaComoCorreccion(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);

        $ida = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-e8-1');
        $idaId = (string) $ida['data']['movimiento']['movimiento_id'];
        $this->assertFalse($ida['data']['movimiento']['es_correccion']);
        $this->assertNull($ida['data']['movimiento']['movimiento_referencia_id']);

        $vuelta = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-01',
        ], 'mv-e8-2');
        $vueltaId = (string) $vuelta['data']['movimiento']['movimiento_id'];

        $this->assertTrue($vuelta['data']['movimiento']['es_correccion']);
        $this->assertSame($idaId, $vuelta['data']['movimiento']['movimiento_referencia_id']);

        // Fila persistida
        $fila = $this->row('SELECT * FROM movements WHERE id = ?', [$vueltaId]);
        $this->assertSame(1, (int) $fila['es_correccion']);
        $this->assertSame($idaId, $fila['movimiento_referencia_id']);

        // El hash de integridad sigue verificable (columnas fuera del hash)
        $movement = $this->movements->findById($vueltaId);
        $this->assertNotNull($movement);
        $this->assertTrue($movement->esIntegro());

        // El evento MOVIDO refleja la corrección en datos_operacion
        $evento = $this->row('SELECT * FROM stock_events WHERE datos_operacion LIKE ?', ['%' . $vueltaId . '%']);
        $this->assertNotNull($evento);
        $datos = json_decode((string) $evento['datos_operacion'], true);
        $this->assertTrue($datos['es_correccion']);
        $this->assertSame($idaId, $datos['movimiento_referencia_id']);
    }

    public function testMovimientoNoInversoNoSeMarcaComoCorreccion(): void
    {
        $this->seedLocation(self::T1, 'LOC-02', capacidad: 10);
        $unit = $this->receive('LOC-01', 3);

        $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-02',
        ], 'mv-e8-3');

        // LOC-02 → LOC-PICK no es el inverso del ida (LOC-01 → LOC-02)
        $tercero = $this->move([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'ubicacion_destino_codigo' => 'LOC-PICK',
        ], 'mv-e8-4');

        $this->assertFalse($tercero['data']['movimiento']['es_correccion']);
        $this->assertNull($tercero['data']['movimiento']['movimiento_referencia_id']);
    }
}
