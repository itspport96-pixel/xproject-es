<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;

/**
 * Doble en memoria del repositorio MySQL de ubicaciones.
 *
 * Reproduce la semántica del LocationRepository real sin base de datos:
 * aislamiento por tenant, soft delete, filtros, jerarquía por prefijo,
 * capacidad disponible y bloqueos de borrado (stock/movimientos).
 */
final class InMemoryLocationRepository implements LocationRepositoryInterface
{
    /** @var array<string, Location> */
    private array $items = [];

    /** @var array<string, true> columnas deleted_at simuladas */
    private array $deleted = [];

    /** @var array<string, true> ubicaciones con unidades de stock asociadas */
    private array $stockUnits = [];

    /** @var array<string, true> ubicaciones con movimientos asociados */
    private array $movements = [];

    public function __construct(private readonly string $tenantId)
    {
    }

    // ==================================================================
    // UTILIDADES DE TEST
    // ==================================================================

    public function seed(Location $location): void
    {
        $this->items[$location->id()] = $location;
    }

    public function withStockUnits(string $locationId): void
    {
        $this->stockUnits[$locationId] = true;
    }

    public function withMovements(string $locationId): void
    {
        $this->movements[$locationId] = true;
    }

    public function isSoftDeleted(string $id): bool
    {
        return isset($this->deleted[$id]);
    }

    public function stored(string $id): ?Location
    {
        return $this->items[$id] ?? null;
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function findById(string $id): ?Location
    {
        $location = $this->items[$id] ?? null;

        if ($location === null || !$this->belongsToCurrentTenant($location) || isset($this->deleted[$id])) {
            return null;
        }

        return $location;
    }

    public function findByCodigo(string $codigo): ?Location
    {
        $normalized = strtoupper(trim($codigo));

        foreach ($this->visibleItems() as $location) {
            if ($location->codigo()->value() === $normalized) {
                return $location;
            }
        }

        return null;
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);

        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Location $l) => $this->matches($l, $filters)
        ));

        // ORDER BY codigo ASC
        usort($items, fn(Location $a, Location $b) => strcmp($a->codigo()->value(), $b->codigo()->value()));

        $total = count($items);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_slice($items, $offset, $perPage),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    public function findActivas(): array
    {
        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Location $l) => $l->activa()
        ));

        usort($items, fn(Location $a, Location $b) => strcmp($a->codigo()->value(), $b->codigo()->value()));

        return $items;
    }

    public function findByTipo(string $tipo): array
    {
        $normalized = strtoupper(trim($tipo));

        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Location $l) => $l->tipo()->value() === $normalized
        ));

        usort($items, fn(Location $a, Location $b) => strcmp($a->codigo()->value(), $b->codigo()->value()));

        return $items;
    }

    public function findWithCapacityAvailable(): array
    {
        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Location $l) => $l->activa()
                && ($l->capacidad() === null || $l->capacidadUsada() < $l->capacidad())
        ));

        usort($items, fn(Location $a, Location $b) => strcmp($a->codigo()->value(), $b->codigo()->value()));

        return $items;
    }

    public function findChildren(string $jerarquiaPadre): array
    {
        $prefix = trim($jerarquiaPadre);

        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Location $l) => $l->jerarquia() !== null
                && str_starts_with($l->jerarquia(), $prefix)
        ));

        usort($items, fn(Location $a, Location $b) => strcmp($a->codigo()->value(), $b->codigo()->value()));

        return $items;
    }

    // ==================================================================
    // ESCRITURA
    // ==================================================================

    public function save(Location $location): void
    {
        if ($this->findByCodigo($location->codigo()->value()) !== null) {
            throw InventoryException::locationCodeAlreadyExists($location->codigo()->value());
        }

        $this->items[$location->id()] = $location;
    }

    public function update(Location $location): void
    {
        if ($this->findById($location->id()) === null) {
            throw InventoryException::locationNotFound($location->id());
        }

        $this->items[$location->id()] = $location;
    }

    public function softDelete(string $id): void
    {
        $location = $this->findById($id);
        if ($location === null) {
            return;
        }

        $location->deactivate();
        $this->deleted[$id] = true;
    }

    public function restore(string $id): void
    {
        $location = $this->items[$id] ?? null;

        if ($location === null || !$this->belongsToCurrentTenant($location) || !isset($this->deleted[$id])) {
            return;
        }

        unset($this->deleted[$id]);
        $location->activate();
    }

    public function existsByCodigo(string $codigo): bool
    {
        return $this->findByCodigo($codigo) !== null;
    }

    public function existsById(string $id): bool
    {
        $location = $this->items[$id] ?? null;

        return $location !== null && $this->belongsToCurrentTenant($location);
    }

    public function hasStockUnits(string $locationId): bool
    {
        return isset($this->stockUnits[$locationId]);
    }

    public function hasMovements(string $locationId): bool
    {
        return isset($this->movements[$locationId]);
    }

    public function count(array $filters = []): int
    {
        $count = 0;

        foreach ($this->visibleItems() as $location) {
            if (!empty($filters['tipo'])
                && $location->tipo()->value() !== strtoupper(trim((string) $filters['tipo']))) {
                continue;
            }
            if (isset($filters['activa']) && $location->activa() !== (bool) $filters['activa']) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    // ==================================================================
    // INTERNOS
    // ==================================================================

    /**
     * @return array<string, Location>
     */
    private function visibleItems(): array
    {
        $items = [];

        foreach ($this->items as $id => $location) {
            if ($this->belongsToCurrentTenant($location) && !isset($this->deleted[$id])) {
                $items[$id] = $location;
            }
        }

        return $items;
    }

    private function belongsToCurrentTenant(Location $location): bool
    {
        return $location->tenantId() === $this->tenantId;
    }

    private function matches(Location $location, array $filters): bool
    {
        if (!empty($filters['tipo'])
            && $location->tipo()->value() !== strtoupper(trim((string) $filters['tipo']))) {
            return false;
        }

        if (isset($filters['activa']) && $location->activa() !== (bool) $filters['activa']) {
            return false;
        }

        if (!empty($filters['search'])) {
            $term = (string) $filters['search'];
            $haystack = $location->codigo()->value() . ' ' . ($location->nombre() ?? '');

            if (stripos($haystack, $term) === false) {
                return false;
            }
        }

        if (!empty($filters['jerarquia'])) {
            if ($location->jerarquia() === null
                || !str_starts_with($location->jerarquia(), trim((string) $filters['jerarquia']))) {
                return false;
            }
        }

        return true;
    }
}
