<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\TemporaryPermission;

/**
 * Acceso a temporary_permissions (spec §5.3, v1.11.0).
 *
 * La tabla no tiene tenant_id: todas las consultas se anclan al tenant a
 * través de users.tenant_id (JOIN con el usuario beneficiario).
 */
interface TemporaryPermissionRepositoryInterface
{
    /**
     * Listado de permisos temporales del tenant.
     *
     * @param string $tenantId Tenant (filtro vía users.tenant_id)
     * @param string|null $userId Filtro opcional por usuario beneficiario
     * @param bool $soloVigentes true: sólo activos y no expirados (default)
     * @param int|null $page Paginación 1-based; null = sin LIMIT (v1.13.3)
     * @param int|null $perPage Tamaño de página (sólo con $page)
     * @return array<int, array<string, mixed>> Filas con joins (permiso y usuarios)
     */
    public function findAll(
        string $tenantId,
        ?string $userId = null,
        bool $soloVigentes = true,
        ?int $page = null,
        ?int $perPage = null
    ): array;

    /**
     * Total de filas que cumplen los filtros de findAll (para paginar).
     */
    public function count(string $tenantId, ?string $userId = null, bool $soloVigentes = true): int;

    /**
     * Fila por id dentro del tenant (JOIN con el usuario beneficiario).
     *
     * @return array<string, mixed>|null null si no existe en el tenant
     */
    public function findById(string $id, string $tenantId): ?array;

    public function save(TemporaryPermission $temporaryPermission): void;

    /**
     * Revoca (activo = 0) si la fila estaba activa.
     *
     * @return bool true si la fila cambió (estaba activa)
     */
    public function deactivate(string $id): bool;

    /**
     * ¿Existe ya un permiso temporal vigente para ese usuario/permiso/recurso?
     * Se usa para denegar duplicados con 409.
     */
    public function existsVigente(string $userId, string $permissionId, ?string $recursoId, string $tenantId): bool;
}
