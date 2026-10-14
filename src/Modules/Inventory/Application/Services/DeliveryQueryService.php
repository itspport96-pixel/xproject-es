<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;
use App\Modules\Inventory\Domain\Entities\Delivery;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de entregas (GET /api/inventory/deliveries) - v1.8.0, CU-007/CU-008.
 *
 * Listado paginado de `deliveries` con filtros de estado, fechas, envío y
 * unidad física, enriquecido con la referencia externa del envío.
 *
 * Sólo lectura: mismo patrón que ShipmentQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class DeliveryQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private const PII_FIELDS = ['destinatario_real', 'firma_comprobante', 'observaciones'];

    private PDO $pdo;
    private ?EncryptionService $encryption;

    public function __construct(PDO $pdo, ?EncryptionService $encryption = null)
    {
        $this->pdo = $pdo;
        if ($encryption !== null) {
            $this->encryption = $encryption;
        } else {
            try {
                $container = Container::getInstance();
                $this->encryption = $container->has(EncryptionService::class) ? $container->get(EncryptionService::class) : null;
            } catch (\Throwable) {
                $this->encryption = null;
            }
        }
    }

    /**
     * Entregas del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters Filtros opcionales:
     *        estado (PENDIENTE|ENTREGADA|RECHAZADA|PARCIAL),
     *        fecha_desde, fecha_hasta (rango sobre timestamp_entrega, UTC),
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

        $countSql = "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(d.estado = 'ENTREGADA'), 0) AS entregadas,
                   COALESCE(SUM(d.estado = 'RECHAZADA'), 0) AS rechazadas,
                   COALESCE(SUM(d.estado = 'PENDIENTE'), 0) AS pendientes,
                   COALESCE(SUM(d.estado = 'PARCIAL'), 0) AS parciales
            FROM deliveries d
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT d.id AS delivery_id,
                   d.shipment_id,
                   s.referencia_externa,
                   s.estado AS shipment_estado,
                   d.unidad_fisica_id,
                   u.codigo_barcode,
                   d.cantidad,
                   d.estado,
                   d.destinatario_real,
                   d.firma_comprobante,
                   d.observaciones,
                   d.actor_id_entrega,
                   d.timestamp_entrega,
                   d.created_at AS fecha_creacion
            FROM deliveries d
            LEFT JOIN shipments s ON s.id = d.shipment_id AND s.tenant_id = d.tenant_id
            LEFT JOIN stock_units u ON u.id = d.unidad_fisica_id AND u.tenant_id = d.tenant_id
            WHERE {$where}
            ORDER BY d.timestamp_entrega DESC, d.id ASC
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
                'entregadas' => (int) ($summary['entregadas'] ?? 0),
                'rechazadas' => (int) ($summary['rechazadas'] ?? 0),
                'pendientes' => (int) ($summary['pendientes'] ?? 0),
                'parciales' => (int) ($summary['parciales'] ?? 0),
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
        $where = ['d.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = mb_strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, Delivery::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'estado',
                    "Estado de entrega desconocido: '{$estado}'."
                );
            }

            $where[] = 'd.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['shipment_id'])) {
            $where[] = 'd.shipment_id = :shipment_id';
            $params['shipment_id'] = trim((string) $filters['shipment_id']);
        }

        if (!empty($filters['unidad_fisica_id'])) {
            $where[] = 'd.unidad_fisica_id = :unidad_fisica_id';
            $params['unidad_fisica_id'] = trim((string) $filters['unidad_fisica_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'd.timestamp_entrega >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate(
                'fecha_desde',
                (string) $filters['fecha_desde'],
                false
            );
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'd.timestamp_entrega <= :fecha_hasta';
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
        $row = $this->decryptPii($row);
        return [
            'delivery_id' => $row['delivery_id'],
            'shipment_id' => $row['shipment_id'],
            'referencia_externa' => $row['referencia_externa'] ?? null,
            'shipment_estado' => $row['shipment_estado'] ?? null,
            'unidad_fisica_id' => $row['unidad_fisica_id'],
            'codigo_barcode' => $row['codigo_barcode'] ?? null,
            'cantidad' => (float) $row['cantidad'],
            'estado' => $row['estado'],
            'destinatario_real' => $row['destinatario_real'],
            'firma_comprobante' => $row['firma_comprobante'],
            'observaciones' => $row['observaciones'],
            'actor_id_entrega' => $row['actor_id_entrega'],
            'timestamp_entrega' => $row['timestamp_entrega'],
            'fecha_creacion' => $row['fecha_creacion'],
        ];
    }

    private function decryptPii(array $row): array
    {
        if ($this->encryption === null) {
            return $row;
        }
        foreach (self::PII_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                try {
                    $row[$field] = $this->encryption->decrypt($row[$field]);
                } catch (\Throwable) {
                }
            }
        }
        return $row;
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
