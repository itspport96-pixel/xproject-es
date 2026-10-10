<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Location;

/**
 * Contrato de persistencia para el agregado Location.
 *
 * Reglas de aislamiento:
 *  - Todas las consultas filtran por tenant_id (garantizado por BaseRepository)
 *  - El código es único por tenant (UNIQUE(tenant_id, codigo))
 *  - Soft delete mediante deleted_at
 *
 * Implementación: Adapters/Persistence/LocationRepository
 */
interface LocationRepositoryInterface
{
    // ====================================================================
    // LECTURA
    // ====================================================================

    /**
     * Busca una ubicación por su ID dentro del tenant actual.
     *
     * @return Location|null Null si no existe o está eliminada
     */
    public function findById(string $id): ?Location;

    /**
     * Busca una ubicación por su código único dentro del tenant actual.
     *
     * @return Location|null Null si no existe o está eliminada
     */
    public function findByCodigo(string $codigo): ?Location;

    /**
     * Lista ubicaciones del tenant actual con filtros opcionales y paginación.
     *
     * Filtros permitidos:
     *  - tipo (string): ALMACENAMIENTO, PICKING, DAÑADOS, BLOQUEADOS, TRANSITO, DEVOLUCION
     *  - activa (bool): true/false
     *  - search (string): busca en codigo, nombre
     *
     * @param array $filters Filtros opcionales
     * @param int   $page    Página actual (default: 1)
     * @param int   $perPage Registros por página (default: 20, max: 100)
     * @return array{data: array<int, Location>, pagination: array{total: int, page: int, per_page: int, has_next: bool}}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array;

    /**
     * Lista todas las ubicaciones activas del tenant actual.
     *
     * @return array<int, Location>
     */
    public function findActivas(): array;

    /**
     * Lista ubicaciones filtradas por tipo.
     *
     * @return array<int, Location>
     */
    public function findByTipo(string $tipo): array;

    /**
     * Busca ubicaciones con capacidad disponible (capacidad_usada < capacidad).
     *
     * @return array<int, Location>
     */
    public function findWithCapacityAvailable(): array;

    /**
     * Busca ubicaciones por jerarquía (padre).
     *
     * @param string $jerarquiaPadre Jerarquía del padre (ej: "A-01")
     * @return array<int, Location>
     */
    public function findChildren(string $jerarquiaPadre): array;

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    /**
     * Persiste una ubicación nueva (INSERT).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si el código ya existe
     */
    public function save(Location $location): void;

    /**
     * Actualiza una ubicación existente (UPDATE).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(Location $location): void;

    /**
     * Eliminación lógica de la ubicación.
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function softDelete(string $id): void;

    /**
     * Restaura una ubicación previamente eliminada.
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function restore(string $id): void;

    // ====================================================================
    // CONSULTAS
    // ====================================================================

    /**
     * Verifica si ya existe una ubicación con el código dado en el tenant actual.
     */
    public function existsByCodigo(string $codigo): bool;

    /**
     * Verifica si una ubicación existe por ID (sin importar estado).
     */
    public function existsById(string $id): bool;

    /**
     * Verifica si la ubicación tiene unidades físicas asociadas.
     * Usado para prevenir eliminación de ubicaciones en uso.
     */
    public function hasStockUnits(string $locationId): bool;

    /**
     * Verifica si la ubicación es origen/destino de movimientos registrados.
     */
    public function hasMovements(string $locationId): bool;

    /**
     * Cuenta ubicaciones del tenant actual con filtros opcionales.
     *
     * @param array $filters Filtros opcionales (tipo, activa)
     */
    public function count(array $filters = []): int;
}
