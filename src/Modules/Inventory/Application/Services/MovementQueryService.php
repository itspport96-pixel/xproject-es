<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\Movement;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de movimientos (GET /api/inventory/movements) - R3, v1.10.0.
 *
 * Listado paginado de `movements` con filtros de unidad física, ubicaciones,
 * motivo, correcciones y rango de fechas, más un summary (total y nº de
 * correcciones E8).
 *
 * Sólo lectura: mismo patrón que IncidentQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class MovementQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Movimientos del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters unidad_fisica_id,
     *        ubicacion_origen_id, ubicacion_destino_id, motivo, es_correccion,
     *        fecha_desde, fecha_hasta
     *
     * @return array{
     *     summary: array<string, int>,
     *     data: array<int, array<string, mixed>>,
     *     pagination: array<string, int|bool>
     * }
     *
     * @throws InventoryException Si un filtro no es válido (400 con `field`)
     */
    public function list(string $tenantId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($tenantId, $filters);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(m.es_correccion = 1), 0) AS correcciones
               FROM movements m
              WHERE {$where}"
        );
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT m.id,
                   m.unidad_fisica_id,
                   u.codigo_barcode,
                   m.cantidad,
                   m.ubicacion_origen_id,
                   lo.codigo AS ubicacion_origen_codigo,
                   m.ubicacion_destino_id,
                   ld.codigo AS ubicacion_destino_codigo,
                   m.motivo,
                   m.actor_id,
                   m.referencia_externa,
                   m.timestamp_movimiento,
                   m.hash_integridad,
                   m.es_correccion,
                   m.movimiento_referencia_id
              FROM movements m
              LEFT JOIN stock_units u ON u.id = m.unidad_fisica_id AND u.tenant_id = m.tenant_id
              LEFT JOIN locations lo ON lo.id = m.ubicacion_origen_id AND lo.tenant_id = m.tenant_id
              LEFT JOIN locations ld ON ld.id = m.ubicacion_destino_id AND ld.tenant_id = m.tenant_id
              WHERE {$where}
              ORDER BY m.timestamp_movimiento DESC, m.id DESC
              LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $total = (int) ($summary['total'] ?? 0);

        return [
            'summary' => [
                'total' => $total,
                'correcciones' => (int) ($summary['correcciones'] ?? 0),
            ],
            'data' => array_map(
                fn(array $row): array => $this->mapRow($row),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            ),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, array $filters): array
    {
        $where = ['m.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        foreach (['unidad_fisica_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'motivo'] as $column) {
            if (!empty($filters[$column])) {
                $value = trim((string) $filters[$column]);

                if ($column === 'motivo') {
                    $value = mb_strtoupper($value);
                }

                $where[] = "m.{$column} = :{$column}";
                $params[$column] = $value;
            }
        }

        if (isset($filters['es_correccion']) && $filters['es_correccion'] !== null && $filters['es_correccion'] !== '') {
            $raw = $filters['es_correccion'];

            if (is_string($raw) && !in_array(mb_strtolower($raw), ['0', '1', 'true', 'false'], true)) {
                throw InventoryException::invalidField('es_correccion', 'Debe ser 0/1 (o false/true).');
            }

            $where[] = 'm.es_correccion = :es_correccion';
            $params['es_correccion'] = (int) (bool) $raw;
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'm.timestamp_movimiento >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate('fecha_desde', (string) $filters['fecha_desde'], false);
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'm.timestamp_movimiento <= :fecha_hasta';
            $params['fecha_hasta'] = $this->assertDate('fecha_hasta', (string) $filters['fecha_hasta'], true);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'movimiento_id' => $row['id'],
            'unidad_fisica_id' => $row['unidad_fisica_id'],
            'codigo_barcode' => $row['codigo_barcode'] ?? null,
            'cantidad' => (float) $row['cantidad'],
            'ubicacion_origen_id' => $row['ubicacion_origen_id'] ?? null,
            'ubicacion_origen_codigo' => $row['ubicacion_origen_codigo'] ?? null,
            'ubicacion_destino_id' => $row['ubicacion_destino_id'] ?? null,
            'ubicacion_destino_codigo' => $row['ubicacion_destino_codigo'] ?? null,
            'motivo' => $row['motivo'],
            'actor_id' => $row['actor_id'] ?? null,
            'referencia_externa' => $row['referencia_externa'] ?? null,
            'timestamp_movimiento' => $row['timestamp_movimiento'],
            'hash_integridad' => $row['hash_integridad'],
            'es_correccion' => (bool) $row['es_correccion'],
            'movimiento_referencia_id' => $row['movimiento_referencia_id'] ?? null,
        ];
    }

    /**
     * Normaliza un filtro de fecha a `Y-m-d H:i:s` (columna TIMESTAMP, UTC).
     *
     * @throws InventoryException 400 si la fecha no es parseable
     */
    private function assertDate(string $field, string $value, bool $endOfDay): string
    {
        $value = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
            }

            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw InventoryException::invalidField(
                $field,
                'Debe ser una fecha válida (ej. 2026-10-01 o 2026-10-01T12:00:00).'
            );
        }
    }
}
