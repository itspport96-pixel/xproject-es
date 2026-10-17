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
 * Ruta del reset de MFA por el admin (v1.17.14).
 *
 *  - POST /api/users/{id}/mfa/reset apunta a UserController::resetMfa.
 *  - AuditMiddleware PRIMERO (escritura platform).
 *  - Exige el permiso nuevo users.mfa.reset (sembrado en
 *    PlatformControlPermissionSeeder, sólo rol ADMIN).
 */
final class UserMfaResetRouteTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $route = null;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }

        $router = new Router();
        $router->loadRoutes(BASE_PATH . '/routes/api.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);

        foreach ($prop->getValue($router) as $route) {
            if (
                strtoupper((string) $route['method']) === 'POST'
                && $route['path'] === '/api/users/{id}/mfa/reset'
            ) {
                $this->route = $route;
                break;
            }
        }
    }

    public function testLaRutaExisteApuntaAlControllerYExigeElPermiso(): void
    {
        $this->assertNotNull($this->route, 'Falta POST /api/users/{id}/mfa/reset');
        $this->assertSame(UserController::class, $this->route['handler'][0]);
        $this->assertSame('resetMfa', $this->route['handler'][1]);

        $middleware = $this->route['middleware'];

        $this->assertSame(AuditMiddleware::class, $middleware[0], 'AuditMiddleware debe ser el PRIMERO');
        $this->assertContains(AuthenticationMiddleware::class, $middleware, 'Auth');
        $this->assertContains(TenantMiddleware::class, $middleware, 'Tenant');
        $this->assertContains(PermissionMiddleware::class . ':users.mfa.reset', $middleware, 'Permiso');

        $rateLimit = array_filter(
            $middleware,
            static fn(string $m): bool => str_starts_with($m, RateLimitMiddleware::class)
        );
        $this->assertNotEmpty($rateLimit, 'RateLimit ausente');
    }
}
