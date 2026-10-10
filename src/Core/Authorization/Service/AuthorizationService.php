<?php

declare(strict_types=1);

namespace App\Core\Authorization\Service;

use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;

final class AuthorizationService
{
    private UserRepositoryInterface $users;
    private RoleRepositoryInterface $roles;
    private PermissionRepositoryInterface $permissions;
    private DatabaseService $db;
    private RedisService $redis;

    private const CACHE_TTL = 60;
    private const CACHE_PREFIX = 'auth:perms:';

    public function __construct(
        ?UserRepositoryInterface $users = null,
        ?RoleRepositoryInterface $roles = null,
        ?PermissionRepositoryInterface $permissions = null,
        ?DatabaseService $db = null,
        ?RedisService $redis = null
    ) {
        $container = Container::getInstance();
        $this->users = $users ?? $container->get(UserRepositoryInterface::class);
        $this->roles = $roles ?? $container->get(RoleRepositoryInterface::class);
        $this->permissions = $permissions ?? $container->get(PermissionRepositoryInterface::class);
        $this->db = $db ?? $container->get('database');
        $this->redis = $redis ?? $container->get(RedisService::class);
    }

    public function hasPermission(string $userId, string $tenantId, string $permissionId, bool $checkMfa = true): bool
    {
        $permisos = $this->getUserPermissions($userId, $tenantId);

        if (in_array($permissionId, $permisos, true)) {
            return $this->validateMfaIfNeeded($userId, $permissionId, $checkMfa);
        }

        foreach ($permisos as $perm) {
            if ($this->matchesPattern($permissionId, $perm)) {
                return $this->validateMfaIfNeeded($userId, $permissionId, $checkMfa);
            }
        }

        return false;
    }

    public function requirePermission(string $userId, string $tenantId, string $permissionId, bool $checkMfa = true): void
    {
        if (!$this->hasPermission($userId, $tenantId, $permissionId, $checkMfa)) {
            throw new AuthorizationException(
                "El usuario '{$userId}' no tiene el permiso '{$permissionId}' en el tenant '{$tenantId}'.",
                403
            );
        }
    }

    public function hasAnyPermission(string $userId, string $tenantId, array $permissionIds, bool $checkMfa = true): bool
    {
        foreach ($permissionIds as $pid) {
            if ($this->hasPermission($userId, $tenantId, $pid, $checkMfa)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(string $userId, string $tenantId, array $permissionIds, bool $checkMfa = true): bool
    {
        foreach ($permissionIds as $pid) {
            if (!$this->hasPermission($userId, $tenantId, $pid, $checkMfa)) {
                return false;
            }
        }
        return true;
    }

    public function getUserPermissions(string $userId, string $tenantId): array
    {
        $cacheKey = self::CACHE_PREFIX . $tenantId . ':' . $userId;

        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded) && isset($decoded['permisos']) && is_array($decoded['permisos'])) {
                // Formato v1.13.0: incluye la caducidad del temporal más
                // próximo. Si ya venció, la entrada se ignora y se
                // recalcula (la expiración surte efecto sin esperar al TTL).
                $expiraEn = $decoded['expira_en'] ?? null;
                if ($expiraEn === null || (string) $expiraEn > date('Y-m-d H:i:s')) {
                    return $decoded['permisos'];
                }
            } elseif (is_array($decoded)) {
                // Formato legado (array plano): se respeta; el TTL de 60 s
                // lo retira solo.
                return $decoded;
            }
        }

        $permisos = [];
        $permisos = array_merge($permisos, $this->getPermissionsFromDirectRoles($userId, $tenantId));
        $permisos = array_merge($permisos, $this->getPermissionsFromGroupRoles($userId, $tenantId));
        $permisos = array_merge($permisos, $this->getTemporaryPermissions($userId));

        $permisos = array_values(array_unique($permisos));
        $permisos = $this->filterActivePermissions($permisos);

        $this->redis->set($cacheKey, json_encode([
            'permisos' => $permisos,
            'expira_en' => $this->nearestTemporaryExpiry($userId),
        ]), self::CACHE_TTL);

        return $permisos;
    }

    public function invalidateCache(string $userId, string $tenantId): void
    {
        $cacheKey = self::CACHE_PREFIX . $tenantId . ':' . $userId;
        $this->redis->delete($cacheKey);
    }

    /**
     * Roles efectivos del usuario con su origen (v1.12.1): roles directos de
     * role_assignments y roles otorgados por grupos activos. Es la fuente
     * unica que usan login y /auth/me; el token no es la autoridad.
     *
     * @return array<int, array<string, mixed>> filas con id, nombre, tipo,
     *         activo, permisos y origen ('directo' | 'grupo'), ordenadas por nombre.
     */
    public function getUserRolesWithOrigin(string $userId, string $tenantId): array
    {
        $roles = [];

        foreach ($this->fetchRoles($userId, $tenantId, false) as $row) {
            $row['origen'] = 'directo';
            $roles[(string) $row['id']] = $row;
        }

        foreach ($this->fetchRoles($userId, $tenantId, true) as $row) {
            $id = (string) $row['id'];
            if (isset($roles[$id])) {
                continue;
            }
            $row['origen'] = 'grupo';
            $roles[$id] = $row;
        }

        $lista = array_values($roles);
        usort(
            $lista,
            static fn(array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre'])
        );

        return $lista;
    }

    /**
     * Permisos temporales vigentes del usuario (v1.12.1): lo que /auth/me
     * expone en permisos_temporales para que la UI distinga el origen.
     *
     * @return array<int, array{identificador: string, fecha_expiracion: string, motivo: ?string}>
     */
    public function getActiveTemporaryPermissions(string $userId): array
    {
        $sql = "SELECT p.identificador, tp.fecha_expiracion, tp.motivo
                FROM temporary_permissions tp
                INNER JOIN permissions p ON p.id = tp.permission_id
                WHERE tp.user_id = :uid
                  AND tp.activo = 1
                  AND tp.fecha_inicio <= NOW()
                  AND tp.fecha_expiracion > NOW()
                  AND p.activo = 1
                ORDER BY tp.fecha_expiracion ASC";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);

        return array_map(
            static fn(array $row): array => [
                'identificador' => (string) $row['identificador'],
                'fecha_expiracion' => (string) $row['fecha_expiracion'],
                'motivo' => $row['motivo'] !== null ? (string) $row['motivo'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Filas de roles del usuario: directos (role_assignments) o via grupos
     * activos (mismas condiciones que los permisos efectivos).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRoles(string $userId, string $tenantId, bool $porGrupo): array
    {
        if ($porGrupo) {
            $sql = "SELECT r.id, r.nombre, r.tipo, r.activo, r.permisos
                    FROM group_members gm
                    INNER JOIN group_roles gr ON gr.group_id = gm.group_id
                    INNER JOIN roles r ON r.id = gr.role_id
                    INNER JOIN users u ON u.id = gm.user_id
                    INNER JOIN user_groups ug ON ug.id = gm.group_id
                    WHERE gm.user_id = :uid
                      AND u.tenant_id = :tid
                      AND ug.tenant_id = :gtid
                      AND ug.activo = 1
                      AND ug.deleted_at IS NULL
                      AND r.activo = 1
                      AND r.deleted_at IS NULL";
            $params = ['uid' => $userId, 'tid' => $tenantId, 'gtid' => $tenantId];
        } else {
            $sql = "SELECT r.id, r.nombre, r.tipo, r.activo, r.permisos
                    FROM role_assignments ra
                    INNER JOIN roles r ON r.id = ra.role_id
                    WHERE ra.user_id = :uid
                      AND ra.tenant_id = :tid
                      AND r.activo = 1
                      AND r.deleted_at IS NULL
                      AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";
            $params = ['uid' => $userId, 'tid' => $tenantId];
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Invalida el cache de permisos de todos los usuarios que tienen asignado
     * el rol indicado en el tenant, tanto directamente (role_assignments)
     * como vía grupos que lo otorgan (group_roles) (v1.13.1): al mutar un rol
     * (permisos, estado, baja) los portadores por grupo deben ver el cambio
     * de inmediato, sin esperar al TTL de 60 s.
     */
    public function invalidateCacheForRole(string $roleId, string $tenantId): void
    {
        $sql = "SELECT user_id
                FROM role_assignments
                WHERE role_id = :rid1 AND tenant_id = :tid1
                UNION
                SELECT gm.user_id
                FROM group_members gm
                INNER JOIN group_roles gr ON gr.group_id = gm.group_id
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gr.role_id = :rid2 AND ug.tenant_id = :tid2";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'rid1' => $roleId,
            'tid1' => $tenantId,
            'rid2' => $roleId,
            'tid2' => $tenantId,
        ]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->invalidateCache((string) $row['user_id'], $tenantId);
        }
    }

    /**
     * Invalida el cache de permisos de todos los miembros del grupo (v1.11.0).
     * Usar tras mutar la pertenencia o los roles del grupo, o su estado
     * (activo/desactivado/baja): los efectados son los miembros, no el actor.
     */
    public function invalidateCacheForGroup(string $groupId, string $tenantId): void
    {
        $sql = "SELECT gm.user_id
                FROM group_members gm
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gm.group_id = :gid AND ug.tenant_id = :tid";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['gid' => $groupId, 'tid' => $tenantId]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->invalidateCache((string) $row['user_id'], $tenantId);
        }
    }

    // ==================================================================
    // ESCALADA DE ACCIONES SOBRE USUARIOS (v1.11.0)
    // ==================================================================

    /**
     * El actor sólo puede actuar sobre un usuario si posee al menos los
     * permisos efectivos del afectado; y sólo un ADMIN puede actuar sobre
     * otro ADMIN. Aplica a: cambio de estado, reset de password, asignación
     * de roles, roles de grupo y permisos temporales.
     *
     * Acciones sobre uno mismo no se limitan aquí (las rutas aplican sus
     * propias reglas de autocambio).
     *
     * @throws HttpException 403 si la escalada no se cumple
     */
    public function assertCanActOnUser(string $actorId, string $tenantId, string $targetId): void
    {
        if ($actorId === $targetId) {
            return;
        }

        $actorPerms = $this->getUserPermissions($actorId, $tenantId);
        $targetPerms = $this->getUserPermissions($targetId, $tenantId);

        if (array_diff($targetPerms, $actorPerms) !== []) {
            throw HttpException::forbidden(
                'El usuario no tiene al menos los permisos efectivos del usuario afectado.'
            );
        }

        if ($this->hasAdminRole($targetId, $tenantId) && !$this->hasAdminRole($actorId, $tenantId)) {
            throw HttpException::forbidden(
                'Sólo un usuario con rol ADMIN puede actuar sobre otro usuario con rol ADMIN.'
            );
        }
    }

    /**
     * Para asignar un rol, el actor debe poseer todos los permisos que el
     * rol otorga (rol destino contenido en los permisos del actor).
     *
     * @throws HttpException 403 si el rol otorga permisos que el actor no tiene
     */
    public function assertCanAssignRole(string $actorId, string $tenantId, string $roleId): void
    {
        $rolePerms = $this->getRolePermissions($roleId);
        $actorPerms = $this->getUserPermissions($actorId, $tenantId);

        if (array_diff($rolePerms, $actorPerms) !== []) {
            throw HttpException::forbidden(
                'El rol a asignar contiene permisos que el usuario no posee.'
            );
        }
    }

    /**
     * Valida que el rol exista y sea asignable desde el tenant indicado
     * (BUILT_IN visibles para todos, CUSTOM sólo de su propio tenant).
     *
     * @throws AuthorizationException 404 si el rol no existe o no es visible
     */
    public function assertRoleBelongsToTenant(string $roleId, string $tenantId): void
    {
        if ($this->roles->findById($roleId, $tenantId) === null) {
            throw AuthorizationException::roleNotFound($roleId);
        }
    }

    /**
     * Permisos de un rol globales (roles.permisos es JSON de identificadores).
     *
     * @return array<int, string>
     */
    public function getRolePermissions(string $roleId): array
    {
        $sql = "SELECT permisos FROM roles
                WHERE id = :id AND activo = 1 AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $roleId]);
        $json = $stmt->fetchColumn();

        if ($json === false || $json === null || $json === '') {
            return [];
        }

        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Nombre del rol por id (null si no existe o está borrado).
     */
    public function roleName(string $roleId): ?string
    {
        $sql = "SELECT nombre FROM roles WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $roleId]);
        $nombre = $stmt->fetchColumn();

        return $nombre === false ? null : (string) $nombre;
    }

    /**
     * ¿El usuario tiene asignado el rol global ADMIN (vigente y activo)?
     */
    public function hasAdminRole(string $userId, string $tenantId): bool
    {
        $sql = "SELECT COUNT(*) FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                WHERE ra.user_id = :uid
                  AND ra.tenant_id = :tid
                  AND r.nombre = 'ADMIN'
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * ¿El usuario es un ADMIN vigente y su cuenta está ACTIVA?
     */
    public function isActiveAdmin(string $userId, string $tenantId): bool
    {
        $sql = "SELECT COUNT(*) FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                INNER JOIN users u ON u.id = ra.user_id
                WHERE ra.user_id = :uid
                  AND ra.tenant_id = :tid
                  AND r.nombre = 'ADMIN'
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND u.estado = 'ACTIVO'
                  AND u.deleted_at IS NULL
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Número de usuarios ADMIN activos en el tenant (regla de último ADMIN:
     * no se puede dejar el tenant sin administradores activos).
     */
    public function countActiveAdmins(string $tenantId): int
    {
        $sql = "SELECT COUNT(DISTINCT ra.user_id) FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                INNER JOIN users u ON u.id = ra.user_id
                WHERE ra.tenant_id = :tid
                  AND r.nombre = 'ADMIN'
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND u.estado = 'ACTIVO'
                  AND u.deleted_at IS NULL
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    private function getPermissionsFromDirectRoles(string $userId, string $tenantId): array
    {
        $sql = "SELECT r.permisos
                FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                WHERE ra.user_id = :uid
                  AND ra.tenant_id = :tid
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->extractPermissionsFromRows($rows);
    }

    private function getPermissionsFromGroupRoles(string $userId, string $tenantId): array
    {
        $sql = "SELECT DISTINCT r.permisos
                FROM group_members gm
                INNER JOIN group_roles gr ON gr.group_id = gm.group_id
                INNER JOIN roles r ON r.id = gr.role_id
                INNER JOIN users u ON u.id = gm.user_id
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gm.user_id = :uid
                  AND u.tenant_id = :tid
                  AND ug.tenant_id = :gtid
                  AND ug.activo = 1
                  AND ug.deleted_at IS NULL
                  AND r.activo = 1
                  AND r.deleted_at IS NULL";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId, 'gtid' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->extractPermissionsFromRows($rows);
    }

    private function getTemporaryPermissions(string $userId): array
    {
        $sql = "SELECT p.identificador
                FROM temporary_permissions tp
                INNER JOIN permissions p ON p.id = tp.permission_id
                WHERE tp.user_id = :uid
                  AND tp.activo = 1
                  AND tp.fecha_inicio <= NOW()
                  AND tp.fecha_expiracion > NOW()
                  AND p.activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'identificador');
    }

    /**
     * Caducidad del permiso temporal vigente más próximo del usuario
     * (v1.13.0): se guarda en la caché para que la expiración de un
     * temporal invalide la entrada sin esperar al TTL de 60 s.
     */
    private function nearestTemporaryExpiry(string $userId): ?string
    {
        $sql = "SELECT MIN(tp.fecha_expiracion)
                FROM temporary_permissions tp
                INNER JOIN permissions p ON p.id = tp.permission_id
                WHERE tp.user_id = :uid
                  AND tp.activo = 1
                  AND tp.fecha_inicio <= NOW()
                  AND tp.fecha_expiracion > NOW()
                  AND p.activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    private function extractPermissionsFromRows(array $rows): array
    {
        $permisos = [];
        foreach ($rows as $row) {
            $json = $row['permisos'] ?? null;
            if ($json === null || $json === '') {
                continue;
            }
            $decoded = is_string($json) ? json_decode($json, true) : $json;
            if (is_array($decoded)) {
                $permisos = array_merge($permisos, $decoded);
            }
        }
        return $permisos;
    }

    private function filterActivePermissions(array $permissionIds): array
    {
        if (empty($permissionIds)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($permissionIds as $i => $pid) {
            $key = "p{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $pid;
        }

        $sql = "SELECT identificador FROM permissions
                WHERE identificador IN (" . implode(',', $placeholders) . ")
                  AND activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'identificador');
    }

    private function validateMfaIfNeeded(string $userId, string $permissionId, bool $checkMfa): bool
    {
        if (!$checkMfa) {
            return true;
        }

        $permission = $this->permissions->findByIdentificador($permissionId);
        if ($permission === null || !$permission->requiresMfa()) {
            return true;
        }

        $sql = "SELECT mfa_habilitado FROM users WHERE id = :uid";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $mfaHabilitado = (bool) $stmt->fetchColumn();

        return $mfaHabilitado;
    }

    private function matchesPattern(string $required, string $granted): bool
    {
        if ($required === $granted) {
            return true;
        }

        if (!str_contains($granted, '*')) {
            return false;
        }

        $regex = '/^' . str_replace('\*', '[a-z0-9_]+', preg_quote($granted, '/')) . '$/';
        return (bool) preg_match($regex, $required);
    }
}
