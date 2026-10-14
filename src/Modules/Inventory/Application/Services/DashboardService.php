<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Datos del dashboard de inventario por capas
 * (INVENTORY_MODULE_SPECIFICATION §9 "Lazy Loading").
 *
 *  CAPA 1 (crítica, primer render)  → critical()
 *  CAPA 2 (secundaria, ~5s)         → secondary()
 *
 * Aislamiento de tenant (spec R4): todos los métodos reciben el tenant_id
 * tomado de los claims JWT y lo aplican en TODAS las consultas; el servicio
 * no lee el tenant de ninguna fuente mutable.
 */
final class DashboardService
{
    /** Filas que devuelve la tabla de stock resumida (CAPA 1). */
    public const STOCK_LIMIT = 20;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ====================================================================
    // CAPA 1 - CRÍTICA
    // ====================================================================

    /**
     * GET /api/inventory/dashboard/critical
     *
     * @return array{
     *     tenant: ?array{id: string, nombre: ?string},
     *     counts: array<string, int|float|array<string, array{unidades: int, cantidad: float}>>,
     *     stock: array<int, array{producto_id: string, sku: string, nombre: string, unidades: int, cantidad: float}>,
     *     generated_at: string
     * }
     */
    public function critical(string $tenantId): array
    {
        return [
            'tenant' => $this->tenant($tenantId),
            'counts' => $this->criticalCounts($tenantId),
            'stock' => $this->stockSummary($tenantId),
            'generated_at' => $this->now(),
        ];
    }

    /**
     * Contadores del primer render: catálogo, alertas de stock y unidades.
     *
     * @return array<string, int|float|array<string, array{unidades: int, cantidad: float}>>
     */
    private function criticalCounts(string $tenantId): array
    {
        $sql = "SELECT
                    (SELECT COUNT(*)
                       FROM products
                      WHERE tenant_id = :t1
                        AND deleted_at IS NULL
                        AND activo = 1) AS productos_activos,
                    (SELECT COUNT(*)
                       FROM products p
                      WHERE p.tenant_id = :t2
                        AND p.deleted_at IS NULL
                        AND p.activo = 1
                        AND NOT EXISTS (
                            SELECT 1 FROM stock_units s
                             WHERE s.tenant_id = p.tenant_id
                               AND s.producto_id = p.id
                        )) AS productos_sin_stock,
                    (SELECT COUNT(*)
                       FROM locations
                      WHERE tenant_id = :t3
                        AND deleted_at IS NULL
                        AND activa = 1) AS ubicaciones_activas,
                    (SELECT COUNT(*)
                       FROM locations
                      WHERE tenant_id = :t4
                        AND deleted_at IS NULL
                        AND activa = 1
                        AND capacidad > 0
                        AND capacidad_usada >= capacidad) AS ubicaciones_criticas";

        $row = $this->fetchOne($sql, [
            't1' => $tenantId,
            't2' => $tenantId,
            't3' => $tenantId,
            't4' => $tenantId,
        ]) ?? [];

        $stock = $this->stockByState($tenantId);

        return [
            'productos_activos' => (int) ($row['productos_activos'] ?? 0),
            'productos_sin_stock' => (int) ($row['productos_sin_stock'] ?? 0),
            'ubicaciones_activas' => (int) ($row['ubicaciones_activas'] ?? 0),
            'ubicaciones_criticas' => (int) ($row['ubicaciones_criticas'] ?? 0),
            'unidades_total' => $stock['unidades_total'],
            'unidades_disponibles' => $stock['unidades_disponibles'],
            'unidades_reservadas' => $stock['unidades_reservadas'],
            'unidades_en_transito' => $stock['unidades_en_transito'],
            'por_estado' => $stock['por_estado'],
        ];
    }

    /**
     * Desglose de unidades físicas por estado (DISPONIBLE/RESERVADO/...).
     *
     * @return array{
     *     unidades_total: int,
     *     cantidad_total: float,
     *     unidades_disponibles: int,
     *     unidades_reservadas: int,
     *     unidades_en_transito: int,
     *     por_estado: array<string, array{unidades: int, cantidad: float}>
     * }
     */
    private function stockByState(string $tenantId): array
    {
        $sql = "SELECT estado,
                       COUNT(*) AS unidades,
                       COALESCE(SUM(cantidad), 0) AS cantidad
                  FROM stock_units
                 WHERE tenant_id = :tenant_id
              GROUP BY estado";

        $porEstado = [];
        $unidadesTotal = 0;
        $cantidadTotal = 0.0;

        foreach ($this->fetchAll($sql, ['tenant_id' => $tenantId]) as $row) {
            $estado = (string) ($row['estado'] ?? '');
            if ($estado === '') {
                continue;
            }

            $unidades = (int) $row['unidades'];
            $cantidad = (float) $row['cantidad'];

            $porEstado[$estado] = ['unidades' => $unidades, 'cantidad' => $cantidad];
            $unidadesTotal += $unidades;
            $cantidadTotal += $cantidad;
        }

        return [
            'unidades_total' => $unidadesTotal,
            'cantidad_total' => $cantidadTotal,
            'unidades_disponibles' => $porEstado['DISPONIBLE']['unidades'] ?? 0,
            'unidades_reservadas' => $porEstado['RESERVADO']['unidades'] ?? 0,
            'unidades_en_transito' => $porEstado['EN_TRANSITO']['unidades'] ?? 0,
            'por_estado' => $porEstado,
        ];
    }

    /**
     * Tabla de stock resumida (primeras STOCK_LIMIT filas), con los productos
     * sin unidades primero para que la capa crítica sea accionable.
     *
     * @return array<int, array{producto_id: string, sku: string, nombre: string, unidades: int, cantidad: float}>
     */
    private function stockSummary(string $tenantId): array
    {
        $sql = "SELECT p.id AS producto_id,
                       p.sku,
                       p.nombre,
                       COUNT(s.id) AS unidades,
                       COALESCE(SUM(s.cantidad), 0) AS cantidad
                  FROM products p
             LEFT JOIN stock_units s
                    ON s.producto_id = p.id
                   AND s.tenant_id = p.tenant_id
                 WHERE p.tenant_id = :tenant_id
                   AND p.deleted_at IS NULL
                   AND p.activo = 1
              GROUP BY p.id, p.sku, p.nombre
              ORDER BY unidades ASC, p.sku ASC
                 LIMIT " . self::STOCK_LIMIT;

        $rows = [];
        foreach ($this->fetchAll($sql, ['tenant_id' => $tenantId]) as $row) {
            $rows[] = [
                'producto_id' => (string) $row['producto_id'],
                'sku' => (string) $row['sku'],
                'nombre' => (string) $row['nombre'],
                'unidades' => (int) $row['unidades'],
                'cantidad' => (float) $row['cantidad'],
            ];
        }

        return $rows;
    }

    // ====================================================================
    // CAPA 2 - SECUNDARIA
    // ====================================================================

    /**
     * GET /api/inventory/dashboard/secondary
     *
     * @return array{
     *     counts: array{ubicaciones_disponibles: int, reservas_activas: int, transporte_transito: int, incidencias_abiertas: int},
     *     generated_at: string
     * }
     */
    public function secondary(string $tenantId): array
    {
        return [
            'counts' => [
                'ubicaciones_disponibles' => $this->count(
                    "SELECT COUNT(*) AS total
                       FROM locations
                      WHERE tenant_id = :tenant_id
                        AND deleted_at IS NULL
                        AND activa = 1
                        AND (capacidad IS NULL OR capacidad = 0 OR capacidad > capacidad_usada)",
                    $tenantId
                ),
                'reservas_activas' => $this->count(
                    "SELECT COUNT(*) AS total
                       FROM reservations
                      WHERE tenant_id = :tenant_id
                        AND estado = 'ACTIVA'",
                    $tenantId
                ),
                'transporte_transito' => $this->count(
                    "SELECT COUNT(*) AS total
                       FROM stock_units
                      WHERE tenant_id = :tenant_id
                        AND estado = 'EN_TRANSITO'",
                    $tenantId
                ),
                'incidencias_abiertas' => $this->count(
                    "SELECT COUNT(*) AS total
                       FROM incidents
                      WHERE tenant_id = :tenant_id
                        AND estado IN ('ABIERTA', 'INVESTIGACION')",
                    $tenantId
                ),
            ],
            'generated_at' => $this->now(),
        ];
    }

    // ====================================================================
    // TENANT
    // ====================================================================

    /**
     * Información mínima del tenant propio para la capa crítica
     * (spec: "Información de tenant"). No expone cuotas, contactos ni
     * configuración: eso sigue tras el permiso tenants.tenant.view.
     *
     * @return ?array{id: string, nombre: ?string}
     */
    private function tenant(string $tenantId): ?array
    {
        $sql = "SELECT id, nombre_publico, nombre_interno
                  FROM tenants
                 WHERE id = :tenant_id
                   AND deleted_at IS NULL";

        $row = $this->fetchOne($sql, ['tenant_id' => $tenantId]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'nombre' => ($row['nombre_publico'] !== null && $row['nombre_publico'] !== '')
                ? (string) $row['nombre_publico']
                : (($row['nombre_interno'] !== null && $row['nombre_interno'] !== '')
                    ? (string) $row['nombre_interno']
                    : null),
        ];
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function count(string $sql, string $tenantId): int
    {
        $row = $this->fetchOne($sql, ['tenant_id' => $tenantId]);

        return (int) ($row['total'] ?? 0);
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
