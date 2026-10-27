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

    /**
     * v1.19.7: $mfaSesion indica si la sesion (login) verifico MFA: true/false
     * desde el claim 'mfa' del token, o null cuando no hay contexto (tokens
     * antiguos, llamadas internas) y no se exige nada por eso.
     */
    public function hasPermission(string $userId, string $tenantId, string $permissionId, bool $checkMfa = true, ?bool $mfaSesion = null): bool
    {
        $permisos = $this->getUserPermissions($userId, $tenantId);

        if (in_array($permissionId, $permisos, true)) {
            return $this->validateMfaIfNeeded($userId, $permissionId, $checkMfa, $mfaSesion);
        }

        foreach ($permisos as $perm) {
            if ($this->matchesPattern($permissionId, $perm)) {
                return $this->validateMfaIfNeeded($userId, $permissionId, $checkMfa, $mfaSesion);
            }
        }

        return false;
    }

    public function requirePermission(string $userId, string $tenantId, string $permissionId, bool $checkMfa = true, ?bool $mfaSesion = null): void
    {
        if (!$this->hasPermission($userId, $tenantId, $permissionId, $checkMfa, $mfaSesion)) {
            throw new AuthorizationException(
                "El usuario '{$userId}' no tiene el permiso '{$permissionId}' en el tenant '{$tenantId}'.",
                403
            );
        }
    }

    public function hasAnyPermission(string $userId, string $tenantId, array $permissionIds, bool $checkMfa = true, ?bool $mfaSesion = null): bool
    {
        foreach ($permissionIds as $pid) {
            if ($this->hasPermission($userId, $tenantId, $pid, $checkMfa, $mfaSesion)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(string $userId, string $tenantId, array $permissionIds, bool $checkMfa = true, ?bool $mfaSesion = null): bool
    {
        foreach ($permissionIds as $pid) {
            if (!$this->hasPermission($userId, $tenantId, $pid, $checkMfa, $mfaSesion)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Permisos efectivos: roles directos + de grupo + temporales vigentes.
     *
     * v1.19.7 (bloque 3): `$recurso` acota los temporales a un recurso.
     *  - null (todos los llamadores actuales: PermissionMiddleware, login,
     *    /auth/me, escalada): sólo temporales con `recurso_id IS NULL`; los
     *    concedidos para un recurso concreto ya no autorizan en todo.
     *  - 'unidad_fisica:12345': los globales (autorizan siempre) + los de
     *    ese recurso.
     *
     * Caché: UNA entrada por usuario (`auth:perms:{tenant}:{user}`, como
     * siempre) que guarda los temporales separados por recurso en
     * `por_recurso`; el ámbito se aplica al leer. Así `invalidateCache()`
     * sigue purgando todo de una sola vez (RedisService no admite borrado
     * por patrón) y revocar un temporal surte efecto inmediato en todos los
     * ámbitos, sin esperar al TTL de 60 s.
     */
    public function getUserPermissions(string $userId, string $tenantId, ?string $recurso = null): array
    {
        $cacheKey = self::CACHE_PREFIX . $tenantId . ':' . $userId;

        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded)
                && isset($decoded['permisos']) && is_array($decoded['permisos'])
                && array_key_exists('por_recurso', $decoded)
            ) {
                // Formato v1.13.0/v1.19.7: incluye la caducidad del temporal
                // más próximo. Si ya venció, la entrada se ignora y se
                // recalcula (la expiración surte efecto sin esperar al TTL).
                // Sin `por_recurso` (entrada escrita por una versión previa)
                // se recalcula también: su `permisos` mezclaba los temporales
                // de recurso con los globales.
                $expiraEn = $decoded['expira_en'] ?? null;
                if ($expiraEn === null || (string) $expiraEn > date('Y-m-d H:i:s')) {
                    return $this->applyScope($decoded, $recurso);
                }
            } elseif (is_array($decoded) && !isset($decoded['permisos']) && $recurso === null) {
                // Formato legado (array plano, sin ámbitos): se respeta sólo
                // sin ámbito; con ámbito se ignora y se recalcula. El TTL de
                // 60 s lo retira solo.
                return $decoded;
            }
        }

        $temporales = $this->getTemporaryPermissions($userId);

        $permisos = [];
        $permisos = array_merge($permisos, $this->getPermissionsFromDirectRoles($userId, $tenantId));
        $permisos = array_merge($permisos, $this->getPermissionsFromGroupRoles($userId, $tenantId));
        $permisos = array_merge($permisos, $temporales['globales']);

        $permisos = array_values(array_unique($permisos));
        $permisos = $this->filterActivePermissions($permisos);

        // Temporales de recurso, precacheados por ámbito: una lectura
        // acotada no tiene por qué volver a la base de datos.
        $porRecurso = [];
        foreach ($temporales['por_recurso'] as $recursoId => $lista) {
            $porRecurso[$recursoId] = $this->filterActivePermissions(
                array_values(array_unique($lista))
            );
        }

        $this->redis->set($cacheKey, json_encode([
            'permisos' => $permisos,
            'por_recurso' => $porRecurso,
            'expira_en' => $this->nearestTemporaryExpiry($userId),
        ]), self::CACHE_TTL);

        return $this->applyScope(['permisos' => $permisos, 'por_recurso' => $porRecurso], $recurso);
    }

    /**
     * v1.19.7: aplica el ámbito a una entrada cacheada (o recién calculada):
     * permisos sin ámbito (roles + temporales globales) más, si hay ámbito,
     * los temporales concedidos para ese recurso.
     *
     * @param array{permisos: array<int, string>, por_recurso?: array<string, array<int, string>>} $entrada
     * @return array<int, string>
     */
    private function applyScope(array $entrada, ?string $recurso): array
    {
        if ($recurso === null) {
            return $entrada['permisos'];
        }

        $especificos = $entrada['por_recurso'][$recurso] ?? [];

        return array_values(array_unique(array_merge($entrada['permisos'], $especificos)));
    }

    public function invalidateCache(string $userId, string $tenantId): void
    {
        $cacheKey = self::CACHE_PREFIX . $tenantId . ':' . $userId;
        $this->redis->delete($cacheKey);
    }

    /**
     * Roles efectivos del usuario con su origen (v1.12.1; contrato ampliado
     * en v1.13.20): roles directos de role_assignments y roles otorgados por
     * grupos activos. Es la fuente unica que usan login, /auth/me y
     * GET /users/{id}/roles; el token no es la autoridad.
     *
     * Origen (enum de la API):
     *  - 'direct'    asignacion directa sin caducidad (role_assignments);
     *  - 'temporary' asignacion directa con fecha_expiracion (trae la fecha);
     *  - 'group'     heredado de un grupo activo (trae group_id y group_name).
     *
     * Si un rol llega por directo Y por grupo, manda el directo (dedup).
     *
     * @return array<int, array<string, mixed>> filas con id, nombre, tipo,
     *         activo, permisos y origen, ordenadas por nombre.
     */
    public function getUserRolesWithOrigin(string $userId, string $tenantId): array
    {
        $roles = [];

        foreach ($this->fetchRoles($userId, $tenantId, false) as $row) {
            $row['origen'] = ($row['fecha_expiracion'] ?? null) !== null ? 'temporary' : 'direct';
            $roles[(string) $row['id']] = $row;
        }

        foreach ($this->fetchRoles($userId, $tenantId, true) as $row) {
            $id = (string) $row['id'];
            if (isset($roles[$id])) {
                continue;
            }
            $row['origen'] = 'group';
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
     * Filas de roles del usuario: directos (role_assignments, con su
     * fecha_expiracion para distinguir temporary) o via grupos activos
     * (mismas condiciones que los permisos efectivos; con group_id y
     * group_name para que la UI pueda señalar el grupo y quitar de él).
     *
     * @param bool $porGrupo true = via group_members/group_roles
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRoles(string $userId, string $tenantId, bool $porGrupo): array
    {
        if ($porGrupo) {
            $sql = "SELECT r.id, r.nombre, r.tipo, r.activo,
                           ug.id AS group_id, ug.nombre AS group_name
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
                      AND r.deleted_at IS NULL
                    ORDER BY ug.nombre ASC, r.nombre ASC";
            $params = ['uid' => $userId, 'tid' => $tenantId, 'gtid' => $tenantId];
        } else {
            $sql = "SELECT r.id, r.nombre, r.tipo, r.activo,
                           ra.fecha_expiracion
                    FROM role_assignments ra
                    INNER JOIN roles r ON r.id = ra.role_id
                    WHERE ra.user_id = :uid
                      AND ra.tenant_id = :tid
                      AND r.activo = 1
                      AND r.deleted_at IS NULL
                      AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())
                    ORDER BY r.nombre ASC";
            $params = ['uid' => $userId, 'tid' => $tenantId];
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Hidratar permisos desde junction role_permissions (N5e)
        $roleIds = array_map(fn(array $r) => (string) $r['id'], $rows);
        $permMap = $this->fetchRolePermissionsMap($roleIds);
        foreach ($rows as &$row) {
            $row['permisos'] = $permMap[(string) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param string[] $roleIds
     * @return array<string, string[]> mapa roleId => identificadores activos
     */
    private function fetchRolePermissionsMap(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $sql = "SELECT rp.role_id, p.identificador
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id IN ({$placeholders})
                  AND p.activo = 1
                ORDER BY p.identificador ASC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array_values($roleIds));

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['role_id']][] = (string) $row['identificador'];
        }
        return $map;
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
            throw (new HttpException('El usuario no tiene al menos los permisos efectivos del usuario afectado.', 403))
                ->withMessageKey('common.permission_escalation');
        }

        if ($this->hasAdminRole($targetId, $tenantId) && !$this->hasAdminRole($actorId, $tenantId)) {
            throw (new HttpException('Sólo un usuario con rol ADMIN puede actuar sobre otro usuario con rol ADMIN.', 403))
                ->withMessageKey('common.admin_on_admin');
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
            throw (new HttpException('El rol a asignar contiene permisos que el usuario no posee.', 403))
                ->withMessageKey('common.role_permission_escalation');
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
     * Permisos de un rol globales (via role_permissions junction).
     *
     * @return array<int, string>
     */
    public function getRolePermissions(string $roleId): array
    {
        $sql = "SELECT p.identificador
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = :id
                  AND p.activo = 1
                ORDER BY p.identificador ASC";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $roleId]);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'identificador');
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
        $sql = "SELECT DISTINCT p.identificador
                FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE ra.user_id = :uid
                  AND ra.tenant_id = :tid
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND p.activo = 1
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'identificador');
    }

    private function getPermissionsFromGroupRoles(string $userId, string $tenantId): array
    {
        $sql = "SELECT DISTINCT p.identificador
                FROM group_members gm
                INNER JOIN group_roles gr ON gr.group_id = gm.group_id
                INNER JOIN roles r ON r.id = gr.role_id
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                INNER JOIN users u ON u.id = gm.user_id
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gm.user_id = :uid
                  AND u.tenant_id = :tid
                  AND ug.tenant_id = :gtid
                  AND ug.activo = 1
                  AND ug.deleted_at IS NULL
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND p.activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId, 'gtid' => $tenantId]);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'identificador');
    }

    /**
     * Temporales vigentes agrupados por ámbito (v1.19.7, bloque 3).
     *
     *  - `globales`: `recurso_id IS NULL`; autorizan en cualquier recurso.
     *  - `por_recurso`: `recurso_id => identificadores`; sólo autorizan
     *    sobre ese recurso (antes se mezclaban con los globales y un
     *    temporal de "unidad_fisica:12345" servía para todo).
     *
     * @return array{globales: array<int, string>, por_recurso: array<string, array<int, string>>}
     */
    private function getTemporaryPermissions(string $userId): array
    {
        $sql = "SELECT p.identificador, tp.recurso_id
                FROM temporary_permissions tp
                INNER JOIN permissions p ON p.id = tp.permission_id
                WHERE tp.user_id = :uid
                  AND tp.activo = 1
                  AND tp.fecha_inicio <= NOW()
                  AND tp.fecha_expiracion > NOW()
                  AND p.activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);

        $globales = [];
        $porRecurso = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $recurso = $row['recurso_id'];
            if ($recurso === null || $recurso === '') {
                $globales[] = (string) $row['identificador'];
                continue;
            }
            $porRecurso[(string) $recurso][] = (string) $row['identificador'];
        }

        return ['globales' => $globales, 'por_recurso' => $porRecurso];
    }

    /**
     * Caducidad del permiso temporal vigente más próximo del usuario
     * (v1.13.0): se guarda en la caché para que la expiración de un
     * temporal invalide la entrada sin esperar al TTL de 60 s.
     *
     * v1.19.7: se calcula sobre TODOS los ámbitos (con o sin `recurso_id`),
     * porque la entrada cacheada es única por usuario y sirve para todas
     * las lecturas: si caduca un temporal de recurso, la entrada entera se
     * recalcula y ese temporal desaparece de su ámbito.
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

    /**
     * v1.19.7: la exigencia de MFA pasa del estado de la cuenta a la sesion.
     *
     * - requiere_mfa (flag del permiso): estricto; la cuenta debe tener MFA
     *   habilitado (semantica anterior, sin cambios).
     * - criticidad CRITICAL: exige MFA aunque requiere_mfa este a 0 (la regla
     *   es por criticidad, asi que cualquier permiso nuevo CRITICAL la hereda
     *   sin tocar su seeder ni su controller).
     * - Cuenta sin MFA y permiso solo CRITICAL: se tolera (fase 1; hoy ninguna
     *   cuenta tiene MFA, no se bloquea ningun flujo).
     * - Sin contexto de sesion ($mfaSesion null: token antiguo o llamada
     *   interna): se tolera. Con contexto, manda el login: la sesion que no
     *   verifico MFA no ejecuta la accion.
     */
    private function validateMfaIfNeeded(string $userId, string $permissionId, bool $checkMfa, ?bool $mfaSesion = null): bool
    {
        if (!$checkMfa) {
            return true;
        }

        $permission = $this->permissions->findByIdentificador($permissionId);
        if ($permission === null) {
            return true;
        }

        $requiereMfa = $permission->requiresMfa();
        if (!$requiereMfa && !$permission->isCritical()) {
            return true;
        }

        $sql = "SELECT mfa_habilitado FROM users WHERE id = :uid";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $mfaHabilitado = (bool) $stmt->fetchColumn();

        if ($requiereMfa) {
            // Estricto: la cuenta sin MFA no puede ejercer el permiso.
            if (!$mfaHabilitado) {
                return false;
            }
        } elseif (!$mfaHabilitado) {
            // Tolerancia de fase 1 para los CRITICAL.
            return true;
        }

        if ($mfaSesion === null) {
            return true;
        }

        return $mfaSesion;
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
