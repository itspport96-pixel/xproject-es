<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Tenants\Service\TenantContext;
use App\Shared\Container\Container;

/**
 * AuditLogger - construye, encadena y persiste registros de auditoría (spec §7.1).
 *
 * Garantías:
 *  - Nunca lanza excepciones: si la escritura falla se degrada a error_log
 *    y la petición HTTP continúa con normalidad.
 *  - Append-only: sólo INSERT (vía AuditPort). Sin update()/delete().
 *  - Hash de integridad encadenado por tenant:
 *      hash = SHA256( prev_hash || payload_canónico )
 *    con prev_hash del último registro del MISMO tenant dentro de la transacción.
 *  - Redacción obligatoria de secretos en valores antes/después y detalles.
 */
class AuditLogger
{
    /** Tamaño máximo de los campos JSON persistidos (evita payloads desbordados). */
    private const MAX_JSON_LENGTH = 8192;

    private const TIPOS_CAMBIO = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXECUTE'];
    private const RESULTADOS = ['SUCCESS', 'FAILURE'];

    public function __construct(
        private readonly AuditPort $port,
        private readonly ?TenantContext $tenantContext = null
    ) {
    }

    /**
     * Registra una operación en `audit_logs`.
     *
     * @param array<string, mixed> $entry Campos opcionales: tenant_id, usuario_id,
     *        operacion, modulo, recurso, entidad_id, tipo_cambio,
     *        valores_anteriores, valores_nuevos, resultado, razon_fallo,
     *        ip_address, user_agent, request_id, duracion_operacion,
     *        detalles_adicionales, timestamp
     */
    public function record(array $entry): void
    {
        $operacion = is_string($entry['operacion'] ?? null) ? $entry['operacion'] : 'unknown';

        try {
            $tenantId = $this->resolveTenantId($entry);
            if ($tenantId === null || $tenantId === '') {
                // Sin tenant no hay fila posible (tenant_id NOT NULL + FK).
                // Es lo esperado en peticiones anónimas (login sin deducir
                // tenant, token inválido...): sólo se avisa si además se
                // conoce al usuario, que sí debería haberse atribuido.
                if (!empty($entry['usuario_id'])) {
                    error_log('[Audit] registro omitido (sin tenant_id): ' . $operacion);
                }
                return;
            }

            $row = $this->buildRow($tenantId, $entry);

            $this->port->transaction($tenantId, function () use ($tenantId, $row): bool {
                $prevHash = $this->port->previousHash($tenantId);

                $row['detalles_adicionales'] = $this->encodeDetails($row['detalles_adicionales'], $prevHash);
                $row['hash_integridad'] = self::computeHash($prevHash, $row);

                $this->port->insert($row);

                return true;
            });
        } catch (\Throwable $e) {
            error_log('[Audit] fallo al persistir (' . $operacion . '): ' . $e->getMessage());
        }
    }

    /**
     * Hash de integridad de un registro: SHA256(prev_hash || payload_canónico).
     *
     * El payload canónico es la fila SIN `hash_integridad`, con claves ordenadas
     * recursivamente. Se expone como estático para poder verificar la cadena
     * desde los tests y desde herramientas de consulta.
     *
     * @param array<string, mixed> $row
     */
    public static function computeHash(?string $prevHash, array $row): string
    {
        unset($row['hash_integridad']);

        return hash('sha256', ($prevHash ?? '') . self::canonicalize($row));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function canonicalize(array $row): string
    {
        self::ksortRecursive($row);

        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '' : $json;
    }

    private static function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::ksortRecursive($data[$key]);
            }
        }
    }

    private function resolveTenantId(array $entry): ?string
    {
        $explicit = $entry['tenant_id'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        if ($this->tenantContext !== null) {
            try {
                return $this->tenantContext->getTenantId();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            $container = Container::getInstance();
            if ($container->has(TenantContext::class)) {
                return $container->get(TenantContext::class)->getTenantId();
            }
        } catch (\Throwable) {
            // Sin contexto de tenant disponible.
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildRow(string $tenantId, array $entry): array
    {
        return [
            'id' => $this->generateUuid(),
            'tenant_id' => $tenantId,
            'usuario_id' => $this->nullableString($entry['usuario_id'] ?? null, 36),
            'operacion' => $this->nullableString($entry['operacion'] ?? null, 100),
            'modulo' => $this->nullableString($entry['modulo'] ?? null, 50),
            'recurso' => $this->nullableString($entry['recurso'] ?? null, 100),
            'entidad_id' => $this->nullableString($entry['entidad_id'] ?? null, 64),
            'tipo_cambio' => $this->enum($entry['tipo_cambio'] ?? null, self::TIPOS_CAMBIO, 'EXECUTE'),
            'valores_anteriores' => $this->encodeJson($entry['valores_anteriores'] ?? null),
            'valores_nuevos' => $this->encodeJson($entry['valores_nuevos'] ?? null),
            'resultado' => $this->enum($entry['resultado'] ?? null, self::RESULTADOS, 'SUCCESS'),
            'razon_fallo' => $this->nullableText($entry['razon_fallo'] ?? null),
            'ip_address' => $this->nullableString($entry['ip_address'] ?? null, 45),
            'user_agent' => $this->nullableText($entry['user_agent'] ?? null),
            'request_id' => $this->nullableString($entry['request_id'] ?? null, 64),
            'timestamp' => is_string($entry['timestamp'] ?? null) && $entry['timestamp'] !== ''
                ? (string) $entry['timestamp']
                : self::now(),
            'duracion_operacion' => isset($entry['duracion_operacion']) && $entry['duracion_operacion'] !== null
                ? (int) $entry['duracion_operacion']
                : null,
            'detalles_adicionales' => $entry['detalles_adicionales'] ?? null,
        ];
    }

    /**
     * Serializa los detalles y añade prev_hash al payload (permite reconstruir
     * la cadena sin depender del orden de las filas).
     */
    private function encodeDetails(mixed $details, ?string $prevHash): ?string
    {
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : ['raw' => $details];
        }

        if (!is_array($details)) {
            $details = $details === null ? [] : ['raw' => $details];
        }

        $details['prev_hash'] = $prevHash;

        return $this->encodeJson($details);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $redacted = SecretRedactor::redact($value);
            $json = is_string($redacted) ? $redacted : json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $redacted = SecretRedactor::redact($value);
            $json = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if ($json === false || $json === null) {
            return null;
        }

        if (strlen($json) > self::MAX_JSON_LENGTH) {
            $json = substr($json, 0, self::MAX_JSON_LENGTH) . '…[TRUNCADO]';
        }

        return $json;
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * Marca temporal con microsegundos.
     *
     * OJO: en Windows date('u') devuelve 000000 (bug de timelib/PHP), lo que
     * colapsa toda la columna a resolución de segundo y rompe el orden de la
     * cadena de integridad (previousHash ordena por timestamp). Se usa
     * DateTimeImmutable, que sí reporta microsegundos.
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
