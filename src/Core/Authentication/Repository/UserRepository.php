<?php

declare(strict_types=1);

namespace App\Core\Authentication\Repository;

use App\Shared\Services\DatabaseService;

class UserRepository implements UserRepositoryInterface
{
    private DatabaseService $db;

    public function __construct(DatabaseService $db)
    {
        $this->db = $db;
    }

    public function findByEmail(string $email, string $tenantId): ?array
    {
        $sql = "SELECT * FROM users
                WHERE email = ? AND tenant_id = ? AND deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetch($sql, [$email, $tenantId]);
    }

    public function findById(string $id, string $tenantId): ?array
    {
        $sql = "SELECT * FROM users
                WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                LIMIT 1";
        return $this->db->fetch($sql, [$id, $tenantId]);
    }

    public function updateLastLogin(string $userId): bool
    {
        $sql = "UPDATE users SET ultimo_login = NOW() WHERE id = ?";
        return $this->db->query($sql, [$userId])->rowCount() >= 0;
    }

    public function getUserRoles(string $userId, string $tenantId): array
    {
        $sql = "SELECT r.id, r.nombre, r.tipo, r.activo
                FROM roles r
                INNER JOIN role_assignments ra ON ra.role_id = r.id
                WHERE ra.user_id = ?
                  AND ra.tenant_id = ?
                  AND r.activo = 1
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())
                ORDER BY r.nombre ASC";

        return $this->db->fetchAll($sql, [$userId, $tenantId]);
    }

    public function getUserPermissions(string $userId, string $tenantId): array
    {
        $sql = "SELECT DISTINCT p.identificador
                FROM permissions p
                WHERE p.activo = 1
                AND (
                    -- Permisos via roles asignados directamente (role_permissions junction)
                    EXISTS (
                        SELECT 1 FROM role_assignments ra
                        INNER JOIN roles r ON r.id = ra.role_id
                        INNER JOIN role_permissions rp ON rp.role_id = r.id
                        WHERE ra.user_id = ?
                          AND ra.tenant_id = ?
                          AND r.activo = 1
                          AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())
                          AND rp.permission_id = p.id
                    )
                    OR
                    -- Permisos via grupos (grupo activo y no borrado, v1.11.0)
                    EXISTS (
                        SELECT 1 FROM group_members gm
                        INNER JOIN group_roles gr ON gr.group_id = gm.group_id
                        INNER JOIN roles r ON r.id = gr.role_id
                        INNER JOIN user_groups ug ON ug.id = gm.group_id
                        INNER JOIN role_permissions rp ON rp.role_id = r.id
                        WHERE gm.user_id = ?
                          AND ug.tenant_id = ?
                          AND ug.activo = 1
                          AND ug.deleted_at IS NULL
                          AND r.activo = 1
                          AND rp.permission_id = p.id
                    )
                    OR
                    -- Permisos temporales activos
                    EXISTS (
                        SELECT 1 FROM temporary_permissions tp
                        WHERE tp.user_id = ?
                          AND tp.permission_id = p.id
                          AND tp.activo = 1
                          AND tp.fecha_inicio <= NOW()
                          AND tp.fecha_expiracion > NOW()
                    )
                )
                ORDER BY p.identificador ASC";

        $rows = $this->db->fetchAll($sql, [$userId, $tenantId, $userId, $tenantId, $userId]);

        return array_column($rows, 'identificador');
    }

    public function isActive(string $userId, string $tenantId): bool
    {
        $sql = "SELECT estado FROM users
                WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                LIMIT 1";
        $row = $this->db->fetch($sql, [$userId, $tenantId]);

        if ($row === null) {
            return false;
        }

        return $row['estado'] === 'ACTIVO';
    }

    public function getAuthState(string $userId, string $tenantId): ?array
    {
        $sql = "SELECT estado, password_change_forced, fecha_expiracion_password FROM users
                WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                LIMIT 1";

        return $this->db->fetch($sql, [$userId, $tenantId]);
    }
}
