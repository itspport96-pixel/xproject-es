<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\Reservation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de reservas (GET /api/inventory/reservations) - v1.6.2.
 *
 * Listado paginado de `reservations` enriquecido con la unidad física, el
 * producto y la ubicación, para que la pantalla Reservas pueda mostrar
 * `reserva_id` y `fecha_expiracion` y ejecutar "Liberar" (en v1.6.1 no
 * existía este endpoint y la pantalla se apoyaba en el stock RESERVADO).
 *
 * Sólo lectura: mismo patrón que StockQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class ReservationQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Reservas del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters Filtros opcionales:
     *        estado (ACTIVA|CANCELADA|COMPLETADA), producto_id, stock_unit_id,
     *        vencimiento_desde, vencimiento_hasta (rango sobre fecha_expiracion)
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
                   COALESCE(SUM(r.estado = 'ACTIVA'), 0) AS activas,
                   COALESCE(SUM(r.fecha_expiracion IS NOT NULL), 0) AS con_expiracion
            FROM reservations r
            JOIN stock_units u ON u.id = r.unidad_fisica_id AND u.tenant_id = r.tenant_id
            JOIN products p ON p.id = u.producto_id AND p.tenant_id = r.tenant_id
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT r.id AS reserva_id,
                   r.unidad_fisica_id AS stock_unit_id,
                   u.codigo_barcode AS barcode,
                   p.nombre AS producto,
                   loc.codigo AS ubicacion,
                   r.cantidad,
                   r.estado,
                   r.fecha_expiracion,
                   r.actor_id AS creado_por,
                   r.created_at AS fecha_creacion
            FROM reservations r
            JOIN stock_units u ON u.id = r.unidad_fisica_id AND u.tenant_id = r.tenant_id
            JOIN products p ON p.id = u.producto_id AND p.tenant_id = r.tenant_id
            LEFT JOIN locations loc ON loc.id = u.ubicacion_id AND loc.tenant_id = r.tenant_id
            WHERE {$where}
            ORDER BY r.created_at DESC, r.id ASC
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
                'activas' => (int) ($summary['activas'] ?? 0),
                'con_expiracion' => (int) ($summary['con_expiracion'] ?? 0),
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
        $where = ['r.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, Reservation::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'estado',
                    "Estado de reserva desconocido: '{$estado}'."
                );
            }

            $where[] = 'r.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['producto_id'])) {
            $where[] = 'u.producto_id = :producto_id';
            $params['producto_id'] = $filters['producto_id'];
        }

        if (!empty($filters['stock_unit_id'])) {
            $where[] = 'r.unidad_fisica_id = :stock_unit_id';
            $params['stock_unit_id'] = $filters['stock_unit_id'];
        }

        if (!empty($filters['vencimiento_desde'])) {
            $where[] = 'r.fecha_expiracion IS NOT NULL AND r.fecha_expiracion >= :vencimiento_desde';
            $params['vencimiento_desde'] = $this->assertDate(
                'vencimiento_desde',
                (string) $filters['vencimiento_desde'],
                false
            );
        }

        if (!empty($filters['vencimiento_hasta'])) {
            $where[] = 'r.fecha_expiracion IS NOT NULL AND r.fecha_expiracion <= :vencimiento_hasta';
            $params['vencimiento_hasta'] = $this->assertDate(
                'vencimiento_hasta',
                (string) $filters['vencimiento_hasta'],
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
            'reserva_id' => $row['reserva_id'],
            'stock_unit_id' => $row['stock_unit_id'],
            'barcode' => $row['barcode'],
            'producto' => $row['producto'],
            'ubicacion' => $row['ubicacion'],
            'cantidad' => (float) $row['cantidad'],
            'estado' => $row['estado'],
            'fecha_expiracion' => $row['fecha_expiracion'],
            'creado_por' => $row['creado_por'],
            'fecha_creacion' => $row['fecha_creacion'],
        ];
    }

    /**
     * Normaliza un filtro de fecha a `Y-m-d H:i:s` (columna DATETIME/TIMESTAMP).
     *
     *  - `YYYY-MM-DD` → inicio del día (desde) o final del día (hasta),
     *    para que un rango por fecha incluya el día completo.
     *  - Cualquier otra fecha parseable (ISO, relativa) → `Y-m-d H:i:s`.
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
