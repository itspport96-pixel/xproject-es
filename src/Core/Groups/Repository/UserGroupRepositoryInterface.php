<?php

declare(strict_types=1);

namespace App\Core\Groups\Repository;

use App\Core\Groups\Entity\UserGroup;

/**
 * Contrato de persistencia para el aggregate UserGroup (spec §4.2).
 *
 * Los grupos son por-tenant. Los pivotes group_members/group_roles no tienen
 * tenant propio: heredan el del grupo.
 */
interface UserGroupRepositoryInterface
{
    /**
     * Lista los grupos del tenant.
     *
     * @param bool $includeDeleted Si true (v1.13.0, hueco de restauración),
     *   incluye también los borrados y omite el filtro de activo.
     *
     * @return array<int, UserGroup>
     */
    public function findAll(string $tenantId, bool $soloActivos = true, bool $includeDeleted = false): array;

    /**
     * Busca un grupo por id dentro del tenant (sin filtrar borrados).
     */
    public function findById(string $id, string $tenantId): ?UserGroup;

    /**
     * ¿Existe un grupo con ese nombre en el tenant (sin contar $excludeId)?
     */
    public function existsByNombre(string $tenantId, string $nombre, ?string $excludeId = null): bool;

    public function save(UserGroup $group): void;

    public function update(UserGroup $group): void;

    public function softDelete(string $id): void;

    /**
     * Restaura un grupo soft-deleteado (v1.13.0): inverso del softDelete
     * (deleted_at NULL y activo=1).
     *
     * @return bool true si había una fila borrada que restaurar
     */
    public function restore(string $id): bool;

    // ---------------------------------------------------------------- miembros

    /**
     * Miembros del grupo con datos de usuario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMembers(string $groupId): array;

    /**
     * Conteo de miembros por grupo de todo el tenant en una sola consulta
     * (v1.13.0, fin del N+1 de GET /groups). Misma semántica que listMembers:
     * sólo usuarios no borrados.
     *
     * @return array<string, int> claves = group_id
     */
    public function memberCounts(string $tenantId): array;

    /**
     * @return array<int, string>
     */
    public function memberUserIds(string $groupId): array;

    public function isMember(string $groupId, string $userId): bool;

    public function addMember(string $id, string $groupId, string $userId, ?string $addedByUserId): void;

    public function removeMember(string $groupId, string $userId): void;

    // ------------------------------------------------------------------- roles

    /**
     * Roles asignados al grupo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRoles(string $groupId): array;

    public function isRoleAssigned(string $groupId, string $roleId): bool;

    public function assignRole(string $id, string $groupId, string $roleId): void;

    public function removeRole(string $groupId, string $roleId): void;
}
