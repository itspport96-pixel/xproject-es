<?php

declare(strict_types=1);

namespace App\Core\Authentication\Seeder;

use PDO;

/**
 * Siembra el permiso de restablecimiento de contraseña propio (SSPR, v1.17.0).
 *
 * Regla (igual que InventoryPermissionSeeder / AuditPermissionSeeder):
 *  - Los permisos son GLOBALES (tabla `permissions`, sin tenant_id).
 *  - Las asignaciones viven en la junction `role_permissions`.
 *  - Idempotente: repetirlo no duplica ni reescribe lo que ya coincide.
 *
 * Decisión de alcance: `password_reset_self_service` NO se asigna a ningún
 * rol BUILT_IN. El permiso se concede o revoca EXCLUSIVAMENTE por
 * administradores autorizados (mediante la gestión de roles/permisos o
 * permisos temporales ya existentes), por lo que el SSPR es opt-in por
 * usuario/rol y queda deshabilitado por defecto.
 */
final class AuthPermissionSeeder
{
    /**
     * @var array<int, array{
     *     identificador: string, nombre: string, modulo: string,
     *     recurso: string, accion: string, criticidad: string,
     *     requiere_mfa: int, activo: int
     * }>
     */
    public const PERMISSIONS = [
        ['password_reset_self_service', 'Restablecer Contrasena Propia (SSPR)', 'auth', 'password_reset', 'self_service', 'HIGH', 0, 1],
    ];

    /**
     * Sin asignación a roles BUILT_IN: opt-in exclusivo de administradores.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [];

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
     * @param array<int, string> $required
     * @return bool true si el rol se modificó
     */
    private function syncRolePermissions(string $nombre, array $required): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM roles WHERE nombre = :nombre AND tipo = ' . "'BUILT_IN'" . ' AND deleted_at IS NULL'
        );
        $stmt->execute(['nombre' => $nombre]);
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            return false;
        }

        $jStmt = $this->pdo->prepare(
            'SELECT p.identificador
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid'
        );
        $jStmt->execute(['rid' => $roleId]);
        $current = $jStmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = array_values(array_diff($required, $current));

        if ($missing === []) {
            return false;
        }

        $this->syncJunctionPermissions((string) $roleId, array_merge($current, $missing));

        return true;
    }

    private function syncJunctionPermissions(string $roleId, array $identifiers): void
    {
        $permMap = [];
        $rows = $this->pdo->query('SELECT id, identificador FROM permissions')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $permMap[$r['identificador']] = $r['id'];
        }

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM role_permissions WHERE role_id = :rid AND permission_id = :pid'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)'
        );
        foreach ($identifiers as $ident) {
            if (!isset($permMap[$ident])) {
                continue;
            }
            $exists->execute(['rid' => $roleId, 'pid' => $permMap[$ident]]);
            if ($exists->fetchColumn() === false) {
                $insert->execute(['rid' => $roleId, 'pid' => $permMap[$ident]]);
            }
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
