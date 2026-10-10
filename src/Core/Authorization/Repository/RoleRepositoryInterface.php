<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\Role;

/**
 * Contrato de persistencia para el aggregate Role.
 *
 * Los roles llevan `tenant_id`: NULL identifica a los BUILT_IN (globales,
 * visibles para todos los tenants) y un valor concreto a los CUSTOM
 * (visibles/editables sólo por su tenant).
 */
interface RoleRepositoryInterface
{
    /**
     * Busca un rol por su ID.
     *
     * Si se indica $tenantId sólo devuelve el rol si es visible desde ese
     * tenant (BUILT_IN con tenant_id NULL, o CUSTOM del mismo tenant).
     *
     * @return Role|null Null si no existe, está eliminado lógicamente o no es visible.
     */
    public function findById(string $id, ?string $tenantId = null): ?Role;

    /**
     * Busca un rol por su nombre (case-insensitive), con el mismo criterio
     * de visibilidad que findById cuando se indica $tenantId.
     */
    public function findByNombre(string $nombre, ?string $tenantId = null): ?Role;

    /**
     * Lista los roles visibles.
     *
     * @param bool $soloActivos Si true, excluye roles con deleted_at o activo=0.
     * @param string|null $tenantId Si se indica, sólo BUILT_IN + CUSTOM de ese tenant.
     * @return array<int, Role>
     */
    public function findAll(bool $soloActivos = true, ?string $tenantId = null, bool $includeDeleted = false): array;

    /**
     * Lista roles filtrados por tipo (BUILT_IN | CUSTOM).
     *
     * @return array<int, Role>
     */
    public function findByTipo(string $tipo): array;

    /**
     * Lista roles que contienen al menos uno de los permisos indicados.
     *
     * @param array<int, string> $permissionIds
     * @return array<int, Role>
     */
    public function findByPermisos(array $permissionIds): array;

    /**
     * Retorna los 4 roles built-in del sistema (ADMIN, MANAGER, USER, VIEWER).
     *
     * @return array<int, Role>
     */
    public function getBuiltInRoles(): array;

    /**
     * Persiste un rol nuevo (INSERT).
     *
     * @throws \RuntimeException Si el nombre ya existe en el ámbito visible.
     */
    public function save(Role $role): void;

    /**
     * Actualiza un rol existente (UPDATE).
     *
     * @throws \RuntimeException Si el rol no existe.
     */
    public function update(Role $role): void;

    /**
     * Eliminación lógica del rol.
     *
     * @param string|null $tenantId Si se indica, sólo aplica a roles visibles desde ese tenant.
     * @throws \RuntimeException Si el rol es BUILT_IN o no es visible.
     */
    public function softDelete(string $id, ?string $tenantId = null): void;

    /**
     * Restaura un rol previamente eliminado.
     *
     * @param string|null $tenantId Si se indica, sólo aplica a roles visibles desde ese tenant.
     */
    public function restore(string $id, ?string $tenantId = null): void;

    /**
     * Verifica si ya existe un rol con el nombre dado.
     *
     * Sin $tenantId comprueba en toda la tabla. Con $tenantId comprueba el
     * ámbito visible: BUILT_IN (tenant_id NULL) + CUSTOM de ese tenant, de
     * modo que un nombre reservado por otro tenant no bloquea y el de un
     * BUILT_IN sí.
     */
    public function existsByNombre(string $nombre, ?string $tenantId = null): bool;

    /**
     * Verifica si un rol existe por ID (sin importar estado).
     */
    public function existsById(string $id): bool;

    /**
     * Cuenta roles (opcional para paginación en controllers).
     *
     * @param bool $soloActivos Si true, solo cuenta activos.
     */
    public function count(bool $soloActivos = true): int;
}
