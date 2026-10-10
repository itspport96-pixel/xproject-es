<?php

declare(strict_types=1);

namespace App\Core\Authorization\Middleware;

use App\Core\Authorization\Exception\AuthorizationException;
use App\Core\Authorization\Service\AuthorizationService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Middleware de autorización basado en permisos.
 *
 * Verifica que el usuario autenticado tenga el permiso requerido
 * para acceder a la ruta protegida.
 *
 * Uso en rutas:
 *   'middleware' => [
 *       AuthenticationMiddleware::class,
 *       PermissionMiddleware::class . ':inventory.item.create'
 *   ]
 *
 * También soporta múltiples permisos (OR lógico):
 *   PermissionMiddleware::class . ':inventory.item.create,inventory.item.edit'
 */
final class PermissionMiddleware
{
    private AuthorizationService $auth;
    private array $requiredPermissions;

    /**
     * @param string|null $permissions Coma-separada de permisos requeridos (OR lógico)
     */
    public function __construct(?string $permissions = null, ?AuthorizationService $auth = null)
    {
        $container = Container::getInstance();
        $this->auth = $auth ?? $container->get(AuthorizationService::class);

        if ($permissions === null || $permissions === '') {
            throw new \InvalidArgumentException('PermissionMiddleware requiere al menos un permiso');
        }

        $this->requiredPermissions = array_map('trim', explode(',', $permissions));
    }

    /**
     * Verifica que el usuario tenga al menos uno de los permisos requeridos.
     *
     * @param array $request Request con claims del usuario (inyectados por AuthenticationMiddleware)
     * @return array Request sin modificar si tiene permiso
     * @throws HttpException 401 si no está autenticado
     * @throws HttpException 403 si no tiene el permiso requerido
     */
    public function handle(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado: faltan claims de usuario', 401);
        }

        $userId = (string) $claims['user_id'];
        $tenantId = (string) $claims['tenant_id'];

        // Verificar si tiene AL MENOS UNO de los permisos (OR lógico)
        $hasPermission = false;
        $missingPermissions = [];

        foreach ($this->requiredPermissions as $permission) {
            if ($this->auth->hasPermission($userId, $tenantId, $permission)) {
                $hasPermission = true;
                break;
            }
            $missingPermissions[] = $permission;
        }

        if (!$hasPermission) {
            $permissionList = implode(', ', $this->requiredPermissions);
            throw new HttpException(
                "Acceso denegado: se requiere uno de los siguientes permisos: {$permissionList}",
                403
            );
        }

        return $request;
    }

    /**
     * Factory method para crear el middleware desde el router con sintaxis simplificada.
     *
     * Ejemplo: PermissionMiddleware::create('inventory.item.create,inventory.item.edit')
     */
    public static function create(string $permissions, ?AuthorizationService $auth = null): self
    {
        return new self($permissions, $auth);
    }
}
