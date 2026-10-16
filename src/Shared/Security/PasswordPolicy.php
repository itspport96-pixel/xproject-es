<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Política de fortaleza de contraseña (v1.16.0).
 *
 * Reglas únicas y compartidas (backend): UserService y cualquier otro punto
 * que fije una contraseña delegan aquí para no duplicar la lógica. Devuelve
 * la clave i18n de la PRIMERA violación encontrada, o null si es válida.
 *
 *  - Mínimo 9 caracteres
 *  - Al menos 1 mayúscula
 *  - Al menos 1 minúscula
 *  - Al menos 1 número
 *  - Al menos 1 carácter especial (cualquier no alfanumérico: . ! # @ ...)
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 9;

    /** @return string|null Clave i18n de la violación o null si cumple. */
    public static function validate(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'auth.password_min_length';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'auth.password_uppercase';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'auth.password_lowercase';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'auth.password_digit';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'auth.password_special';
        }

        return null;
    }
}
