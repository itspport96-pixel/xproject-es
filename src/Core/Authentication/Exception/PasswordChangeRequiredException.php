<?php

declare(strict_types=1);

namespace App\Core\Authentication\Exception;

use App\Shared\Exceptions\HttpException;
use Throwable;

/**
 * El usuario debe cambiar su contraseña antes de continuar (v1.11.0).
 *
 * Se lanza desde AuthenticationMiddleware cuando users.password_change_forced
 * está marcada (alta con password temporal o reset de admin) o
 * users.fecha_expiracion_password está vencida (caducidad) y la ruta
 * solicitada no está en la allowlist de cambio forzado. El manejador global de
 * public/index.php devuelve el 403 con category=password_change_required, que
 * el frontend interpreta como redirección al formulario de cambio de contraseña.
 */
final class PasswordChangeRequiredException extends HttpException
{
    public const CATEGORY = 'password_change_required';

    public function __construct(
        string $message = 'Debes cambiar tu contraseña antes de continuar.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 403, $previous, [], null, 'auth.password_change_required');
    }

    public function getField(): string
    {
        return 'password';
    }

    public function getCategory(): string
    {
        return self::CATEGORY;
    }
}
