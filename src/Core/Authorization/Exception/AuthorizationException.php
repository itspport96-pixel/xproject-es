<?php

declare(strict_types=1);

namespace App\Core\Authorization\Exception;

use App\Shared\Exceptions\HttpException;

/**
 * Excepción de dominio de autorización.
 *
 * Extiende HttpException para que los códigos ya fijados en los factories
 * (403/404/409) se traduzcan al estado HTTP real en public/index.php; antes
 * era RuntimeException y todos los guards BUILT_IN respondían 500.
 * Los throws "desnudos" (validaciones de entidad) deben llevar el código
 * explícito (400, 403, 409); sin código caen al 500 de HttpException.
 */
final class AuthorizationException extends HttpException
{
    public static function roleNotFound(string $roleId): self
    {
        return (new self("El rol con ID '{$roleId}' no existe.", 404))
            ->withMessageKey('roles.role_not_found', ['id' => $roleId]);
    }

    public static function permissionNotFound(string $permissionId): self
    {
        return (new self("El permiso con ID '{$permissionId}' no existe.", 404))
            ->withMessageKey('roles.permission_not_found', ['id' => $permissionId]);
    }

    public static function roleAlreadyExists(string $nombre): self
    {
        return (new self("El rol '{$nombre}' ya existe.", 409))
            ->withMessageKey('roles.role_already_exists', ['nombre' => $nombre]);
    }

    public static function permissionAlreadyExists(string $identificador): self
    {
        return (new self("El permiso '{$identificador}' ya existe.", 409))
            ->withMessageKey('roles.permission_already_exists', ['identificador' => $identificador]);
    }

    public static function cannotDeleteBuiltInRole(): self
    {
        return (new self("No se puede eliminar un rol BUILT_IN.", 403))
            ->withMessageKey('roles.cannot_delete_built_in');
    }

    public static function cannotModifyBuiltInRole(): self
    {
        return (new self("No se puede modificar un rol BUILT_IN.", 403))
            ->withMessageKey('roles.cannot_modify_built_in');
    }
}
