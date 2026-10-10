<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Shipment;

/**
 * Contrato de persistencia para el agregado Shipment.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - Estado PREPARACION → EN_RUTA al autorizar la salida (CU-006)
 *
 * Implementación: Adapters/Persistence/ShipmentRepository
 */
interface ShipmentRepositoryInterface
{
    /**
     * Busca un envío por ID dentro del tenant actual.
     */
    public function findById(string $id): ?Shipment;

    /**
     * Persiste un envío nuevo (INSERT).
     */
    public function save(Shipment $shipment): void;

    /**
     * Actualiza un envío existente (estado, hora_salida, supervisor).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(Shipment $shipment): void;
}
