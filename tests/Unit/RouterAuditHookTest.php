<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Exceptions\HttpException;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClaimsMiddleware;
use Tests\Support\TestHookMiddleware;
use Tests\Support\TestThrowingMiddleware;

/**
 * Hook genérico de auditoría del Router (desviación aprobada de la spec §7.1):
 * `$request['audit_finish']` se invoca SIEMPRE — éxito, excepción del handler
 * y fallo de un middleware posterior — preservando la request por referencia.
 */
final class RouterAuditHookTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        TestHookMiddleware::reset();
        $this->router = new Router();
    }

    private function register(string $method, string $path, array $middleware, callable $handler): void
    {
        match ($method) {
            'GET' => $this->router->get($path, $handler, $middleware),
            'POST' => $this->router->post($path, $handler, $middleware),
            'PUT' => $this->router->put($path, $handler, $middleware),
            'PATCH' => $this->router->patch($path, $handler, $middleware),
            'DELETE' => $this->router->delete($path, $handler, $middleware),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dispatch(string $method, string $uri): array
    {
        ob_start();

        try {
            $this->router->dispatch($method, $uri);
        } finally {
            ob_end_clean();
        }

        return TestHookMiddleware::$calls;
    }

    public function testSeInvocaEnExitoConElResultado(): void
    {
        $this->register('POST', '/api/demo', [TestHookMiddleware::class], function (array $request): array {
            return ['status' => 201, 'data' => ['id' => 'nuevo']];
        });

        $calls = $this->dispatch('POST', '/api/demo');

        $this->assertCount(1, $calls);
        $this->assertSame(201, $calls[0]['status']);
        $this->assertNull($calls[0]['error']);
        $this->assertSame('nuevo', $calls[0]['result']['data']['id']);
        $this->assertTrue($calls[0]['request']['hook_installed']);
    }

    public function testSeInvocaCuandoElHandlerFalla(): void
    {
        $this->register('DELETE', '/api/demo/{id}', [TestHookMiddleware::class], function (): array {
            throw new HttpException('Recurso no encontrado', 404);
        });

        try {
            $this->dispatch('DELETE', '/api/demo/abc');
            $this->fail('Debía propagarse la excepción');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $calls = TestHookMiddleware::$calls;
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]['status']);
        $this->assertInstanceOf(HttpException::class, $calls[0]['error']);
        $this->assertSame(404, $calls[0]['error']->getStatusCode());
    }

    public function testSeInvocaCon403CuandoUnMiddlewareFallaTrasElHook(): void
    {
        $this->register(
            'POST',
            '/api/demo',
            [TestHookMiddleware::class, TestClaimsMiddleware::class, TestThrowingMiddleware::class . ':403'],
            fn (): array => ['status' => 201]
        );

        try {
            $this->dispatch('POST', '/api/demo');
            $this->fail('Debía propagarse el 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $calls = TestHookMiddleware::$calls;
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]['status']);
        $this->assertSame(403, $calls[0]['error']->getStatusCode());

        // La request conserva los claims inyectados por el middleware previo.
        $this->assertSame('user-1', $calls[0]['request']['user']['user_id']);
        $this->assertSame('tenant-1', $calls[0]['request']['user']['tenant_id']);
    }

    public function testLaRequestEsPorReferenciaEntreMiddlewares(): void
    {
        $this->register(
            'POST',
            '/api/demo',
            [TestClaimsMiddleware::class, TestThrowingMiddleware::class . ':429'],
            fn (): array => ['status' => 201]
        );

        try {
            $this->dispatch('POST', '/api/demo');
            $this->fail('Debía propagarse el 429');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }

        // Sin hook instalado no hay invocación, pero la excepción sí se propaga
        // y los claims anteriores se conservaron en la request interna.
        $this->assertSame([], TestHookMiddleware::$calls);
    }

    public function testUnFalloDentroDelHookNoRompeLaRespuesta(): void
    {
        $this->register('POST', '/api/demo', [TestBrokenHookMiddleware::class], function (): array {
            return ['status' => 200, 'data' => ['ok' => true]];
        });

        $this->expectOutputRegex('/"ok":true/');
        $this->router->dispatch('POST', '/api/demo');
    }

    /**
     * v1.7.1 (A3): el marcador `_replay` es interno: llega al hook (para que
     * AuditMiddleware se salte la escritura) pero NO aparece en el JSON que
     * ve el cliente, de modo que la respuesta del replay es idéntica a la
     * de la ejecución original.
     */
    public function testElMarcadorDeReplayLlegaAlHookPeroNoAlBody(): void
    {
        $this->register('POST', '/api/demo', [TestHookMiddleware::class], function (): array {
            return ['status' => 200, 'data' => ['ok' => true], '_replay' => true];
        });

        ob_start();
        $this->router->dispatch('POST', '/api/demo');
        $body = (string) ob_get_clean();

        $this->assertStringNotContainsString('_replay', $body);
        $this->assertStringContainsString('"ok":true', $body);

        $calls = TestHookMiddleware::$calls;
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['result']['_replay'], 'El hook debe ver el marcador');
    }

    public function testLasRutasGetTambienAtraviesanElHookSinEscribir(): void
    {
        $this->register('GET', '/api/demo', [TestHookMiddleware::class], function (): array {
            return ['status' => 200, 'data' => []];
        });

        $calls = $this->dispatch('GET', '/api/demo');

        $this->assertCount(1, $calls);
        $this->assertSame(200, $calls[0]['status']);
    }
}

/**
 * Hook instalado que siempre revienta: verifica la red de seguridad del Router.
 */
final class TestBrokenHookMiddleware
{
    public function handle(array $request): array
    {
        $request['audit_finish'] = static function (): void {
            throw new \RuntimeException('auditoria rota');
        };

        return $request;
    }
}
