<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\PickingOperation;

/**
 * Contrato de persistencia para el agregado PickingOperation.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - `id_lote_picking` agrupa las operaciones de UN picking y es único por
 *    tenant (se comprueba con findByLote dentro de la transacción)
 *
 * Implementación: Adapters/Persistence/PickingOperationRepository
 */
interface PickingOperationRepositoryInterface
{
    /**
     * Todas las operaciones de un lote dentro del tenant actual, ordenadas
     * por fecha de creación. Vacío si el lote no existe.
     *
     * @return array<int, PickingOperation>
     */
    public function findByLote(string $idLotePicking): array;

    /**
     * Última operación de picking de una unidad física (la más reciente),
     * sea cual sea su lote.
     */
    public function findLatestByUnidad(string $unidadFisicaId): ?PickingOperation;

    /**
     * ¿Existe ya un lote con ese ID en el tenant? (unicidad del lote)
     */
    public function existsLote(string $idLotePicking): bool;

    /**
     * Persiste una operación nueva (INSERT).
     */
    public function save(PickingOperation $operation): void;

    /**
     * Actualiza una operación existente (estado / supervisor).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(PickingOperation $operation): void;
}
