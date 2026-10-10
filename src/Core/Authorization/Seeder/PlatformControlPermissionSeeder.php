<?php

declare(strict_types=1);

namespace App\Core\Authorization\Seeder;

use PDO;

/**
 * Siembra los permisos de la capa de control de identidad (v1.11.0, §4/§5).
 *
 * Cubre los 12 permisos nuevos del sprint:
 *  - users.user.status, users.password.reset   (ciclo de vida de usuarios)
 *  - permissions.permission.view               (catálogo de permisos)
 *  - groups.group.*                            (grupos de trabajo)
 *  - temporary.permission.*                    (permisos temporales)
 *
 * Regla (igual que InventoryPermissionSeeder/AuditPermissionSeeder):
 *  - Los permisos son GLOBALES (tabla `permissions`, sin tenant_id).
 *  - Las asignaciones viven en `roles.permisos` (JSON array de identificadores).
 *  - Idempotente: repetirlo no duplica ni reescribe lo que ya coincide.
 *
 * Reparto aprobado (v1.11.0): ADMIN = los 12; MANAGER = todos salvo los
 * CRITICAL (groups.group.delete y temporary.permission.grant); USER/VIEWER =
 * groups.group.view + permissions.permission.view.
 */
final class PlatformControlPermissionSeeder
{
    /**
     * @var array<int, array{
     *     identificador: string, nombre: string, modulo: string,
     *     recurso: string, accion: string, criticidad: string,
     *     requiere_mfa: int, activo: int
     * }>
     */
    public const PERMISSIONS = [
        ['users.user.status', 'Cambiar Estado de Usuario', 'users', 'user', 'status', 'HIGH', 0, 1],
        ['users.password.reset', 'Resetear Contraseña', 'users', 'password', 'reset', 'HIGH', 0, 1],
        ['permissions.permission.view', 'Ver Permisos', 'permissions', 'permission', 'view', 'MEDIUM', 0, 1],
        ['groups.group.view', 'Ver Grupos', 'groups', 'group', 'view', 'MEDIUM', 0, 1],
        ['groups.group.create', 'Crear Grupos', 'groups', 'group', 'create', 'MEDIUM', 0, 1],
        ['groups.group.edit', 'Editar Grupos', 'groups', 'group', 'edit', 'MEDIUM', 0, 1],
        ['groups.group.delete', 'Eliminar Grupos', 'groups', 'group', 'delete', 'CRITICAL', 0, 1],
        ['groups.group.manage_members', 'Gestionar Miembros de Grupo', 'groups', 'group', 'manage_members', 'HIGH', 0, 1],
        ['groups.group.manage_roles', 'Gestionar Roles de Grupo', 'groups', 'group', 'manage_roles', 'HIGH', 0, 1],
        ['temporary.permission.view', 'Ver Permisos Temporales', 'temporary_permissions', 'temporary_permission', 'view', 'MEDIUM', 0, 1],
        ['temporary.permission.grant', 'Otorgar Permiso Temporal', 'temporary_permissions', 'temporary_permission', 'grant', 'CRITICAL', 0, 1],
        ['temporary.permission.revoke', 'Revocar Permiso Temporal', 'temporary_permissions', 'temporary_permission', 'revoke', 'HIGH', 0, 1],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'ADMIN' => [
            'users.user.status',
            'users.password.reset',
            'permissions.permission.view',
            'groups.group.view',
            'groups.group.create',
            'groups.group.edit',
            'groups.group.delete',
            'groups.group.manage_members',
            'groups.group.manage_roles',
            'temporary.permission.view',
            'temporary.permission.grant',
            'temporary.permission.revoke',
        ],
        'MANAGER' => [
            'users.user.status',
            'users.password.reset',
            'permissions.permission.view',
            'groups.group.view',
            'groups.group.create',
            'groups.group.edit',
            'groups.group.manage_members',
            'groups.group.manage_roles',
            'temporary.permission.view',
            'temporary.permission.revoke',
        ],
        'USER' => [
            'groups.group.view',
            'permissions.permission.view',
        ],
        'VIEWER' => [
            'groups.group.view',
            'permissions.permission.view',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{permissions_created: int, permissions_updated: int, roles_updated: int}
     */
    public function seed(): array
    {
        $created = 0;
        $updated = 0;

        foreach (self::PERMISSIONS as $definition) {
            [$identificador, $nombre, $modulo, $recurso, $accion, $criticidad, $requiereMfa, $activo] = $definition;

            $stmt = $this->pdo->prepare(
                'SELECT id, nombre, modulo, recurso, accion, criticidad, requiere_mfa, activo
                   FROM permissions
                  WHERE identificador = :identificador'
            );
            $stmt->execute(['identificador' => $identificador]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $expected = [
                'nombre' => $nombre,
                'modulo' => $modulo,
                'recurso' => $recurso,
                'accion' => $accion,
                'criticidad' => $criticidad,
                'requiere_mfa' => $requiereMfa,
                'activo' => $activo,
            ];

            if ($row === false) {
                $this->insertPermission($identificador, $expected);
                $created++;
                continue;
            }

            $drift = false;
            foreach ($expected as $column => $value) {
                if ((string) $row[$column] !== (string) $value) {
                    $drift = true;
                    break;
                }
            }

            if ($drift) {
                $this->updatePermission((string) $row['id'], $expected);
                $updated++;
            }
        }

        $rolesUpdated = 0;
        foreach (self::ROLE_PERMISSIONS as $nombre => $required) {
            if ($this->syncRolePermissions($nombre, $required)) {
                $rolesUpdated++;
            }
        }

        return [
            'permissions_created' => $created,
            'permissions_updated' => $updated,
            'roles_updated' => $rolesUpdated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertPermission(string $identificador, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO permissions
                (id, identificador, nombre, descripcion, modulo, recurso, accion,
                 criticidad, requiere_mfa, activo, metadata, created_at)
             VALUES
                (:id, :identificador, :nombre, NULL, :modulo, :recurso, :accion,
                 :criticidad, :requiere_mfa, :activo, NULL, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            'id' => $this->generateUuid(),
            'identificador' => $identificador,
            'nombre' => $data['nombre'],
            'modulo' => $data['modulo'],
            'recurso' => $data['recurso'],
            'accion' => $data['accion'],
            'criticidad' => $data['criticidad'],
            'requiere_mfa' => $data['requiere_mfa'],
            'activo' => $data['activo'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updatePermission(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE permissions
                SET nombre = :nombre,
                    modulo = :modulo,
                    recurso = :recurso,
                    accion = :accion,
                    criticidad = :criticidad,
                    requiere_mfa = :requiere_mfa,
                    activo = :activo
              WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'nombre' => $data['nombre'],
            'modulo' => $data['modulo'],
            'recurso' => $data['recurso'],
            'accion' => $data['accion'],
            'criticidad' => $data['criticidad'],
            'requiere_mfa' => $data['requiere_mfa'],
            'activo' => $data['activo'],
        ]);
    }

    /**
     * Añade al rol (si existe) los permisos que le falten.
     *
     * @param array<int, string> $required
     * @return bool true si el rol se modificó
     */
    private function syncRolePermissions(string $nombre, array $required): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, permisos FROM roles WHERE nombre = :nombre AND tipo = ' . "'BUILT_IN'" . ' AND deleted_at IS NULL'
        );
        $stmt->execute(['nombre' => $nombre]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $current = $row['permisos'] ?? null;
        $decoded = is_string($current) ? json_decode($current, true) : $current;

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded = array_values(array_map('strval', $decoded));
        $missing = array_values(array_diff($required, $decoded));

        if ($missing === []) {
            return false;
        }

        $merged = array_values(array_unique(array_merge($decoded, $missing)));
        sort($merged);

        $update = $this->pdo->prepare(
            'UPDATE roles SET permisos = :permisos, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $update->execute([
            'permisos' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'id' => $row['id'],
        ]);

        return true;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
