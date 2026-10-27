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
            throw (new HttpException('No autorizado: faltan claims de usuario', 401))->withMessageKey('auth.missing_user_claims');
        }

        $userId = (string) $claims['user_id'];
        $tenantId = (string) $claims['tenant_id'];

        // v1.19.7: MFA verificado por la sesion, si el token lo trae. null
        // cuando el claim no existe (token antiguo) y AuthorizationService
        // aplica la tolerancia de transicion.
        $mfaSesion = array_key_exists('mfa', $claims) ? (bool) $claims['mfa'] : null;

        // Verificar si tiene AL MENOS UNO de los permisos (OR lógico)
        $hasPermission = false;
        $missingPermissions = [];

        foreach ($this->requiredPermissions as $permission) {
            if ($this->auth->hasPermission($userId, $tenantId, $permission, true, $mfaSesion)) {
                $hasPermission = true;
                break;
            }
            $missingPermissions[] = $permission;
        }

        if (!$hasPermission) {
            $permissionList = implode(', ', $this->requiredPermissions);

            // v1.19.7: si el permiso existe para el usuario pero la sesion no
            // verifico MFA, es otra denegacion (mismo 403, clave distinta para
            // el audit y la UI). Con checkMfa=false se comprueba el permiso a
            // secas; sin ese contexto el 403 sigue siendo el de siempre.
            if ($mfaSesion === false
                && $this->auth->hasAnyPermission($userId, $tenantId, $this->requiredPermissions, false)
            ) {
                throw (new HttpException(
                    "Acceso denegado: se requiere una sesión verificada con MFA para: {$permissionList}",
                    403
                ))->withMessageKey('auth.mfa_session_required');
            }

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
