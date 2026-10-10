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
use App\Modules\Inventory\Http\ReservationController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Router\Router;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/inventory/reservations (v1.6.2).
 *
 * Registra la ruta con su permiso `inventory.reservation.view`, comprueba
 * middlewares (Auth/Tenant/RateLimit/Permission una sola vez) y verifica el
 * 403 cuando el usuario NO tiene el permiso (y el paso limpio cuando sí).
 */
final class ReservationListRoutesTest extends TestCase
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

    public function testLaRutaGetReservationsExisteConSuPermiso(): void
    {
        $route = $this->find('GET', '/api/inventory/reservations');

        $this->assertNotNull($route, 'Falta GET /api/inventory/reservations');
        $this->assertSame([ReservationController::class, 'index'], $route['handler']);

        $permissions = $this->middlewareOf($route, PermissionMiddleware::class);
        $this->assertCount(1, $permissions, 'Cada ruta debe exigir UN único permiso');
        $this->assertStringEndsWith(':inventory.reservation.view', $permissions[0]);
    }

    public function testAuthTenantRateLimitYPermisoSeAplicanUnaSolaVez(): void
    {
        $route = $this->find('GET', '/api/inventory/reservations');
        $this->assertNotNull($route);

        $this->assertCount(1, $this->middlewareOf($route, AuthenticationMiddleware::class));
        $this->assertCount(1, $this->middlewareOf($route, TenantMiddleware::class));
        $this->assertCount(1, $this->middlewareOf($route, RateLimitMiddleware::class));
        $this->assertCount(1, $this->middlewareOf($route, PermissionMiddleware::class));
    }

    public function testSinElPermisoReservaDevuelve403(): void
    {
        $middleware = new PermissionMiddleware('inventory.reservation.view', $this->auth([]));

        try {
            $middleware->handle($this->request());
            $this->fail('Debía devolver 403 sin inventory.reservation.view');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('inventory.reservation.view', $e->getMessage());
        }
    }

    public function testConElPermisoReservaLaPeticionPasa(): void
    {
        $request = $this->request();
        $middleware = new PermissionMiddleware(
            'inventory.reservation.view',
            $this->auth(['inventory.reservation.view'])
        );

        $this->assertSame($request, $middleware->handle($request));
    }

    public function testElPermisoDeConsultaNoConfundeConCrearNiCancelar(): void
    {
        $view = $this->find('GET', '/api/inventory/reservations');
        $store = $this->find('POST', '/api/inventory/reservations');
        $release = $this->find('POST', '/api/inventory/reservations/{id}/release');

        $this->assertNotNull($view);
        $this->assertNotNull($store);
        $this->assertNotNull($release);

        $this->assertStringEndsWith(
            ':inventory.reservation.view',
            $this->middlewareOf($view, PermissionMiddleware::class)[0]
        );
        $this->assertStringEndsWith(
            ':inventory.reservation.create',
            $this->middlewareOf($store, PermissionMiddleware::class)[0]
        );
        $this->assertStringEndsWith(
            ':inventory.reservation.cancel',
            $this->middlewareOf($release, PermissionMiddleware::class)[0]
        );
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
