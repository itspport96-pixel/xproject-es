<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Historial de una unidad física (GET /api/inventory/stock/{id}/history)
 * - R3, v1.10.0.
 *
 * Fusiona en UNA línea de tiempo (más reciente primero) los `stock_events`
 * (append-only, con hash) y los `movements` de la unidad, para que el
 * operador vea de un vistazo recepciones, movimientos, bloques, incidencias
 * y resoluciones.
 *
 * Sólo lectura: mismo patrón que IncidentQueryService (PDO directo). El
 * `tenant_id` llega SIEMPRE por los claims del contexto autenticado.
 *
 * LIMITACIÓN documentada: se leen como máximo los últimos 100 eventos y los
 * últimos 100 movimientos de la unidad (tope de `findByUnidadFisica`); la
 * paginación se aplica sobre la unión ordenada de esos dos conjuntos.
 */
final class StockHistoryQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    /** Tope de filas por fuente (eventos y movimientos). */
    private const SOURCE_LIMIT = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Línea de tiempo de UNA unidad del tenant (404 si no existe).
     *
     * @return array{
     *     unidad: array<string, mixed>,
     *     data: array<int, array<string, mixed>>,
     *     pagination: array<string, int|bool>
     * }
     *
     * @throws InventoryException 404 si la unidad no existe en el tenant
     */
    public function history(string $tenantId, string $unitId, int $page = 1, int $perPage = 50): array
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $page = max(1, $page);

        $unit = $this->loadUnit($tenantId, $unitId);
        $entries = array_merge($this->loadEvents($tenantId, $unitId), $this->loadMovements($tenantId, $unitId));

        usort(
            $entries,
            static function (array $a, array $b): int {
                $cmp = strcmp($b['timestamp'], $a['timestamp']);

                return $cmp !== 0 ? $cmp : strcmp($b['id'], $a['id']);
            }
        );

        $total = count($entries);
        $slice = array_slice($entries, ($page - 1) * $perPage, $perPage);

        return [
            'unidad' => $unit,
            'data' => array_values($slice),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => (($page - 1) * $perPage + $perPage) < $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadUnit(string $tenantId, string $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, codigo_barcode, estado, ubicacion_id, cantidad, created_at
               FROM stock_units
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $unitId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::unitNotFound($unitId);
        }

        return [
            'unidad_fisica_id' => $row['id'],
            'codigo_barcode' => $row['codigo_barcode'],
            'estado' => $row['estado'],
            'ubicacion_id' => $row['ubicacion_id'] ?? null,
            'cantidad' => (float) $row['cantidad'],
            'fecha_creacion' => $row['created_at'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEvents(string $tenantId, string $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo_evento, descripcion, datos_operacion,
                    cantidad_anterior, cantidad_posterior,
                    estado_anterior, estado_posterior,
                    created_at, hash_integridad
               FROM stock_events
              WHERE tenant_id = :tenant_id AND unidad_fisica_id = :unidad
              ORDER BY created_at DESC, id DESC
              LIMIT ' . self::SOURCE_LIMIT
        );
        $stmt->execute(['tenant_id' => $tenantId, 'unidad' => $unitId]);

        return array_map(function (array $row): array {
            $datos = null;

            if (!empty($row['datos_operacion'])) {
                $decoded = json_decode((string) $row['datos_operacion'], true);
                $datos = is_array($decoded) ? $decoded : null;
            }

            return [
                'fuente' => 'evento',
                'id' => $row['id'],
                'timestamp' => $row['created_at'],
                'tipo' => $row['tipo_evento'],
                'descripcion' => $row['descripcion'],
                'estado_anterior' => $row['estado_anterior'],
                'estado_posterior' => $row['estado_posterior'],
                'cantidad_anterior' => $row['cantidad_anterior'] !== null ? (float) $row['cantidad_anterior'] : null,
                'cantidad_posterior' => $row['cantidad_posterior'] !== null ? (float) $row['cantidad_posterior'] : null,
                'datos' => $datos,
                'hash_integridad' => $row['hash_integridad'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMovements(string $tenantId, string $unitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.cantidad, m.ubicacion_origen_id, lo.codigo AS origen_codigo,
                    m.ubicacion_destino_id, ld.codigo AS destino_codigo,
                    m.motivo, m.actor_id, m.referencia_externa,
                    m.timestamp_movimiento, m.hash_integridad,
                    m.es_correccion, m.movimiento_referencia_id
               FROM movements m
               LEFT JOIN locations lo ON lo.id = m.ubicacion_origen_id AND lo.tenant_id = m.tenant_id
               LEFT JOIN locations ld ON ld.id = m.ubicacion_destino_id AND ld.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant_id AND m.unidad_fisica_id = :unidad
              ORDER BY m.timestamp_movimiento DESC, m.id DESC
              LIMIT ' . self::SOURCE_LIMIT
        );
        $stmt->execute(['tenant_id' => $tenantId, 'unidad' => $unitId]);

        return array_map(static fn(array $row): array => [
            'fuente' => 'movimiento',
            'id' => $row['id'],
            'timestamp' => $row['timestamp_movimiento'],
            'tipo' => $row['motivo'],
            'descripcion' => sprintf(
                'Movimiento %s%s',
                $row['origen_codigo'] ?? 'SIN_UBICACION',
                ($row['destino_codigo'] ?? '') !== '' ? ' → ' . $row['destino_codigo'] : ''
            ),
            'ubicacion_origen_id' => $row['ubicacion_origen_id'] ?? null,
            'ubicacion_origen_codigo' => $row['origen_codigo'] ?? null,
            'ubicacion_destino_id' => $row['ubicacion_destino_id'] ?? null,
            'ubicacion_destino_codigo' => $row['destino_codigo'] ?? null,
            'cantidad' => (float) $row['cantidad'],
            'motivo' => $row['motivo'],
            'actor_id' => $row['actor_id'] ?? null,
            'referencia_externa' => $row['referencia_externa'] ?? null,
            'es_correccion' => (bool) $row['es_correccion'],
            'movimiento_referencia_id' => $row['movimiento_referencia_id'] ?? null,
            'hash_integridad' => $row['hash_integridad'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
