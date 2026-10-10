<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Exceptions\HttpException;

/**
 * Middleware que instala UN hook de auditoría (como AuditMiddleware) y guarda
 * cada invocación en $calls. Permite verificar el contrato exacto del hook
 * del Router sin depender de la BD.
 */
final class TestHookMiddleware
{
    /**
     * @var array<int, array{
     *     status: int|null, error: \Throwable|null, result: array, request: array
     * }>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function handle(array $request): array
    {
        $request['audit_finish'] = static function (
            ?int $status,
            ?\Throwable $error = null,
            array $result = [],
            array $finalRequest = []
        ): void {
            self::$calls[] = [
                'status' => $status,
                'error' => $error,
                'result' => $result,
                'request' => $finalRequest,
            ];
        };

        $request['hook_installed'] = true;

        return $request;
    }
}
