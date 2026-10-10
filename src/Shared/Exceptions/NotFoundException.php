<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found', ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous);
    }
}
