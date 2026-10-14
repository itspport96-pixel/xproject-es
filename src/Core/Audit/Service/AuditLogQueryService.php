<?php

declare(strict_types=1);

namespace App\Core\Audit\Service;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;

/**
 * AuditLogQueryService - consulta paginada de `audit_logs` (spec §7.1).
 *
 * Filtros soportados (query string):
 *  - fecha_desde / fecha_hasta → timestamp (acepta YYYY-MM-DD o fecha-hora)
 *  - usuario_id                → id exacto del actor
 *  - accion (alias: operacion)  → operación exacta ("POST /api/inventory/movements")
 *  - entidad  (alias: recurso)  → recurso exacto ("products")
 *  - entidad_id                 → id del recurso afectado
 *  - request_id                 → correlación con X-Request-Id
 *  - resultado                  → SUCCESS | FAILURE
 *  - page / per_page            → paginación (per_page máximo 100)
 *
 * El tenant SIEMPRE se fuerza desde la sesión (aislamiento multi-tenant):
 * el filtro de query string no puede cambiarlo.
 */
final class AuditLogQueryService
{
    private const RESULTADOS = ['SUCCESS', 'FAILURE'];
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly ?EncryptionService $encryption = null
    ) {
    }

    /**
     * @param array<string, mixed> $filters Query string de la petición
     * @return array{summary: array<string, int>, data: array<int, array<string, mixed>>, pagination: array<string, int>}
     * @throws HttpException 400 si un filtro es inválido
     */
    public function search(array $filters, string $tenantId): array
    {
        $page = $this->positiveInt($filters['page'] ?? null, 1);
        $perPage = $this->positiveInt($filters['per_page'] ?? null, self::DEFAULT_PER_PAGE);
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        [$whereSql, $params] = $this->buildWhere($filters, $tenantId);

        $summary = $this->fetchSummary($whereSql, $params);
        $rows = $this->fetchRows($whereSql, $params, $page, $perPage);

        $total = $summary['total'];

        return [
            'summary' => [
                'total' => $total,
                'exitosos' => $summary['exitosos'],
                'fallidos' => $summary['fallidos'],
            ],
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>} SQL WHERE (sin "WHERE") y parámetros
     */
    private function buildWhere(array $filters, string $tenantId): array
    {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (($value = $this->stringFilter($filters, 'fecha_desde')) !== null) {
            $where[] = 'timestamp >= :fecha_desde';
            $params['fecha_desde'] = $this->normalizeDesde($value);
        }

        if (($value = $this->stringFilter($filters, 'fecha_hasta')) !== null) {
            $where[] = 'timestamp <= :fecha_hasta';
            $params['fecha_hasta'] = $this->normalizeHasta($value);
        }

        if (($value = $this->stringFilter($filters, 'usuario_id')) !== null) {
            $where[] = 'usuario_id = :usuario_id';
            $params['usuario_id'] = $value;
        }

        $accion = $this->stringFilter($filters, 'accion') ?? $this->stringFilter($filters, 'operacion');
        if ($accion !== null) {
            $where[] = 'operacion = :operacion';
            $params['operacion'] = $accion;
        }

        $entidad = $this->stringFilter($filters, 'entidad') ?? $this->stringFilter($filters, 'recurso');
        if ($entidad !== null) {
            $where[] = 'recurso = :recurso';
            $params['recurso'] = $entidad;
        }

        if (($value = $this->stringFilter($filters, 'entidad_id')) !== null) {
            $where[] = 'entidad_id = :entidad_id';
            $params['entidad_id'] = $value;
        }

        if (($value = $this->stringFilter($filters, 'request_id')) !== null) {
            $where[] = 'request_id = :request_id';
            $params['request_id'] = $value;
        }

        if (($value = $this->stringFilter($filters, 'resultado')) !== null) {
            $resultado = strtoupper($value);
            if (!in_array($resultado, self::RESULTADOS, true)) {
                throw (new HttpException("Filtro 'resultado' inválido: debe ser SUCCESS o FAILURE", 400))
                    ->withMessageKey('common.filtro_resultado_invalido');
            }
            $where[] = 'resultado = :resultado';
            $params['resultado'] = $resultado;
        }

        return [implode(' AND ', $where), $params];
    }

    private function fetchSummary(string $whereSql, array $params): array
    {
        $sql = "SELECT resultado, COUNT(*) AS total
                  FROM audit_logs
                 WHERE {$whereSql}
                 GROUP BY resultado";

        $summary = ['total' => 0, 'exitosos' => 0, 'fallidos' => 0];

        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $count = (int) $row['total'];
            $summary['total'] += $count;

            if ($row['resultado'] === 'FAILURE') {
                $summary['fallidos'] += $count;
            } else {
                $summary['exitosos'] += $count;
            }
        }

        return $summary;
    }

    private function fetchRows(string $whereSql, array $params, int $page, int $perPage): array
    {
        $sql = "SELECT id, tenant_id, usuario_id, operacion, modulo, recurso, entidad_id,
                       tipo_cambio, valores_anteriores, valores_nuevos, resultado, razon_fallo,
                       ip_address, user_agent, request_id, timestamp, duracion_operacion,
                       detalles_adicionales, hash_integridad
                  FROM audit_logs
                 WHERE {$whereSql}
                 ORDER BY timestamp DESC, id DESC
                 LIMIT :limite OFFSET :offset";

        $stmt = $this->db->getConnection()->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue((string) $key, $value);
        }
        $stmt->bindValue('limite', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    private function present(array $row): array
    {
        $detalles = $this->decodeJson($row['detalles_adicionales'] ?? null);
        $prevHash = null;

        if (is_array($detalles)) {
            $prevHash = $detalles['prev_hash'] ?? null;
            unset($detalles['prev_hash']);
        }

        return [
            'id' => $row['id'],
            'tenant_id' => $row['tenant_id'],
            'usuario_id' => $row['usuario_id'],
            'operacion' => $row['operacion'],
            'modulo' => $row['modulo'],
            'recurso' => $row['recurso'],
            'entidad_id' => $row['entidad_id'],
            'tipo_cambio' => $row['tipo_cambio'],
            'valores_anteriores' => $this->decodeJson($row['valores_anteriores'] ?? null),
            'valores_nuevos' => $this->decodeJson($row['valores_nuevos'] ?? null),
            'resultado' => $row['resultado'],
            'razon_fallo' => $row['razon_fallo'],
            'ip_address' => $this->decryptField($row['ip_address'] ?? null),
            'user_agent' => $this->decryptField($row['user_agent'] ?? null),
            'request_id' => $row['request_id'],
            'timestamp' => $row['timestamp'],
            'duracion_operacion' => $row['duracion_operacion'] === null ? null : (int) $row['duracion_operacion'],
            'detalles_adicionales' => $detalles,
            'prev_hash' => $prevHash,
            'hash_integridad' => $row['hash_integridad'],
        ];
    }

    /**
     * Descifra un campo cifrado con EncryptionService.
     * Si el valor no está cifrado (datos pre-migración), lo devuelve tal cual.
     */
    private function decryptField(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->encryption === null) {
            return $value;
        }

        try {
            return $this->encryption->decrypt($value);
        } catch (\Throwable) {
            // Datos pre-migración (en claro) o valor no cifrado: devolver tal cual.
            return $value;
        }
    }

    private function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function normalizeDesde(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ' 00:00:00.000000';
        }

        return $value;
    }

    private function normalizeHasta(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ' 23:59:59.999999';
        }

        return $value;
    }

    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return $default;
        }

        $int = (int) $value;

        return $int > 0 ? $int : $default;
    }
}
