<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Method not allowed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 405, $previous);
    }
}
