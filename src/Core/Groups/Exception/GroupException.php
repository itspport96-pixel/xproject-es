<?php

declare(strict_types=1);

namespace App\Core\Groups\Exception;

use App\Shared\Exceptions\HttpException;
use Throwable;

/**
 * Errores de dominio/servicio de grupos (v1.11.0).
 *
 * Mismo contrato que UserException: extiende HttpException para devolver el
 * codigo HTTP real (4xx) y expone getField()/getCategory() para el envelope
 * `data` de public/index.php.
 */
final class GroupException extends HttpException
{
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_NOT_FOUND  = 'not_found';
    public const CATEGORY_CONFLICT   = 'conflict';

    private ?string $field;
    private string $category;

    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?string $field = null,
        string $category = self::CATEGORY_VALIDATION
    ) {
        $statusCode = ($code >= 400 && $code <= 499) ? $code : 400;
        parent::__construct($message, $statusCode, $previous);
        $this->field = $field;
        $this->category = $category;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public static function requiredField(string $field): self
    {
        return (new self(
            "El campo '{$field}' es obligatorio.",
            400,
            null,
            $field,
            self::CATEGORY_VALIDATION
        ))->withMessageKey('groups.required_field', ['field' => $field]);
    }

    public static function invalidField(string $field, string $reason): self
    {
        return new self($reason, 400, null, $field, self::CATEGORY_VALIDATION);
    }

    public static function groupNotFound(string $groupId): self
    {
        return (new self(
            "El grupo con ID '{$groupId}' no existe.",
            404,
            null,
            'id',
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('groups.group_not_found', ['id' => $groupId]);
    }

    public static function userNotFound(string $userId): self
    {
        return (new self(
            "El usuario con ID '{$userId}' no existe en este tenant.",
            404,
            null,
            'user_id',
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('groups.user_not_found', ['id' => $userId]);
    }

    public static function roleNotFound(string $roleId): self
    {
        return (new self(
            "El rol con ID '{$roleId}' no existe.",
            404,
            null,
            'role_id',
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('groups.role_not_found', ['id' => $roleId]);
    }

    public static function nombreDuplicado(string $nombre): self
    {
        return (new self(
            "Ya existe un grupo llamado '{$nombre}' en este tenant.",
            409,
            null,
            'nombre',
            self::CATEGORY_CONFLICT
        ))->withMessageKey('groups.nombre_duplicado', ['nombre' => $nombre]);
    }

    public static function alreadyMember(string $userId): self
    {
        return (new self(
            "El usuario '{$userId}' ya pertenece al grupo.",
            409,
            null,
            'user_id',
            self::CATEGORY_CONFLICT
        ))->withMessageKey('groups.already_member', ['userId' => $userId]);
    }

    public static function notMember(string $userId): self
    {
        return (new self(
            "El usuario '{$userId}' no pertenece al grupo.",
            404,
            null,
            'user_id',
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('groups.not_member', ['userId' => $userId]);
    }

    public static function roleAlreadyAssigned(string $roleId): self
    {
        return (new self(
            "El rol '{$roleId}' ya está asignado al grupo.",
            409,
            null,
            'role_id',
            self::CATEGORY_CONFLICT
        ))->withMessageKey('groups.role_already_assigned', ['roleId' => $roleId]);
    }

    public static function roleNotAssigned(string $roleId): self
    {
        return (new self(
            "El rol '{$roleId}' no está asignado al grupo.",
            404,
            null,
            'role_id',
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('groups.role_not_assigned', ['roleId' => $roleId]);
    }

    public static function idempotencyKeyInvalid(string $reason): self
    {
        return (new self(
            "Idempotency-Key inválida: {$reason}",
            400,
            null,
            'idempotency-key',
            self::CATEGORY_VALIDATION
        ))->withMessageKey('groups.idempotency_key_invalid', ['reason' => $reason]);
    }
}
