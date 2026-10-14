<?php

declare(strict_types=1);

namespace App\Core\Users\Exception;

use App\Shared\Exceptions\HttpException;
use Throwable;

/**
 * Errores del endpoint de preferencias del propio usuario
 * (GET/PATCH /api/users/me/preferences).
 *
 * Extiende HttpException para que el manejador global de errores
 * devuelva el codigo HTTP real (400/404) en vez de 500.
 * Categorias:
 *  - 400: validacion de valores (idioma, tema)
 *  - 404: usuario no encontrado en el tenant
 */
final class UserPreferencesException extends HttpException
{
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_NOT_FOUND  = 'not_found';

    private string $category;
    private ?string $field;

    public function __construct(
        string $message,
        int $statusCode = 400,
        string $category = self::CATEGORY_VALIDATION,
        ?string $field = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->category = $category;
        $this->field = $field;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public static function userNotFound(string $userId): self
    {
        return (new self(
            "El usuario con ID '{$userId}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('users.user_not_found', ['id' => $userId]);
    }

    public static function invalidValue(string $field, mixed $value, array $permitidos): self
    {
        $render = is_string($value)
            ? "'{$value}'"
            : json_encode($value, JSON_UNESCAPED_UNICODE);

        return (new self(
            "Valor de preferencia inválido en '{$field}': {$render}. Permitidos: " . implode(', ', $permitidos) . '.',
            400,
            self::CATEGORY_VALIDATION,
            $field
        ))->withMessageKey('users.preference_invalid_value', [
            'field' => $field,
            'value' => $render,
            'allowed' => implode(', ', $permitidos),
        ]);
    }
}
