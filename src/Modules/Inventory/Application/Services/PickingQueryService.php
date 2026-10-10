<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\PickingOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de operaciones de picking (GET /api/inventory/picking) - v1.7.0.
 *
 * Listado paginado de `picking_operations` enriquecido con la unidad física,
 * el producto y las ubicaciones de origen/preparación.
 *
 * Sólo lectura: mismo patrón que ReservationQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class PickingQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Operaciones de picking del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters Filtros opcionales:
     *        estado (PENDIENTE|EN_CURSO|COMPLETADA|VERIFICADA), id_lote_picking,
     *        fecha_desde, fecha_hasta (rango sobre created_at, UTC)
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

        $countSql = "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(o.estado = 'EN_CURSO'), 0) AS en_curso,
                   COALESCE(SUM(o.estado = 'COMPLETADA'), 0) AS completadas,
                   COALESCE(SUM(o.estado = 'VERIFICADA'), 0) AS verificadas
            FROM picking_operations o
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT o.id AS picking_id,
                   o.id_lote_picking,
                   o.unidad_fisica_id AS stock_unit_id,
                   u.codigo_barcode AS barcode,
                   p.nombre AS producto,
                   o.cantidad,
                   o.estado,
                   origen.codigo AS ubicacion_origen,
                   preparacion.codigo AS ubicacion_preparacion,
                   o.referencia_orden,
                   o.actor_id_selector AS selector,
                   o.actor_id_supervisor AS supervisor,
                   o.created_at AS fecha_creacion,
                   o.updated_at AS fecha_actualizacion
            FROM picking_operations o
            JOIN stock_units u ON u.id = o.unidad_fisica_id AND u.tenant_id = o.tenant_id
            JOIN products p ON p.id = u.producto_id AND p.tenant_id = o.tenant_id
            LEFT JOIN locations origen ON origen.id = o.ubicacion_origen_id AND origen.tenant_id = o.tenant_id
            LEFT JOIN locations preparacion ON preparacion.id = o.ubicacion_preparacion_id AND preparacion.tenant_id = o.tenant_id
            WHERE {$where}
            ORDER BY o.created_at DESC, o.id ASC
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
                'en_curso' => (int) ($summary['en_curso'] ?? 0),
                'completadas' => (int) ($summary['completadas'] ?? 0),
                'verificadas' => (int) ($summary['verificadas'] ?? 0),
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
        $where = ['o.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, PickingOperation::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'estado',
                    "Estado de picking desconocido: '{$estado}'."
                );
            }

            $where[] = 'o.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['id_lote_picking'])) {
            $where[] = 'o.id_lote_picking = :id_lote_picking';
            $params['id_lote_picking'] = trim((string) $filters['id_lote_picking']);
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'o.created_at >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate(
                'fecha_desde',
                (string) $filters['fecha_desde'],
                false
            );
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'o.created_at <= :fecha_hasta';
            $params['fecha_hasta'] = $this->assertDate(
                'fecha_hasta',
                (string) $filters['fecha_hasta'],
                true
            );
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
            'picking_id' => $row['picking_id'],
            'id_lote_picking' => $row['id_lote_picking'],
            'stock_unit_id' => $row['stock_unit_id'],
            'barcode' => $row['barcode'],
            'producto' => $row['producto'],
            'cantidad' => (float) $row['cantidad'],
            'estado' => $row['estado'],
            'ubicacion_origen' => $row['ubicacion_origen'],
            'ubicacion_preparacion' => $row['ubicacion_preparacion'],
            'referencia_orden' => $row['referencia_orden'],
            'selector' => $row['selector'],
            'supervisor' => $row['supervisor'],
            'fecha_creacion' => $row['fecha_creacion'],
            'fecha_actualizacion' => $row['fecha_actualizacion'],
        ];
    }

    /**
     * Normaliza un filtro de fecha a `Y-m-d H:i:s` (columna TIMESTAMP, UTC).
     *
     *  - `YYYY-MM-DD` → inicio del día (desde) o final del día (hasta)
     *  - Cualquier otra fecha parseable → `Y-m-d H:i:s`
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
