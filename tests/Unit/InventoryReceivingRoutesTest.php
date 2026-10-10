<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * Registro de las rutas nuevas del Sprint 5 en src/Modules/Inventory/routes.php:
 *  - POST /api/inventory/receive  (inventory.receiving.create)
 *  - GET  /api/inventory/stock    (inventory.stock.view)
 *
 * Auth + Tenant se aplican UNA sola vez (desde el group) y cada ruta lleva
 * su permiso granular + rate limit.
 */
final class InventoryReceivingRoutesTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $routes;

    protected function setUp(): void
    {
        $router = new Router();
        $router->loadRoutes(dirname(__DIR__, 2) . '/src/Modules/Inventory/routes.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        $this->routes = $prop->getValue($router);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $method, string $suffix): ?array
    {
        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) === $method && str_ends_with($route['path'], $suffix)) {
                return $route;
            }
        }

        return null;
    }

    public function testRutaReceiveEstaRegistrada(): void
    {
        $route = $this->find('POST', '/receive');

        $this->assertNotNull($route, 'Falta POST /api/inventory/receive');
        $this->assertSame('/api/inventory/receive', $route['path']);
    }

    public function testReceiveRequiereElPermisoDeRecepcion(): void
    {
        $route = $this->find('POST', '/receive');

        $permissions = array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_contains($m, 'PermissionMiddleware')
        ));

        $this->assertCount(1, $permissions);
        $this->assertStringEndsWith(':inventory.receiving.create', $permissions[0]);
    }

    public function testRutaStockEstaRegistrada(): void
    {
        $route = $this->find('GET', '/stock');

        $this->assertNotNull($route, 'Falta GET /api/inventory/stock');
        $this->assertSame('/api/inventory/stock', $route['path']);
    }

    public function testStockRequiereElPermisoDeLectura(): void
    {
        $route = $this->find('GET', '/stock');

        $permissions = array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_contains($m, 'PermissionMiddleware')
        ));

        $this->assertCount(1, $permissions);
        $this->assertStringEndsWith(':inventory.stock.view', $permissions[0]);
    }

    public function testAmbasRutasAplicanAuthTenantYRateLimitUnaSolaVez(): void
    {
        foreach ([['POST', '/receive'], ['GET', '/stock']] as [$method, $suffix]) {
            $route = $this->find($method, $suffix);
            $this->assertNotNull($route);

            $count = static fn(string $prefix): int => count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, $prefix)
            ));

            $this->assertSame(1, $count(AuthenticationMiddleware::class), "{$method} {$suffix}: Auth");
            $this->assertSame(1, $count(TenantMiddleware::class), "{$method} {$suffix}: Tenant");
            $this->assertSame(1, $count(RateLimitMiddleware::class), "{$method} {$suffix}: RateLimit");
            $this->assertSame(1, $count(PermissionMiddleware::class), "{$method} {$suffix}: un único permiso");
        }
    }
}
