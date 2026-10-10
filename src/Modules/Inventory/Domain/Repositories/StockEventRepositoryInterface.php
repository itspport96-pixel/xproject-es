<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\StockEvent;

/**
 * Contrato de persistencia para StockEvent (bitácora append-only).
 *
 * Reglas:
 *  - Sólo INSERT: no existen métodos de actualización ni borrado
 *  - Se persiste dentro de la transacción del movimiento que lo origina
 *
 * Implementación: Adapters/Persistence/StockEventRepository
 */
interface StockEventRepositoryInterface
{
    /**
     * Persiste un evento (INSERT único e inmutable).
     */
    public function save(StockEvent $event): void;

    /**
     * Historial de eventos de una unidad, orden cronológico.
     *
     * @return array<int, StockEvent>
     */
    public function findByUnidadFisica(string $unidadFisicaId, int $limit = 100): array;
}
