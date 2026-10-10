<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\InventoryCount;

/**
 * Contrato de persistencia para el agregado InventoryCount (CU-011).
 *
 * Implementación: Adapters/Persistence/InventoryCountRepository
 */
interface InventoryCountRepositoryInterface
{
    /**
     * Persiste un conteo físico nuevo (INSERT).
     */
    public function save(InventoryCount $count): void;

    /**
     * Actualiza un conteo existente (estado, resultados, discrepancias,
     * ajustes). Lanza `countNotFound` (404) si no existe en el tenant actual.
     */
    public function update(InventoryCount $count): void;

    /**
     * Busca un conteo por ID dentro del tenant actual.
     */
    public function findById(string $id): ?InventoryCount;
}
