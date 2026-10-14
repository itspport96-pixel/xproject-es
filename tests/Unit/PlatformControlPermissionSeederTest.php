<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Seeder\PlatformControlPermissionSeeder;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Seeder de la capa de control (v1.11.0, spec §4/§5): los 12 permisos
 * users.user.status / users.password.reset / permissions.permission.view /
 * groups.group.* / temporary.permission.* y su reparto entre los roles
 * BUILT_IN.
 *
 * Reglas verificadas:
 *  - Siembra exacta de los 12 identificadores con sus metadatos.
 *  - Reparto aprobado: ADMIN=12, MANAGER=10 (sin los CRITICAL),
 *    USER=VIEWER=2 (sólo view de groups y permissions).
 *  - Jerarquía VIEWER ⊂ USER ⊂ MANAGER ⊂ ADMIN.
 *  - Idempotente y no toca permisos/roles ajenos.
 */
final class PlatformControlPermissionSeederTest extends PlatformControlSqliteTestCase
{
    private PlatformControlPermissionSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seeder = new PlatformControlPermissionSeeder($this->pdo);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<int, string>
     */
    private function permissionIdentifiers(): array
    {
        return array_map(
            static fn(array $p): string => $p[0],
            PlatformControlPermissionSeeder::PERMISSIONS
        );
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
             WHERE r.nombre = :nombre
             ORDER BY p.identificador'
        );
        $stmt->execute(['nombre' => $nombre]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function permissionRow(string $identificador): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM permissions WHERE identificador = :id');
        $stmt->execute(['id' => $identificador]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ====================================================================
    // PERMISOS
    // ====================================================================

    public function testSiembraLos12PermisosDelSprint(): void
    {
        $result = $this->seeder->seed();

        $this->assertSame(12, $result['permissions_created']);
        $this->assertSame(0, $result['permissions_updated']);
        $this->assertSame(12, $this->countRows('permissions'));

        foreach (PlatformControlPermissionSeeder::PERMISSIONS as $definition) {
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
        }
    }

    public function testLosDoceIdentificadoresSonUnicosYDeLosModulosDelSprint(): void
    {
        $identificadores = $this->permissionIdentifiers();

        $this->assertCount(12, $identificadores);
        $this->assertSame($identificadores, array_values(array_unique($identificadores)));

        $this->assertContains('users.user.status', $identificadores);
        $this->assertContains('users.password.reset', $identificadores);
        $this->assertContains('permissions.permission.view', $identificadores);
        $this->assertContains('temporary.permission.grant', $identificadores);

        foreach ($identificadores as $identificador) {
            $this->assertMatchesRegularExpression(
                '/^(users|permissions|groups|temporary)\./',
                $identificador
            );
        }
    }

    public function testEsIdempotente(): void
    {
        $first = $this->seeder->seed();
        $second = $this->seeder->seed();

        $this->assertSame(12, $first['permissions_created']);
        $this->assertSame(0, $second['permissions_created']);
        $this->assertSame(0, $second['permissions_updated']);
        $this->assertSame(12, $this->countRows('permissions'));
    }

    public function testNoTocaPermisosAjenos(): void
    {
        $this->seedPermission('inventory.item.view');

        $result = $this->seeder->seed();

        $this->assertSame(12, $result['permissions_created']);
        $this->assertSame(13, $this->countRows('permissions'));

        $ajeno = $this->permissionRow('inventory.item.view');
        $this->assertNotNull($ajeno);
        $this->assertSame('Permiso inventory.item.view', $ajeno['nombre']);
    }

    public function testReparaElDriftDeMetadatos(): void
    {
        $this->seeder->seed();

        $this->pdo
            ->prepare('UPDATE permissions SET nombre = :n, criticidad = :c, activo = 0 WHERE identificador = :i')
            ->execute(['n' => 'Nombre alterado', 'c' => 'LOW', 'i' => 'groups.group.delete']);

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['permissions_created']);
        $this->assertSame(1, $result['permissions_updated']);

        $fixed = $this->permissionRow('groups.group.delete');
        $this->assertNotNull($fixed);
        $this->assertSame('Eliminar Grupos', $fixed['nombre']);
        $this->assertSame('CRITICAL', $fixed['criticidad']);
        $this->assertSame('1', (string) $fixed['activo']);
    }

    // ====================================================================
    // REPARTO POR ROLES
    // ====================================================================

    public function testRepartoAprobadoPorRol(): void
    {
        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->seedRole('role-' . strtolower($nombre), $nombre, [], 'BUILT_IN');
        }

        $result = $this->seeder->seed();

        $this->assertSame(4, $result['roles_updated']);

        $esperado = [
            'ADMIN' => 12,
            'MANAGER' => 10,
            'USER' => 2,
            'VIEWER' => 2,
        ];

        foreach ($esperado as $nombre => $cantidad) {
            $permisos = $this->rolePermissions($nombre);
            sort($permisos);

            $requeridos = PlatformControlPermissionSeeder::ROLE_PERMISSIONS[$nombre];
            sort($requeridos);

            $this->assertSame($requeridos, $permisos, "Listado distinto para {$nombre}");
            $this->assertCount($cantidad, $permisos, "Cantidad distinta para {$nombre}");
        }
    }

    public function testJerarquiaVIEWERsubsetDEUSERsubsetDEMANAGERsubsetDEADMIN(): void
    {
        $rp = PlatformControlPermissionSeeder::ROLE_PERMISSIONS;

        $this->assertSame([], array_diff($rp['VIEWER'], $rp['USER']));
        $this->assertSame([], array_diff($rp['USER'], $rp['MANAGER']));
        $this->assertSame([], array_diff($rp['MANAGER'], $rp['ADMIN']));

        // USER y VIEWER comparten los mismos 2 permisos de lectura aprobados
        $this->assertSame($rp['USER'], $rp['VIEWER']);
        $this->assertNotSame($rp['MANAGER'], $rp['USER']);
        $this->assertNotSame($rp['ADMIN'], $rp['MANAGER']);
    }

    public function testLosCriticalSonExclusivosDeAdmin(): void
    {
        $critical = [];
        foreach (PlatformControlPermissionSeeder::PERMISSIONS as $definition) {
            if ($definition[5] === 'CRITICAL') {
                $critical[] = $definition[0];
            }
        }

        $this->assertSame(
            ['groups.group.delete', 'temporary.permission.grant'],
            $critical,
            'Los 2 CRITICAL del sprint son groups.group.delete y temporary.permission.grant'
        );

        $rp = PlatformControlPermissionSeeder::ROLE_PERMISSIONS;

        foreach ($critical as $identificador) {
            $this->assertContains($identificador, $rp['ADMIN']);
            $this->assertNotContains($identificador, $rp['MANAGER'], "{$identificador} no debe estar en MANAGER");
            $this->assertNotContains($identificador, $rp['USER'], "{$identificador} no debe estar en USER");
            $this->assertNotContains($identificador, $rp['VIEWER'], "{$identificador} no debe estar en VIEWER");
        }

        foreach (['users.user.status', 'users.password.reset', 'temporary.permission.revoke'] as $high) {
            $this->assertContains($high, $rp['ADMIN']);
            $this->assertContains($high, $rp['MANAGER'], "{$high} es HIGH y debe estar en MANAGER");
            $this->assertNotContains($high, $rp['USER']);
            $this->assertNotContains($high, $rp['VIEWER']);
        }
    }

    public function testUserYViewerSoloVenGruposYPermisos(): void
    {
        $esperado = ['groups.group.view', 'permissions.permission.view'];

        foreach (['USER', 'VIEWER'] as $nombre) {
            $permisos = PlatformControlPermissionSeeder::ROLE_PERMISSIONS[$nombre];
            sort($permisos);
            $esperadoOrdenado = $esperado;
            sort($esperadoOrdenado);

            $this->assertSame($esperadoOrdenado, $permisos, "{$nombre} debe limitarse a los 2 permisos de lectura");
        }
    }

    public function testLosPermisosDelSprintVanATodosLosRoles(): void
    {
        $rp = PlatformControlPermissionSeeder::ROLE_PERMISSIONS;

        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->assertContains('groups.group.view', $rp[$nombre], "groups.group.view ausente en {$nombre}");
            $this->assertContains('permissions.permission.view', $rp[$nombre], "permissions.permission.view ausente en {$nombre}");
        }
        $this->assertContains('temporary.permission.view', $rp['ADMIN']);
        $this->assertContains('temporary.permission.view', $rp['MANAGER']);
        $this->assertNotContains('temporary.permission.view', $rp['USER'], 'USER no lista temporales del sprint');
        $this->assertNotContains('temporary.permission.view', $rp['VIEWER'], 'VIEWER no lista temporales del sprint');
    }

    public function testNoModificaRolesQueYaEstanCompletos(): void
    {
        // Sembrar permisos ANTES de seedRole para que junction pueda poblarse
        foreach (PlatformControlPermissionSeeder::PERMISSIONS as $def) {
            $this->seedPermission($def[0]);
        }

        foreach (['ADMIN', 'MANAGER', 'USER', 'VIEWER'] as $nombre) {
            $this->seedRole(
                'role-' . strtolower($nombre),
                $nombre,
                PlatformControlPermissionSeeder::ROLE_PERMISSIONS[$nombre],
                'BUILT_IN'
            );
        }

        $result = $this->seeder->seed();

        $this->assertSame(0, $result['roles_updated']);
        $this->assertSame(0, $result['permissions_created'], 'Los 12 permisos ya existen');
    }

    public function testAnadeSoloLoQueFaltaSinBorrarLoPreexistente(): void
    {
        // Permisos preexistentes: deben existir ANTES de seedRole
        $this->seedPermission('inventory.stock.view');
        $this->seedPermission('audit.view');
        $this->seedRole('role-admin', 'ADMIN', ['inventory.stock.view', 'audit.view'], 'BUILT_IN');

        $result = $this->seeder->seed();

        $this->assertSame(1, $result['roles_updated']);

        $permisos = $this->rolePermissions('ADMIN');

        foreach (PlatformControlPermissionSeeder::ROLE_PERMISSIONS['ADMIN'] as $requerido) {
            $this->assertContains($requerido, $permisos, "ADMIN carece de {$requerido}");
        }
        $this->assertContains('inventory.stock.view', $permisos, 'Los permisos ajenos se conservan');
        $this->assertContains('audit.view', $permisos, 'Los permisos ajenos se conservan');
    }

    public function testUnRolInexistenteSeOmiteSinError(): void
    {
        $result = $this->seeder->seed();

        $this->assertSame(0, $result['roles_updated']);
    }
}
