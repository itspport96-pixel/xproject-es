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

    public function __construct(
        string $message = '',
        int $statusCode = 500,
        ?Throwable $previous = null,
        array $headers = [],
        mixed $payload = null
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->payload = $payload;

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
