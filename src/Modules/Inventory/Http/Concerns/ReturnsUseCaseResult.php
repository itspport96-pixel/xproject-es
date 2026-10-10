<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Concerns;

/**
 * Envoltorio HTTP de la respuesta de un use case con idempotencia (v1.7.1).
 *
 * Los use cases marcan la respuesta de replay con `_replay => true` cuando
 * `IdempotencyService::tryBegin()` devuelve la respuesta original ya ejecutada.
 * Este trait hace dos cosas:
 *
 *  1. `isReplay()` → el controller NO escribe su fila de auditoría de dominio
 *     (no se duplica el registro de la operación original).
 *  2. `useCaseResponse()` → reenvía el marcador al Router, que lo retira en
 *     `sendJsonResponse()` (recibe el array por valor) antes de codificar el
 *     body: la respuesta del replay sigue siendo idéntica a la original y
 *     `notifyAuditFinish()` aún puede verlo para que AuditMiddleware no escriba
 *     una fila nueva en `audit_logs`.
 *
 * Ojo: el marcador es INTERNO. No puede colarse en el JSON que ve el cliente.
 */
trait ReturnsUseCaseResult
{
    private const REPLAY_MARKER = '_replay';

    /** true si el use case devolvió un replay idempotente (misma clave). */
    protected function isReplay(array $response): bool
    {
        return (bool) ($response[self::REPLAY_MARKER] ?? false);
    }

    /**
     * @param array<string, mixed> $response resultado del use case
     *
     * @return array{status: int, data: array<string, mixed>, _replay?: bool}
     */
    protected function useCaseResponse(array $response): array
    {
        $result = ['status' => $response['status'], 'data' => $response['data']];

        if ($this->isReplay($response)) {
            $result[self::REPLAY_MARKER] = true;
        }

        return $result;
    }
}
