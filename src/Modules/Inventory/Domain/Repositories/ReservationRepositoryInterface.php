<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Reservation;
use DateTimeImmutable;

/**
 * Contrato de persistencia para el agregado Reservation.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - Una unidad física admite a lo sumo UNA reserva ACTIVA a la vez (se
 *    comprueba con findActivaByUnidad dentro de la transacción)
 *
 * Implementación: Adapters/Persistence/ReservationRepository
 */
interface ReservationRepositoryInterface
{
    /**
     * Busca una reserva por ID dentro del tenant actual.
     */
    public function findById(string $id): ?Reservation;

    /**
     * Reserva ACTIVA de una unidad física (null si no tiene).
     * Se usa para rechazar la doble reserva (spec CU-003 / R8).
     */
    public function findActivaByUnidad(string $unidadFisicaId): ?Reservation;

    /**
     * Reservas ACTIVAS cuyo plazo ya venció (spec E7), para el comando de
     * expiración.
     *
     * @return array<int, Reservation>
     */
    public function findExpiradas(DateTimeImmutable $now, int $limit = 500): array;

    /**
     * Persiste una reserva nueva (INSERT).
     */
    public function save(Reservation $reservation): void;

    /**
     * Actualiza una reserva existente (estado/motivo de cancelación).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si no existe
     */
    public function update(Reservation $reservation): void;

    /**
     * Cuenta reservas del tenant con filtros opcionales.
     *
     * @param array $filters Filtros opcionales (estado, unidad_fisica_id)
     */
    public function count(array $filters = []): int;
}
