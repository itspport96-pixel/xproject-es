<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Modules\Inventory\Http\PickingController;
use App\Modules\Inventory\Http\ShipmentController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Router\Router;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PHPUnit\Framework\TestCase;

/**
 * Rutas de picking y transporte (Sprint 7, v1.7.0).
 *
 * Registra los 8 endpoints nuevos con su permiso, comprueba middlewares
 * (Auth/Tenant/RateLimit/Permission una sola vez) y verifica el 403 cuando
 * el usuario NO tiene el permiso.
 */
final class PickingShipmentRoutesTest extends TestCase
{
    private array $routes;

    /**
     * @var array<int, array{method: string, path: string, handler: array<int, string>, permission: string}>
     */
    private const ESPERADAS = [
        ['method' => 'GET', 'path' => '/api/inventory/picking', 'handler' => [PickingController::class, 'index'], 'permission' => 'inventory.picking.view'],
        ['method' => 'POST', 'path' => '/api/inventory/picking/start', 'handler' => [PickingController::class, 'start'], 'permission' => 'inventory.picking.create'],
        ['method' => 'POST', 'path' => '/api/inventory/picking/{id}/complete', 'handler' => [PickingController::class, 'complete'], 'permission' => 'inventory.picking.confirm'],
        ['method' => 'POST', 'path' => '/api/inventory/picking/{id}/verify', 'handler' => [PickingController::class, 'verify'], 'permission' => 'inventory.picking.verify'],
        ['method' => 'GET', 'path' => '/api/inventory/shipments', 'handler' => [ShipmentController::class, 'index'], 'permission' => 'inventory.shipping.view'],
        ['method' => 'GET', 'path' => '/api/inventory/shipments/{id}', 'handler' => [ShipmentController::class, 'show'], 'permission' => 'inventory.shipping.view'],
        ['method' => 'POST', 'path' => '/api/inventory/shipments/load', 'handler' => [ShipmentController::class, 'load'], 'permission' => 'inventory.shipping.create'],
        ['method' => 'POST', 'path' => '/api/inventory/shipments/authorize', 'handler' => [ShipmentController::class, 'authorize'], 'permission' => 'inventory.shipping.confirm'],
    ];

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $router = new Router();
        $router->loadRoutes(dirname(__DIR__, 2) . '/src/Modules/Inventory/routes.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        $this->routes = $prop->getValue($router);
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
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
    private function middlewareOf(array $route, string $class): array
    {
        return array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_starts_with($m, $class)
        ));
    }

    public function testLasOchoRutasExisteConSuPermiso(): void
    {
        foreach (self::ESPERADAS as $esperada) {
            $route = $this->find($esperada['method'], $esperada['path']);
            $nombre = "{$esperada['method']} {$esperada['path']}";

            $this->assertNotNull($route, "Falta {$nombre}");
            $this->assertSame($esperada['handler'], $route['handler'], "Handler distinto en {$nombre}");

            $permissions = $this->middlewareOf($route, PermissionMiddleware::class);
            $this->assertCount(1, $permissions, "{$nombre}: debe exigir UN único permiso");
            $this->assertStringEndsWith(':' . $esperada['permission'], $permissions[0]);
        }
    }

    public function testMiddlewaresSeAplicanUnaSolaVez(): void
    {
        foreach (self::ESPERADAS as $esperada) {
            $route = $this->find($esperada['method'], $esperada['path']);
            $nombre = "{$esperada['method']} {$esperada['path']}";
            $this->assertNotNull($route, "Falta {$nombre}");

            $this->assertCount(1, $this->middlewareOf($route, AuthenticationMiddleware::class), $nombre);
            $this->assertCount(1, $this->middlewareOf($route, TenantMiddleware::class), $nombre);
            $this->assertCount(1, $this->middlewareOf($route, RateLimitMiddleware::class), $nombre);
            $this->assertCount(1, $this->middlewareOf($route, PermissionMiddleware::class), $nombre);
        }
    }

    public function testElPermisoDeLecturaNoConfundeConElDeEscritura(): void
    {
        $view = $this->find('GET', '/api/inventory/picking');
        $start = $this->find('POST', '/api/inventory/picking/start');
        $shipView = $this->find('GET', '/api/inventory/shipments');
        $load = $this->find('POST', '/api/inventory/shipments/load');

        $this->assertNotNull($view);
        $this->assertNotNull($start);
        $this->assertNotNull($shipView);
        $this->assertNotNull($load);

        $this->assertStringEndsWith(
            ':inventory.picking.view',
            $this->middlewareOf($view, PermissionMiddleware::class)[0]
        );
        $this->assertStringEndsWith(
            ':inventory.picking.create',
            $this->middlewareOf($start, PermissionMiddleware::class)[0]
        );
        $this->assertStringEndsWith(
            ':inventory.shipping.view',
            $this->middlewareOf($shipView, PermissionMiddleware::class)[0]
        );
        $this->assertStringEndsWith(
            ':inventory.shipping.create',
            $this->middlewareOf($load, PermissionMiddleware::class)[0]
        );
    }

    public function testSinElPermisoDevuelve403(): void
    {
        foreach (['inventory.picking.view', 'inventory.picking.verify', 'inventory.shipping.view'] as $permiso) {
            $middleware = new PermissionMiddleware($permiso, $this->auth([]));

            try {
                $middleware->handle($this->request());
                $this->fail("Debía devolver 403 sin {$permiso}");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertStringContainsString($permiso, $e->getMessage());
            }
        }
    }

    public function testConElPermisoLaPeticionPasa(): void
    {
        $request = $this->request();

        foreach (['inventory.picking.view', 'inventory.picking.verify', 'inventory.shipping.view'] as $permiso) {
            $middleware = new PermissionMiddleware($permiso, $this->auth([$permiso]));
            $this->assertSame($request, $middleware->handle($request));
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        return [
            'user' => ['user_id' => 'user-test', 'tenant_id' => 'tenant-test'],
            'tenant' => ['id' => 'tenant-test'],
        ];
    }

    /**
     * AuthorizationService con la caché de permisos precargada (fail-open de
     * Redis simulado): evita depender de MySQL/Redis reales en el test.
     *
     * @param array<int, string> $permissions
     */
    private function auth(array $permissions): AuthorizationService
    {
        $redis = new class(json_encode($permissions)) extends RedisService {
            public function __construct(private readonly string $cached)
            {
                parent::__construct([]);
            }

            public function get(string $key): mixed
            {
                return $this->cached;
            }
        };

        $permissionRepo = $this->createStub(PermissionRepositoryInterface::class);
        $permissionRepo->method('findByIdentificador')->willReturn(null);

        return new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $permissionRepo,
            new DatabaseService([]),
            $redis
        );
    }
}
