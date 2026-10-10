<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\StockUnit;

/**
 * Contrato de persistencia para el agregado StockUnit.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - `codigo_barcode` es único POR TENANT: `UNIQUE (tenant_id, codigo_barcode)`
 *    en BD; dos tenants distintos pueden compartir código (formato SU-…)
 *  - Las unidades no se eliminan: cambian de estado (spec R8)
 *
 * Implementación: Adapters/Persistence/StockUnitRepository
 */
interface StockUnitRepositoryInterface
{
    /**
     * Busca una unidad física por ID dentro del tenant actual.
     */
    public function findById(string $id): ?StockUnit;

    /**
     * Busca una unidad por su código de barras dentro del tenant actual.
     */
    public function findByBarcode(string $barcode): ?StockUnit;

    /**
     * ¿Existe ya una unidad con ese código de barras en el tenant actual?
     * Usado por BarcodeService/use case para reintentar la generación.
     */
    public function existsByBarcode(string $barcode): bool;

    /**
     * Lista unidades del tenant con filtros opcionales y paginación.
     *
     * Filtros: producto_id, ubicacion_id, estado, search (barcode/lote/serie)
     *
     * @return array{data: array<int, StockUnit>, pagination: array{total: int, page: int, per_page: int, has_next: bool}}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array;

    /**
     * Persiste una unidad física nueva (INSERT).
     *
     * @throws \PDOException SQLSTATE 23000 si el código ya existe en el
     *         tenant actual (UNIQUE (tenant_id, codigo_barcode))
     */
    public function save(StockUnit $unit): void;

    /**
     * Actualiza una unidad existente (UPDATE de estado/ubicación/cantidades).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(StockUnit $unit): void;

    /**
     * Cuenta unidades del tenant con filtros opcionales.
     *
     * @param array $filters Filtros opcionales (producto_id, ubicacion_id, estado)
     */
    public function count(array $filters = []): int;
}
