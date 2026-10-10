<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\MovementController;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * POST /api/inventory/movements (CU-002 + R7).
 *
 * Capa HTTP del controlador:
 *  - 401 sin claims
 *  - 400 si falta/esta mal el header Idempotency-Key (obligatorio)
 *  - 201 con el movimiento
 *  - replay identico 201, payload distinto con la misma clave 409
 */
final class MovementControllerTest extends InventorySqliteTestCase
{
    private MovementController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(self::T1, 'LOC-02');
        $this->controller = new MovementController($this->moveUseCase);
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
    private function request(array $body, array $headers = [], ?array $user = null): array
    {
        return [
            'user' => $user ?? $this->claims(),
            'headers' => $headers,
            'body' => $body,
            'query' => [],
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
            'ubicacion_destino_codigo' => 'LOC-02',
            'motivo' => 'REUBICACION',
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

    // ====================================================================
    // AUTENTICACION E IDEMPOTENCIA (capa HTTP)
    // ====================================================================

    public function testSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->create([
                'user' => null,
                'headers' => ['idempotency-key' => 'k1'],
                'body' => $this->body(),
                'query' => [],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testSinIdempotencyKeyDevuelve400(): void
    {
        try {
            $this->controller->create($this->request($this->body(), []));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(0, $this->countRows('movements'));
    }

    public function testIdempotencyKeyVaciaDevuelve400(): void
    {
        $body = $this->body();

        try {
            $this->controller->create($this->request($body, ['idempotency-key' => '   ']));
            $this->fail('Clave vacia debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }
    }

    public function testIdempotencyKeyConCaracteresInvalidosDevuelve400(): void
    {
        $body = $this->body();

        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->create($this->request($body, ['idempotency-key' => 'clave con espacios!']));
    }

    public function testIdempotencyKeyMasLargaDe64Devuelve400(): void
    {
        $body = $this->body();

        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->create(
            $this->request($body, ['idempotency-key' => str_repeat('a', 65)])
        );
    }

    // ====================================================================
    // CAMINO FELIZ Y REPLAY
    // ====================================================================

    public function testMovimientoValidoDevuelve201(): void
    {
        $body = $this->body();

        $result = $this->controller->create(
            $this->request($body, ['Idempotency-Key' => 'mv-http-1'])
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame($body['unidad_fisica_id'], $result['data']['unidad_fisica_id']);
        $this->assertSame('DISPONIBLE', $result['data']['estado']);
        $this->assertSame('REUBICACION', $result['data']['movimiento']['motivo']);
        $this->assertSame('user-1', $result['data']['movimiento']['actor_id']);
        $this->assertSame('LOC-02', $result['data']['ubicacion_destino']['codigo']);
        $this->assertSame(1, $this->countRows('movements'));
    }

    public function testReplayConLaMismaClaveDevuelveElMismoMovimiento(): void
    {
        $body = $this->body();
        $headers = ['idempotency-key' => 'mv-http-replay'];

        $first = $this->controller->create($this->request($body, $headers));
        $second = $this->controller->create($this->request($body, $headers));

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(
            $first['data']['movimiento']['movimiento_id'],
            $second['data']['movimiento']['movimiento_id']
        );
        $this->assertSame(1, $this->countRows('movements'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $body = $this->body();
        $headers = ['idempotency-key' => 'mv-http-409'];

        $this->controller->create($this->request($body, $headers));

        try {
            $this->controller->create(
                $this->request($this->body(['referencia_externa' => 'REF-2']), $headers)
            );
            $this->fail('Payload distinto con la misma clave debe devolver 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('movements'));
    }

    public function testErroresDeValidacionNoEscribenNada(): void
    {
        $body = $this->body(['ubicacion_destino_codigo' => 'NO-EXISTE']);

        try {
            $this->controller->create(
                $this->request($body, ['idempotency-key' => 'mv-http-404'])
            );
            $this->fail('Ubicacion inexistente debe devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('movements'));
        $this->assertSame(0, $this->countRows('idempotency_keys'));
    }
}
