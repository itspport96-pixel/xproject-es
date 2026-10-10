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
use App\Modules\Inventory\Http\DeliveryController;
use App\Modules\Inventory\Http\ReturnController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Router\Router;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PHPUnit\Framework\TestCase;

/**
 * Rutas de entregas y devoluciones (v1.8.0, CU-007/CU-008/CU-009).
 *
 * Comprueba que las 5 rutas del Sprint 8 existen con su handler, su permiso
 * y los middlewares (Auth/Tenant/RateLimit/Permission una sola vez), que el
 * permiso de consulta NO confunde con el de escritura y que sin permiso la
 * petición sale con 403.
 */
final class DeliveryReturnRoutesTest extends TestCase
{
    private array $routes;

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

    private function permissionOf(array $route): string
    {
        $permissions = $this->middlewareOf($route, PermissionMiddleware::class);
        $this->assertCount(1, $permissions, 'Cada ruta debe exigir UN único permiso');

        return substr($permissions[0], (int) strrpos($permissions[0], ':') + 1);
    }

    public function testLasCincoRutasDelSprint8Existen(): void
    {
        $esperadas = [
            ['GET', '/api/inventory/deliveries', [DeliveryController::class, 'index'], 'inventory.delivery.view'],
            ['POST', '/api/inventory/deliveries/confirm', [DeliveryController::class, 'confirm'], 'inventory.delivery.confirm'],
            ['POST', '/api/inventory/deliveries/failed', [DeliveryController::class, 'failed'], 'inventory.delivery.confirm'],
            ['GET', '/api/inventory/returns', [ReturnController::class, 'index'], 'inventory.return.view'],
            ['POST', '/api/inventory/returns/register', [ReturnController::class, 'register'], 'inventory.return.create'],
        ];

        foreach ($esperadas as [$method, $path, $handler, $permiso]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route, "Falta {$method} {$path}");
            $this->assertSame($handler, $route['handler'], "Handler incorrecto en {$path}");
            $this->assertSame($permiso, $this->permissionOf($route), "Permiso incorrecto en {$path}");
        }
    }

    public function testAuthTenantRateLimitYPermisoSeAplicanUnaSolaVez(): void
    {
        foreach ([
            ['GET', '/api/inventory/deliveries'],
            ['POST', '/api/inventory/deliveries/confirm'],
            ['POST', '/api/inventory/deliveries/failed'],
            ['GET', '/api/inventory/returns'],
            ['POST', '/api/inventory/returns/register'],
        ] as [$method, $path]) {
            $route = $this->find($method, $path);
            $this->assertNotNull($route);

            $this->assertCount(1, $this->middlewareOf($route, AuthenticationMiddleware::class), $path);
            $this->assertCount(1, $this->middlewareOf($route, TenantMiddleware::class), $path);
            $this->assertCount(1, $this->middlewareOf($route, RateLimitMiddleware::class), $path);
            $this->assertCount(1, $this->middlewareOf($route, PermissionMiddleware::class), $path);
        }
    }

    public function testElPermisoDeLecturaNoConfundeConElDeEscritura(): void
    {
        $listDeliveries = $this->find('GET', '/api/inventory/deliveries');
        $listReturns = $this->find('GET', '/api/inventory/returns');
        $confirm = $this->find('POST', '/api/inventory/deliveries/confirm');
        $failed = $this->find('POST', '/api/inventory/deliveries/failed');
        $register = $this->find('POST', '/api/inventory/returns/register');

        $this->assertSame('inventory.delivery.view', $this->permissionOf($listDeliveries));
        $this->assertSame('inventory.return.view', $this->permissionOf($listReturns));
        $this->assertSame('inventory.delivery.confirm', $this->permissionOf($confirm));
        $this->assertSame('inventory.delivery.confirm', $this->permissionOf($failed));
        $this->assertSame('inventory.return.create', $this->permissionOf($register));
    }

    public function testSinElPermisoCorrespondienteDevuelve403(): void
    {
        $casos = [
            ['inventory.delivery.view', '/api/inventory/deliveries'],
            ['inventory.delivery.confirm', '/api/inventory/deliveries/confirm'],
            ['inventory.return.view', '/api/inventory/returns'],
            ['inventory.return.create', '/api/inventory/returns/register'],
        ];

        foreach ($casos as [$permiso, $ruta]) {
            $middleware = new PermissionMiddleware($permiso, $this->auth([]));

            try {
                $middleware->handle($this->request());
                $this->fail("Debía devolver 403 sin {$permiso} ({$ruta})");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $ruta);
                $this->assertStringContainsString($permiso, $e->getMessage());
            }
        }
    }

    public function testConElPermisoLaPeticionPasa(): void
    {
        $request = $this->request();
        $middleware = new PermissionMiddleware('inventory.delivery.confirm', $this->auth(['inventory.delivery.confirm']));

        $this->assertSame($request, $middleware->handle($request));
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
