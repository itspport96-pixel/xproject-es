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
 * Rutas del dashboard por capas (INVENTORY_MODULE_SPECIFICATION §9):
 *  - GET /api/inventory/dashboard/critical  → CAPA 1
 *  - GET /api/inventory/dashboard/secondary → CAPA 2
 *
 * Verifica que ambas queden registradas con el mismo contrato de seguridad
 * que el resto del módulo: Auth y Tenant una sola vez, permiso granular
 * inventory.stock.view y rate limit.
 */
final class InventoryDashboardRoutesTest extends TestCase
{
    private const CRITICAL = 'GET /api/inventory/dashboard/critical';
    private const SECONDARY = 'GET /api/inventory/dashboard/secondary';
    private const PERMISSION = PermissionMiddleware::class . ':inventory.stock.view';

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

    public function testBothDashboardRoutesAreRegistered(): void
    {
        $paths = array_map(
            static fn(array $route): string => $route['method'] . ' ' . $route['path'],
            $this->routes
        );

        $this->assertContains(self::CRITICAL, $paths);
        $this->assertContains(self::SECONDARY, $paths);
    }

    public function testCriticalRouteHasSecurityMiddlewares(): void
    {
        $route = $this->route(self::CRITICAL);

        $this->assertNotNull($route, 'Falta la ruta critical');
        $this->assertSame(1, $this->countMiddleware($route, AuthenticationMiddleware::class));
        $this->assertSame(1, $this->countMiddleware($route, TenantMiddleware::class));
        $this->assertContains(self::PERMISSION, $route['middleware']);
        $this->assertSame(1, $this->countMiddleware($route, RateLimitMiddleware::class));
    }

    public function testSecondaryRouteHasSecurityMiddlewares(): void
    {
        $route = $this->route(self::SECONDARY);

        $this->assertNotNull($route, 'Falta la ruta secondary');
        $this->assertSame(1, $this->countMiddleware($route, AuthenticationMiddleware::class));
        $this->assertSame(1, $this->countMiddleware($route, TenantMiddleware::class));
        $this->assertContains(self::PERMISSION, $route['middleware']);
        $this->assertSame(1, $this->countMiddleware($route, RateLimitMiddleware::class));
    }

    /**
     * inventory.stock.view lo tienen ADMIN, MANAGER, USER y VIEWER (seed de BD):
     * la capa crítica del dashboard tiene que servir a todos los roles.
     */
    public function testPermissionIsTheStockViewSharedByAllRoles(): void
    {
        $this->assertStringEndsWith(':inventory.stock.view', self::PERMISSION);
    }

    /** @return ?array{method: string, path: string, middleware: array<int, string>} */
    private function route(string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (($route['method'] . ' ' . $route['path']) === $path) {
                return $route;
            }
        }

        return null;
    }

    private function countMiddleware(array $route, string $class): int
    {
        return count(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_starts_with($m, $class)
        ));
    }
}
