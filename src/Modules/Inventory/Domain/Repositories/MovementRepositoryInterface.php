<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Repositories;

use App\Modules\Inventory\Domain\Entities\Movement;

/**
 * Contrato de persistencia para el agregado Movement.
 *
 * Reglas:
 *  - Todas las consultas filtran por tenant_id
 *  - El movimiento es inmutable: sólo se INSERTA (spec §3.11 y E8: las
 *    correcciones son movimientos inversos nuevos; el histórico no se toca)
 *
 * Implementación: Adapters/Persistence/MovementRepository
 */
interface MovementRepositoryInterface
{
    /**
     * Busca un movimiento por ID dentro del tenant actual.
     */
    public function findById(string $id): ?Movement;

    /**
     * Persiste un movimiento nuevo (INSERT).
     *
     * @throws \App\Modules\Inventory\Domain\Exceptions\InventoryException Si ya existe
     */
    public function save(Movement $movement): void;

    /**
     * Cuenta movimientos del tenant con filtros opcionales.
     *
     * @param array $filters Filtros opcionales (unidad_fisica_id, ubicacion_origen_id,
     *                       ubicacion_destino_id, motivo, es_correccion,
     *                       fecha_desde, fecha_hasta)
     */
    public function count(array $filters = []): int;

    /**
     * Lista movimientos paginados (más recientes primero).
     *
     * @param array $filters Mismos filtros que count()
     * @return list<Movement>
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array;

    /**
     * Último movimiento de una unidad (v1.10.0 E8: para detectar que un
     * movimiento es la corrección inversa de uno ya registrado).
     */
    public function findLatestByUnidad(string $unidadFisicaId): ?Movement;
}
