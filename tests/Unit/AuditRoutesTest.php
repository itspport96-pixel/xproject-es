<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditMiddleware;
use App\Core\Audit\Http\AuditLogController;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * Registro de rutas de auditoría (spec §7.1):
 *  - GET /api/audit/logs   (audit.log.view) — SÓLO lectura, sin AuditMiddleware
 *  - AuditMiddleware PRIMERO en todas las escrituras platform
 *  - AuditMiddleware PRIMERO en el group() de Inventory (inofensivo en GET:
 *    AuditMiddleware no instala hook en lecturas)
 */
final class AuditRoutesTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $routes;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }

        $router = new Router();
        $router->loadRoutes(BASE_PATH . '/routes/api.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        $this->routes = $prop->getValue($router);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) === $method && $route['path'] === $path) {
                return $route;
            }
        }

        return null;
    }

    public function testRutaDeLecturaDeLaBitacora(): void
    {
        $route = $this->find('GET', '/api/audit/logs');

        $this->assertNotNull($route, 'Falta GET /api/audit/logs');
        $this->assertSame(AuditLogController::class, $route['handler'][0]);
        $this->assertSame('index', $route['handler'][1]);

        $middleware = $route['middleware'];
        $this->assertContains(AuthenticationMiddleware::class, $middleware, 'Auth');
        $this->assertContains(TenantMiddleware::class, $middleware, 'Tenant');
        $this->assertContains(PermissionMiddleware::class . ':audit.log.view', $middleware, 'Permiso');
        $this->assertMiddlewareStartsWith($middleware, RateLimitMiddleware::class, 'RateLimit');

        // Sólo lectura: NO lleva AuditMiddleware (no se auditan GET).
        $this->assertNotContains(AuditMiddleware::class, $middleware);
    }

    public function testNoExisteEndpointDeEscrituraDeAuditoria(): void
    {
        $rutas = array_filter(
            $this->routes,
            static fn(array $r): bool => str_starts_with($r['path'], '/api/audit')
        );

        $paths = array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], $rutas);
        $this->assertSame(['GET /api/audit/logs'], array_values($paths), 'Append-only: sin POST/PUT/DELETE');
    }

    public function testAuditMiddlewareVaPrimeroEnLasEscriturasPlatform(): void
    {
        $escrituras = [
            ['POST', '/api/auth/login'],
            ['POST', '/api/auth/refresh'],
            ['POST', '/api/auth/logout'],
            ['POST', '/api/users'],
            ['PATCH', '/api/users/{id}'],
            ['DELETE', '/api/users/{id}'],
            ['POST', '/api/users/{id}/roles'],
            ['DELETE', '/api/users/{id}/roles/{roleId}'],
            ['POST', '/api/roles'],
            ['PATCH', '/api/roles/{id}'],
            ['DELETE', '/api/roles/{id}'],
            ['PATCH', '/api/roles/{id}/permissions'],
            ['PATCH', '/api/tenant'],
            ['POST', '/api/tenant/activate'],
        ];

        foreach ($escrituras as [$method, $path]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, "Falta {$method} {$path}");
            $this->assertSame(
                AuditMiddleware::class,
                $route['middleware'][0],
                "{$method} {$path}: AuditMiddleware debe ser el PRIMERO (401/403 posterior aún auditado)"
            );
        }
    }

    public function testLasRutasDeLecturaYPingNoSeAuditan(): void
    {
        $sinAuditoria = [
            ['GET', '/api/auth/me'],
            ['POST', '/api/auth/ping'],
            ['GET', '/api/users'],
            ['GET', '/api/roles'],
            ['GET', '/api/tenant'],
        ];

        foreach ($sinAuditoria as [$method, $path]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, "Falta {$method} {$path}");
            $this->assertNotContains(
                AuditMiddleware::class,
                $route['middleware'],
                "{$method} {$path}: no debe auditar"
            );
        }
    }

    public function testAuditMiddlewareVaPrimeroEnElGrupoInventory(): void
    {
        // Muestra de rutas del Sprint 5/6 dentro del group() de Inventory.
        $muestras = [
            ['POST', '/api/inventory/receive'],
            ['POST', '/api/inventory/movements'],
            ['POST', '/api/inventory/reservations'],
            ['POST', '/api/inventory/reservations/{id}/release'],
            ['POST', '/api/inventory/products'],
            ['PATCH', '/api/inventory/products/{id}'],
            ['DELETE', '/api/inventory/products/{id}'],
            ['POST', '/api/inventory/locations'],
        ];

        foreach ($muestras as [$method, $path]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, "Falta {$method} {$path}");
            $this->assertSame(
                AuditMiddleware::class,
                $route['middleware'][0],
                "{$method} {$path}: AuditMiddleware debe ser el PRIMERO"
            );
        }
    }

    public function testAuditMiddlewareVaPrimeroTambienEnLasLecturasDeInventory(): void
    {
        // AuditMiddleware se declara en el group() de Inventory: acompaña a TODAS
        // las rutas (Auth/Tenant también están ahí). Para las lecturas es inofensivo:
        // AuditMiddleware no instala hook en GET (ver AuditMiddlewareTest).
        $lecturas = [
            ['GET', '/api/inventory/stock/summary'],
            ['GET', '/api/inventory/stock'],
            ['GET', '/api/inventory/products'],
            ['GET', '/api/inventory/dashboard/critical'],
        ];

        foreach ($lecturas as [$method, $path]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, "Falta {$method} {$path}");

            $middleware = $route['middleware'];
            $this->assertSame(AuditMiddleware::class, $middleware[0], "{$path}: AuditMiddleware primero");
            $this->assertContains(AuthenticationMiddleware::class, $middleware, "{$path}: Auth");
            $this->assertContains(TenantMiddleware::class, $middleware, "{$path}: Tenant");
            $this->assertMiddlewareStartsWith($middleware, PermissionMiddleware::class, $path);
        }
    }

    private function assertMiddlewareStartsWith(array $middleware, string $prefix, string $context): void
    {
        $found = array_filter(
            $middleware,
            static fn(string $m): bool => str_starts_with($m, $prefix)
        );

        $this->assertNotEmpty($found, "{$context}: falta middleware con prefijo {$prefix}");
    }
}
