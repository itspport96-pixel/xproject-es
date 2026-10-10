<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Exceptions\HttpException;

/**
 * Middleware que falla siempre con el código dado (parámetro de ruta),
 * simulando un 403 de PermissionMiddleware DESPUÉS de que el hook de
 * auditoría ya esté instalado.
 */
final class TestThrowingMiddleware
{
    public function __construct(private readonly string $status = '403')
    {
    }

    public function handle(array $request): array
    {
        $code = (int) $this->status;

        throw new HttpException(
            'Acceso denegado: se requiere el permiso demo.view',
            $code
        );
    }
}
