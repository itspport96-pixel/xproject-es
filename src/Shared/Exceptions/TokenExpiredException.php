<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;
use Throwable;

final class TokenExpiredException extends RuntimeException
{
    public function __construct(string $message = 'Token expired', int $code = 401, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
