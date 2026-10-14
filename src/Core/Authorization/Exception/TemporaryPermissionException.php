<?php

declare(strict_types=1);

namespace App\Core\Authorization\Exception;

use App\Shared\Exceptions\HttpException;

/**
 * Errores de dominio de permisos temporales (spec §5.3, v1.11.0).
 *
 * Expone getField()/getCategory() para que el handler de public/index.php
 * los serialice dentro de data (mismo envelope que UserException/GroupException).
 */
final class TemporaryPermissionException extends HttpException
{
    private ?string $field;
    private ?string $category;

    public function __construct(
        string $message,
        int $statusCode = 400,
        ?string $field = null,
        ?string $category = null
    ) {
        parent::__construct($message, $statusCode);
        $this->field = $field;
        $this->category = $category;
    }

    public static function invalidField(string $field, string $message): self
    {
        return new self($message, 400, $field, 'validation');
    }

    public static function notFound(string $message = 'Permiso temporal no encontrado', ?string $field = null): self
    {
        return (new self($message, 404, $field, 'not_found'))
            ->withMessageKey('temp_perm.not_found');
    }

    public static function conflict(string $message = 'Conflict', ?string $field = null): self
    {
        return new self($message, 409, $field, 'conflict');
    }

    public static function forbidden(string $message = 'Forbidden', ?string $field = null): self
    {
        return new self($message, 403, $field, 'forbidden');
    }

    public static function unauthorized(string $message = 'No autorizado'): self
    {
        return (new self($message, 401, null, 'authentication'))
            ->withMessageKey('common.unauthorized');
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }
}
