<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Authentication failed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}
