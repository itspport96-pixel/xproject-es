<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;

/**
 * Doble en memoria del repositorio MySQL de productos.
 *
 * Reproduce la semántica del ProductRepository real sin base de datos:
 * aislamiento por tenant, soft delete (deleted_at), filtros y paginación.
 */
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<string, Product> */
    private array $items = [];

    /** @var array<string, true> columnas deleted_at simuladas */
    private array $deleted = [];

    /** @var array<string, true> productos con unidades de stock asociadas */
    private array $stockUnits = [];

    public function __construct(private readonly string $tenantId)
    {
    }

    // ==================================================================
    // UTILIDADES DE TEST
    // ==================================================================

    public function seed(Product $product): void
    {
        $this->items[$product->id()] = $product;
    }

    public function withStockUnits(string $productId): void
    {
        $this->stockUnits[$productId] = true;
    }

    public function isSoftDeleted(string $id): bool
    {
        return isset($this->deleted[$id]);
    }

    public function stored(string $id): ?Product
    {
        return $this->items[$id] ?? null;
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function findById(string $id): ?Product
    {
        $product = $this->items[$id] ?? null;

        if ($product === null || !$this->belongsToCurrentTenant($product) || isset($this->deleted[$id])) {
            return null;
        }

        return $product;
    }

    public function findBySku(string $sku): ?Product
    {
        $normalized = strtoupper(trim($sku));

        foreach ($this->visibleItems() as $product) {
            if ($product->sku()->value() === $normalized) {
                return $product;
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
            fn(Product $p) => $this->matches($p, $filters)
        ));

        // ORDER BY created_at DESC (con desempate por id para ser determinista)
        usort($items, function (Product $a, Product $b): int {
            $byDate = $b->createdAt() <=> $a->createdAt();

            return $byDate !== 0 ? $byDate : strcmp($b->id(), $a->id());
        });

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

    public function findActivos(): array
    {
        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Product $p) => $p->activo()
        ));

        usort($items, fn(Product $a, Product $b) => strcmp($a->nombre(), $b->nombre()));

        return $items;
    }

    public function findByCategoria(string $categoria): array
    {
        $categoria = trim($categoria);

        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Product $p) => $p->activo() && $p->categoria() === $categoria
        ));

        usort($items, fn(Product $a, Product $b) => strcmp($a->nombre(), $b->nombre()));

        return $items;
    }

    public function findByUnidadMedida(string $unidadMedida): array
    {
        $um = strtoupper(trim($unidadMedida));

        $items = array_values(array_filter(
            $this->visibleItems(),
            fn(Product $p) => $p->activo() && $p->unidadMedida()->value() === $um
        ));

        usort($items, fn(Product $a, Product $b) => strcmp($a->nombre(), $b->nombre()));

        return $items;
    }

    // ==================================================================
    // ESCRITURA
    // ==================================================================

    public function save(Product $product): void
    {
        if ($this->findBySku($product->sku()->value()) !== null) {
            throw InventoryException::skuAlreadyExists($product->sku()->value());
        }

        $this->items[$product->id()] = $product;
    }

    public function update(Product $product): void
    {
        if ($this->findById($product->id()) === null) {
            throw InventoryException::productNotFound($product->id());
        }

        $this->items[$product->id()] = $product;
    }

    public function softDelete(string $id): void
    {
        $product = $this->findById($id);
        if ($product === null) {
            return;
        }

        $product->deactivate();
        $this->deleted[$id] = true;
    }

    public function findRawIncludingDeleted(string $id): ?array
    {
        $product = $this->items[$id] ?? null;

        if ($product === null || !$this->belongsToCurrentTenant($product)) {
            return null;
        }

        return [
            'id'         => $product->id(),
            'tenant_id'  => $product->tenantId(),
            'sku'        => $product->sku()->value(),
            'deleted'    => isset($this->deleted[$id]),
            'deleted_at' => isset($this->deleted[$id]) ? 'simulado' : null,
        ];
    }

    public function existsBySku(string $sku): bool
    {
        return $this->findBySku($sku) !== null;
    }

    public function existsById(string $id): bool
    {
        $product = $this->items[$id] ?? null;

        return $product !== null && $this->belongsToCurrentTenant($product);
    }

    public function hasStockUnits(string $productId): bool
    {
        return isset($this->stockUnits[$productId]);
    }

    public function count(array $filters = []): int
    {
        $count = 0;

        foreach ($this->visibleItems() as $product) {
            if (isset($filters['activo']) && $product->activo() !== (bool) $filters['activo']) {
                continue;
            }
            if (!empty($filters['unidad_medida'])
                && $product->unidadMedida()->value() !== strtoupper(trim((string) $filters['unidad_medida']))) {
                continue;
            }
            if (!empty($filters['categoria']) && $product->categoria() !== trim((string) $filters['categoria'])) {
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
     * @return array<string, Product>
     */
    private function visibleItems(): array
    {
        $items = [];

        foreach ($this->items as $id => $product) {
            if ($this->belongsToCurrentTenant($product) && !isset($this->deleted[$id])) {
                $items[$id] = $product;
            }
        }

        return $items;
    }

    private function belongsToCurrentTenant(Product $product): bool
    {
        return $product->tenantId() === $this->tenantId;
    }

    private function matches(Product $product, array $filters): bool
    {
        if (isset($filters['activo']) && $product->activo() !== (bool) $filters['activo']) {
            return false;
        }

        if (!empty($filters['unidad_medida'])
            && $product->unidadMedida()->value() !== strtoupper(trim((string) $filters['unidad_medida']))) {
            return false;
        }

        if (!empty($filters['categoria']) && $product->categoria() !== trim((string) $filters['categoria'])) {
            return false;
        }

        if (!empty($filters['search'])) {
            $term = (string) $filters['search'];
            $haystack = $product->sku()->value() . ' ' . $product->nombre() . ' '
                . ($product->descripcion() ?? '') . ' ' . ($product->categoria() ?? '');

            if (stripos($haystack, $term) === false) {
                return false;
            }
        }

        return true;
    }
}
