<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\ShipmentItem;

/**
 * Contrato de persistencia para el agregado ShipmentItem.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - Una unidad física aparece en UN envío abierto a la vez (garantizado por
 *    el estado de la unidad: no se puede cargar algo que ya está CARGADO)
 *
 * Implementación: Adapters/Persistence/ShipmentItemRepository
 */
interface ShipmentItemRepositoryInterface
{
    /**
     * Todos los ítems de un envío dentro del tenant actual, por orden de
     * creación.
     *
     * @return array<int, ShipmentItem>
     */
    public function findByShipment(string $shipmentId): array;

    /**
     * Persiste un ítem nuevo (INSERT).
     */
    public function save(ShipmentItem $item): void;

    /**
     * Actualiza un ítem existente (estado).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(ShipmentItem $item): void;
}
