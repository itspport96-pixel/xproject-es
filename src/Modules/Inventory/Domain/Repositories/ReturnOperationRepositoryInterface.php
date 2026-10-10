<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\ReturnOperation;

/**
 * Contrato de persistencia para el agregado ReturnOperation (spec §3.16).
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id (R4)
 *  - Se crea en la MISMA transacción que el cambio de estado de la unidad
 *
 * Implementación: Adapters/Persistence/ReturnOperationRepository
 */
interface ReturnOperationRepositoryInterface
{
    /**
     * Persiste una devolución nueva (INSERT).
     */
    public function save(ReturnOperation $returnOperation): void;

    /**
     * Devolución por ID dentro del tenant actual (o null).
     */
    public function findById(string $id): ?ReturnOperation;

    /**
     * Devoluciones registradas para una unidad física, más recientes primero.
     *
     * @return array<int, ReturnOperation>
     */
    public function findByUnidad(string $unidadFisicaId): array;
}
