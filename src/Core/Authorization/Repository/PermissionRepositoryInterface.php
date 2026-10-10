<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\Permission;

/**
 * Contrato de persistencia para el aggregate Permission.
 *
 * Los permisos son globales (no pertenecen a un tenant).
 * No admiten soft delete ni updated_at según el schema.
 * El identificador (modulo.recurso.accion) es único globalmente.
 */
interface PermissionRepositoryInterface
{
    /**
     * Busca un permiso por su ID.
     *
     * @return Permission|null Null si no existe.
     */
    public function findById(string $id): ?Permission;

    /**
     * Busca un permiso por su identificador único (ej: "inventory.item.create").
     */
    public function findByIdentificador(string $identificador): ?Permission;

    /**
     * Lista todos los permisos.
     *
     * @param bool $soloActivos Si true, excluye permisos con activo=0.
     * @return array<int, Permission>
     */
    public function findAll(bool $soloActivos = true): array;

    /**
     * Lista permisos filtrados por módulo (ej: "inventory").
     *
     * @return array<int, Permission>
     */
    public function findByModulo(string $modulo): array;

    /**
     * Lista permisos filtrados por recurso (ej: "item").
     *
     * @return array<int, Permission>
     */
    public function findByRecurso(string $recurso): array;

    /**
     * Lista permisos filtrados por criticidad.
     *
     * @param string $criticidad LOW|MEDIUM|HIGH|CRITICAL
     * @return array<int, Permission>
     */
    public function findByCriticidad(string $criticidad): array;

    /**
     * Lista permisos que requieren MFA.
     *
     * @return array<int, Permission>
     */
    public function findRequiringMfa(): array;

    /**
     * Busca permisos que coincidan con un patrón con wildcards.
     *
     * Ejemplos de patrones:
     * - "inventory.*.*"       → todos los permisos del módulo inventory
     * - "inventory.item.*"    → todas las acciones sobre item
     * - "inventory.item.view" → permiso exacto
     *
     * @return array<int, Permission>
     */
    public function findByPattern(string $pattern): array;

    /**
     * Busca permisos a partir de una lista de identificadores.
     *
     * @param array<int, string> $identificadores
     * @return array<int, Permission>
     */
    public function findByIdentificadores(array $identificadores): array;

    /**
     * Persiste un permiso nuevo (INSERT).
     *
     * @throws \RuntimeException Si el identificador ya existe.
     */
    public function save(Permission $permission): void;

    /**
     * Actualiza un permiso existente (UPDATE).
     *
     * @throws \RuntimeException Si el permiso no existe.
     */
    public function update(Permission $permission): void;

    /**
     * Elimina físicamente un permiso (hard delete).
     *
     * ADVERTENCIA: Los permisos no admiten soft delete.
     * Antes de eliminar, verificar que no esté asignado a roles.
     *
     * @throws \RuntimeException Si el permiso no existe.
     */
    public function delete(string $id): void;

    /**
     * Verifica si ya existe un permiso con el identificador dado.
     */
    public function existsByIdentificador(string $identificador): bool;

    /**
     * Verifica si un permiso existe por ID.
     */
    public function existsById(string $id): bool;

    /**
     * Cuenta permisos (opcional para paginación en controllers).
     *
     * @param bool $soloActivos Si true, solo cuenta activos.
     */
    public function count(bool $soloActivos = true): int;

    /**
     * Verifica si un permiso está asignado a algún rol.
     *
     * Útil para prevenir eliminación de permisos en uso.
     */
    public function isAssignedToAnyRole(string $permissionId): bool;
}
