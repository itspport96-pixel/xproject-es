<?php

declare(strict_types=1);

namespace App\Core\Audit\Seeder;

use PDO;

/**
 * Siembra el permiso de lectura de la bitácora de auditoría (spec §7.1).
 *
 * Regla (igual que InventoryPermissionSeeder):
 *  - Los permisos son GLOBALES (tabla `permissions`, sin tenant_id).
 *  - Las asignaciones viven en `roles.permisos` (JSON array de identificadores).
 *  - Idempotente: repetirlo no duplica ni reescribe lo que ya coincide.
 *
 * Nota (desviación de spec §7.1): el ejemplo de la spec menciona
 * `audit.log.export`; este sprint sólo siembra `audit.log.view` porque el
 * endpoint de exportación no forma parte del alcance aprobado.
 */
final class AuditPermissionSeeder
{
    /**
     * @var array<int, array{
     *     identificador: string, nombre: string, modulo: string,
     *     recurso: string, accion: string, criticidad: string,
     *     requiere_mfa: int, activo: int
     * }>
     */
    public const PERMISSIONS = [
        ['audit.log.view', 'Ver Bitácora de Auditoría', 'audit', 'log', 'view', 'HIGH', 0, 1],
    ];

    /**
     * Roles BUILT_IN que deben poder consultar la bitácora.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'ADMIN' => ['audit.log.view'],
        'MANAGER' => ['audit.log.view'],
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

        // Sincronizar junction role_permissions
        $this->syncJunctionPermissions($row['id'], $merged);

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
