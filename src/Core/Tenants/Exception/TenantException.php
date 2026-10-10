<?php

declare(strict_types=1);

namespace App\Core\Tenants\Exception;

use RuntimeException;
use Throwable;

/**
 * Excepción personalizada para el dominio de Tenants.
 *
 * Encapsula errores de reglas de negocio, validaciones fallidas
 * o estados inválidos dentro de la entidad Tenant.
 */
final class TenantException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function tenantNotFound(string $tenantId): self
    {
        return new self("El tenant con ID '{$tenantId}' no existe.", 404);
    }

    public static function transitLocationSeedFailed(string $tenantId, string $detail): self
    {
        return new self(
            "No se pudo crear la ubicación TRANSITO del tenant '{$tenantId}': {$detail}",
            500
        );
    }

    public static function nombreInternoAlreadyExists(string $nombreInterno): self
    {
        return new self("El nombre interno '{$nombreInterno}' ya está registrado.", 409);
    }

    public static function dominioCustomAlreadyExists(string $dominio): self
    {
        return new self("El dominio custom '{$dominio}' ya está registrado.", 409);
    }

    public static function ownerNotFound(string $ownerUserId): self
    {
        return new self("El usuario owner con ID '{$ownerUserId}' no existe.", 404);
    }

    public static function invalidEstado(string $estado): self
    {
        return new self("Estado de tenant inválido: '{$estado}'.", 400);
    }

    public static function invalidPlan(string $plan): self
    {
        return new self("Plan de tenant inválido: '{$plan}'.", 400);
    }

    public static function tenantSuspended(string $tenantId): self
    {
        return new self("El tenant '{$tenantId}' se encuentra suspendido.", 403);
    }

    public static function tenantCanceled(string $tenantId): self
    {
        return new self("El tenant '{$tenantId}' se encuentra cancelado.", 403);
    }

    public static function tenantPendingActivation(string $tenantId): self
    {
        return new self("El tenant '{$tenantId}' está pendiente de activación.", 403);
    }

    public static function cannotDeleteTenantWithUsers(): self
    {
        return new self("No se puede eliminar un tenant con usuarios asociados.", 409);
    }

    public static function cannotDeleteOwnerTenant(): self
    {
        return new self("No se puede eliminar el tenant del usuario owner.", 403);
    }

    public static function invalidNombreInterno(string $nombreInterno): self
    {
        return new self("El nombre interno '{$nombreInterno}' es inválido.", 400);
    }

    public static function invalidNombrePublico(string $nombrePublico): self
    {
        return new self("El nombre público es inválido o está vacío.", 400);
    }

    public static function invalidEmailContacto(string $email): self
    {
        return new self("El email de contacto '{$email}' es inválido.", 400);
    }
}
