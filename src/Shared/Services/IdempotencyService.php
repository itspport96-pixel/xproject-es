<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Exceptions\HttpException;
use PDO;
use PDOException;

/**
 * IdempotencyService (spec R7 - IDEMPOTENCIA).
 *
 * Operación repetida = mismo resultado. La key se INSERTA dentro de la misma
 * transacción que la operación:
 *
 *  1. INSERT de la key.
 *     - OK  → ejecutar la operación y guardar respuesta antes del COMMIT.
 *     - 23000 (duplicado) → leer la fila guardada:
 *         · mismo request_hash  → devolver la respuesta original (replay).
 *         · distinto            → 409 (misma key con payload distinto).
 *  2. Si la transacción hace ROLLBACK, la key se revierte y el cliente
 *     puede reintentar con la misma key.
 *  3. Limpieza: se borran las filas con más de 7 días (mismo patrón que la
 *     limpieza de refresh_tokens).
 *
 * Reutilizable (recepción, movimientos, reservas, envíos): sólo necesita el
 * PDO de la conexión sobre la que corre la operación.
 */
final class IdempotencyService
{
    private const RETENTION_DAYS = 7;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Reserva la key dentro de la transacción activa.
     *
     * @return array<string, mixed>|null null = ejecutar la operación;
     *                                    array = respuesta original (replay)
     *
     * @throws HttpException 409 si la key ya existe con otro payload o sin respuesta
     */
    public function tryBegin(
        string $tenantId,
        string $key,
        string $operacion,
        string $requestHash,
        ?string $actorId = null
    ): ?array {
        $this->cleanup();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO idempotency_keys (id, tenant_id, idempotency_key, operacion, request_hash, actor_id)
                 VALUES (:id, :tenant_id, :idempotency_key, :operacion, :request_hash, :actor_id)'
            );
            $stmt->execute([
                'id' => $this->generateUuid(),
                'tenant_id' => $tenantId,
                'idempotency_key' => $key,
                'operacion' => $operacion,
                'request_hash' => $requestHash,
                'actor_id' => $actorId,
            ]);

            return null;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $row = $this->find($tenantId, $key, $operacion);

        if ($row === null) {
            throw HttpException::conflict('La clave de idempotencia no está disponible, reintente.');
        }

        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw HttpException::conflict(
                'La clave de idempotencia ya fue usada con una carga útil distinta.'
            );
        }

        if ($row['response_json'] === null || $row['http_status'] === null) {
            throw HttpException::conflict('La operación asociada a esta clave sigue en curso.');
        }

        $stored = json_decode((string) $row['response_json'], true);

        return is_array($stored) ? $stored : null;
    }

    /**
     * Persiste la respuesta de la operación antes del COMMIT.
     *
     * @param array<string, mixed> $response envoltorio completo {status, data}
     */
    public function complete(
        string $tenantId,
        string $key,
        string $operacion,
        array $response,
        int $httpStatus
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE idempotency_keys
             SET response_json = :response_json, http_status = :http_status
             WHERE tenant_id = :tenant_id
               AND idempotency_key = :idempotency_key
               AND operacion = :operacion'
        );

        // JSON_PRESERVE_ZERO_FRACTION: 5.0 se guarda como 5.0 (y no como 5),
        // de modo que el replay devuelva exactamente los mismos tipos.
        $stmt->execute([
            'response_json' => json_encode(
                $response,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ),
            'http_status' => $httpStatus,
            'tenant_id' => $tenantId,
            'idempotency_key' => $key,
            'operacion' => $operacion,
        ]);
    }

    /**
     * SHA256 del payload con claves ordenadas de forma canónica, de modo que
     * dos peticiones idénticas (aunque ordenen sus campos distinto) generen
     * el mismo hash.
     */
    public static function requestHash(mixed $payload): string
    {
        return hash('sha256', self::canonicalize($payload));
    }

    // ==================================================================
    // INTERNOS
    // ==================================================================

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $tenantId, string $key, string $operacion): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_hash, response_json, http_status
             FROM idempotency_keys
             WHERE tenant_id = :tenant_id
               AND idempotency_key = :idempotency_key
               AND operacion = :operacion'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'idempotency_key' => $key,
            'operacion' => $operacion,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM idempotency_keys WHERE created_at < :limite'
        );
        $stmt->execute([
            'limite' => date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400),
        ]);
    }

    private static function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(
                    static fn(mixed $item): string => self::canonicalize($item),
                    $value
                )) . ']';
            }

            ksort($value);

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (json_encode((string) $key, JSON_UNESCAPED_UNICODE) ?: '""')
                    . ':' . self::canonicalize($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
        }

        return (string) json_encode($value);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
