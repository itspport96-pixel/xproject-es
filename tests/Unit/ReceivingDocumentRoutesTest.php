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
 * Registro de las rutas del BLOQUE R1 (v1.13.28) en
 * src/Modules/Inventory/routes.php:
 *  - GET    /api/inventory/receiving/documents
 *  - POST   /api/inventory/receiving/documents
 *  - GET    /api/inventory/receiving/documents/{id}
 *  - PATCH  /api/inventory/receiving/documents/{id}
 *  - POST   /api/inventory/receiving/documents/{id}/cancel
 *
 * Las 5 rutas exigen inventory.receiving.plan; Auth + Tenant se aplican
 * UNA sola vez (desde el group) y cada ruta lleva su permiso granular +
 * rate limit.
 */
final class ReceivingDocumentRoutesTest extends TestCase
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

    /**
     * @return array<int, string>
     */
    private function permissionOf(array $route): array
    {
        return array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_contains($m, 'PermissionMiddleware')
        ));
    }

    public function testLasCincoRutasEstanRegistradas(): void
    {
        $expected = [
            ['GET', '/receiving/documents'],
            ['POST', '/receiving/documents'],
            ['GET', '/receiving/documents/{id}'],
            ['PATCH', '/receiving/documents/{id}'],
            ['POST', '/receiving/documents/{id}/cancel'],
        ];

        foreach ($expected as [$method, $suffix]) {
            $route = $this->find($method, $suffix);
            $this->assertNotNull($route, "Falta {$method} /api/inventory{$suffix}");
        }
    }

    public function testTodasLasRutasExigenInventoryReceivingPlan(): void
    {
        $expected = [
            ['GET', '/receiving/documents'],
            ['POST', '/receiving/documents'],
            ['GET', '/receiving/documents/{id}'],
            ['PATCH', '/receiving/documents/{id}'],
            ['POST', '/receiving/documents/{id}/cancel'],
        ];

        foreach ($expected as [$method, $suffix]) {
            $route = $this->find($method, $suffix);
            $this->assertNotNull($route);

            $permissions = $this->permissionOf($route);
            $this->assertCount(1, $permissions, "{$method} {$suffix}: un único permiso");
            $this->assertStringEndsWith(':inventory.receiving.plan', $permissions[0]);
        }
    }

    public function testAmbasRutasDeListadoYCreacionAplicanAuthTenantYRateLimitUnaSolaVez(): void
    {
        foreach ([['GET', '/receiving/documents'], ['POST', '/receiving/documents']] as [$method, $suffix]) {
            $route = $this->find($method, $suffix);
            $this->assertNotNull($route);

            $count = static fn(string $prefix): int => count(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, $prefix)
            ));

            $this->assertSame(1, $count(AuthenticationMiddleware::class), "{$method} {$suffix}: Auth");
            $this->assertSame(1, $count(TenantMiddleware::class), "{$method} {$suffix}: Tenant");
            $this->assertSame(1, $count(RateLimitMiddleware::class), "{$method} {$suffix}: RateLimit");
        }
    }

    public function testLaRutaDeDetalleNoChocaConLaDeListado(): void
    {
        // /receiving/documents/{id} es más específica que /receiving/documents;
        // el Router recorre en orden y la de detalle debe existir aparte.
        $list = $this->find('GET', '/receiving/documents');
        $detail = $this->find('GET', '/receiving/documents/{id}');

        $this->assertNotNull($list);
        $this->assertNotNull($detail);
        $this->assertNotSame($list['path'], $detail['path']);
    }
}
