<?php
declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Excepción base para errores de seguridad.
 *
 * Usada por:
 * - EncryptionService (encriptación/desencriptación fallida)
 * - InputSanitizer (validación de entrada)
 * - RateLimiter (límite excedido)
 * - CsrfToken (token inválido)
 * - SecretsVault (secrets management)
 */
class SecurityException extends HttpException
{
    public function __construct(string $message = 'Security error', int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function encryptionFailed(string $reason = ''): self
    {
        return (new self('Encryption failed' . ($reason ? ": {$reason}" : ''), 500))
            ->withMessageKey('security.encryption_failed');
    }

    public static function decryptionFailed(string $reason = ''): self
    {
        return (new self('Decryption failed' . ($reason ? ": {$reason}" : ''), 500))
            ->withMessageKey('security.decryption_failed');
    }

    public static function invalidKey(): self
    {
        return (new self('Invalid encryption key', 500))
            ->withMessageKey('security.invalid_key');
    }

    public static function rateLimitExceeded(int $retryAfter = 60): self
    {
        return (new self("Rate limit exceeded. Retry after {$retryAfter} seconds.", 429))
            ->withMessageKey('security.rate_limit_exceeded', ['retryAfter' => $retryAfter]);
    }

    public static function invalidCsrfToken(): self
    {
        return (new self('Invalid CSRF token', 403))
            ->withMessageKey('security.invalid_csrf_token');
    }

    public static function secretNotFound(string $name = ''): self
    {
        return (new self("Secret not found" . ($name ? ": {$name}" : ''), 404))
            ->withMessageKey('security.secret_not_found');
    }

    public static function unauthorizedAccess(string $resource = ''): self
    {
        return (new self("Unauthorized access" . ($resource ? " to: {$resource}" : ''), 403))
            ->withMessageKey('security.unauthorized_access');
    }
}
