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
 * Registro de las rutas del Sprint 6 en src/Modules/Inventory/routes.php:
 *  - POST /api/inventory/movements                (inventory.movement.create)
 *  - POST /api/inventory/reservations             (inventory.reservation.create)
 *  - POST /api/inventory/reservations/{id}/release (inventory.reservation.cancel)
 *  - GET  /api/inventory/stock/summary            (inventory.stock.view)
 *
 * Auth + Tenant se aplican UNA sola vez (desde el group) y cada ruta lleva
 * su permiso granular + rate limit.
 */
final class InventoryMovementRoutesTest extends TestCase
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
    private function find(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) === $method && $route['path'] === $path) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function permissionsOf(array $route): array
    {
        return array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_contains($m, 'PermissionMiddleware')
        ));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function rutasDelSprint(): array
    {
        return [
            ['POST', '/api/inventory/movements', 'inventory.movement.create'],
            ['POST', '/api/inventory/reservations', 'inventory.reservation.create'],
            ['POST', '/api/inventory/reservations/{id}/release', 'inventory.reservation.cancel'],
            ['GET', '/api/inventory/stock/summary', 'inventory.stock.view'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasDelSprint')]
    public function testRutaRegistradaConSuPermisoGranular(string $method, string $path, string $permiso): void
    {
        $route = $this->find($method, $path);

        $this->assertNotNull($route, "Falta {$method} {$path}");
        $this->assertSame($path, $route['path']);

        $permissions = $this->permissionsOf($route);
        $this->assertCount(1, $permissions, 'Cada ruta debe exigir UN único permiso');
        $this->assertStringEndsWith(':' . $permiso, $permissions[0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasDelSprint')]
    public function testAuthTenantYRateLimitSeAplicanUnaSolaVez(string $method, string $path): void
    {
        $route = $this->find($method, $path);
        $this->assertNotNull($route);

        $count = static fn(string $prefix): int => count(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_starts_with($m, $prefix)
        ));

        $this->assertSame(1, $count(AuthenticationMiddleware::class), "{$path}: Auth");
        $this->assertSame(1, $count(TenantMiddleware::class), "{$path}: Tenant");
        $this->assertSame(1, $count(RateLimitMiddleware::class), "{$path}: RateLimit");
        $this->assertSame(1, $count(PermissionMiddleware::class), "{$path}: permiso único");
    }

    public function testLaRutaStockNoConfundeConStockSummary(): void
    {
        $stock = $this->find('GET', '/api/inventory/stock');
        $summary = $this->find('GET', '/api/inventory/stock/summary');

        $this->assertNotNull($stock, 'Falta GET /api/inventory/stock');
        $this->assertNotNull($summary, 'Falta GET /api/inventory/stock/summary');
        $this->assertNotSame($stock['path'], $summary['path']);
        $this->assertStringEndsWith(':inventory.stock.view', $this->permissionsOf($stock)[0]);
    }

    public function testLasRutasUsanLosControladoresDelSprint6(): void
    {
        $esperado = [
            ['POST', '/api/inventory/movements', \App\Modules\Inventory\Http\MovementController::class, 'create'],
            ['POST', '/api/inventory/reservations', \App\Modules\Inventory\Http\ReservationController::class, 'store'],
            ['POST', '/api/inventory/reservations/{id}/release', \App\Modules\Inventory\Http\ReservationController::class, 'release'],
            ['GET', '/api/inventory/stock/summary', \App\Modules\Inventory\Http\StockController::class, 'summary'],
        ];

        foreach ($esperado as [$method, $path, $controlador, $accion]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, $path);
            $this->assertSame($controlador, $route['handler'][0]);
            $this->assertSame($accion, $route['handler'][1]);
        }
    }

    public function testLosPermisosDelSprint6SonLosDeLaBdViva(): void
    {
        $perfiles = [
            '/api/inventory/movements' => ['inventory', 'movement', 'create'],
            '/api/inventory/reservations' => ['inventory', 'reservation', 'create'],
            '/api/inventory/reservations/{id}/release' => ['inventory', 'reservation', 'cancel'],
        ];

        foreach ($perfiles as $path => [$modulo, $recurso, $accion]) {
            $route = $this->find('POST', $path);
            $this->assertNotNull($route, $path);

            // Identificadores SINGULARES (spec CU-002/CU-003 y BD viva)
            $this->assertStringEndsWith(
                ':' . $modulo . '.' . $recurso . '.' . $accion,
                $this->permissionsOf($route)[0]
            );
            $this->assertStringNotContainsString($recurso . 's.', $this->permissionsOf($route)[0]);
        }
    }
}
