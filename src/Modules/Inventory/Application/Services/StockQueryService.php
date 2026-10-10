<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de stock actual (GET /api/inventory/stock).
 *
 * Devuelve la proyección R1 por unidad (física, reservada, en tránsito,
 * disponible) enriquecida con producto y ubicación, más un resumen total.
 * Sólo lectura: mismo patrón que DashboardService (PDO directo).
 */
final class StockQueryService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $filters Filtros: producto_id, ubicacion_id, estado, search
     * @return array{summary: array<string, float|int>, data: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public function list(string $tenantId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($tenantId, $filters);

        $countSql = "
            SELECT COUNT(*) AS unidades,
                   COALESCE(SUM(l.cantidad_fisica), 0) AS cantidad_fisica,
                   COALESCE(SUM(l.cantidad_reservada), 0) AS cantidad_reservada,
                   COALESCE(SUM(l.cantidad_en_transito), 0) AS cantidad_en_transito,
                   COALESCE(SUM(l.cantidad_disponible), 0) AS cantidad_disponible
            FROM stock_ledger l
            JOIN stock_units u ON u.id = l.unidad_fisica_id AND u.tenant_id = l.tenant_id
            LEFT JOIN products p ON p.id = u.producto_id AND p.tenant_id = l.tenant_id
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT l.unidad_fisica_id,
                   l.cantidad_fisica,
                   l.cantidad_reservada,
                   l.cantidad_en_transito,
                   l.cantidad_disponible,
                   l.estado_actual,
                   l.ultima_actualizacion,
                   u.codigo_barcode,
                   u.producto_id,
                   u.lote,
                   u.numero_serie,
                   u.ubicacion_id,
                   u.estado AS estado_unidad,
                   p.sku,
                   p.nombre AS producto_nombre,
                   loc.codigo AS ubicacion_codigo
            FROM stock_ledger l
            JOIN stock_units u ON u.id = l.unidad_fisica_id AND u.tenant_id = l.tenant_id
            JOIN products p ON p.id = u.producto_id AND p.tenant_id = l.tenant_id
            LEFT JOIN locations loc ON loc.id = u.ubicacion_id AND loc.tenant_id = l.tenant_id
            WHERE {$where}
            ORDER BY l.ultima_actualizacion DESC, l.unidad_fisica_id ASC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $total = (int) ($summary['unidades'] ?? 0);

        return [
            'summary' => [
                'unidades' => $total,
                'cantidad_fisica' => (float) ($summary['cantidad_fisica'] ?? 0),
                'cantidad_reservada' => (float) ($summary['cantidad_reservada'] ?? 0),
                'cantidad_en_transito' => (float) ($summary['cantidad_en_transito'] ?? 0),
                'cantidad_disponible' => (float) ($summary['cantidad_disponible'] ?? 0),
            ],
            'data' => array_map(fn(array $row): array => $this->mapRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    /**
     * Stock total por producto en UNA consulta agregada (Sprint 6, 0b).
     *
     * Sustituye el N+1 que hacía el frontend (1 GET por producto): con
     * `producto_id[]` devuelve exactamente los productos pedidos; sin filtro,
     * todos los del tenant. Un producto sin unidades aparece con totales 0.
     *
     * @param array<int, string> $productoIds
     * @param int                $maxIds    tope del IN (evita listas absurdas)
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    public function summaryByProduct(string $tenantId, array $productoIds = [], int $maxIds = 500): array
    {
        $ids = [];
        foreach ($productoIds as $id) {
            $value = trim((string) $id);
            if ($value !== '') {
                $ids[$value] = $value;
            }
        }
        $ids = array_values($ids);

        if (count($ids) > $maxIds) {
            throw InventoryException::invalidField(
                'producto_id',
                "Máximo {$maxIds} identificadores por consulta."
            );
        }

        $where = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        if ($ids !== []) {
            $placeholders = [];
            foreach ($ids as $i => $id) {
                $key = "producto{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $id;
            }
            $where[] = 'p.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = '
            SELECT p.id AS producto_id,
                   p.sku,
                   p.nombre,
                   COUNT(DISTINCT u.id) AS unidades,
                   COALESCE(SUM(l.cantidad_fisica), 0) AS cantidad_fisica,
                   COALESCE(SUM(l.cantidad_reservada), 0) AS cantidad_reservada,
                   COALESCE(SUM(l.cantidad_en_transito), 0) AS cantidad_en_transito,
                   COALESCE(SUM(l.cantidad_disponible), 0) AS cantidad_disponible
              FROM products p
              LEFT JOIN stock_units u
                     ON u.producto_id = p.id AND u.tenant_id = p.tenant_id
              LEFT JOIN stock_ledger l
                     ON l.unidad_fisica_id = u.id AND l.tenant_id = p.tenant_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id, p.sku, p.nombre
             ORDER BY p.sku ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        $totals = [
            'productos' => 0,
            'unidades' => 0,
            'cantidad_fisica' => 0.0,
            'cantidad_reservada' => 0.0,
            'cantidad_en_transito' => 0.0,
            'cantidad_disponible' => 0.0,
        ];

        foreach ($rows as $row) {
            $fisica = (float) $row['cantidad_fisica'];
            $reservada = (float) $row['cantidad_reservada'];
            $transito = (float) $row['cantidad_en_transito'];
            $disponible = (float) $row['cantidad_disponible'];
            $unidades = (int) $row['unidades'];

            $data[] = [
                'producto_id' => $row['producto_id'],
                'sku' => $row['sku'],
                'nombre' => $row['nombre'],
                'unidades' => $unidades,
                'cantidad_fisica' => $fisica,
                'cantidad_reservada' => $reservada,
                'cantidad_en_transito' => $transito,
                'cantidad_disponible' => $disponible,
            ];

            $totals['productos']++;
            $totals['unidades'] += $unidades;
            $totals['cantidad_fisica'] += $fisica;
            $totals['cantidad_reservada'] += $reservada;
            $totals['cantidad_en_transito'] += $transito;
            $totals['cantidad_disponible'] += $disponible;
        }

        return ['summary' => $totals, 'data' => $data];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, array $filters): array
    {
        $where = ['l.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['producto_id'])) {
            $where[] = 'u.producto_id = :producto_id';
            $params['producto_id'] = $filters['producto_id'];
        }
        if (!empty($filters['ubicacion_id'])) {
            $where[] = 'u.ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = $filters['ubicacion_id'];
        }
        if (!empty($filters['estado'])) {
            $where[] = 'u.estado = :estado';
            $params['estado'] = strtoupper(trim($filters['estado']));
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.sku LIKE :search1 OR u.codigo_barcode LIKE :search2 OR u.lote LIKE :search3)';
            $term = '%' . trim($filters['search']) . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
            $params['search3'] = $term;
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
            'unidad_fisica_id' => $row['unidad_fisica_id'],
            'codigo_barcode' => $row['codigo_barcode'],
            'producto_id' => $row['producto_id'],
            'sku' => $row['sku'],
            'producto_nombre' => $row['producto_nombre'],
            'lote' => $row['lote'],
            'numero_serie' => $row['numero_serie'],
            'ubicacion_id' => $row['ubicacion_id'],
            'ubicacion_codigo' => $row['ubicacion_codigo'],
            'estado' => $row['estado_unidad'],
            'estado_actual' => $row['estado_actual'],
            'cantidad_fisica' => (float) $row['cantidad_fisica'],
            'cantidad_reservada' => (float) $row['cantidad_reservada'],
            'cantidad_en_transito' => (float) $row['cantidad_en_transito'],
            'cantidad_disponible' => (float) $row['cantidad_disponible'],
            'ultima_actualizacion' => $row['ultima_actualizacion'],
        ];
    }
}
