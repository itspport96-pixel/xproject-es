<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\ReceivingController;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * POST /api/inventory/receive (CU-001 + R7).
 *
 * El controlador sólo añade la capa HTTP sobre el use case:
 *  - 401 sin claims
 *  - 400 si falta/iba mal el header Idempotency-Key (obligatorio en HTTP)
 *  - 201 con la respuesta de la recepción
 *  - replay idéntico 201, payload distinto con la misma key → 409
 */
final class ReceivingControllerTest extends InventorySqliteTestCase
{
    private ReceivingController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ReceivingController($this->useCase);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function claims(): array
    {
        return ['user_id' => 'user-1', 'tenant_id' => self::T1];
    }

    private function request(array $body, array $headers = [], ?array $user = null): array
    {
        return [
            'user' => $user ?? $this->claims(),
            'headers' => $headers,
            'body' => $body,
            'query' => [],
        ];
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => 4,
            'ubicacion_codigo' => 'LOC-01',
        ], $overrides);
    }

    // ====================================================================
    // AUTENTICACIÓN E IDEMPOTENCIA (capa HTTP)
    // ====================================================================

    public function testSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->receive([
                'user' => null,
                'headers' => ['idempotency-key' => 'k1'],
                'body' => $this->body(),
                'query' => [],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
    }

    public function testSinIdempotencyKeyDevuelve400(): void
    {
        try {
            $this->controller->receive($this->request($this->body(), []));
            $this->fail('Sin Idempotency-Key debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }

        $this->assertSame(0, $this->countRows('stock_units'));
    }

    public function testIdempotencyKeyVaciaDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->receive($this->request($this->body(), ['idempotency-key' => '   ']));
    }

    public function testIdempotencyKeyConCaracteresInvalidosDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->receive($this->request($this->body(), ['idempotency-key' => 'clave con espacios!']));
    }

    public function testIdempotencyKeyMasLargaDe64Devuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->receive(
            $this->request($this->body(), ['idempotency-key' => str_repeat('a', 65)])
        );
    }

    public function testHeaderConMayusculasSeReconoce(): void
    {
        $result = $this->controller->receive(
            $this->request($this->body(), ['Idempotency-Key' => 'key-mixed-case'])
        );

        $this->assertSame(201, $result['status']);
        $this->assertArrayHasKey('codigo_barcode', $result['data']);
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    // ====================================================================
    // FLUJO HTTP COMPLETO
    // ====================================================================

    public function testRecepcionExitosaDevuelve201ConUnidadYLedger(): void
    {
        $result = $this->controller->receive(
            $this->request($this->body(['cantidad_recibida' => 4]), ['idempotency-key' => 'http-ok-1'])
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame('SKU-ALFA', $result['data']['sku']);
        $this->assertSame('DISPONIBLE', $result['data']['estado']);
        $this->assertSame(4.0, $result['data']['ledger']['cantidad_disponible']);
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    public function testMismaKeyMismoBodyDevuelveMismaRespuestaSinRepetir(): void
    {
        $headers = ['idempotency-key' => 'http-replay-1'];

        $first = $this->controller->receive($this->request($this->body(), $headers));
        $second = $this->controller->receive($this->request($this->body(), $headers));

        $this->assertSame($first['status'], $second['status']);
        $this->assertSame($first['data'], $second['data']);
        $this->assertSame(1, $this->countRows('stock_units'));
        $this->assertSame(1, $this->countRows('stock_events'));
    }

    /**
     * v1.7.1 (A3): el replay con la misma clave no repite la fila de
     * auditoría de dominio (`receiving.create`) y devuelve la misma respuesta.
     */
    public function testReplayNoRepiteLaAuditoriaDeDominio(): void
    {
        $audit = new RecordingAuditLogger();
        $controller = new ReceivingController($this->useCase, $audit);
        $headers = ['idempotency-key' => 'http-replay-audit'];

        $first = $controller->receive($this->request($this->body(), $headers));
        $this->assertSame(201, $first['status']);
        $this->assertCount(1, $audit->entries);
        $this->assertNotNull($audit->byOperacion('receiving.create'));

        $second = $controller->receive($this->request($this->body(), $headers));

        $this->assertSame($first['status'], $second['status']);
        $this->assertSame($first['data'], $second['data']);
        $this->assertTrue($second['_replay'] ?? false, 'El marcador debe llegar al Router');
        $this->assertCount(1, $audit->entries, 'El replay no debe auditar de nuevo');
        $this->assertSame(1, $this->countRows('stock_units'));
    }

    public function testMismaKeyBodyDistintoDevuelve409(): void
    {
        $headers = ['idempotency-key' => 'http-conflict-1'];

        $this->controller->receive($this->request($this->body(), $headers));

        try {
            $this->controller->receive($this->request($this->body(['cantidad_esperada' => 8]), $headers));
            $this->fail('Mismo key con body distinto debe devolver 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $this->countRows('stock_units'));
    }

    public function testErroresDeNegocioSePropaganConSuCodigo(): void
    {
        try {
            $this->controller->receive(
                $this->request($this->body(['sku' => 'SKU-OFF']), ['idempotency-key' => 'http-inactive'])
            );
            $this->fail('Producto inactivo debe devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // La key no quedó huérfana: el rollback la revirtió
        $this->assertSame(0, $this->countRows('idempotency_keys'));
        $this->assertSame(0, $this->countRows('stock_units'));
    }
}
