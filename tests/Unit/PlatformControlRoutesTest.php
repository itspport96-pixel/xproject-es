<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditMiddleware;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Http\PermissionController;
use App\Core\Authorization\Http\TemporaryPermissionController;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Groups\Http\GroupController;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Shared\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * Rutas de la capa de control (v1.11.0, spec §4/§5):
 *  - GET /api/permissions[/{id}]      catálogo de solo lectura (§5.2)
 *  - /api/groups (11 rutas)           grupos de usuarios (§4.2)
 *  - /api/temporary-permissions       permisos temporales (§5.3)
 *
 * Verifica handler + middlewares: autenticación, tenant, permiso de ruta
 * exacto, rate limit y auditoría sólo en mutaciones.
 */
final class PlatformControlRoutesTest extends TestCase
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
     * @param array<string, mixed> $route
     * @return array<int, string>
     */
    private function permissionMiddlewares(array $route): array
    {
        return array_values(array_filter(
            $route['middleware'],
            static fn(string $m): bool => str_starts_with($m, PermissionMiddleware::class)
        ));
    }

    /**
     * @param array<string, mixed> $route
     */
    private function assertCommonMiddleware(array $route): void
    {
        $this->assertContains(AuthenticationMiddleware::class, $route['middleware'], 'Autenticacion obligatoria');
        $this->assertContains(TenantMiddleware::class, $route['middleware'], 'Tenant obligatorio');
        $this->assertContains(RateLimitMiddleware::class . ':api', $route['middleware'], 'Rate limit');
    }

    // ====================================================================
    // CATALOGO DE PERMISOS (GET, solo lectura)
    // ====================================================================

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasPermisos')]
    public function testCatalogoPermisosEsDeSoloLectura(string $method, string $path, string $handlerMethod): void
    {
        $route = $this->find($method, $path);

        $this->assertNotNull($route, "Falta {$method} {$path}");
        $this->assertSame(PermissionController::class, $route['handler'][0]);
        $this->assertSame($handlerMethod, $route['handler'][1]);

        $this->assertCommonMiddleware($route);
        $this->assertSame(
            [PermissionMiddleware::class . ':permissions.permission.view'],
            $this->permissionMiddlewares($route),
            'El catalogo exige permissions.permission.view'
        );
        $this->assertNotContains(
            AuditMiddleware::class,
            $route['middleware'],
            'Las lecturas no auditan via AuditMiddleware'
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function rutasPermisos(): array
    {
        return [
            'index' => ['GET', '/api/permissions', 'index'],
            'show' => ['GET', '/api/permissions/{id}', 'show'],
        ];
    }

    public function testCatalogoPermisosNoAdmiteEscrituras(): void
    {
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->assertNull(
                $this->find($method, '/api/permissions'),
                "No debe existir {$method} /api/permissions (los permisos los siembran los seeders)"
            );
            $this->assertNull(
                $this->find($method, '/api/permissions/{id}'),
                "No debe existir {$method} /api/permissions/{id}"
            );
        }
    }

    // ====================================================================
    // GRUPOS (11 rutas, §4.2)
    // ====================================================================

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasGrupos')]
    public function testRutasGrupos(
        string $method,
        string $path,
        string $handlerMethod,
        string $permiso,
        bool $esMutacion
    ): void {
        $route = $this->find($method, $path);

        $this->assertNotNull($route, "Falta {$method} {$path}");
        $this->assertSame(GroupController::class, $route['handler'][0]);
        $this->assertSame($handlerMethod, $route['handler'][1]);

        $this->assertCommonMiddleware($route);
        $this->assertSame(
            [PermissionMiddleware::class . ':' . $permiso],
            $this->permissionMiddlewares($route),
            "Permiso de ruta incorrecto en {$method} {$path}"
        );

        if ($esMutacion) {
            $this->assertContains(AuditMiddleware::class, $route['middleware'], "Falta auditoria en {$method} {$path}");
        } else {
            $this->assertNotContains(AuditMiddleware::class, $route['middleware']);
        }
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function rutasGrupos(): array
    {
        return [
            'GET index' => ['GET', '/api/groups', 'index', 'groups.group.view', false],
            'GET show' => ['GET', '/api/groups/{id}', 'show', 'groups.group.view', false],
            'GET members' => ['GET', '/api/groups/{id}/members', 'members', 'groups.group.view', false],
            'GET roles' => ['GET', '/api/groups/{id}/roles', 'roles', 'groups.group.view', false],
            'POST store' => ['POST', '/api/groups', 'store', 'groups.group.create', true],
            'PATCH update' => ['PATCH', '/api/groups/{id}', 'update', 'groups.group.edit', true],
            'DELETE destroy' => ['DELETE', '/api/groups/{id}', 'destroy', 'groups.group.delete', true],
            'POST addMember' => ['POST', '/api/groups/{id}/members', 'addMember', 'groups.group.manage_members', true],
            'DELETE removeMember' => ['DELETE', '/api/groups/{id}/members/{userId}', 'removeMember', 'groups.group.manage_members', true],
            'POST assignRole' => ['POST', '/api/groups/{id}/roles', 'assignRole', 'groups.group.manage_roles', true],
            'DELETE removeRole' => ['DELETE', '/api/groups/{id}/roles/{roleId}', 'removeRole', 'groups.group.manage_roles', true],
        ];
    }

    public function testGruposSonOnceRutas(): void
    {
        $count = 0;
        foreach ($this->routes as $route) {
            if (str_starts_with((string) $route['path'], '/api/groups')) {
                $count++;
            }
        }

        $this->assertSame(11, $count, 'El bloque GROUPS de routes/api.php debe tener 11 rutas');
    }

    // ====================================================================
    // PERMISOS TEMPORALES (3 rutas, §5.3)
    // ====================================================================

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasTemporales')]
    public function testRutasPermisosTemporales(
        string $method,
        string $path,
        string $handlerMethod,
        string $permiso,
        bool $esMutacion
    ): void {
        $route = $this->find($method, $path);

        $this->assertNotNull($route, "Falta {$method} {$path}");
        $this->assertSame(TemporaryPermissionController::class, $route['handler'][0]);
        $this->assertSame($handlerMethod, $route['handler'][1]);

        $this->assertCommonMiddleware($route);
        $this->assertSame(
            [PermissionMiddleware::class . ':' . $permiso],
            $this->permissionMiddlewares($route),
            "Permiso de ruta incorrecto en {$method} {$path}"
        );

        if ($esMutacion) {
            $this->assertContains(AuditMiddleware::class, $route['middleware'], "Falta auditoria en {$method} {$path}");
        } else {
            $this->assertNotContains(AuditMiddleware::class, $route['middleware']);
        }
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function rutasTemporales(): array
    {
        return [
            'GET index' => ['GET', '/api/temporary-permissions', 'index', 'temporary.permission.view', false],
            'POST store' => ['POST', '/api/temporary-permissions', 'store', 'temporary.permission.grant', true],
            'DELETE revoke' => ['DELETE', '/api/temporary-permissions/{id}', 'revoke', 'temporary.permission.revoke', true],
        ];
    }

    public function testPermisosTemporalesSonTresRutas(): void
    {
        $count = 0;
        foreach ($this->routes as $route) {
            if (str_starts_with((string) $route['path'], '/api/temporary-permissions')) {
                $count++;
            }
        }

        $this->assertSame(3, $count, 'El bloque TEMPORARY PERMISSIONS de routes/api.php debe tener 3 rutas');
    }

    public function testNoHayRutasDeEscrituraDePermisos(): void
    {
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->assertNull(
                $this->find($method, '/api/temporary-permissions/{id}/permissions'),
                "No debe existir {$method} sobre permisos del catalogo"
            );
        }

        $this->assertNull($this->find('POST', '/api/permissions'));
        $this->assertNull($this->find('DELETE', '/api/permissions/{id}'));
    }
}
