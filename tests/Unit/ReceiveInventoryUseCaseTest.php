<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;
use PDO;
use PDOException;
use Tests\Support\CollidingBarcodeRepository;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-001: Recepción de inventario (Sprint 5).
 *
 * Verifica sobre repositorios REALES + SQLite:
 *  - happy path transaccional: unidad + ledger + evento + capacidad
 *  - R1: cantidad_disponible = física - reservada - en tránsito
 *  - aislamiento de tenant
 *  - capacidad excedida → 422 sin efectos parciales
 *  - atomicidad real (falla al FINAL de la transacción → rollback total)
 *  - recepción parcial → incidencia DISCREPANCIA_CANTIDAD en la misma transacción
 *  - idempotencia: replay idéntico, 409 con payload distinto, key liberada tras rollback
 *  - barcode SU-… único por tenant (reintentos ante colisión)
 *  - hash SHA256 del evento (append-only)
 */
final class ReceiveInventoryUseCaseTest extends InventorySqliteTestCase
{

    // ====================================================================
    // HAPPY PATH
    // ====================================================================

    public function testRecepcionCreaUnidadLedgerEventoYCapacidad(): void
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 10,
            'cantidad_recibida' => 10,
            'ubicacion_codigo' => 'LOC-01',
            'lote' => 'LOTE-A',
        ]);

        $this->assertSame(201, $result['status']);
        $data = $result['data'];

        $this->assertSame('Recepción registrada correctamente.', $data['mensaje']);
        $this->assertSame('SKU-ALFA', $data['sku']);
        $this->assertSame('DISPONIBLE', $data['estado']);
        $this->assertSame(10.0, $data['cantidad_esperada']);
        $this->assertSame(10.0, $data['cantidad_recibida']);
        $this->assertFalse($data['recepcion_parcial']);
        $this->assertNull($data['incidencia']);

        // Unidad física
        $unit = $this->row(
            'SELECT * FROM stock_units WHERE id = :id',
            ['id' => $data['unidad_fisica_id']]
        );
        $this->assertNotNull($unit);
        $this->assertSame(self::T1, $unit['tenant_id']);
        $this->assertSame('DISPONIBLE', $unit['estado']);
        $this->assertSame('LOTE-A', $unit['lote']);
        $this->assertSame(10.0, (float) $unit['cantidad']);
        $this->assertSame($this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']), $unit['ubicacion_id']);

        // Ledger (R1)
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = :u', ['u' => $data['unidad_fisica_id']]);
        $this->assertNotNull($ledger);
        $this->assertSame(10.0, (float) $ledger['cantidad_fisica']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame(10.0, (float) $ledger['cantidad_disponible']);
        $this->assertSame('DISPONIBLE', $ledger['estado_actual']);

        // Evento RECIBIDO
        $this->assertSame(1, $this->countRows('stock_events'));
        $event = $this->row('SELECT * FROM stock_events WHERE unidad_fisica_id = :u', ['u' => $data['unidad_fisica_id']]);
        $this->assertSame('RECIBIDO', $event['tipo_evento']);
        $this->assertSame(0.0, (float) $event['cantidad_anterior']);
        $this->assertSame(10.0, (float) $event['cantidad_posterior']);
        $this->assertNull($event['estado_anterior']);
        $this->assertSame('DISPONIBLE', $event['estado_posterior']);

        // Capacidad usada = ceil(recibida)
        $this->assertSame(
            10,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-01'])
        );

        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(1, $this->countRows('stock_ledger'));
    }

    public function testRecepcionSinIdempotencyKeyNoCreaClave(): void
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 2,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(1, $this->countRows('stock_units'));
    }

    // ====================================================================
    // R1 - CANTIDAD DISPONIBLE
    // ====================================================================

    public function testLedgerCumpleR1DisponibleIgualFisicaMenosReservadaMenosTransito(): void
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 15,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $fisica = (float) $result['data']['ledger']['cantidad_fisica'];
        $reservada = (float) $result['data']['ledger']['cantidad_reservada'];
        $transito = (float) $result['data']['ledger']['cantidad_en_transito'];
        $disponible = (float) $result['data']['ledger']['cantidad_disponible'];

        $this->assertSame($fisica - $reservada - $transito, $disponible);
        $this->assertSame($this->calculation->disponible($fisica, $reservada, $transito), $disponible);
        $this->assertSame(15.0, $disponible);
    }

    // ====================================================================
    // RECEPCIÓN PARCIAL + INCIDENCIA
    // ====================================================================

    public function testRecepcionParcialCreaIncidenciaEnLaMismaTransaccion(): void
    {
        $result = $this->useCase->execute(
            [
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 10,
                'cantidad_recibida' => 7,
                'ubicacion_codigo' => 'LOC-01',
            ],
            'key-parcial-1',
            'user-99'
        );

        $this->assertSame(201, $result['status']);
        $data = $result['data'];

        $this->assertTrue($data['recepcion_parcial']);
        $this->assertSame(7.0, $data['cantidad_recibida']);
        $this->assertSame(10.0, $data['cantidad_esperada']);

        $this->assertNotNull($data['incidencia']);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $data['incidencia']['tipo']);
        $this->assertSame('ABIERTA', $data['incidencia']['estado']);
        $this->assertSame($data['unidad_fisica_id'], $data['incidencia']['unidad_fisica_id']);

        // La unidad sólo contiene lo recibido
        $this->assertSame(7.0, (float) $this->scalar(
            'SELECT cantidad FROM stock_units WHERE id = :id',
            ['id' => $data['unidad_fisica_id']]
        ));

        // Incidencia persistida en la misma transacción, con reporter y descripción
        $this->assertSame(1, $this->countRows('incidents'));
        $incident = $this->row('SELECT * FROM incidents WHERE tenant_id = :t', ['t' => self::T1]);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $incident['tipo']);
        $this->assertSame('ABIERTA', $incident['estado']);
        $this->assertSame('user-99', $incident['actor_id_reporta']);
        $this->assertSame($data['unidad_fisica_id'], $incident['unidad_fisica_id']);
        $this->assertStringContainsString('SKU-ALFA', $incident['descripcion']);
        $this->assertStringContainsString('esperadas 10.0000, recibidas 7.0000', $incident['descripcion']);

        // El evento documenta ambas cantidades
        $datos = json_decode(
            (string) $this->scalar('SELECT datos_operacion FROM stock_events LIMIT 1'),
            true
        );
        $this->assertSame(10.0, (float) $datos['cantidad_esperada']);
        $this->assertSame(7.0, (float) $datos['cantidad_recibida']);
    }

    public function testRecepcionExactaNoCreaIncidencia(): void
    {
        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 6,
            'cantidad_recibida' => 6,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertSame(0, $this->countRows('incidents'));
        $this->assertFalse($this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 6,
            'ubicacion_codigo' => 'LOC-01',
        ])['data']['recepcion_parcial']);
    }

    // ====================================================================
    // VALIDACIONES (CU-001)
    // ====================================================================

    public function testSkuRequeridoDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->useCase->execute(['cantidad_esperada' => 1, 'ubicacion_codigo' => 'LOC-01']);
    }

    public function testCantidadEsperadaCeroDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 0,
            'ubicacion_codigo' => 'LOC-01',
        ]);
    }

    public function testRecibidaMayorQueEsperadaDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 5,
            'cantidad_recibida' => 6,
            'ubicacion_codigo' => 'LOC-01',
        ]);
    }

    public function testUbicacionRequeridaDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->useCase->execute(['sku' => 'SKU-ALFA', 'cantidad_esperada' => 1]);
    }

    public function testProductoInexistenteDevuelve404(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(404);

        $this->useCase->execute([
            'sku' => 'SKU-NOEXISTE',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);
    }

    public function testProductoInactivoDevuelve422(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(422);

        $this->useCase->execute([
            'sku' => 'SKU-OFF',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);
    }

    public function testUbicacionInexistenteDevuelve404(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(404);

        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-NOEXISTE',
        ]);
    }

    public function testUbicacionInactivaDevuelve422(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(422);

        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-OFF',
        ]);
    }

    public function testUbicacionPorIdFuncionaIgualQuePorCodigo(): void
    {
        $locationId = (string) $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);

        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 3,
            'ubicacion_id' => $locationId,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame($locationId, $result['data']['ubicacion_id']);
    }

    // ====================================================================
    // CAPACIDAD
    // ====================================================================

    public function testCapacidadExcedidaDevuelve422YSinEfectos(): void
    {
        try {
            $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 1,
                'ubicacion_codigo' => 'LOC-FULL',
            ], 'key-capacidad-1');
            $this->fail('Debía lanzar capacityExceeded (422)');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('business_rule', $e->getCategory());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countRows('stock_ledger'));
        $this->assertSame(0, $this->countRows('stock_events'));
        // La key vive en la MISMA transacción: el rollback también la libera
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(
            5,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-FULL'])
        );
    }

    public function testCapacidadSeRedondeaHaciaArriba(): void
    {
        $this->seedLocation(self::T1, 'LOC-100', capacidad: 10, capacidadUsada: 0);

        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1.5,
            'ubicacion_codigo' => 'LOC-100',
        ]);

        $this->assertSame(
            2,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-100'])
        );
    }

    // ====================================================================
    // ATOMICIDAD (rollback con datos ya escritos)
    // ====================================================================

    public function testFalloAlFinalDeLaTransaccionRevocaTodo(): void
    {
        // La última operación de la recepción es UPDATE locations: un trigger
        // que la aborta simula un fallo con unidad+ledger+evento ya insertados.
        $this->pdo->exec(
            'CREATE TRIGGER trg_fail_location_update BEFORE UPDATE ON locations
             BEGIN SELECT RAISE(ABORT, \'fallo forzado\'); END'
        );

        try {
            $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 4,
                'ubicacion_codigo' => 'LOC-01',
            ], 'key-atomicidad', 'user-1');
            $this->fail('El trigger debió abortar la recepción');
        } catch (PDOException $e) {
            $this->assertStringContainsString('fallo forzado', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countRows('stock_ledger'));
        $this->assertSame(0, $this->countRows('stock_events'));
        $this->assertSame(0, $this->countRows('incidents'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-01'])
        );
    }

    // ====================================================================
    // AISLAMIENTO DE TENANT
    // ====================================================================

    public function testNoSePuedeRecibirEnUbicacionDeOtroTenant(): void
    {
        $this->useTenant(self::T2);

        try {
            $this->useCase->execute([
                'sku' => 'SKU-T2',
                'cantidad_esperada' => 1,
                'ubicacion_codigo' => 'LOC-01',
            ]);
            $this->fail('La ubicación LOC-01 pertenece a T1 (404 para T2)');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
    }

    public function testNoSePuedeRecibirProductoDeOtroTenant(): void
    {
        $this->useTenant(self::T2);

        try {
            $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 1,
                'ubicacion_codigo' => 'LOC-T2',
            ]);
            $this->fail('El producto SKU-ALFA pertenece a T1 (404 para T2)');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
    }

    public function testRecepcionEnSegundoTenantNoContaminaLosDatosDelPrimero(): void
    {
        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 8,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->useTenant(self::T2);
        $t2 = $this->useCase->execute([
            'sku' => 'SKU-T2',
            'cantidad_esperada' => 3,
            'ubicacion_codigo' => 'LOC-T2',
        ]);

        $this->assertSame(201, $t2['status']);
        $this->assertStringStartsWith('SU-', $t2['data']['codigo_barcode']);

        // Cada tenant ve sólo sus unidades
        $this->assertSame(2, $this->countRows('stock_units'));
        $this->assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?',
            [self::T1]
        ));
        $this->assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?',
            [self::T2]
        ));

        // La capacidad de T1 no se tocó
        $this->assertSame(
            8,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-01'])
        );
        $this->assertSame(
            3,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-T2'])
        );
    }

    // ====================================================================
    // BARCODE
    // ====================================================================

    public function testBarcodeTienePrefijoFijoSUYFormatoValido(): void
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $barcode = $result['data']['codigo_barcode'];

        $this->assertStringStartsWith('SU-', $barcode);
        $this->assertMatchesRegularExpression(
            '/^SU-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{6}-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{8}$/',
            $barcode
        );
        $this->assertTrue($this->barcodeService->matches($barcode));

        $random = explode('-', $barcode);
        $this->assertCount(3, $random);
        foreach (['0', '1', 'O', 'I'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $random[1] . $random[2]);
        }
    }

    public function testBarcodeEsUnicoEntreRecepciones(): void
    {
        $a = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);
        $b = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertNotSame($a['data']['codigo_barcode'], $b['data']['codigo_barcode']);
        $this->assertSame(2, $this->countRows('stock_units'));
        $this->assertSame(
            2,
            (int) $this->scalar('SELECT COUNT(DISTINCT codigo_barcode) FROM stock_units')
        );
    }

    public function testElMismoTenantNoPuedeRepetirElCodigoBarcode(): void
    {
        $code = 'SU-DUPDUP-DUPDUP123';

        $this->saveUnit(self::T1, $code);

        try {
            $this->saveUnit(self::T1, $code);
            $this->fail('UNIQUE (tenant_id, codigo_barcode) debió rechazar el duplicado');
        } catch (PDOException $e) {
            $this->assertSame('23000', (string) ($e->errorInfo[0] ?? ''));
        }

        $this->assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM stock_units WHERE codigo_barcode = ?',
            [$code]
        ));
    }

    public function testDosTenantsPuedenRepetirElMismoCodigoBarcode(): void
    {
        $code = 'SU-SHARED-SHARED01';

        $this->saveUnit(self::T1, $code);
        $this->saveUnit(self::T2, $code);

        $this->assertSame(2, (int) $this->scalar(
            'SELECT COUNT(*) FROM stock_units WHERE codigo_barcode = ?',
            [$code]
        ));
        $this->assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM stock_units WHERE codigo_barcode = ? AND tenant_id = ?',
            [$code, self::T1]
        ));
    }

    public function testReintentoTrasColisionSimuladaGeneraCodigoValido(): void
    {
        $repo = new CollidingBarcodeRepository($this->stockUnits, forcedCollisions: 2);
        $useCase = $this->buildUseCase($repo);

        $result = $useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertSame(201, $result['status']);
        // 2 colisiones forzadas + 1 comprobación real de unicidad
        $this->assertSame(3, $repo->barcodeChecks());
        $this->assertTrue($this->barcodeService->matches($result['data']['codigo_barcode']));
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(
            $result['data']['codigo_barcode'],
            $this->scalar('SELECT codigo_barcode FROM stock_units')
        );
    }

    public function testBarcodeGenerationFailedTrasCincoColisiones(): void
    {
        $repo = new CollidingBarcodeRepository($this->stockUnits, forcedCollisions: 5);
        $useCase = $this->buildUseCase($repo);

        try {
            $useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 1,
                'ubicacion_codigo' => 'LOC-01',
            ]);
            $this->fail('Tras MAX_ATTEMPTS=5 colisiones debe fallar barcodeGenerationFailed');
        } catch (InventoryException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertSame(5, $repo->barcodeChecks());
        $this->assertSame(0, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countRows('stock_events'));
    }

    private function saveUnit(string $tenantId, string $barcode): void
    {
        $productoId = $this->scalar(
            'SELECT id FROM products WHERE tenant_id = ? AND sku = ?',
            [$tenantId, $tenantId === self::T1 ? 'SKU-ALFA' : 'SKU-T2']
        );

        $this->stockUnits->save(StockUnit::create(
            uuid(),
            $tenantId,
            (string) $productoId,
            $barcode,
            1.0
        ));
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testMismaKeyMismoPayloadDevuelveLaMismaRespuesta(): void
    {
        $body = [
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 5,
            'ubicacion_codigo' => 'LOC-01',
        ];

        $first = $this->useCase->execute($body, 'key-replay-1', 'user-1');
        $second = $this->useCase->execute($body, 'key-replay-1', 'user-1');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame($first['data'], $second['data']);

        // La segunda llamada NO ejecutó la operación
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(1, $this->countRows('stock_ledger'));
        $this->assertSame(1, $this->countRows('stock_events'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
        $this->assertSame(
            5,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-01'])
        );
    }

    public function testMismaKeyPayloadDistintoDevuelve409(): void
    {
        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 5,
            'ubicacion_codigo' => 'LOC-01',
        ], 'key-conflict-1');

        try {
            $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 6,
                'ubicacion_codigo' => 'LOC-01',
            ], 'key-conflict-1');
            $this->fail('La misma key con otro payload debe devolver 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
        $this->assertSame(
            5,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE codigo = ?', ['LOC-01'])
        );
    }

    public function testReplayDeRecepcionParcialDevuelveLaIncidenciaOriginal(): void
    {
        $body = [
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 10,
            'cantidad_recibida' => 4,
            'ubicacion_codigo' => 'LOC-01',
        ];

        $first = $this->useCase->execute($body, 'key-parcial-replay');
        $second = $this->useCase->execute($body, 'key-parcial-replay');

        $this->assertSame($first['data']['incidencia'], $second['data']['incidencia']);
        $this->assertTrue($second['data']['recepcion_parcial']);
        $this->assertSame(1, $this->countRows('incidents'));
        $this->assertSame(1, $this->countRows('stock_units'));
    }

    public function testKeysDistintasEjecutanOperacionesIndependientes(): void
    {
        $body = [
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 2,
            'ubicacion_codigo' => 'LOC-01',
        ];

        $first = $this->useCase->execute($body, 'key-a');
        $second = $this->useCase->execute($body, 'key-b');

        $this->assertNotSame($first['data']['unidad_fisica_id'], $second['data']['unidad_fisica_id']);
        $this->assertSame(2, $this->countRows('stock_units'));
        $this->assertSame(2, $this->countRows('idempotency_keys'));
    }

    // ====================================================================
    // EVENTO APPEND-ONLY + HASH
    // ====================================================================

    public function testHashDeIntegridadDelEventoEsVerificable(): void
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 9,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $row = $this->row('SELECT * FROM stock_events WHERE tenant_id = :t', ['t' => self::T1]);
        $event = StockEvent::fromArray($row);

        $this->assertTrue($event->esIntegro());
        $this->assertSame(64, strlen($event->hashIntegridad()));
        $this->assertSame($row['hash_integridad'], $event->hashIntegridad());
        $this->assertSame($result['data']['evento']['hash_integridad'], $event->hashIntegridad());
        $this->assertSame(StockEvent::computeHash([
            'id' => $row['id'],
            'tenant_id' => $row['tenant_id'],
            'unidad_fisica_id' => $row['unidad_fisica_id'],
            'tipo_evento' => $row['tipo_evento'],
            'descripcion' => $row['descripcion'],
            'datos_operacion' => json_decode((string) $row['datos_operacion'], true),
            'cantidad_anterior' => (float) $row['cantidad_anterior'],
            'cantidad_posterior' => (float) $row['cantidad_posterior'],
            'estado_anterior' => $row['estado_anterior'],
            'estado_posterior' => $row['estado_posterior'],
            'created_at' => $row['created_at'],
        ]), $event->hashIntegridad());
    }

    public function testAlteracionDeUnEventoSeDetectaPorElHash(): void
    {
        $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 9,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        // Alteración directa de la fila (la bitácora no tiene setters de negocio)
        $this->pdo->exec("UPDATE stock_events SET cantidad_posterior = 999");

        $row = $this->row('SELECT * FROM stock_events LIMIT 1');
        $event = StockEvent::fromArray($row);

        $this->assertFalse($event->esIntegro());
    }

    // ====================================================================
    // v1.10.0 (E1): RECHAZO TOTAL + UMBRAL DE DISCREPANCIA → PRIORIDAD
    // ====================================================================

    public function testRechazoTotalNoCreaUnidadNiTocaElCupo(): void
    {
        $loc = $this->scalar('SELECT id FROM locations WHERE codigo = ?', ['LOC-01']);

        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 10,
            'cantidad_recibida' => 0,
            'ubicacion_codigo' => 'LOC-01',
            'rechazo_total' => true,
        ], 'key-rechazo-1', 'user-99');

        $this->assertSame(201, $result['status']);
        $data = $result['data'];

        $this->assertTrue($data['rechazo_total']);
        $this->assertFalse($data['recepcion_parcial']);
        $this->assertNull($data['unidad_fisica_id']);
        $this->assertNull($data['estado']);
        $this->assertNull($data['ledger']);
        $this->assertNull($data['evento']);
        $this->assertSame(100.0, $data['discrepancia_porcentaje']);

        // Incidencia ENTREGA_RECHAZADA con prioridad ALTA (100 % ≥ umbral)
        $this->assertNotNull($data['incidencia']);
        $this->assertSame('ENTREGA_RECHAZADA', $data['incidencia']['tipo']);
        $this->assertSame('ALTA', $data['incidencia']['prioridad']);
        $this->assertNull($data['incidencia']['unidad_fisica_id']);

        // Nada físico quedó escrito y el cupo no se mueve
        $this->assertSame(0, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countRows('stock_ledger'));
        $this->assertSame(0, $this->countRows('stock_events'));
        $this->assertSame(1, $this->countRows('incidents'));
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );
    }

    public function testRechazoTotalConRecibidaPositivaDevuelve400(): void
    {
        try {
            $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 10,
                'cantidad_recibida' => 5,
                'ubicacion_codigo' => 'LOC-01',
                'rechazo_total' => true,
            ], 'key-rechazo-2');
            $this->fail('rechazo_total con cantidad_recibida > 0 debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('rechazo_total', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
        $this->assertSame(0, $this->countRows('incidents'));
    }

    public function testDiscrepanciaSobreUmbralCreaIncidenciaConPrioridadAlta(): void
    {
        // 100 esperadas, 90 recibidas → 10 % ≥ umbral (5 % por defecto)
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 100,
            'cantidad_recibida' => 90,
            'ubicacion_codigo' => 'LOC-01',
        ], 'key-umbral-1');

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['data']['recepcion_parcial']);
        $this->assertFalse($result['data']['rechazo_total']);
        $this->assertSame(10.0, $result['data']['discrepancia_porcentaje']);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $result['data']['incidencia']['tipo']);
        $this->assertSame('ALTA', $result['data']['incidencia']['prioridad']);
        $this->assertSame(
            'ALTA',
            $this->scalar('SELECT prioridad FROM incidents WHERE tipo = ?', ['DISCREPANCIA_CANTIDAD'])
        );
    }

    public function testDiscrepanciaBajoUmbralCreaIncidenciaConPrioridadNormal(): void
    {
        // 1000 esperadas, 998 recibidas → 0,2 % < umbral
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 1000,
            'cantidad_recibida' => 998,
            'ubicacion_codigo' => 'LOC-01',
        ], 'key-umbral-2');

        $this->assertSame(201, $result['status']);
        $this->assertSame(0.2, $result['data']['discrepancia_porcentaje']);
        $this->assertSame('NORMAL', $result['data']['incidencia']['prioridad']);
    }

    public function testUmbralEsConfigurablePorVariableDeEntorno(): void
    {
        $_ENV['RECEPTION_DISCREPANCY_THRESHOLD'] = '50';

        try {
            $this->assertSame(50.0, $this->useCase->thresholdPercent());

            // 10 % < 50 % → por debajo del umbral configurado
            $result = $this->useCase->execute([
                'sku' => 'SKU-ALFA',
                'cantidad_esperada' => 100,
                'cantidad_recibida' => 90,
                'ubicacion_codigo' => 'LOC-01',
            ], 'key-umbral-3');

            $this->assertSame(10.0, $result['data']['discrepancia_porcentaje']);
            $this->assertSame('NORMAL', $result['data']['incidencia']['prioridad']);
        } finally {
            unset($_ENV['RECEPTION_DISCREPANCY_THRESHOLD']);
        }

        // Restaurado el valor por defecto
        $this->assertSame(5.0, $this->useCase->thresholdPercent());
    }

    public function testRechazoTotalConReplayDevuelveLaMismaIncidencia(): void
    {
        $input = [
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 4,
            'cantidad_recibida' => 0,
            'ubicacion_codigo' => 'LOC-01',
            'rechazo_total' => true,
        ];

        $primera = $this->useCase->execute($input, 'key-rechazo-3', 'user-99');
        $segunda = $this->useCase->execute($input, 'key-rechazo-3', 'user-99');

        $this->assertSame(201, $primera['status']);
        $this->assertTrue($segunda['_replay']);
        $this->assertSame($primera['data'], $segunda['data']);
        $this->assertSame(1, $this->countRows('incidents'));
        $this->assertSame(0, $this->countRows('stock_units'));
    }
}
