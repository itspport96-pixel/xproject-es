<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditMiddleware;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * AuditMiddleware: sólo escrituras, request_id, derivación de módulo/recurso
 * y fallo silencioso cuando no hay logger disponible.
 */
final class AuditMiddlewareTest extends TestCase
{
    private RecordingAuditLogger $logger;
    private AuditMiddleware $middleware;

    protected function setUp(): void
    {
        $this->logger = new RecordingAuditLogger();
        $this->middleware = new AuditMiddleware($this->logger);
    }

    /**
     * @return array<string, mixed>
     */
    private function postRequest(array $overrides = []): array
    {
        return $overrides + [
            'method' => 'POST',
            'path' => '/api/inventory/products',
            'headers' => [],
            'query' => [],
            'body' => ['sku' => 'SKU-1'],
            'params' => [],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ];
    }

    public function testNoInstalaHookEnLecturas(): void
    {
        $request = $this->middleware->handle($this->postRequest(['method' => 'GET']));

        $this->assertArrayNotHasKey('audit_finish', $request);
        $this->assertArrayNotHasKey('request_id', $request);
    }

    public function testInstalaHookYRequestIdEnEscrituras(): void
    {
        $request = $this->middleware->handle($this->postRequest());

        $this->assertArrayHasKey('audit_finish', $request);
        $this->assertIsCallable($request['audit_finish']);
        $this->assertNotEmpty($request['request_id']);
    }

    public function testReutilizaElRequestIdDeCabecera(): void
    {
        $request = $this->middleware->handle($this->postRequest([
            'headers' => ['x-request-id' => 'req-custom-1'],
        ]));

        $this->assertSame('req-custom-1', $request['request_id']);

        $finish = $request['audit_finish'];
        $finish(201, null, ['status' => 201], $request);

        $this->assertSame('req-custom-1', $this->logger->entries[0]['request_id']);
    }

    public function testRegistraExitoConClaimsDerivados(): void
    {
        $request = $this->middleware->handle($this->postRequest([
            'params' => ['id' => 'prod-123'],
            'headers' => ['idempotency-key' => 'key-1'],
            'query' => ['x' => 'y'],
        ]));

        $finalRequest = $request + [
            'user' => ['user_id' => 'user-1', 'tenant_id' => 'tenant-1'],
        ];

        $finish = $request['audit_finish'];
        $finish(201, null, ['status' => 201, 'data' => []], $finalRequest);

        $entry = $this->logger->entries[0];
        $this->assertSame('POST /api/inventory/products', $entry['operacion']);
        $this->assertSame('inventory', $entry['modulo']);
        $this->assertSame('products', $entry['recurso']);
        $this->assertSame('prod-123', $entry['entidad_id']);
        $this->assertSame('EXECUTE', $entry['tipo_cambio']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertNull($entry['razon_fallo']);
        $this->assertSame('tenant-1', $entry['tenant_id']);
        $this->assertSame('user-1', $entry['usuario_id']);
        $this->assertSame('127.0.0.1', $entry['ip_address']);
        $this->assertSame(['sku' => 'SKU-1'], $entry['valores_nuevos']);
        $this->assertIsInt($entry['duracion_operacion']);
        $this->assertGreaterThanOrEqual(0, $entry['duracion_operacion']);

        $detalles = $entry['detalles_adicionales'];
        $this->assertSame('POST', $detalles['metodo_http']);
        $this->assertSame('/api/inventory/products', $detalles['ruta']);
        $this->assertSame(201, $detalles['codigo_respuesta']);
        $this->assertSame('key-1', $detalles['idempotency_key']);
        $this->assertSame(['x' => 'y'], $detalles['query']);
    }

    public function testRegistraFalloConExcepcionHttp(): void
    {
        $request = $this->middleware->handle($this->postRequest([
            'method' => 'DELETE',
            'path' => '/api/inventory/products',
        ]));
        $request += ['user' => ['user_id' => 'user-9', 'tenant_id' => 'tenant-1']];

        $finish = $request['audit_finish'];
        $finish(null, new HttpException('Acceso denegado: se requiere el permiso product.delete', 403), [], $request);

        $entry = $this->logger->entries[0];
        $this->assertSame('FAILURE', $entry['resultado']);
        $this->assertStringContainsString('Acceso denegado', (string) $entry['razon_fallo']);
        $this->assertSame('DELETE', $entry['tipo_cambio']);
        $this->assertNull($entry['valores_nuevos'], 'DELETE no envía body nuevo');
        $this->assertIsInt($entry['duracion_operacion']);
    }

    public function testTipoCambioSegunMetodo(): void
    {
        $esperado = ['PUT' => 'UPDATE', 'PATCH' => 'UPDATE', 'POST' => 'EXECUTE'];

        foreach ($esperado as $method => $tipo) {
            $this->logger->reset();
            $request = $this->middleware->handle($this->postRequest(['method' => $method]));
            $finish = $request['audit_finish'];
            $finish(200, null, [], $request);

            $this->assertSame($tipo, $this->logger->entries[0]['tipo_cambio'], $method);
        }
    }

    /**
     * v1.7.1 (A3): replay idempotente. El handler marca la respuesta con
     * `_replay` (Router la retira del body) y el hook NO escribe ninguna fila
     * nueva, ni en el éxito ni en el fallo: la operación ya está auditada por
     * la ejecución original.
     */
    public function testReplayNoEscribeFilaDeAuditoria(): void
    {
        $request = $this->middleware->handle($this->postRequest([
            'headers' => ['idempotency-key' => 'key-replay'],
        ]));
        $request += ['user' => ['user_id' => 'user-1', 'tenant_id' => 'tenant-1']];

        $finish = $request['audit_finish'];
        $finish(201, null, ['status' => 201, 'data' => []], $request);
        $this->assertCount(1, $this->logger->entries, 'La primera ejecución sí audita');

        // Replay con éxito
        $finish(201, null, ['status' => 201, 'data' => [], '_replay' => true], $request);
        // Replay con error (409 del mismo payload)
        $finish(
            null,
            new HttpException('La clave de idempotencia ya fue usada con otra carga útil.', 409),
            ['_replay' => true],
            $request
        );

        $this->assertCount(1, $this->logger->entries, 'El replay no debe añadir filas');
    }

    public function testSinLoggerNoInstalaHook(): void
    {
        Container::resetInstance();

        $middleware = new AuditMiddleware();
        $request = $middleware->handle($this->postRequest());

        $this->assertArrayNotHasKey('audit_finish', $request);
    }
}
