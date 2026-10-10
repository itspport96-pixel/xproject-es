<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditMiddleware;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Core\Users\Http\UserController;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint de preferencias propias (v1.10.3, sprint Correccion v1.10.3-A):
 *  - GET/PATCH /api/users/me/preferences sobre users.preferencias (JSON)
 *  - autenticado si, SIN permiso de modulo (cada usuario lee/scribe las suyas)
 *  - PATCH auditado (AuditMiddleware + auditoria de dominio del controller)
 *  - declarada antes de /api/users/{id} para que "me" no se capture como id
 */
final class UserPreferencesRoutesTest extends TestCase
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

    /**
     * @return array<int, string>
     */
    private function permissionMiddlewares(array $route): array
    {
        return array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_starts_with($m, PermissionMiddleware::class)
        ));
    }

    public function testGetDePreferenciasRegistrado(): void
    {
        $route = $this->find('GET', '/api/users/me/preferences');

        $this->assertNotNull($route, 'Falta GET /api/users/me/preferences');
        $this->assertSame([UserController::class, 'mePreferences'], $route['handler']);

        $middleware = $route['middleware'];
        $this->assertContains(AuthenticationMiddleware::class, $middleware, 'Autenticacion obligatoria');
        $this->assertContains(TenantMiddleware::class, $middleware, 'Tenant desde claims');
        $this->assertContains(RateLimitMiddleware::class . ':api', $middleware, 'Rate limit estandar');
        $this->assertNotContains(AuditMiddleware::class, $middleware, 'Lectura sin auditoria de dominio');
    }

    public function testPatchDePreferenciasRegistradoYAuditado(): void
    {
        $route = $this->find('PATCH', '/api/users/me/preferences');

        $this->assertNotNull($route, 'Falta PATCH /api/users/me/preferences');
        $this->assertSame([UserController::class, 'updateMePreferences'], $route['handler']);

        $middleware = $route['middleware'];
        $this->assertContains(AuditMiddleware::class, $middleware, 'Escritura auditada');
        $this->assertContains(AuthenticationMiddleware::class, $middleware, 'Autenticacion obligatoria');
        $this->assertContains(TenantMiddleware::class, $middleware, 'Tenant desde claims');
        $this->assertContains(RateLimitMiddleware::class . ':api', $middleware, 'Rate limit estandar');
    }

    public function testNoRequierePermisoDeModulo(): void
    {
        $get = $this->find('GET', '/api/users/me/preferences');
        $patch = $this->find('PATCH', '/api/users/me/preferences');

        $this->assertNotNull($get);
        $this->assertNotNull($patch);
        $this->assertSame([], $this->permissionMiddlewares($get), 'GET: sin PermissionMiddleware');
        $this->assertSame([], $this->permissionMiddlewares($patch), 'PATCH: sin PermissionMiddleware');
    }

    public function testRutaEstaticaAntesDeUsersIdParaNoSerCapturada(): void
    {
        $paths = array_column($this->routes, 'path');
        $miGet = array_search('/api/users/me/preferences', $paths, true);
        $usersId = array_search('/api/users/{id}', $paths, true);

        $this->assertNotFalse($miGet, 'GET /api/users/me/preferences ausente');
        $this->assertNotFalse($usersId, 'GET /api/users/{id} ausente');
        $this->assertLessThan($usersId, $miGet, 'La ruta estatica debe declararse antes del patron {id}');

        $patchPaths = [];
        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) === 'PATCH') {
                $patchPaths[] = $route['path'];
            }
        }
        $miPatch = array_search('/api/users/me/preferences', $patchPaths, true);
        $usersIdPatch = array_search('/api/users/{id}', $patchPaths, true);

        $this->assertNotFalse($miPatch, 'PATCH /api/users/me/preferences ausente');
        $this->assertNotFalse($usersIdPatch, 'PATCH /api/users/{id} ausente');
        $this->assertLessThan($usersIdPatch, $miPatch, 'PATCH estatico antes del patron {id}');
    }
}
