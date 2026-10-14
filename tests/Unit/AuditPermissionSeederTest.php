<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\Seeder\AuditPermissionSeeder;
use Tests\Support\InventorySqliteTestCase;

/**
 * AuditPermissionSeeder (spec §7.1): siembra `audit.log.view` y lo asigna a
 * ADMIN y MANAGER. Idempotente y con reparación de drift, igual que
 * InventoryPermissionSeeder.
 *
 * Nota (desviación declarada): la spec menciona `audit.log.export`; este
 * sprint NO lo siembra porque no existe endpoint de exportación.
 */
final class AuditPermissionSeederTest extends InventorySqliteTestCase
{
    private AuditPermissionSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec(
            'CREATE TABLE permissions (
                id TEXT PRIMARY KEY,
                identificador TEXT NOT NULL UNIQUE,
                nombre TEXT NOT NULL,
                descripcion TEXT,
                modulo TEXT NOT NULL,
                recurso TEXT NOT NULL,
                accion TEXT NOT NULL,
                criticidad TEXT NOT NULL,
                requiere_mfa INTEGER NOT NULL DEFAULT 0,
                activo INTEGER NOT NULL DEFAULT 1,
                metadata TEXT,
                created_at TEXT
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE roles (
                id TEXT PRIMARY KEY,
                nombre TEXT NOT NULL UNIQUE,
                tipo TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE role_permissions (
                role_id TEXT NOT NULL,
                permission_id TEXT NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            )'
        );

        // Permisos base: deben existir ANTES de seedRole para que junction pueda poblarse
        $this->seedPermission('users.user.view');
        $this->seedPermission('inventory.item.view');

        $this->seedRole('ADMIN', ['users.user.view']);
        $this->seedRole('MANAGER', ['inventory.item.view']);
        $this->seedRole('USER', []);
        $this->seedRole('VIEWER', []);

        $this->seeder = new AuditPermissionSeeder($this->pdo);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function seedPermission(string $identificador): string
    {
        $id = 'perm-' . str_replace('.', '-', $identificador);
        $parts = explode('.', $identificador, 3);
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO permissions (id, identificador, nombre, modulo, recurso, accion, criticidad)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $identificador,
            'Permiso ' . $identificador,
            $parts[0] ?? '',
            $parts[1] ?? '',
            $parts[2] ?? '',
            'MEDIUM',
        ]);
        return $id;
    }

    private function seedRole(string $nombre, array $permisos): void
    {
        $roleId = 'role-' . strtolower($nombre);
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, tipo, updated_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $roleId,
            $nombre,
            'BUILT_IN',
            date('Y-m-d H:i:s'),
        ]);

        foreach ($permisos as $ident) {
            $q = $this->pdo->prepare('SELECT id FROM permissions WHERE identificador = ?');
            $q->execute([$ident]);
            $permId = $q->fetchColumn();
            if ($permId === false) {
                continue;
            }
            $j = $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
            );
            $j->execute([$roleId, (string) $permId]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function rolePermissions(string $nombre): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.identificador
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             INNER JOIN roles r ON r.id = rp.role_id
             WHERE r.nombre = ?
             ORDER BY p.identificador'
        );
        $stmt->execute([$nombre]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function permissionRow(string $identificador): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM permissions WHERE identificador = ?');
        $stmt->execute([$identificador]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function permissionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    }

    // ====================================================================
    // PERMISO
    // ====================================================================

    public function testSiembraElPermisoDeLectura(): void
    {
        $result = $this->seeder->seed();

        $this->assertSame(1, $result['permissions_created']);
        $this->assertSame(0, $result['permissions_updated']);
        $this->assertSame(2, $result['roles_updated']);

        $row = $this->permissionRow('audit.log.view');
        $this->assertNotNull($row, 'Falta audit.log.view');
        $this->assertSame('audit', $row['modulo']);
        $this->assertSame('log', $row['recurso']);
        $this->assertSame('view', $row['accion']);
        $this->assertSame('HIGH', $row['criticidad']);
        $this->assertSame(1, (int) $row['activo']);
        // setUp siembra 2 permisos base (users.user.view, inventory.item.view)
        // para junction; el seeder añade 1 más (audit.log.view) → total 3
        $this->assertSame(3, $this->permissionCount(), 'Sólo siembra UN permiso nuevo');
    }

    public function testNoSiembraAuditLogExport(): void
    {
        $this->seeder->seed();

        $this->assertNull(
            $this->permissionRow('audit.log.export'),
            'Desviación declarada: sin endpoint de export no se siembra export'
        );
    }

    public function testEsIdempotente(): void
    {
        $this->seeder->seed();
        $first = $this->permissionRow('audit.log.view');

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['permissions_created']);
        $this->assertSame(0, $result['permissions_updated']);
        $this->assertSame(0, $result['roles_updated']);
        // setUp siembra 2 permisos base + 1 del seeder = 3
        $this->assertSame(3, $this->permissionCount());
        $this->assertSame($first, $this->permissionRow('audit.log.view'));
    }

    public function testReparaElDriftDeMetadatos(): void
    {
        $this->seeder->seed();

        $this->pdo->exec("UPDATE permissions SET criticidad = 'LOW', activo = 0 WHERE identificador = 'audit.log.view'");

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['permissions_created']);
        $this->assertSame(1, $result['permissions_updated']);

        $row = $this->permissionRow('audit.log.view');
        $this->assertSame('HIGH', $row['criticidad']);
        $this->assertSame(1, (int) $row['activo']);
    }

    // ====================================================================
    // ROLES
    // ====================================================================

    public function testAnadeElPermisoAAAdminYManager(): void
    {
        // setUp ya crea ADMIN/MANAGER con users.user.view e inventory.item.view
        $this->seeder->seed();

        $this->assertContains('audit.log.view', $this->rolePermissions('ADMIN'));
        $this->assertContains('audit.log.view', $this->rolePermissions('MANAGER'));

        // No pierde los permisos previos.
        $this->assertContains('users.user.view', $this->rolePermissions('ADMIN'));
        $this->assertContains('inventory.item.view', $this->rolePermissions('MANAGER'));
    }

    public function testNoTocaLosDemasRoles(): void
    {
        $this->seeder->seed();

        $this->assertSame([], $this->rolePermissions('USER'));
        $this->assertSame([], $this->rolePermissions('VIEWER'));
    }

    public function testUnRolInexistenteSeOmiteSinError(): void
    {
        $this->pdo->exec("DELETE FROM roles WHERE nombre = 'MANAGER'");

        $result = $this->seeder->seed();

        $this->assertContains('audit.log.view', $this->rolePermissions('ADMIN'));
        $this->assertSame(1, $result['roles_updated'], 'Sólo ADMIN contó como actualizado');
    }

    public function testPlatformSeederInvocaAlSeederDeAuditoria(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/seeders/PlatformSeeder.php'
        );

        $this->assertStringContainsString('AuditPermissionSeeder', $source);
        $this->assertStringContainsString('->seed()', $source);
    }
}
