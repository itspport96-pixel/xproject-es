<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Seeder\InventoryPermissionSeeder;
use Tests\Support\InventorySqliteTestCase;

/**
 * Seeder de permisos Inventory (task 0a): 34 permisos `inventory.*` de la BD
 * viva + asignaciÃ³n a los roles BUILT_IN.
 *
 * Tablas `permissions`/`roles` se crean aquÃ­ a imagen de MySQL porque no
 * forman parte del esquema de tests de recepciÃ³n/stock.
 */
final class InventoryPermissionSeederTest extends InventorySqliteTestCase
{
    private InventoryPermissionSeeder $seeder;

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
                permisos TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )'
        );

        $this->seeder = new InventoryPermissionSeeder($this->pdo);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function seedRole(string $nombre, array $permisos, string $id = ''): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, tipo, permisos, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id !== '' ? $id : 'role-' . strtolower($nombre),
            $nombre,
            'BUILT_IN',
            json_encode($permisos, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
        ]);
    }

    private function rolePermissions(string $nombre): array
    {
        $stmt = $this->pdo->prepare('SELECT permisos FROM roles WHERE nombre = ?');
        $stmt->execute([$nombre]);
        $json = $stmt->fetchColumn();

        return $json === false ? [] : (array) json_decode((string) $json, true);
    }

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
    // PERMISOS
    // ====================================================================

    public function testSiembraLos34PermisosDeLaBdViva(): void
    {
        $result = $this->seeder->seed();

        $this->assertSame(34, $result['permissions_created']);
        $this->assertSame(0, $result['permissions_updated']);
        $this->assertSame(34, $this->permissionCount());

        foreach (InventoryPermissionSeeder::PERMISSIONS as $definition) {
            [$identificador, $nombre, $modulo, $recurso, $accion, $criticidad, $requiereMfa, $activo] = $definition;

            $row = $this->permissionRow($identificador);
            $this->assertNotNull($row, "Falta el permiso {$identificador}");
            $this->assertSame($nombre, $row['nombre']);
            $this->assertSame($modulo, $row['modulo']);
            $this->assertSame($recurso, $row['recurso']);
            $this->assertSame($accion, $row['accion']);
            $this->assertSame($criticidad, $row['criticidad']);
            $this->assertSame((string) $requiereMfa, (string) $row['requiere_mfa']);
            $this->assertSame((string) $activo, (string) $row['activo']);
            $this->assertNull($row['descripcion']);
        }
    }

    public function testTodosLosPermisosSonDelModuloInventory(): void
    {
        $this->seeder->seed();

        foreach (InventoryPermissionSeeder::PERMISSIONS as [$identificador, , $modulo]) {
            $this->assertStringStartsWith('inventory.', $identificador);
            $this->assertSame('inventory', $modulo);
        }

        $this->assertCount(34, InventoryPermissionSeeder::PERMISSIONS);

        $identificadores = array_map(
            static fn(array $p): string => $p[0],
            InventoryPermissionSeeder::PERMISSIONS
        );
        $this->assertSame($identificadores, array_values(array_unique($identificadores)));
    }

    public function testIncluyeLosPermisosDelSprint6(): void
    {
        $this->seeder->seed();

        $identificadores = array_map(
            static fn(array $p): string => $p[0],
            InventoryPermissionSeeder::PERMISSIONS
        );

        foreach ([
            'inventory.movement.create',
            'inventory.reservation.create',
            'inventory.reservation.cancel',
            'inventory.reservation.view',
            'inventory.stock.view',
        ] as $permiso) {
            $this->assertContains($permiso, $identificadores);
            $this->assertNotNull($this->permissionRow($permiso));
        }
    }

    public function testReservationViewVaALosRolesQueYaVenStock(): void
    {
        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->seedRole($nombre, []);
        }

        $this->seeder->seed();

        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $permisos = $this->rolePermissions($nombre);

            $this->assertContains('inventory.stock.view', $permisos, "{$nombre} carece de inventory.stock.view");
            $this->assertContains('inventory.reservation.view', $permisos, "{$nombre} carece de inventory.reservation.view");
        }
    }

    public function testEsIdempotente(): void
    {
        $first = $this->seeder->seed();
        $second = $this->seeder->seed();

        $this->assertSame(34, $first['permissions_created']);
        $this->assertSame(0, $second['permissions_created']);
        $this->assertSame(0, $second['permissions_updated']);
        $this->assertSame(0, $second['roles_updated']);
        $this->assertSame(34, $this->permissionCount());
    }

    public function testNoTocaPermisosAjenosAInventory(): void
    {
        $this->pdo
            ->prepare(
                'INSERT INTO permissions
                    (id, identificador, nombre, descripcion, modulo, recurso, accion,
                     criticidad, requiere_mfa, activo, metadata, created_at)
                 VALUES
                    (?, ?, ?, NULL, ?, ?, ?, ?, 0, 1, NULL, CURRENT_TIMESTAMP)'
            )
            ->execute(['perm-otro', 'users.view', 'Ver Usuarios', 'users', 'user', 'view', 'MEDIUM']);

        $this->seeder->seed();

        $row = $this->permissionRow('users.view');
        $this->assertNotNull($row);
        $this->assertSame('Ver Usuarios', $row['nombre']);
        $this->assertSame(35, $this->permissionCount());
    }

    public function testReparaElDriftDeMetadatos(): void
    {
        $this->seeder->seed();

        // Permiso alterado + permiso borrado
        $this->pdo
            ->prepare('UPDATE permissions SET nombre = ?, criticidad = ?, activo = 0 WHERE identificador = ?')
            ->execute(['Nombre alterado', 'LOW', 'inventory.movement.create']);
        $this->pdo->prepare('DELETE FROM permissions WHERE identificador = ?')
            ->execute(['inventory.reservation.cancel']);

        $result = $this->seeder->seed();

        $this->assertSame(1, $result['permissions_created']);
        $this->assertSame(1, $result['permissions_updated']);

        $fixed = $this->permissionRow('inventory.movement.create');
        $this->assertSame('Mover Inventario', $fixed['nombre']);
        $this->assertSame('MEDIUM', $fixed['criticidad']);
        $this->assertSame('1', (string) $fixed['activo']);
        $this->assertNotNull($this->permissionRow('inventory.reservation.cancel'));
        $this->assertSame(34, $this->permissionCount());
    }

    // ====================================================================
    // ROLES
    // ====================================================================

    public function testAnadeLosPermisosFaltantesALosRoles(): void
    {
        // Roles con listas incompletas (nuevo tenant) + permisos ajenos
        $this->seedRole('ADMIN', ['users.create', 'inventory.item.view']);
        $this->seedRole('MANAGER', []);
        $this->seedRole('USER', ['audit.view']);
        $this->seedRole('VIEWER', []);

        $result = $this->seeder->seed();

        $this->assertSame(4, $result['roles_updated']);

        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $permisos = $this->rolePermissions($nombre);

            foreach (InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre] as $requerido) {
                $this->assertContains($requerido, $permisos, "{$nombre} carece de {$requerido}");
            }

            // Los permisos ajenos a Inventory se conservan
            $inventory = array_values(array_filter(
                $permisos,
                static fn(string $p): bool => str_starts_with($p, 'inventory.')
            ));
            $this->assertCount(count(InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre]), $inventory);
        }

        $this->assertContains('users.create', $this->rolePermissions('ADMIN'));
        $this->assertContains('audit.view', $this->rolePermissions('USER'));
    }

    public function testNoModificaRolesQueYaEstanCompletos(): void
    {
        $this->seedRole('ADMIN', InventoryPermissionSeeder::ROLE_PERMISSIONS['ADMIN']);
        $this->seedRole('MANAGER', InventoryPermissionSeeder::ROLE_PERMISSIONS['MANAGER']);
        $this->seedRole('USER', InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']);
        $this->seedRole('VIEWER', InventoryPermissionSeeder::ROLE_PERMISSIONS['VIEWER']);

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['roles_updated']);
        $this->assertSame(34, $result['permissions_created']);
    }

    public function testUnRolInexistenteSeOmiteSinError(): void
    {
        $this->seedRole('SOLO-ADMIN', []);

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['roles_updated']);
        $this->assertSame([], $this->rolePermissions('SOLO-ADMIN'));
    }

    public function testLasListasPorRolCoincidenConLaEspecificacion(): void
    {
        $this->seeder->seed();

        $todos = array_map(
            static fn(array $p): string => $p[0],
            InventoryPermissionSeeder::PERMISSIONS
        );

        $esperado = [
            'ADMIN' => 34,
            'MANAGER' => 31,
            'USER' => 17,
            'VIEWER' => 10,
        ];

        foreach ($esperado as $nombre => $cantidad) {
            $this->seedRole($nombre, []);

            // Re-ejecutar para que el rol reciba su lista completa
            $this->seeder->seed();

            $inventory = array_values(array_intersect($this->rolePermissions($nombre), $todos));
            sort($inventory);

            $requeridos = InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre];
            sort($requeridos);

            $this->assertSame($requeridos, $inventory, "Listado distinto para {$nombre}");
            $this->assertCount($cantidad, $inventory);
        }

        // Jerarquia: VIEWER âŠ‚ USER âŠ‚ MANAGER âŠ‚ ADMIN
        $this->assertSubset(
            InventoryPermissionSeeder::ROLE_PERMISSIONS['VIEWER'],
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']
        );
        $this->assertSubset(
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER'],
            InventoryPermissionSeeder::ROLE_PERMISSIONS['MANAGER']
        );
        $this->assertSubset(
            InventoryPermissionSeeder::ROLE_PERMISSIONS['MANAGER'],
            InventoryPermissionSeeder::ROLE_PERMISSIONS['ADMIN']
        );

        // El Sprint 6 aÃ±ade movimiento/reserva a todos los roles operativos
        foreach (['ADMIN', 'MANAGER', 'USER'] as $nombre) {
            $this->assertContains(
                'inventory.movement.create',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre]
            );
            $this->assertContains(
                'inventory.reservation.create',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre]
            );
        }

        // SÃ³lo ADMIN y MANAGER cancelan reservas
        $this->assertContains(
            'inventory.reservation.cancel',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['ADMIN']
        );
        $this->assertNotContains(
            'inventory.reservation.cancel',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']
        );
        $this->assertNotContains(
            'inventory.reservation.cancel',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['VIEWER']
        );

        // El Sprint 7 (picking/transporte): verificaciÃ³n y visiÃ³n sÃ³lo para
        // ADMIN/MANAGER (las operaciones se listan en el panel); los roles
        // operativos sÃ³lo inician/cargan lo que les corresponde.
        $this->assertContains(
            'inventory.picking.verify',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['ADMIN']
        );
        $this->assertContains(
            'inventory.picking.verify',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['MANAGER']
        );
        $this->assertNotContains(
            'inventory.picking.verify',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']
        );
        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->assertContains(
                'inventory.picking.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "picking.view ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.shipping.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "shipping.view ausente en {$nombre}"
            );
        }
        foreach (['ADMIN', 'MANAGER'] as $nombre) {
            $this->assertContains(
                'inventory.shipping.confirm',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "shipping.confirm ausente en {$nombre}"
            );
            $this->assertNotContains(
                'inventory.shipping.confirm',
                InventoryPermissionSeeder::ROLE_PERMISSIONS['USER'],
                'shipping.confirm no debe estar en USER'
            );
        }

        // Sprint 8 (v1.8.0): entregas y devoluciones. Criterio de roles del
        // prompt: los permisos de lectura van a los 4 BUILT_IN (como
        // shipping.view) y los de escritura a ADMIN/MANAGER.
        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->assertContains(
                'inventory.delivery.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "delivery.view ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.return.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "return.view ausente en {$nombre}"
            );
        }
        foreach (['ADMIN', 'MANAGER'] as $nombre) {
            $this->assertContains(
                'inventory.delivery.confirm',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "delivery.confirm ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.return.create',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "return.create ausente en {$nombre}"
            );
        }

        // v1.8.3: USER no confirma entregas ni crea devoluciones (sÃ³lo lee).
        $this->assertNotContains(
            'inventory.delivery.confirm',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER'],
            'delivery.confirm no debe estar en USER'
        );
        $this->assertNotContains(
            'inventory.return.create',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['USER'],
            'return.create no debe estar en USER'
        );

        // Sprint 9 (v1.9.0): incidencias y conteos físicos.
        // - `*.view` → los 4 BUILT_IN (criterio de shipping.view).
        // - report/create → ADMIN/MANAGER/USER (escritura operativa).
        // - resolve/reconcile → sólo ADMIN/MANAGER (decisión de gestión).
        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->assertContains(
                'inventory.incident.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "incident.view ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.count.view',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "count.view ausente en {$nombre}"
            );
        }
        foreach (['ADMIN', 'MANAGER', 'USER'] as $nombre) {
            $this->assertContains(
                'inventory.incident.report',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "incident.report ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.count.create',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "count.create ausente en {$nombre}"
            );
        }
        foreach (['ADMIN', 'MANAGER'] as $nombre) {
            $this->assertContains(
                'inventory.incident.resolve',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "incident.resolve ausente en {$nombre}"
            );
            $this->assertContains(
                'inventory.count.reconcile',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "count.reconcile ausente en {$nombre}"
            );
        }
        foreach (['USER', 'VIEWER'] as $nombre) {
            $this->assertNotContains(
                'inventory.incident.resolve',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "incident.resolve no debe estar en {$nombre}"
            );
            $this->assertNotContains(
                'inventory.count.reconcile',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "count.reconcile no debe estar en {$nombre}"
            );
        }
        $this->assertNotContains(
            'inventory.count.create',
            InventoryPermissionSeeder::ROLE_PERMISSIONS['VIEWER'],
            'count.create no debe estar en VIEWER'
        );

        // v1.10.0 (E4): bloquear/desbloquear unidades → sólo ADMIN/MANAGER.
        foreach (['ADMIN', 'MANAGER'] as $nombre) {
            $this->assertContains(
                'inventory.stock.block',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "stock.block ausente en {$nombre}"
            );
        }
        foreach (['USER', 'VIEWER'] as $nombre) {
            $this->assertNotContains(
                'inventory.stock.block',
                InventoryPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                "stock.block no debe estar en {$nombre}"
            );
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param array<int, string> $subset
     * @param array<int, string> $superset
     */
    private function assertSubset(array $subset, array $superset): void
    {
        $missing = array_diff($subset, $superset);
        $this->assertSame([], array_values($missing));
    }
}
