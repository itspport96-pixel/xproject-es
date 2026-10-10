<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use PDO;

/**
 * Consulta de conteos físicos (GET /api/inventory/counts) - CU-011, v1.9.0.
 *
 *  - `list()`    listado paginado de `inventory_counts` con filtros de
 *                estado, ubicación y rango de fechas, más summary.
 *  - `detail()`  detalle de UN conteo (lista teórica, resultados,
 *                discrepancias y ajustes).
 *  - `sheet()`   la lista teórica para contar en almacén; `format=csv`
 *                devuelve el CSV descargable.
 *
 * Sólo lectura: PDO directo, `tenant_id` SIEMPRE por los claims (aislamiento
 * entre tenants).
 */
final class CountQueryService
{
    /** Tope de filas por página (spec: per_page máximo 100). */
    private const MAX_PER_PAGE = 100;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $filters estado, ubicacion_id, fecha_desde, fecha_hasta
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
            'est_pendiente' => InventoryCount::ESTADO_PENDIENTE,
            'est_en_curso' => InventoryCount::ESTADO_EN_CURSO,
            'est_completado' => InventoryCount::ESTADO_COMPLETADO,
            'est_reconciliado' => InventoryCount::ESTADO_RECONCILIADO,
        ];

        $countSql = "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(c.estado = :est_pendiente), 0) AS pendientes,
                   COALESCE(SUM(c.estado = :est_en_curso), 0) AS en_curso,
                   COALESCE(SUM(c.estado = :est_completado), 0) AS completados,
                   COALESCE(SUM(c.estado = :est_reconciliado), 0) AS reconciliados
            FROM inventory_counts c
            WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $summary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sql = "
            SELECT c.id,
                   c.ubicacion_id,
                   l.codigo AS ubicacion_codigo,
                   c.estado,
                   c.usuario_responsable_id,
                   c.fecha_conteo,
                   c.discrepancias,
                   c.reconciliado,
                   c.created_at AS fecha_creacion,
                   c.updated_at AS fecha_actualizacion
            FROM inventory_counts c
            LEFT JOIN locations l ON l.id = c.ubicacion_id AND l.tenant_id = c.tenant_id
            WHERE {$where}
            ORDER BY c.fecha_conteo DESC, c.id ASC
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
                'pendientes' => (int) ($summary['pendientes'] ?? 0),
                'en_curso' => (int) ($summary['en_curso'] ?? 0),
                'completados' => (int) ($summary['completados'] ?? 0),
                'reconciliados' => (int) ($summary['reconciliados'] ?? 0),
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
     * Detalle de UN conteo del tenant (404 si no existe).
     *
     * @return array<string, mixed>
     */
    public function detail(string $tenantId, string $countId): array
    {
        $count = $this->loadEntity($tenantId, $countId);

        return $count->toDetailArray() + ['ubicacion_codigo' => $this->ubicacionCodigo($tenantId, $count->ubicacionId())];
    }

    /**
     * Lista teórica del conteo para contar en almacén.
     *
     * @return array{conteo: array<string, mixed>, filas: array<int, array<string, mixed>>}
     */
    public function sheet(string $tenantId, string $countId): array
    {
        $count = $this->loadEntity($tenantId, $countId);
        $resultados = [];

        foreach ($count->resultados() as $fila) {
            $resultados[(string) $fila['unidad_fisica_id']] = $fila;
        }

        $filas = [];

        foreach ($count->listaTeorica() as $fila) {
            $unidadId = (string) $fila['unidad_fisica_id'];

            $filas[] = [
                'unidad_fisica_id' => $unidadId,
                'codigo_barcode' => $fila['codigo_barcode'] ?? null,
                'producto_id' => $fila['producto_id'] ?? null,
                'ubicacion_codigo' => $fila['ubicacion_codigo'] ?? null,
                'cantidad_esperada' => (float) ($fila['cantidad_esperada'] ?? 0.0),
                'cantidad_contada' => isset($resultados[$unidadId])
                    ? (float) $resultados[$unidadId]['cantidad_encontrada']
                    : null,
                'notas' => $resultados[$unidadId]['notas'] ?? null,
            ];
        }

        return [
            'conteo' => $count->toPublicArray() + [
                'ubicacion_codigo' => $this->ubicacionCodigo($tenantId, $count->ubicacionId()),
            ],
            'filas' => $filas,
        ];
    }

    /**
     * Misma lista teórica en formato CSV (cabecera + una fila por unidad),
     * con columnas en blanco para rellenar a mano en almacén.
     */
    public function sheetCsv(string $tenantId, string $countId): string
    {
        $sheet = $this->sheet($tenantId, $countId);

        $lineas = ['unidad_fisica_id,codigo_barcode,producto_id,ubicacion,cantidad_esperada,cantidad_contada,notas'];

        foreach ($sheet['filas'] as $fila) {
            $lineas[] = implode(',', [
                $this->csv($fila['unidad_fisica_id']),
                $this->csv($fila['codigo_barcode']),
                $this->csv($fila['producto_id']),
                $this->csv($fila['ubicacion_codigo']),
                $this->csv($fila['cantidad_esperada']),
                $this->csv($fila['cantidad_contada']),
                $this->csv($fila['notas']),
            ]);
        }

        return implode("\r\n", $lineas) . "\r\n";
    }

    private function csv(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        $texto = (string) $valor;

        if (preg_match('/[",\r\n]/', $texto) === 1) {
            return '"' . str_replace('"', '""', $texto) . '"';
        }

        return $texto;
    }

    private function loadEntity(string $tenantId, string $countId): InventoryCount
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inventory_counts WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $countId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::countNotFound($countId);
        }

        return InventoryCount::fromArray($row);
    }

    private function ubicacionCodigo(string $tenantId, ?string $ubicacionId): ?string
    {
        if ($ubicacionId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT codigo FROM locations WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $ubicacionId, 'tenant_id' => $tenantId]);
        $codigo = $stmt->fetchColumn();

        return $codigo === false ? null : (string) $codigo;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, array $filters): array
    {
        $where = ['c.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $estado = mb_strtoupper(trim((string) $filters['estado']));

            if (!in_array($estado, InventoryCount::ESTADOS_VALIDOS, true)) {
                throw InventoryException::invalidField('estado', "Estado de conteo desconocido: '{$estado}'.");
            }

            $where[] = 'c.estado = :estado';
            $params['estado'] = $estado;
        }

        if (!empty($filters['ubicacion_id'])) {
            $where[] = 'c.ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = trim((string) $filters['ubicacion_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'c.fecha_conteo >= :fecha_desde';
            $params['fecha_desde'] = $this->assertDate('fecha_desde', (string) $filters['fecha_desde'], false);
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'c.fecha_conteo <= :fecha_hasta';
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
        $discrepancias = null;

        if (!empty($row['discrepancias'])) {
            $decoded = json_decode((string) $row['discrepancias'], true);
            $discrepancias = is_array($decoded) ? array_values($decoded) : null;
        }

        return [
            'conteo_id' => $row['id'],
            'ubicacion_id' => $row['ubicacion_id'] ?? null,
            'ubicacion_codigo' => $row['ubicacion_codigo'] ?? null,
            'estado' => $row['estado'],
            'usuario_responsable_id' => $row['usuario_responsable_id'] ?? null,
            'fecha_conteo' => $row['fecha_conteo'],
            'total_discrepancias' => $discrepancias !== null ? count($discrepancias) : 0,
            'reconciliado' => (bool) ($row['reconciliado'] ?? false),
            'fecha_creacion' => $row['fecha_creacion'],
            'fecha_actualizacion' => $row['fecha_actualizacion'],
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
                'Debe ser una fecha válida (ej. 2026-10-04 o 2026-10-04T09:00:00).'
            );
        }
    }
}
