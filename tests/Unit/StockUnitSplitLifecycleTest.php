<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * v1.8.2: ciclo de vida de una unidad dividida (StockUnitSplitService).
 *
 * Cubre: picking/carga/autorización sólo sobre la HIJA, capacidad de la zona
 * de picking (5 tras iniciar picking, 0 tras autorizar la salida), la MADRE
 * intocable y la capacidad de la ubicación de origen sin tocar la división,
 * eventos con la cantidad de la unidad y aislamiento de tenants en la
 * división.
 */
final class StockUnitSplitLifecycleTest extends InventorySqliteTestCase
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

    private function capacidad(string $codigo, string $tenantId = self::T1): int
    {
        return (int) $this->scalar(
            'SELECT capacidad_usada FROM locations WHERE codigo = ? AND tenant_id = ?',
            [$codigo, $tenantId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receive(int $cantidad, string $ubicacion = 'LOC-01', string $sku = 'SKU-ALFA'): array
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
     * Reserva parcial: devuelve el resultado con `data.division`.
     *
     * @return array<string, mixed>
     */
    private function reservePartial(string $unidadId, int $cantidad, ?string $key = 'rs-split'): array
    {
        $result = $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unidadId, 'cantidad' => $cantidad],
            $key,
            'user-9'
        );

        $this->assertSame(201, $result['status']);
        $this->assertNotNull($result['data']['division'] ?? null);

        return $result['data'];
    }

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }

    // ====================================================================
    // PICKING / CARGA / AUTORIZACIÓN SOBRE LA HIJA
    // ====================================================================

    public function testPickingCargaYAutorizacionDeLaHijaLiberanLaCapacidadSinTocarLaMadre(): void
    {
        $unit = $this->receive(10);
        $this->assertSame(10, $this->capacidad('LOC-01'));

        $data = $this->reservePartial($unit['unidad_fisica_id'], 5);
        $madreId = (string) $data['division']['unidad_madre_id'];
        $hijaId = (string) $data['division']['unidad_hija_id'];

        // La división no mueve presencia física: 10 unidades repartidas en dos
        // filas siguen ocupando lo mismo.
        $this->assertSame(10, $this->capacidad('LOC-01'));

        // --- CU-004: sólo la hija (RESERVADO) entra en picking -------------
        $picking = $this->startPickingUseCase->execute([
            'id_lote_picking' => 'LOTE-SPLIT',
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $hijaId]],
        ], 'pk-split', self::SELECTOR);

        $this->assertSame(201, $picking['status']);

        // Capacidad: el origen suelta los 5 de la hija y la zona gana 5
        $this->assertSame(5, $this->capacidad('LOC-01'));
        $this->assertSame(5, $this->capacidad('LOC-PICK'));

        $hijaRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$hijaId]);
        $this->assertSame('EN_PREPARACION', $hijaRow['estado']);
        $this->assertSame($this->loc('LOC-PICK'), $hijaRow['ubicacion_id']);

        $madreRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$madreId]);
        $this->assertSame('DISPONIBLE', $madreRow['estado']);
        $this->assertSame(5.0, (float) $madreRow['cantidad']);
        $this->assertSame($this->loc('LOC-01'), $madreRow['ubicacion_id']);
        $this->assertSame($unit['codigo_barcode'], $madreRow['codigo_barcode']);

        $op = $this->row('SELECT * FROM picking_operations WHERE unidad_fisica_id = ?', [$hijaId]);
        $this->assertNotNull($op);
        $this->assertSame(5.0, (float) $op['cantidad']);
        $this->assertSame(
            0,
            $this->countQuery('SELECT id FROM picking_operations WHERE unidad_fisica_id = ?', [$madreId])
        );

        $this->completePickingUseCase->execute('LOTE-SPLIT');

        // --- CU-005: la carga tampoco libera la zona -----------------------
        $load = $this->loadShipmentUseCase->execute([
            'unidades' => [['unidad_fisica_id' => $hijaId, 'cantidad' => 5]],
            'shipment' => [
                'referencia_externa' => 'TR-SPLIT',
                'destinatario_nombre' => 'ACME SA',
                'conductor_nombre' => 'Chofer Uno',
            ],
        ], 'ld-split', self::SELECTOR);

        $this->assertSame(201, $load['status']);
        $this->assertSame(5, $this->capacidad('LOC-PICK'), 'La carga no libera slots: siguen en la zona');
        $this->assertSame(5.0, (float) $load['data']['items'][0]['cantidad']);

        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame(5.0, (float) $item['cantidad']);
        $this->assertSame(
            0,
            $this->countQuery('SELECT id FROM shipment_items WHERE unidad_fisica_id = ?', [$madreId])
        );

        $cargado = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'CARGADO'");
        $this->assertNotNull($cargado);
        $this->assertSame($hijaId, $cargado['unidad_fisica_id']);
        // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de la unidad
        $this->assertSame(5.0, (float) $cargado['cantidad_anterior']);
        $this->assertSame(5.0, (float) $cargado['cantidad_posterior']);

        // --- CU-006: al autorizar la salida se libera la zona física -------
        $authorize = $this->authorizeShipmentUseCase->execute(
            ['shipment_id' => $load['data']['shipment']['shipment_id']],
            'az-split',
            self::SUPERVISOR
        );

        $this->assertSame(200, $authorize['status']);
        $this->assertSame(0, $this->capacidad('LOC-PICK'));
        // TRANSITO es virtual: nunca ocupa capacidad
        $this->assertSame(0, $this->capacidad('LOC-TRN'));

        $hijaAfter = $this->row('SELECT * FROM stock_units WHERE id = ?', [$hijaId]);
        $this->assertSame('EN_TRANSITO', $hijaAfter['estado']);
        $this->assertSame($this->loc('LOC-TRN'), $hijaAfter['ubicacion_id']);

        // La madre no se enteró: DISPONIBLE en el origen con su ledger intacto
        $madreFinal = $this->row('SELECT * FROM stock_units WHERE id = ?', [$madreId]);
        $this->assertSame('DISPONIBLE', $madreFinal['estado']);
        $this->assertSame(5.0, (float) $madreFinal['cantidad']);
        $this->assertSame($this->loc('LOC-01'), $madreFinal['ubicacion_id']);

        $ledgerMadre = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$madreId]);
        $this->assertSame(5.0, (float) $ledgerMadre['cantidad_fisica']);
        $this->assertSame(5.0, (float) $ledgerMadre['cantidad_disponible']);
        $this->assertSame(0.0, (float) $ledgerMadre['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledgerMadre['cantidad_en_transito']);

        $enTransito = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'EN_TRANSITO'");
        $this->assertNotNull($enTransito);
        $this->assertSame($hijaId, $enTransito['unidad_fisica_id']);
        $this->assertSame(5.0, (float) $enTransito['cantidad_anterior']);
        $this->assertSame(5.0, (float) $enTransito['cantidad_posterior']);
        $datos = json_decode((string) $enTransito['datos_operacion'], true);
        $this->assertSame(5.0, (float) $datos['cantidad_en_transito']);
        // En tránsito ya estaba a 5 desde la carga (CU-005): al autorizar la
        // salida sólo cambia la ubicación, no la cantidad de la unidad.
        $this->assertSame(5.0, (float) $datos['cantidad_en_transito_anterior']);
    }

    // ====================================================================
    // LA MADRE NO ENTRA EN EL FLUJO DE ENVÍO
    // ====================================================================

    public function testLaMadreNoSePuedePicking(): void
    {
        $unit = $this->receive(10);
        $data = $this->reservePartial($unit['unidad_fisica_id'], 5);
        $madreId = (string) $data['division']['unidad_madre_id'];

        try {
            $this->startPickingUseCase->execute([
                'id_lote_picking' => 'LOTE-MADRE',
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
                'unidades' => [['unidad_fisica_id' => $madreId]],
            ], 'pk-madre', self::SELECTOR);
            $this->fail('La madre queda DISPONIBLE: no debe poder entrar en picking');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('RESERVADO', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
        $this->assertSame(10, $this->capacidad('LOC-01'));

        $madreRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$madreId]);
        $this->assertSame('DISPONIBLE', $madreRow['estado']);
        $this->assertSame($this->loc('LOC-01'), $madreRow['ubicacion_id']);
    }

    // ====================================================================
    // AISLAMIENTO DE TENANTS EN LA DIVISIÓN
    // ====================================================================

    public function testLaDivisionRespetaElAislamientoDeTenants(): void
    {
        $this->useTenant(self::T2);
        $unitT2 = $this->receive(10, 'LOC-T2', 'SKU-T2');
        $data = $this->reservePartial($unitT2['unidad_fisica_id'], 5, 'rs-split-t2');

        $this->useTenant(self::T1);

        $madreId = (string) $data['division']['unidad_madre_id'];
        $hijaId = (string) $data['division']['unidad_hija_id'];

        // Ambas mitades y sus eventos son del tenant T2
        foreach ([$madreId, $hijaId] as $id) {
            $fila = $this->row('SELECT tenant_id FROM stock_units WHERE id = ?', [$id]);
            $this->assertNotNull($fila);
            $this->assertSame(self::T2, $fila['tenant_id']);
        }

        $this->assertSame(2, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO' AND tenant_id = ?",
            [self::T2]
        ));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'DIVIDIDO' AND tenant_id = ?",
            [self::T1]
        ));
        $this->assertSame(0, $this->countQuery(
            'SELECT id FROM stock_units WHERE tenant_id = ?',
            [self::T1]
        ));

        // T1 no ve la unidad dividida de T2 (404, sin escrituras)
        try {
            $this->reserveUseCase->execute(
                ['unidad_fisica_id' => $hijaId],
                'rs-cross',
                'user-9'
            );
            $this->fail('Debía devolver 404 para una unidad de otro tenant');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('reservations'));
    }
}
