<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;

/**
 * Doble de colisión: devuelve "true" (ya existe) en las primeras N llamadas a
 * existsByBarcode para forzar que el use case reintente la generación del
 * barcode; el resto de métodos se delega al repositorio real.
 */
final class CollidingBarcodeRepository implements StockUnitRepositoryInterface
{
    private int $barcodeChecks = 0;

    public function __construct(
        private readonly StockUnitRepositoryInterface $inner,
        private readonly int $forcedCollisions = 1
    ) {
    }

    public function existsByBarcode(string $barcode): bool
    {
        $this->barcodeChecks++;

        if ($this->barcodeChecks <= $this->forcedCollisions) {
            return true;
        }

        return $this->inner->existsByBarcode($barcode);
    }

    /** Número de veces que el use case consultó la unicidad del barcode. */
    public function barcodeChecks(): int
    {
        return $this->barcodeChecks;
    }

    public function findById(string $id): ?StockUnit
    {
        return $this->inner->findById($id);
    }

    public function findByBarcode(string $barcode): ?StockUnit
    {
        return $this->inner->findByBarcode($barcode);
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->inner->findAll($filters, $page, $perPage);
    }

    public function save(StockUnit $unit): void
    {
        $this->inner->save($unit);
    }

    public function update(StockUnit $unit): void
    {
        $this->inner->update($unit);
    }

    public function count(array $filters = []): int
    {
        return $this->inner->count($filters);
    }
}
