<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Product;

interface ProductRepositoryInterface
{
    public function findById(string $id): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * @param array $filters Filtros: activo, unidad_medida, categoria, es_serializable, es_loteable, search
     * @return array{data: array<int, Product>, pagination: array{total: int, page: int, per_page: int, has_next: bool}}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array;

    /** @return array<int, Product> */
    public function findActivos(): array;

    /** @return array<int, Product> */
    public function findByCategoria(string $categoria): array;

    /** @return array<int, Product> */
    public function findByUnidadMedida(string $unidadMedida): array;

    public function save(Product $product): void;

    public function update(Product $product): void;

    public function softDelete(string $id): void;

    /**
     * Fila cruda del producto incluyendo los borrados lógicos
     * (para auditoría del borrado: estado posterior al soft delete).
     */
    public function findRawIncludingDeleted(string $id): ?array;

    public function existsBySku(string $sku): bool;

    public function existsById(string $id): bool;

    public function hasStockUnits(string $productId): bool;

    public function count(array $filters = []): int;
}
