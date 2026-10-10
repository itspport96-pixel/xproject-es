<?php

declare(strict_types=1);

namespace App\Core\Audit;

/**
 * SecretRedactor - enmascara secretos antes de persistirlos en `audit_logs`.
 *
 * Reglas (spec §7.1: la bitácora nunca guarda claves en claro):
 *  - Claves sensibles (case-insensitive, coincidencia por subcadena): password,
 *    secret, token, api_key, authorization, credencial, clave, bearer, cookie...
 *  - Valores con forma de secreto: JWT (eyJ...), cabeceras "Bearer ..." / "Basic ...".
 *
 * El enmascarado es conservador: si una clave es ambigua se enmascara.
 */
final class SecretRedactor
{
    public const MASK = '[REDACTED]';

    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'apikey',
        'api_key',
        'private_key',
        'authorization',
        'credential',
        'credencial',
        'clave',
        'bearer',
        'cookie',
        'csrf',
        'session_key',
    ];

    private const SENSITIVE_VALUE_PATTERNS = [
        '/^eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]*$/', // JWT
        '/^Bearer\s+\S+/i',
        '/^Basic\s+\S+/i',
    ];

    /**
     * Devuelve $value con todos los secretos enmascarados.
     *
     * @param mixed $value Escalar, array o string JSON
     */
    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $childValue) {
                $out[$childKey] = self::redact(
                    $childValue,
                    is_string($childKey) ? $childKey : null
                );
            }
            return $out;
        }

        if ($key !== null && self::isSensitiveKey($key)) {
            return self::MASK;
        }

        if (is_string($value) && self::isSensitiveValue($value)) {
            return self::MASK;
        }

        return $value;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function isSensitiveValue(string $value): bool
    {
        foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
