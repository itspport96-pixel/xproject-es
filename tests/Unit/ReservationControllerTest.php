<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\ReservationQueryService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\ReservationController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * POST /api/inventory/reservations, .../release y GET / (CU-003 + R7).
 *
 * Capa HTTP:
 *  - 401 sin claims
 *  - index: paginación con tope de 100, filtros y 400 si el estado es malo
 *  - store: Idempotency-Key OBLIGATORIO (400 si falta), 201, replay 201,
 *    409 con payload distinto
 *  - release: Idempotency-Key OPCIONAL (200 sin clave), 400 si falta el id,
 *    404/409 segun la reserva
 */
final class ReservationControllerTest extends InventorySqliteTestCase
{
    private ReservationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ReservationController($this->reserveUseCase, $this->releaseUseCase);

        Container::setInstance(new Container());
        Container::getInstance()->instance(
            ReservationQueryService::class,
            new ReservationQueryService($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function claims(): array
    {
        return ['user_id' => 'user-1', 'tenant_id' => self::T1];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(array $body, array $headers = [], ?array $user = null, array $params = [], array $query = []): array
    {
        return [
            'user' => $user ?? $this->claims(),
            'headers' => $headers,
            'body' => $body,
            'query' => $query,
            'params' => $params,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        $unit = $this->receive('LOC-01', 3);

        return array_merge([
            'unidad_fisica_id' => $unit['unidad_fisica_id'],
            'referencia_orden' => 'SO-77',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function receive(string $ubicacionCodigo, int $cantidad): array
    {
        return $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacionCodigo,
        ])['data'];
    }

    /**
     * Crea una reserva y devuelve su id.
     */
    private function createReservation(string $key = 'res-http'): string
    {
        $result = $this->controller->store(
            $this->request($this->body(), ['idempotency-key' => $key])
        );

        return (string) $result['data']['reserva']['reserva_id'];
    }

    // ====================================================================
    // INDEX (GET)
    // ====================================================================

    public function testIndexSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->index(['user' => null, 'query' => []]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testIndexDevuelveReservasPaginadasConLosCamposDeLaPantalla(): void
    {
        $reservaId = $this->createReservation('res-index-1');

        $result = $this->controller->index(
            $this->request([], [], null, [], ['page' => 1, 'per_page' => 10])
        );

        $this->assertSame(200, $result['status']);
        $payload = $result['data'];

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame(1, $payload['pagination']['page']);
        $this->assertSame(10, $payload['pagination']['per_page']);
        $this->assertFalse($payload['pagination']['has_next']);
        $this->assertSame(1, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['activas']);

        $row = $payload['data'][0];
        $this->assertSame($reservaId, $row['reserva_id']);
        $this->assertSame('ACTIVA', $row['estado']);
        $this->assertArrayHasKey('fecha_expiracion', $row);
        $this->assertArrayHasKey('producto', $row);
        $this->assertArrayHasKey('ubicacion', $row);
    }

    public function testIndexRecortaPerPageAlMaximoDe100(): void
    {
        $result = $this->controller->index(
            $this->request([], [], null, [], ['per_page' => 999])
        );

        $this->assertSame(100, $result['data']['pagination']['per_page']);

        $minus = $this->controller->index(
            $this->request([], [], null, [], ['page' => 0, 'per_page' => 0])
        );
        $this->assertSame(1, $minus['data']['pagination']['page']);
        $this->assertSame(1, $minus['data']['pagination']['per_page']);
    }

    public function testIndexConEstadoDesconocidoDevuelve400(): void
    {
        try {
            $this->controller->index(
                $this->request([], [], null, [], ['estado' => 'VOLADORA'])
            );
            $this->fail('Un estado desconocido debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->toArray()['field']);
        }
    }

    public function testIndexIgnoraQueryParamsFueraDeLosPermitidos(): void
    {
        $this->createReservation('res-index-2');

        $result = $this->controller->index(
            $this->request([], [], null, [], ['inactiva' => '1', 'orden' => 'asc'])
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['pagination']['total']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function testStoreSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->store([
                'user' => null,
                'headers' => ['idempotency-key' => 'k1'],
                'body' => $this->body(),
                'query' => [],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    public function testStoreSinIdempotencyKeyDevuelve400(): void
    {
        try {
            $this->controller->store($this->request($this->body(), []));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(0, $this->countRows('reservations'));
    }

    public function testStoreValidoDevuelve201(): void
    {
        $result = $this->controller->store(
            $this->request($this->body(), ['Idempotency-Key' => 'res-http-1'])
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame('Reserva registrada correctamente.', $result['data']['mensaje']);
        $this->assertSame('ACTIVA', $result['data']['reserva']['estado']);
        $this->assertSame('RESERVADO', $result['data']['estado']);
        $this->assertSame('user-1', $result['data']['reserva']['actor_id']);
        $this->assertSame(1, $this->countRows('reservations'));
    }

    public function testStoreReplayConLaMismaClaveDevuelveLaMismaReserva(): void
    {
        $body = $this->body();
        $headers = ['idempotency-key' => 'res-http-replay'];

        $first = $this->controller->store($this->request($body, $headers));
        $second = $this->controller->store($this->request($body, $headers));

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['reserva']['reserva_id'],
            $second['data']['reserva']['reserva_id']
        );
        $this->assertSame(1, $this->countRows('reservations'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testStoreMismaClavePayloadDistintoDevuelve409(): void
    {
        $headers = ['idempotency-key' => 'res-http-409'];
        $this->controller->store($this->request($this->body(), $headers));

        try {
            $this->controller->store($this->request($this->body(['cantidad' => 1]), $headers));
            $this->fail('Payload distinto con la misma clave debe devolver 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('reservations'));
    }

    public function testStoreDobleReservaDevuelve409(): void
    {
        $body = $this->body();
        $this->controller->store($this->request($body, ['idempotency-key' => 'res-a']));

        try {
            $this->controller->store($this->request($body, ['idempotency-key' => 'res-b']));
            $this->fail('La doble reserva debe devolver 409');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $this->assertSame(1, $this->countRows('reservations'));
    }

    // ====================================================================
    // RELEASE
    // ====================================================================

    public function testReleaseSinClaimsDevuelve401(): void
    {
        $reservaId = $this->createReservation();

        try {
            $this->controller->release([
                'user' => null,
                'headers' => [],
                'body' => [],
                'query' => [],
                'params' => ['id' => $reservaId],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $row = $this->row('SELECT estado FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('ACTIVA', $row['estado']);
    }

    public function testReleaseSinIdDevuelve400(): void
    {
        try {
            $this->controller->release($this->request([], [], null, []));
            $this->fail('Sin id de reserva debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('reserva_id', $e->getField());
        }
    }

    public function testReleaseSinIdempotencyKeyDevuelve200(): void
    {
        $reservaId = $this->createReservation('res-rel');
        $keysBefore = $this->countRows('idempotency_keys');

        // La clave es OPCIONAL al cancelar (Sprint 6): se ejecuta directamente
        $result = $this->controller->release(
            $this->request(['motivo' => 'Cancelada desde API'], [], null, ['id' => $reservaId])
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('CANCELADA', $result['data']['reserva']['estado']);
        $this->assertSame('DISPONIBLE', $result['data']['estado']);

        // Sin clave no se registra ninguna entrada de idempotencia nueva
        $this->assertSame($keysBefore, $this->countRows('idempotency_keys'));

        $row = $this->row('SELECT estado FROM reservations WHERE id = ?', [$reservaId]);
        $this->assertSame('CANCELADA', $row['estado']);
    }

    public function testReleaseReplayConClaveDevuelveLaMismaRespuesta(): void
    {
        $reservaId = $this->createReservation('res-rel-rp');
        $request = $this->request(
            ['motivo' => 'x'],
            ['idempotency-key' => 'rel-http-1'],
            null,
            ['id' => $reservaId]
        );

        $first = $this->controller->release($request);
        $second = $this->controller->release($request);

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(
            $first['data']['reserva']['reserva_id'],
            $second['data']['reserva']['reserva_id']
        );
        $this->assertSame(
            1,
            $this->countQuery("SELECT id FROM stock_events WHERE tipo_evento = 'LIBERADO'")
        );
    }

    public function testReleaseReservaInexistenteDevuelve404(): void
    {
        try {
            $this->controller->release(
                $this->request([], [], null, ['id' => 'no-existe'])
            );
            $this->fail('Reserva inexistente debe devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('reserva_id', $e->getField());
        }
    }

    public function testReleaseReservaNoActivaDevuelve409(): void
    {
        $reservaId = $this->createReservation('res-rel-409');
        $request = $this->request([], [], null, ['id' => $reservaId]);

        $this->controller->release($request);

        try {
            $this->controller->release($request);
            $this->fail('Reserva ya cancelada debe devolver 409');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }
}
