<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de incidencias (GET /api/inventory/incidents) - CU-010, v1.9.0.
 *
 * Listado paginado de `incidents` con filtros de estado, tipo, unidad
 * física y rango de fechas, más un summary por estado; y detalle de UNA
 * incidencia (con su resolución y el conteo vinculado, si existe).
 *
 * Sólo lectura: mismo patrón que ReturnQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class IncidentQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Incidencias del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters estado, tipo, unidad_fisica_id,
     *        ubicacion_id, fecha_desde, fecha_hasta
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

        $countParams = $params + [
            'est_abierta' => Incident::ESTADO_ABIERTA,
            'est_investigacion' => Incident::ESTADO_INVESTIGACION,
            'est_resuelta' => Incident::ESTADO_RESUELTA,
            'est_no_resuelto' => Incident::ESTADO_NO_RESUELTO,
        ];

        $countSql = "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(i.estado = :est_abierta), 0) AS abiertas,
                   COALESCE(SUM(i.estado = :est_investigacion), 0) AS investigacion,
                   COALESCE(SUM(i.estado = :est_resuelta), 0) AS resueltas,
                   COALESCE(SUM(i.estado = :est_no_resuelto), 0) AS no_resueltas
            FROM incidents i
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT i.id,
                   i.tipo,
                   i.descripcion,
                   i.observaciones,
                   i.estado,
                   i.prioridad,
                   i.unidad_fisica_id,
                   u.codigo_barcode,
                   i.ubicacion_id,
                   l.codigo AS ubicacion_codigo,
                   i.inventory_count_id,
                   i.actor_id_reporta,
                   i.timestamp_deteccion,
                   i.resolucion,
                   i.created_at AS fecha_creacion
            FROM incidents i
            LEFT JOIN stock_units u ON u.id = i.unidad_fisica_id AND u.tenant_id = i.tenant_id
            LEFT JOIN locations l ON l.id = i.ubicacion_id AND l.tenant_id = i.tenant_id
            WHERE {$where}
            ORDER BY i.timestamp_deteccion DESC, i.id ASC
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
                'abiertas' => (int) ($summary['abiertas'] ?? 0),
                'investigacion' => (int) ($summary['investigacion'] ?? 0),
                'resueltas' => (int) ($summary['resueltas'] ?? 0),
                'no_resueltas' => (int) ($summary['no_resueltas'] ?? 0),
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
     * Detalle de UNA incidencia del tenant (404 si no existe).
     *
     * @return array<string, mixed>
     */
    public function detail(string $tenantId, string $incidentId): array
    {
        $sql = "
            SELECT i.id,
                   i.tipo,
                   i.descripcion,
                   i.observaciones,
                   i.estado,
                   i.prioridad,
                   i.unidad_fisica_id,
                   u.codigo_barcode,
                   i.ubicacion_id,
                   l.codigo AS ubicacion_codigo,
                   i.inventory_count_id,
                   i.actor_id_reporta,
                   i.timestamp_deteccion,
                   i.resolucion,
                   i.created_at AS fecha_creacion,
                   i.updated_at AS fecha_actualizacion
            FROM incidents i
            LEFT JOIN stock_units u ON u.id = i.unidad_fisica_id AND u.tenant_id = i.tenant_id
            LEFT JOIN locations l ON l.id = i.ubicacion_id AND l.tenant_id = i.tenant_id
            WHERE i.id = :id AND i.tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $incidentId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::incidentNotFound($incidentId);
        }

        return $this->mapRow($row) + ['fecha_actualizacion' => $row['fecha_actualizacion']];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, array $filters): array
    {
        $where = ['i.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = mb_strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, Incident::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField('estado', "Estado de incidencia desconocido: '{$estado}'.");
            }

            $where[] = 'i.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['tipo'])) {
            // Los tipos llevan tilde (DAÑO): siempre como parámetro.
            $tipo = mb_strtoupper(trim((string) $filters['tipo']));

            if (!in_array($tipo, Incident::TIPOS_VALIDOS, true)) {
                throw InventoryException::invalidField('tipo', "Tipo de incidencia desconocido: '{$tipo}'.");
            }

            $where[] = 'i.tipo = :tipo';
            $params['tipo'] = $tipo;
        }

        if (!empty($filters['unidad_fisica_id'])) {
            $where[] = 'i.unidad_fisica_id = :unidad_fisica_id';
            $params['unidad_fisica_id'] = trim((string) $filters['unidad_fisica_id']);
        }

        if (!empty($filters['ubicacion_id'])) {
            $where[] = 'i.ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = trim((string) $filters['ubicacion_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'i.timestamp_deteccion >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate('fecha_desde', (string) $filters['fecha_desde'], false);
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'i.timestamp_deteccion <= :fecha_hasta';
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
        $resolucion = null;

        if (!empty($row['resolucion'])) {
            $decoded = json_decode((string) $row['resolucion'], true);
            $resolucion = is_array($decoded) ? $decoded : null;
        }

        return [
            'incidencia_id' => $row['id'],
            'tipo' => $row['tipo'],
            'descripcion' => $row['descripcion'],
            'observaciones' => $row['observaciones'] ?? null,
            'estado' => $row['estado'],
            'prioridad' => $row['prioridad'] ?? Incident::PRIORIDAD_NORMAL,
            'unidad_fisica_id' => $row['unidad_fisica_id'] ?? null,
            'codigo_barcode' => $row['codigo_barcode'] ?? null,
            'ubicacion_id' => $row['ubicacion_id'] ?? null,
            'ubicacion_codigo' => $row['ubicacion_codigo'] ?? null,
            'inventory_count_id' => $row['inventory_count_id'] ?? null,
            'actor_id_reporta' => $row['actor_id_reporta'] ?? null,
            'fecha_deteccion' => $row['timestamp_deteccion'],
            'fecha_creacion' => $row['fecha_creacion'],
            'resolucion' => $resolucion,
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
