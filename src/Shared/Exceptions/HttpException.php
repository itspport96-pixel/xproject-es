<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    private int $statusCode;
    private array $headers;
    private mixed $payload;
    private ?string $messageKey;
    private array $messageParams;

    public function __construct(
        string $message = '',
        int $statusCode = 500,
        ?Throwable $previous = null,
        array $headers = [],
        mixed $payload = null,
        ?string $messageKey = null,
        array $messageParams = []
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->payload = $payload;
        $this->messageKey = $messageKey;
        $this->messageParams = $messageParams;

        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /** Clave del catalogo api_errors (null = mensaje hardcodeado sin i18n). */
    public function getMessageKey(): ?string
    {
        return $this->messageKey;
    }

    /** Placeholders {nombre} para el messageKey. */
    public function getMessageParams(): array
    {
        return $this->messageParams;
    }

    /** Asocia messageKey + params (fluent, para factories). */
    public function withMessageKey(string $key, array $params = []): static
    {
        $this->messageKey = $key;
        $this->messageParams = $params;
        return $this;
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function badRequest(string $message = 'Bad Request', mixed $payload = null): self
    {
        return new self($message, 400, null, [], $payload);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return new self($message, 405);
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new self($message, 409);
    }

    public static function unprocessable(string $message = 'Unprocessable Entity', mixed $payload = null): self
    {
        return new self($message, 422, null, [], $payload);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests'): self
    {
        return new self($message, 429);
    }

    public static function internal(string $message = 'Internal Server Error'): self
    {
        return new self($message, 500);
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable'): self
    {
        return new self($message, 503);
    }
}
