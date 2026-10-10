<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Incident;

/**
 * Contrato de persistencia para el agregado Incident.
 *
 * Implementación: Adapters/Persistence/IncidentRepository
 */
interface IncidentRepositoryInterface
{
    /**
     * Persiste una incidencia nueva (INSERT).
     */
    public function save(Incident $incident): void;

    /**
     * Actualiza una incidencia existente (estado, resolución, vínculo con un
     * conteo físico). Lanza `incidentNotFound` (404) si no existe en el
     * tenant actual.
     */
    public function update(Incident $incident): void;

    /**
     * Busca una incidencia por ID dentro del tenant actual.
     */
    public function findById(string $id): ?Incident;

    /**
     * Incidencias abiertas del tenant (para capas del dashboard).
     *
     * @return array<int, Incident>
     */
    public function findAbiertas(int $limit = 100): array;
}
