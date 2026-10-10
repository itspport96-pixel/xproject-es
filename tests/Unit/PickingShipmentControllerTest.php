<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\PickingController;
use App\Modules\Inventory\Http\ShipmentController;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Capa HTTP de picking y transporte (Sprint 7, v1.7.0).
 *
 *  - 401 sin claims; Idempotency-Key OBLIGATORIO en start/load/authorize
 *    (400 si falta) y OPCIONAL en complete/verify (409 gated por estado).
 *  - Cada escritura con éxito escribe UNA fila de auditoría de dominio
 *    (`picking.start|complete|verify`, `shipment.load|authorize`); las
 *    operaciones fallidas NO generan fila.
 *  - index/show: paginación con tope de 100 y 400 si el filtro es malo.
 */
final class PickingShipmentControllerTest extends InventorySqliteTestCase
{
    private const USER = 'user-1';
    private const SUPERVISOR = 'supervisor-1';

    private PickingController $picking;
    private ShipmentController $shipment;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = new RecordingAuditLogger();
        $this->picking = new PickingController(
            $this->startPickingUseCase,
            $this->completePickingUseCase,
            $this->verifyPickingUseCase,
            $this->pickingQuery,
            $this->audit
        );
        $this->shipment = new ShipmentController(
            $this->loadShipmentUseCase,
            $this->authorizeShipmentUseCase,
            $this->shipmentQuery,
            $this->audit
        );
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<string, mixed>
     */
    private function claims(string $userId = self::USER): array
    {
        return ['user_id' => $userId, 'tenant_id' => self::T1];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(
        array $body = [],
        array $headers = [],
        ?array $user = null,
        array $params = [],
        array $query = [],
        ?string $requestId = null
    ): array {
        return [
            'user' => $user ?? $this->claims(),
            'headers' => $headers,
            'body' => $body,
            'query' => $query,
            'params' => $params,
            'request_id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receive(int $cantidad = 3): array
    {
        return $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => 'LOC-01',
        ])['data'];
    }

    private function loc(string $codigo): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr(self::T1, 0, 5);
    }

    private function reserve(string $unitId): void
    {
        $this->reserveUseCase->execute(['unidad_fisica_id' => $unitId], null, 'user-9');
    }

    /**
     * Unidad RESERVADO preparada (lote EN_CURSO) para las pruebas de carga.
     */
    private function preparedUnit(string $lote, int $cantidad = 3): array
    {
        $unit = $this->receive($cantidad);
        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $this->picking->start($this->request([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], ['idempotency-key' => 'pk-http-' . $lote]));

        return $unit;
    }

    /**
     * Unidad cargada en un envío PREPARACION.
     *
     * @return array{unidad: array<string, mixed>, shipment_id: string}
     */
    private function loadedUnit(string $lote): array
    {
        $unit = $this->preparedUnit($lote);
        $this->picking->complete($this->request([], [], null, ['id' => $lote]));
        $this->audit->reset();

        $result = $this->shipment->load($this->request([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => ['destinatario_nombre' => 'ACME SA'],
        ], ['idempotency-key' => 'ld-http-' . $lote]));

        $this->assertSame(201, $result['status']);

        return [
            'unidad' => $unit,
            'shipment_id' => (string) $result['data']['shipment']['shipment_id'],
        ];
    }

    // ====================================================================
    // PICKING
    // ====================================================================

    public function testStartSinClaimsDevuelve401(): void
    {
        try {
            $this->picking->start(['user' => null, 'headers' => [], 'body' => []]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testStartSinIdempotencyKeyDevuelve400(): void
    {
        $unit = $this->receive();

        try {
            $this->picking->start($this->request([
                'id_lote_picking' => 'LOTE-HTTP',
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(0, $this->countRows('picking_operations'));
        $this->assertCount(0, $this->audit->entries);
    }

    public function testStartValidoDevuelve201YRegistraAuditoria(): void
    {
        $unit = $this->receive();
        $this->reserve($unit['unidad_fisica_id']);
        $this->audit->reset();

        $result = $this->picking->start($this->request([
            'id_lote_picking' => 'LOTE-HTTP-1',
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], ['idempotency-key' => 'pk-http-1'], null, [], [], 'req-pick-1'));

        $this->assertSame(201, $result['status']);

        $entry = $this->audit->byOperacion('picking.start');
        $this->assertNotNull($entry, 'Falta la fila picking.start');
        $this->assertSame(1, count($this->audit->entries), 'Cada escritura debe auditar UNA fila');
        $this->assertSame(self::T1, $entry['tenant_id']);
        $this->assertSame(self::USER, $entry['usuario_id']);
        $this->assertSame('inventory', $entry['modulo']);
        $this->assertSame('picking', $entry['recurso']);
        $this->assertSame('CREATE', $entry['tipo_cambio']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame('LOTE-HTTP-1', $entry['entidad_id']);
        $this->assertSame('req-pick-1', $entry['request_id']);
        $this->assertSame('pk-http-1', $entry['detalles_adicionales']['idempotency_key']);
        $this->assertSame(201, $entry['detalles_adicionales']['codigo_respuesta']);
    }

    public function testStartFallidoNoGeneraFilaDeAuditoria(): void
    {
        $unit = $this->receive();
        $this->reserve($unit['unidad_fisica_id']);
        $this->picking->start($this->request([
            'id_lote_picking' => 'LOTE-DUP-HTTP',
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], ['idempotency-key' => 'pk-dup-1']));
        $this->audit->reset();

        try {
            $this->picking->start($this->request([
                'id_lote_picking' => 'LOTE-DUP-HTTP',
                'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ], ['idempotency-key' => 'pk-dup-2']));
            $this->fail('Debía devolver 409 por lote duplicado');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertCount(0, $this->audit->entries, 'El error lo audita AuditMiddleware, no el controller');
    }

    /**
     * v1.7.1 (A3): el replay con la MISMA clave no vuelve a escribir la fila
     * de auditoría de dominio y devuelve la misma respuesta.
     *
     * El marcador `_replay` viaja al Router, que lo retira del body y lo usa
     * para que AuditMiddleware tampoco escriba su fila (AuditMiddlewareTest).
     */
    public function testStartReplayNoRepiteLaAuditoriaDeDominio(): void
    {
        $unit = $this->receive();
        $this->reserve($unit['unidad_fisica_id']);

        $body = [
            'id_lote_picking' => 'LOTE-REPLAY-HTTP',
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ];
        $headers = ['idempotency-key' => 'pk-replay-http'];

        $first = $this->picking->start($this->request($body, $headers));

        $this->assertSame(201, $first['status']);
        $this->assertCount(1, $this->audit->entries);

        $second = $this->picking->start($this->request($body, $headers));

        $this->assertSame(201, $second['status']);
        $this->assertSame($first['data'], $second['data']);
        $this->assertTrue($second['_replay'] ?? false, 'El marcador debe llegar al Router');
        $this->assertCount(1, $this->audit->entries, 'El replay no debe auditar de nuevo');
        $this->assertNotNull($this->audit->byOperacion('picking.start'));
        $this->assertSame(1, $this->countRows('picking_operations'));
    }

    public function testCompleteYVerifyConIdempotencyKeyOpcional(): void
    {
        $this->preparedUnit('LOTE-HTTP-2');
        $this->audit->reset();

        $complete = $this->picking->complete($this->request(
            [],
            [],
            $this->claims(),
            ['id' => 'LOTE-HTTP-2']
        ));
        $this->assertSame(200, $complete['status']);

        $verify = $this->picking->verify($this->request(
            [],
            [],
            $this->claims(self::SUPERVISOR),
            ['id' => 'LOTE-HTTP-2']
        ));
        $this->assertSame(200, $verify['status']);
        $this->assertSame(self::SUPERVISOR, $verify['data']['actor_id_supervisor']);

        $this->assertNotNull($this->audit->byOperacion('picking.complete'));
        $this->assertNotNull($this->audit->byOperacion('picking.verify'));
        $this->assertSame('picking', $this->audit->byOperacion('picking.verify')['recurso']);
        $this->assertSame('UPDATE', $this->audit->byOperacion('picking.verify')['tipo_cambio']);
        $this->assertSame('LOTE-HTTP-2', $this->audit->byOperacion('picking.complete')['entidad_id']);
        $this->assertSame(2, count($this->audit->entries));
    }

    public function testVerifyConElMismoUsuarioQueElSelectorDevuelve422(): void
    {
        $this->preparedUnit('LOTE-HTTP-3');
        $this->picking->complete($this->request([], [], null, ['id' => 'LOTE-HTTP-3']));
        $this->audit->reset();

        try {
            $this->picking->verify($this->request([], [], null, ['id' => 'LOTE-HTTP-3']));
            $this->fail('Debía exigir un supervisor distinto del selector');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertCount(0, $this->audit->entries);
    }

    public function testIndexDevuelveListadoYRecortaPerPage(): void
    {
        $this->preparedUnit('LOTE-HTTP-4');

        $result = $this->picking->index($this->request(
            [],
            [],
            null,
            [],
            ['page' => 1, 'per_page' => 500]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame(100, $result['data']['pagination']['per_page']);
        $this->assertSame(1, $result['data']['summary']['total']);
        $this->assertSame('EN_CURSO', $result['data']['data'][0]['estado']);

        try {
            $this->picking->index($this->request([], [], null, [], ['estado' => 'VOLANDO']));
            $this->fail('Debía devolver 400 con un estado desconocido');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    // ====================================================================
    // SHIPMENTS
    // ====================================================================

    public function testLoadSinIdempotencyKeyDevuelve400(): void
    {
        $unit = $this->preparedUnit('LOTE-HTTP-5');
        $this->picking->complete($this->request([], [], null, ['id' => 'LOTE-HTTP-5']));
        $this->audit->reset();

        try {
            $this->shipment->load($this->request([
                'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            ]));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(0, $this->countRows('shipments'));
        $this->assertCount(0, $this->audit->entries);
    }

    public function testLoadValidoDevuelve201YRegistraAuditoria(): void
    {
        $unit = $this->preparedUnit('LOTE-HTTP-6');
        $this->picking->complete($this->request([], [], null, ['id' => 'LOTE-HTTP-6']));
        $this->audit->reset();

        $result = $this->shipment->load($this->request([
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => ['destinatario_nombre' => 'ACME SA'],
        ], ['idempotency-key' => 'ld-http-6'], null, [], [], 'req-ship-1'));

        $this->assertSame(201, $result['status']);

        $entry = $this->audit->byOperacion('shipment.load');
        $this->assertNotNull($entry, 'Falta la fila shipment.load');
        $this->assertSame(1, count($this->audit->entries));
        $this->assertSame('inventory', $entry['modulo']);
        $this->assertSame('shipment', $entry['recurso']);
        $this->assertSame('CREATE', $entry['tipo_cambio']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame(
            $result['data']['shipment']['shipment_id'],
            $entry['entidad_id']
        );
        $this->assertSame('ld-http-6', $entry['detalles_adicionales']['idempotency_key']);
        $this->assertSame(201, $entry['detalles_adicionales']['codigo_respuesta']);
    }

    public function testAuthorizeSinIdempotencyKeyDevuelve400(): void
    {
        $cargado = $this->loadedUnit('LOTE-HTTP-7');

        try {
            $this->shipment->authorize($this->request([
                'shipment_id' => $cargado['shipment_id'],
            ]));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(
            'PREPARACION',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$cargado['shipment_id']])['estado']
        );
    }

    public function testAuthorizeValidoDevuelve200YRegistraAuditoria(): void
    {
        $cargado = $this->loadedUnit('LOTE-HTTP-8');
        $this->audit->reset();

        $result = $this->shipment->authorize($this->request(
            ['shipment_id' => $cargado['shipment_id']],
            ['idempotency-key' => 'az-http-8']
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('EN_RUTA', $result['data']['shipment']['estado']);

        $entry = $this->audit->byOperacion('shipment.authorize');
        $this->assertNotNull($entry, 'Falta la fila shipment.authorize');
        $this->assertSame(1, count($this->audit->entries));
        $this->assertSame('shipment', $entry['recurso']);
        $this->assertSame('UPDATE', $entry['tipo_cambio']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame($cargado['shipment_id'], $entry['entidad_id']);
        $this->assertSame(200, $entry['detalles_adicionales']['codigo_respuesta']);
    }

    public function testAuthorizeFallidoNoGeneraFilaDeAuditoria(): void
    {
        $cargado = $this->loadedUnit('LOTE-HTTP-9');
        $this->audit->reset();

        try {
            $this->shipment->authorize($this->request(
                ['shipment_id' => 'no-existe'],
                ['idempotency-key' => 'az-http-9']
            ));
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertCount(0, $this->audit->entries);
        $this->assertSame(
            'PREPARACION',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$cargado['shipment_id']])['estado']
        );
    }

    public function testIndexYShowDeEnvios(): void
    {
        $cargado = $this->loadedUnit('LOTE-HTTP-10');

        $index = $this->shipment->index($this->request(
            [],
            [],
            null,
            [],
            ['page' => 1, 'per_page' => 10, 'estado' => 'PREPARACION']
        ));
        $this->assertSame(200, $index['status']);
        $this->assertSame(1, $index['data']['summary']['total']);
        $this->assertSame(1, $index['data']['data'][0]['total_items']);

        $show = $this->shipment->show($this->request([], [], null, ['id' => $cargado['shipment_id']]));
        $this->assertSame(200, $show['status']);
        $this->assertSame($cargado['shipment_id'], $show['data']['shipment']['shipment_id']);
        $this->assertCount(1, $show['data']['items']);
        $this->assertSame('CARGADA', $show['data']['items'][0]['estado']);

        try {
            $this->shipment->show($this->request([], [], null, ['id' => 'no-existe']));
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(1, $this->shipmentQuery->list(self::T1, ['fecha_desde' => date('Y-m-d')])['summary']['total']);
    }
}
