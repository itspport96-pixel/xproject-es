<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * Regresión del hallazgo H4 ("inventory aplica Auth+Tenant dos veces").
 * Verificado: el group() de inventory routes.php NO declara middleware de
 * grupo, por lo que cada ruta aplica Auth/Tenant exactamente UNA vez.
 */
final class InventoryRoutesMiddlewareTest extends TestCase
{
    /** @var array<int, array{method: string, path: string, middleware: array<int, string>}> */
    private array $routes;

    protected function setUp(): void
    {
        $router = new Router();
        $router->loadRoutes(dirname(__DIR__, 2) . '/src/Modules/Inventory/routes.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        $this->routes = $prop->getValue($router);
    }

    public function testRoutesAreRegistered(): void
    {
        $this->assertGreaterThanOrEqual(20, count($this->routes));
    }

    public function testAuthMiddlewareAppliedExactlyOncePerRoute(): void
    {
        foreach ($this->routes as $route) {
            $count = count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, AuthenticationMiddleware::class)
            ));
            $this->assertSame(
                1,
                $count,
                "{$route['method']} {$route['path']}: Auth debe aplicarse exactamente 1 vez (aparece {$count})"
            );
        }
    }

    public function testTenantMiddlewareAppliedExactlyOncePerRoute(): void
    {
        foreach ($this->routes as $route) {
            $count = count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, TenantMiddleware::class)
            ));
            $this->assertSame(
                1,
                $count,
                "{$route['method']} {$route['path']}: Tenant debe aplicarse exactamente 1 vez (aparece {$count})"
            );
        }
    }

    public function testEveryRouteHasRateLimitAndPermission(): void
    {
        foreach ($this->routes as $route) {
            $hasRateLimit = count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, RateLimitMiddleware::class)
            )) === 1;
            $hasPermission = count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_contains($m, 'PermissionMiddleware')
            )) >= 1;

            $this->assertTrue($hasRateLimit, "{$route['method']} {$route['path']}: falta RateLimit");
            $this->assertTrue($hasPermission, "{$route['method']} {$route['path']}: falta Permission");
        }
    }
}
