<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de envíos (GET /api/inventory/shipments y /{id}) - v1.7.0.
 *
 * Listado paginado de `shipments` y detalle con sus `shipment_items`,
 * enriquecidos con la unidad física y el producto.
 *
 * Sólo lectura: mismo patrón que ReservationQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado y se
 * aplica en el WHERE de todas las consultas (aislamiento entre tenants).
 */
final class ShipmentQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private const PII_FIELDS = ['destino_direccion', 'destinatario_nombre', 'conductor_nombre', 'destinatario_contacto', 'conductor_contacto'];

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
     * Envíos del tenant con filtros y paginación.
     *
     * @param array<string, mixed> $filters Filtros opcionales:
     *        estado (PREPARACION|EN_RUTA|ENTREGADO|FALLO_ENTREGA|DEVUELTO),
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
                   COALESCE(SUM(s.estado = 'PREPARACION'), 0) AS en_preparacion,
                   COALESCE(SUM(s.estado = 'EN_RUTA'), 0) AS en_ruta,
                   COALESCE(SUM(s.estado = 'ENTREGADO'), 0) AS entregados
            FROM shipments s
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT s.id AS shipment_id,
                   s.referencia_externa,
                   s.tipo,
                   origen.codigo AS ubicacion_origen,
                   s.destino_direccion,
                   s.destinatario_id,
                   s.destinatario_nombre,
                   s.destinatario_contacto,
                   s.conductor_nombre,
                   s.vehiculo_referencia,
                   s.estado,
                   s.hora_salida,
                   s.hora_entrega_esperada,
                   s.hora_entrega_real,
                   s.actor_id_autoriza_salida,
                   s.created_at AS fecha_creacion,
                   s.updated_at AS fecha_actualizacion,
                   (SELECT COUNT(*) FROM shipment_items i WHERE i.shipment_id = s.id AND i.tenant_id = s.tenant_id) AS total_items
            FROM shipments s
            LEFT JOIN locations origen ON origen.id = s.origen_ubicacion_id AND origen.tenant_id = s.tenant_id
            WHERE {$where}
            ORDER BY s.created_at DESC, s.id ASC
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
                'en_preparacion' => (int) ($summary['en_preparacion'] ?? 0),
                'en_ruta' => (int) ($summary['en_ruta'] ?? 0),
                'entregados' => (int) ($summary['entregados'] ?? 0),
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
     * Detalle de UN envío con sus ítems (spec CU-006: shipment + items).
     *
     * @throws InventoryException 404 si no existe en el tenant
     */
    public function show(string $tenantId, string $shipmentId): array
    {
        $sql = "
            SELECT s.id AS shipment_id,
                   s.referencia_externa,
                   s.tipo,
                   origen.codigo AS ubicacion_origen,
                   s.destino_direccion,
                   s.destinatario_id,
                   s.destinatario_nombre,
                   s.destinatario_contacto,
                   s.conductor_nombre,
                   s.conductor_contacto,
                   s.vehiculo_referencia,
                   s.estado,
                   s.hora_salida,
                   s.hora_entrega_esperada,
                   s.hora_entrega_real,
                   s.observaciones,
                   s.actor_id_autoriza_salida,
                   s.created_at AS fecha_creacion,
                   s.updated_at AS fecha_actualizacion
            FROM shipments s
            LEFT JOIN locations origen ON origen.id = s.origen_ubicacion_id AND origen.tenant_id = s.tenant_id
            WHERE s.id = :id AND s.tenant_id = :tenant_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $shipmentId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::shipmentNotFound($shipmentId);
        }

        $itemsSql = "
            SELECT i.id AS shipment_item_id,
                   i.unidad_fisica_id AS stock_unit_id,
                   u.codigo_barcode AS barcode,
                   p.nombre AS producto,
                   i.cantidad,
                   i.estado,
                   i.created_at AS fecha_creacion
            FROM shipment_items i
            JOIN stock_units u ON u.id = i.unidad_fisica_id AND u.tenant_id = i.tenant_id
            JOIN products p ON p.id = u.producto_id AND p.tenant_id = i.tenant_id
            WHERE i.shipment_id = :id AND i.tenant_id = :tenant_id
            ORDER BY i.created_at ASC, i.id ASC";

        $itemsStmt = $this->pdo->prepare($itemsSql);
        $itemsStmt->execute(['id' => $shipmentId, 'tenant_id' => $tenantId]);

        $items = array_map(
            fn(array $item): array => $this->mapItem($item),
            $itemsStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        return [
            'shipment' => $this->mapRow($row),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, array $filters): array
    {
        $where = ['s.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, Shipment::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField(
                    'estado',
                    "Estado de envío desconocido: '{$estado}'."
                );
            }

            $where[] = 's.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 's.created_at >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate(
                'fecha_desde',
                (string) $filters['fecha_desde'],
                false
            );
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 's.created_at <= :fecha_hasta';
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
            'shipment_id' => $row['shipment_id'],
            'referencia_externa' => $row['referencia_externa'],
            'tipo' => $row['tipo'],
            'ubicacion_origen' => $row['ubicacion_origen'],
            'destino_direccion' => $row['destino_direccion'],
            'destinatario_id' => $row['destinatario_id'],
            'destinatario_nombre' => $row['destinatario_nombre'],
            'destinatario_contacto' => $row['destinatario_contacto'] ?? null,
            'conductor_nombre' => $row['conductor_nombre'],
            'conductor_contacto' => $row['conductor_contacto'] ?? null,
            'vehiculo_referencia' => $row['vehiculo_referencia'],
            'estado' => $row['estado'],
            'hora_salida' => $row['hora_salida'],
            'hora_entrega_esperada' => $row['hora_entrega_esperada'],
            'hora_entrega_real' => $row['hora_entrega_real'],
            'observaciones' => $row['observaciones'] ?? null,
            'actor_id_autoriza_salida' => $row['actor_id_autoriza_salida'],
            'total_items' => (int) ($row['total_items'] ?? 0),
            'fecha_creacion' => $row['fecha_creacion'],
            'fecha_actualizacion' => $row['fecha_actualizacion'],
        ];
    }

    /**
     * Descifra campos PII de una fila. Si no está cifrado, lo devuelve tal cual.
     */
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
                    // Pre-migración: valor en claro, dejar tal cual.
                }
            }
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapItem(array $row): array
    {
        return [
            'shipment_item_id' => $row['shipment_item_id'],
            'stock_unit_id' => $row['stock_unit_id'],
            'barcode' => $row['barcode'],
            'producto' => $row['producto'],
            'cantidad' => (float) $row['cantidad'],
            'estado' => $row['estado'],
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
