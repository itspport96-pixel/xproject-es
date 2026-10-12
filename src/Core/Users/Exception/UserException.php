<?php

declare(strict_types=1);

namespace App\Core\Users\Exception;

use App\Shared\Exceptions\HttpException;
use Throwable;

/**
 * Errores de dominio/servicio de usuarios.
 *
 * Extiende HttpException (v1.11.0) para que el manejador global de
 * public/index.php devuelva el codigo HTTP real en vez de 500: cualquier
 * UserException se traduce a un 4xx. Reglas:
 *   - codigo 400..499 explícito -> se respeta (404 no encontrado, 401 credenciales...)
 *   - codigo 0 o fuera de rango -> 400 (validacion de entrada)
 * Ademas expone getField()/getCategory() para el envelope `data` del handler.
 *
 * Categorias alineadas con UserPreferencesException/InventoryException:
 *  - validation: datos de entrada invalidos (400)
 *  - not_found:  recurso inexistente en el tenant (404)
 *  - auth:       credenciales/cuenta (401/403)
 *  - forbidden:  permisos insuficientes (403)
 *  - conflict:   el request choca con el estado actual (409)
 *
 * Ademas, `errorCode` (v1.13.20) es un codigo de maquina que el handler de
 * public/index.php serializa en `data.error_code` para que la UI mapee un
 * mensaje i18n propio (ej. ROLE_NOT_DIRECT); sin codigo es null.
 */
final class UserException extends HttpException
{
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_NOT_FOUND  = 'not_found';
    public const CATEGORY_AUTH       = 'auth';
    public const CATEGORY_FORBIDDEN  = 'forbidden';
    public const CATEGORY_CONFLICT   = 'conflict';

    private ?string $field;
    private string $category;
    private ?string $errorCode;

    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?string $field = null,
        string $category = self::CATEGORY_VALIDATION,
        ?string $errorCode = null
    ) {
        $statusCode = ($code >= 400 && $code <= 499) ? $code : 400;
        parent::__construct($message, $statusCode, $previous);
        $this->field = $field;
        $this->category = $category;
        $this->errorCode = $errorCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /** Codigo de maquina para la UI (data.error_code); null si no aplica. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public static function emailAlreadyExists(string $email): self
    {
        return new self(
            "El email '{$email}' ya está registrado en este tenant.",
            400,
            null,
            'email',
            self::CATEGORY_VALIDATION
        );
    }

    public static function userNotFound(string $userId): self
    {
        return new self(
            "El usuario con ID '{$userId}' no existe.",
            404,
            null,
            'id',
            self::CATEGORY_NOT_FOUND
        );
    }

    public static function requiredField(string $field): self
    {
        return new self(
            "El campo '{$field}' es obligatorio.",
            400,
            null,
            $field,
            self::CATEGORY_VALIDATION
        );
    }

    public static function invalidCredentials(): self
    {
        return new self('Credenciales inválidas.', 401, null, null, self::CATEGORY_AUTH);
    }

    public static function accountSuspended(): self
    {
        return new self('La cuenta de usuario se encuentra suspendida.', 403, null, null, self::CATEGORY_FORBIDDEN);
    }

    public static function accountInactive(): self
    {
        return new self('La cuenta de usuario no está activa.', 403, null, null, self::CATEGORY_FORBIDDEN);
    }

    public static function passwordMismatch(): self
    {
        return new self('La contraseña proporcionada no coincide.', 400, null, 'password', self::CATEGORY_VALIDATION);
    }

    public static function emailNotVerified(): self
    {
        return new self('El email del usuario no ha sido verificado.', 403, null, null, self::CATEGORY_FORBIDDEN);
    }

    /**
     * DELETE /users/{id}/roles/{roleId} sobre un rol que NO esta asignado
     * directamente (viene de un grupo o no hay asignacion): 409 con el
     * codigo de maquina ROLE_NOT_DIRECT, nunca un 200 silencioso.
     */
    public static function roleNotDirect(): self
    {
        return new self(
            'El rol no está asignado directamente al usuario; si es heredado de un grupo, quítalo desde el grupo.',
            409,
            null,
            null,
            self::CATEGORY_CONFLICT,
            'ROLE_NOT_DIRECT'
        );
    }
}
