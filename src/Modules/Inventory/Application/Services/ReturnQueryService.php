<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\ReturnOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de devoluciones (GET /api/inventory/returns) - v1.8.0, CU-009.
 *
 * Listado paginado de `return_operations` con filtros de estado al devolver,
 * motivo, fechas, envío y unidad física.
 *
 * El filtro de envío se resuelve a través de la entrega enlazada
 * (`return_operations.delivery_id` → `deliveries.shipment_id`).
 *
 * Sólo lectura: mismo patrón que ShipmentQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class ReturnQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Devoluciones del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters Filtros opcionales:
     *        estado (estado_al_devolver: OK|DAÑADO|DETERIORADO|PERDIDO),
     *        motivo (CAMBIO_CLIENTE|DAÑO|FALLO_ENTREGA|NO_APLICA|OTRO),
     *        fecha_desde, fecha_hasta (rango sobre timestamp_devolucion, UTC),
     *        shipment_id, unidad_fisica_id
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

        // Los estados "DAÑADO"/"DAÑO" llevan tilde: van SIEMPRE como
        // parámetro, nunca como literal dentro del SQL (depende del charset
        // de la conexión).
        $countParams = $params + [
            'est_ok' => ReturnOperation::ESTADO_OK,
            'est_danado' => ReturnOperation::ESTADO_DANADO,
            'est_deteriorado' => ReturnOperation::ESTADO_DETERIORADO,
            'est_perdido' => ReturnOperation::ESTADO_PERDIDO,
        ];

        $countSql = "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(r.estado_al_devolver = :est_ok), 0) AS ok,
                   COALESCE(SUM(r.estado_al_devolver = :est_danado), 0) AS danadas,
                   COALESCE(SUM(r.estado_al_devolver = :est_deteriorado), 0) AS deterioradas,
                   COALESCE(SUM(r.estado_al_devolver = :est_perdido), 0) AS perdidas
            FROM return_operations r
            LEFT JOIN deliveries d ON d.id = r.delivery_id AND d.tenant_id = r.tenant_id
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT r.id AS return_id,
                   r.delivery_id,
                   d.shipment_id,
                   s.referencia_externa,
                   r.unidad_fisica_id,
                   u.codigo_barcode,
                   r.cantidad,
                   r.motivo,
                   r.descripcion_motivo,
                   r.estado_al_devolver,
                   r.ubicacion_destino_id,
                   l.codigo AS ubicacion_destino,
                   r.actor_id_recibe_devolucion,
                   r.timestamp_devolucion,
                   r.created_at AS fecha_creacion
            FROM return_operations r
            LEFT JOIN deliveries d ON d.id = r.delivery_id AND d.tenant_id = r.tenant_id
            LEFT JOIN shipments s ON s.id = d.shipment_id AND s.tenant_id = r.tenant_id
            LEFT JOIN stock_units u ON u.id = r.unidad_fisica_id AND u.tenant_id = r.tenant_id
            LEFT JOIN locations l ON l.id = r.ubicacion_destino_id AND l.tenant_id = r.tenant_id
            WHERE {$where}
            ORDER BY r.timestamp_devolucion DESC, r.id ASC
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
                'ok' => (int) ($summary['ok'] ?? 0),
                'danadas' => (int) ($summary['danadas'] ?? 0),
                'deterioradas' => (int) ($summary['deterioradas'] ?? 0),
                'perdidas' => (int) ($summary['perdidas'] ?? 0),
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
            // mb_strtoupper: los estados DAÑADO/DETERIORADO llevan tilde y
            // strtoupper (ASCII) no convierte la ñ.
            $estado = mb_strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, ReturnOperation::ESTADOS_AL_DEVOLVER_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'estado',
                    "Estado al devolver desconocido: '{$estado}'."
                );
            }

            $where[] = 'r.estado_al_devolver = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['motivo'])) {
            $motivo = mb_strtoupper(trim((string) $filters['motivo']));

            if (!in_array($motivo, ReturnOperation::MOTIVOS_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'motivo',
                    "Motivo de devolución desconocido: '{$motivo}'."
                );
            }

            $where[] = 'r.motivo = :motivo';
            $params['motivo'] = $motivo;
        }

        if (!empty($filters['shipment_id'])) {
            $where[] = 'd.shipment_id = :shipment_id';
            $params['shipment_id'] = trim((string) $filters['shipment_id']);
        }

        if (!empty($filters['unidad_fisica_id'])) {
            $where[] = 'r.unidad_fisica_id = :unidad_fisica_id';
            $params['unidad_fisica_id'] = trim((string) $filters['unidad_fisica_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'r.timestamp_devolucion >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate(
                'fecha_desde',
                (string) $filters['fecha_desde'],
                false
            );
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'r.timestamp_devolucion <= :fecha_hasta';
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
            'return_id' => $row['return_id'],
            'delivery_id' => $row['delivery_id'] ?? null,
            'shipment_id' => $row['shipment_id'] ?? null,
            'referencia_externa' => $row['referencia_externa'] ?? null,
            'unidad_fisica_id' => $row['unidad_fisica_id'],
            'codigo_barcode' => $row['codigo_barcode'] ?? null,
            'cantidad' => (float) $row['cantidad'],
            'motivo' => $row['motivo'],
            'descripcion_motivo' => $row['descripcion_motivo'],
            'estado_al_devolver' => $row['estado_al_devolver'],
            'ubicacion_destino_id' => $row['ubicacion_destino_id'],
            'ubicacion_destino' => $row['ubicacion_destino'] ?? null,
            'actor_id_recibe_devolucion' => $row['actor_id_recibe_devolucion'],
            'timestamp_devolucion' => $row['timestamp_devolucion'],
            'fecha_creacion' => $row['fecha_creacion'],
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
