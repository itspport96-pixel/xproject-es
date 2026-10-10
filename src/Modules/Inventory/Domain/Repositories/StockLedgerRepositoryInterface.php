<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\StockLedger;

/**
 * Contrato de persistencia para el agregado StockLedger.
 *
 * Un ledger por unidad física (UNIQUE en la práctica: se crea en la misma
 * transacción que la unidad y se actualiza en cada movimiento).
 *
 * Implementación: Adapters/Persistence/StockLedgerRepository
 */
interface StockLedgerRepositoryInterface
{
    /**
     * Busca el ledger de una unidad física dentro del tenant actual.
     */
    public function findByUnidadFisica(string $unidadFisicaId): ?StockLedger;

    /**
     * Persiste un ledger nuevo (INSERT).
     */
    public function save(StockLedger $ledger): void;

    /**
     * Actualiza un ledger existente (UPDATE de cantidades + estado).
     */
    public function update(StockLedger $ledger): void;
}
