<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Delivery;

/**
 * Contrato de persistencia para el agregado Delivery (spec §3.15).
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id (R4)
 *  - Una fila por unidad física y envío (se crea al confirmar la entrega o
 *    al registrar el fallo, en la MISMA transacción que el cambio de estado)
 *
 * Implementación: Adapters/Persistence/DeliveryRepository
 */
interface DeliveryRepositoryInterface
{
    /**
     * Persiste una entrega nueva (INSERT).
     */
    public function save(Delivery $delivery): void;

    /**
     * Entrega por ID dentro del tenant actual (o null).
     */
    public function findById(string $id): ?Delivery;

    /**
     * Entregas de un envío dentro del tenant actual.
     *
     * @return array<int, Delivery>
     */
    public function findByShipment(string $shipmentId): array;

    /**
     * Última entrega registrada para una unidad física (o null).
     */
    public function findLatestByUnidad(string $unidadFisicaId): ?Delivery;
}
