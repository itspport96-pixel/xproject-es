<?php

declare(strict_types=1);

namespace App\Core\Groups\Repository;

use App\Core\Groups\Entity\UserGroup;
use App\Core\Groups\Exception\GroupException;
use App\Shared\Services\DatabaseService;

final class UserGroupRepository implements UserGroupRepositoryInterface
{
    public function __construct(private DatabaseService $db)
    {
    }

    public function findAll(string $tenantId, bool $soloActivos = true): array
    {
        $sql = "SELECT * FROM user_groups
                WHERE tenant_id = :tid AND deleted_at IS NULL"
            . ($soloActivos ? " AND activo = 1" : "")
            . " ORDER BY nombre ASC";

        $rows = $this->db->fetchAll($sql, ['tid' => $tenantId]);

        return array_map(fn(array $row): UserGroup => UserGroup::fromArray($row), $rows);
    }

    public function findById(string $id, string $tenantId): ?UserGroup
    {
        $sql = "SELECT * FROM user_groups
                WHERE id = :id AND tenant_id = :tid
                LIMIT 1";
        $row = $this->db->fetch($sql, ['id' => $id, 'tid' => $tenantId]);

        return $row !== null && $row !== false ? UserGroup::fromArray($row) : null;
    }

    public function existsByNombre(string $tenantId, string $nombre, ?string $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM user_groups
                WHERE tenant_id = :tid AND nombre = :nombre AND deleted_at IS NULL";
        $params = ['tid' => $tenantId, 'nombre' => $nombre];

        if ($excludeId !== null) {
            $sql .= " AND id <> :excl";
            $params['excl'] = $excludeId;
        }

        return (int) $this->db->query($sql, $params)->fetchColumn() > 0;
    }

    public function save(UserGroup $group): void
    {
        $sql = "INSERT INTO user_groups
                (id, tenant_id, nombre, descripcion, propietario_id, activo, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :nombre, :descripcion, :propietario_id, :activo, :created_at, :updated_at)";

        $this->db->query($sql, [
            'id'             => $group->id(),
            'tenant_id'      => $group->tenantId(),
            'nombre'         => $group->nombre(),
            'descripcion'    => $group->descripcion(),
            'propietario_id' => $group->propietarioId(),
            'activo'         => $group->activo() ? 1 : 0,
            'created_at'     => $group->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'     => $group->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(UserGroup $group): void
    {
        $sql = "UPDATE user_groups
                SET nombre = :nombre,
                    descripcion = :descripcion,
                    propietario_id = :propietario_id,
                    activo = :activo,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL";

        $this->db->query($sql, [
            'id'             => $group->id(),
            'tid'            => $group->tenantId(),
            'nombre'         => $group->nombre(),
            'descripcion'    => $group->descripcion(),
            'propietario_id' => $group->propietarioId(),
            'activo'         => $group->activo() ? 1 : 0,
            'updated_at'     => $group->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function softDelete(string $id): void
    {
        $sql = "UPDATE user_groups
                SET deleted_at = NOW(), activo = 0, updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL";
        $this->db->query($sql, ['id' => $id]);
    }

    public function findRawIncludingDeleted(string $id): ?array
    {
        $sql = "SELECT * FROM user_groups WHERE id = :id LIMIT 1";
        $row = $this->db->fetch($sql, ['id' => $id]);

        return $row !== null && $row !== false ? $row : null;
    }

    // ---------------------------------------------------------------- miembros

    public function memberCounts(string $tenantId): array
    {
        $sql = "SELECT gm.group_id, COUNT(*) AS total
                FROM group_members gm
                INNER JOIN users u ON u.id = gm.user_id
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE u.deleted_at IS NULL
                  AND ug.tenant_id = :tid
                GROUP BY gm.group_id";

        $rows = $this->db->fetchAll($sql, ['tid' => $tenantId]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['group_id']] = (int) $row['total'];
        }

        return $counts;
    }

    public function listMembers(string $groupId): array
    {
        $sql = "SELECT gm.id AS membership_id, gm.user_id, gm.added_at, gm.added_by_user_id,
                       u.email, u.nombre, u.apellido, u.estado
                FROM group_members gm
                INNER JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id = :gid
                  AND u.deleted_at IS NULL
                ORDER BY u.nombre ASC, u.apellido ASC";

        return $this->db->fetchAll($sql, ['gid' => $groupId]);
    }

    public function memberUserIds(string $groupId): array
    {
        $sql = "SELECT gm.user_id
                FROM group_members gm
                INNER JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id = :gid
                  AND u.deleted_at IS NULL";
        $rows = $this->db->fetchAll($sql, ['gid' => $groupId]);

        return array_values(array_map(static fn(array $r): string => (string) $r['user_id'], $rows));
    }

    public function isMember(string $groupId, string $userId): bool
    {
        $sql = "SELECT COUNT(*) FROM group_members WHERE group_id = :gid AND user_id = :uid";
        $params = ['gid' => $groupId, 'uid' => $userId];

        return (int) $this->db->query($sql, $params)->fetchColumn() > 0;
    }

    public function addMember(string $id, string $groupId, string $userId, ?string $addedByUserId): void
    {
        if ($this->isMember($groupId, $userId)) {
            throw GroupException::alreadyMember($userId);
        }

        $sql = "INSERT INTO group_members (id, group_id, user_id, added_at, added_by_user_id)
                VALUES (:id, :gid, :uid, NOW(), :by)";
        $this->db->query($sql, [
            'id'  => $id,
            'gid' => $groupId,
            'uid' => $userId,
            'by'  => $addedByUserId,
        ]);
    }

    public function removeMember(string $groupId, string $userId): void
    {
        if (!$this->isMember($groupId, $userId)) {
            throw GroupException::notMember($userId);
        }

        $sql = "DELETE FROM group_members WHERE group_id = :gid AND user_id = :uid";
        $this->db->query($sql, ['gid' => $groupId, 'uid' => $userId]);
    }

    // ------------------------------------------------------------------- roles

    public function listRoles(string $groupId): array
    {
        $sql = "SELECT gr.id AS assignment_id, gr.role_id, gr.assigned_at,
                       r.nombre, r.tipo, r.descripcion, r.activo, r.deleted_at AS role_deleted_at
                FROM group_roles gr
                INNER JOIN roles r ON r.id = gr.role_id
                WHERE gr.group_id = :gid
                ORDER BY r.nombre ASC";

        return $this->db->fetchAll($sql, ['gid' => $groupId]);
    }

    public function isRoleAssigned(string $groupId, string $roleId): bool
    {
        $sql = "SELECT COUNT(*) FROM group_roles WHERE group_id = :gid AND role_id = :rid";
        return (int) $this->db->query($sql, ['gid' => $groupId, 'rid' => $roleId])->fetchColumn() > 0;
    }

    public function assignRole(string $id, string $groupId, string $roleId): void
    {
        if ($this->isRoleAssigned($groupId, $roleId)) {
            throw GroupException::roleAlreadyAssigned($roleId);
        }

        $sql = "INSERT INTO group_roles (id, group_id, role_id, assigned_at)
                VALUES (:id, :gid, :rid, NOW())";
        $this->db->query($sql, ['id' => $id, 'gid' => $groupId, 'rid' => $roleId]);
    }

    public function removeRole(string $groupId, string $roleId): void
    {
        if (!$this->isRoleAssigned($groupId, $roleId)) {
            throw GroupException::roleNotAssigned($roleId);
        }

        $sql = "DELETE FROM group_roles WHERE group_id = :gid AND role_id = :rid";
        $this->db->query($sql, ['gid' => $groupId, 'rid' => $roleId]);
    }
}
