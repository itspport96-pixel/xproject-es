<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Entity\Role;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Core\Authorization\Http\RoleController;
use App\Core\Authorization\Repository\RoleRepository;
use App\Shared\Exceptions\HttpException;
use Tests\Support\PlatformControlSqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Guards de gestión de roles (spec §5.1, ajuste F3):
 *  - Sólo ADMIN gestiona roles (requireAdmin comprueba el rol vigente en
 *    role_assignments; los claims del token no son la autoridad).
 *  - Roles BUILT_IN no se renombran ni se eliminan.
 *  - El rol ADMIN no se desactiva ni puede perder permisos.
 *  - Roles CUSTOM: renombrar/reasignar permisos funciona y audita.
 *
 * RoleRepository REAL sobre SQLite (sus guards viven en el repo) y
 * AuthorizationService real para la invalidación de cache por rol.
 */
final class RoleControllerGuardTest extends PlatformControlSqliteTestCase
{
    private const U_ADMIN = 'u-admin-rol';
    private const U_MANAGER = 'u-manager-rol';

    private RoleController $controller;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermission('users.user.view');
        $this->seedPermission('users.user.status');

        $this->seedRole('role-admin', 'ADMIN', ['users.user.view', 'users.user.status'], 'BUILT_IN');
        $this->seedRole('role-user', 'USER', ['users.user.view'], 'BUILT_IN');
        $this->seedRole('role-custom', 'ALMACEN', ['users.user.view']);

        $this->seedUser(self::U_ADMIN);
        $this->seedUser(self::U_MANAGER);

        // requireAdmin consulta la BD: el admin necesita la asignación real.
        $this->assignRole(self::U_ADMIN, 'role-admin');

        $this->audit = new RecordingAuditLogger();
        $this->controller = new RoleController(
            new RoleRepository($this->db),
            $this->authz,
            $this->audit
        );
    }

    /**
     * @param array<string, mixed> $overrides Request (body, query...)
     * @param array<int, array<string, mixed>|string> $roles Claim 'roles' del JWT
     * @return array<string, mixed>
     */
    private function adminRequest(array $overrides = [], array $roles = []): array
    {
        return $this->request($overrides, $this->claims(
            self::U_ADMIN,
            $roles !== [] ? $roles : [['nombre' => 'ADMIN']]
        ));
    }

    private function managerRequest(array $overrides = []): array
    {
        return $this->request($overrides, $this->claims(
            self::U_MANAGER,
            [['nombre' => 'MANAGER']]
        ));
    }

    private function assertForbidden(callable $fn, string $mensaje): void
    {
        try {
            $fn();
            $this->fail('Se esperaba un 403 pero no se lanzó nada');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    // ====================================================================
    // requireAdmin
    // ====================================================================

    public function testNoAdminNoPuedeGestionarRoles(): void
    {
        $this->assertForbidden(
            fn() => $this->controller->update(
                $this->managerRequest(['body' => ['nombre' => 'OTRO']]),
                ['id' => 'role-custom']
            ),
            'Se requiere rol ADMIN'
        );

        $this->assertSame(
            [],
            $this->audit->entries,
            'Un 403 no debe generar auditoría de dominio'
        );
    }

    public function testSinClaimsDa401(): void
    {
        try {
            $this->controller->index(['ip' => '127.0.0.1']);
            $this->fail('Se esperaba 401 sin claims');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formasDeClaimAdmin')]
    public function testClaimAdminEnDiferentesFormas(array $roles): void
    {
        $result = $this->controller->show(
            $this->adminRequest([], $roles),
            ['id' => 'role-custom']
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('ALMACEN', $result['data']['role']['nombre']);
    }

    /**
     * @return array<string, array{array<int, array<string, string>|string>}>
     */
    public static function formasDeClaimAdmin(): array
    {
        return [
            'array de arrays con nombre' => [[['nombre' => 'ADMIN']]],
            'array de strings' => [['ADMIN']],
            'array de objetos' => [[(object) ['nombre' => 'ADMIN']]],
        ];
    }

    // ====================================================================
    // GUARDS SOBRE ROLES BUILT_IN / ADMIN
    // ====================================================================

    public function testNoSePuedeRenombrarUnRolBuiltIn(): void
    {
        $this->assertForbidden(
            fn() => $this->controller->update(
                $this->adminRequest(['body' => ['nombre' => 'OTRO']]),
                ['id' => 'role-user']
            ),
            'Los roles BUILT_IN no pueden ser renombrados'
        );

        $row = $this->roleRow('role-user');
        $this->assertSame('USER', $row['nombre'], 'El rol no debe modificarse');
    }

    public function testNoSePuedeDesactivarElRolAdmin(): void
    {
        $this->assertForbidden(
            fn() => $this->controller->update(
                $this->adminRequest(['body' => ['activo' => false]]),
                ['id' => 'role-admin']
            ),
            'El rol ADMIN no puede desactivarse'
        );

        $row = $this->roleRow('role-admin');
        $this->assertSame('1', (string) $row['activo'], 'El rol ADMIN debe seguir activo');
    }

    public function testElRolAdminNoPuedePerderPermisos(): void
    {
        $this->assertForbidden(
            fn() => $this->controller->updatePermissions(
                $this->adminRequest(['body' => ['permisos' => ['users.user.view']]]),
                ['id' => 'role-admin']
            ),
            'El rol ADMIN no puede perder permisos'
        );

        $row = $this->roleRow('role-admin');
        $permisos = (array) json_decode((string) $row['permisos'], true);
        $this->assertSame(['users.user.view', 'users.user.status'], $permisos);
    }

    public function testElRolAdminNoSePuedeModificarSusPermisosPorLaApi(): void
    {
        // Añadir permisos también está bloqueado: reemplazarPermisos exige
        // ensureMutable y ADMIN es BUILT_IN (los permisos del rol los
        // siembran los seeders directamente en BD).
        try {
            $this->controller->updatePermissions(
                $this->adminRequest(['body' => ['permisos' => [
                    'users.user.view',
                    'users.user.status',
                    'users.password.reset',
                ]]]),
                ['id' => 'role-admin']
            );
            $this->fail('Se esperaba 403 al modificar permisos de un rol BUILT_IN');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Los roles BUILT_IN no pueden ser modificados', $e->getMessage());
        }

        $row = $this->roleRow('role-admin');
        $permisos = (array) json_decode((string) $row['permisos'], true);
        $this->assertSame(['users.user.view', 'users.user.status'], $permisos);
    }

    public function testNoSePuedeEliminarUnRolBuiltIn(): void
    {
        try {
            $this->controller->destroy($this->adminRequest(), ['id' => 'role-admin']);
            $this->fail('Se esperaba 403 al borrar un rol BUILT_IN');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('No se puede eliminar un rol BUILT_IN.', $e->getMessage());
        }

        $this->assertNotNull($this->roleRow('role-admin'), 'El rol no debe borrarse');
    }

    public function testNoSePuedeDesactivarElRolAdminNiPorSuNombreEnLaRuta(): void
    {
        // El guard es por nombre interno, no por lo que diga el claim
        $this->assertForbidden(
            fn() => $this->controller->update(
                $this->adminRequest(
                    ['body' => ['activo' => 0, 'descripcion' => 'sin administradores']],
                    [['nombre' => 'ADMIN'], ['nombre' => 'MANAGER']]
                ),
                ['id' => 'role-admin']
            ),
            'El rol ADMIN no puede desactivarse'
        );
    }

    // ====================================================================
    // ROLES CUSTOM (funciona y audita)
    // ====================================================================

    public function testRenombrarRolCustomFuncionaYAudita(): void
    {
        $result = $this->controller->update(
            $this->adminRequest(['body' => [
                'nombre' => 'almacen norte',
                'descripcion' => 'Operarios del almacén norte',
            ]]),
            ['id' => 'role-custom']
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('ALMACEN NORTE', $result['data']['role']['nombre']);
        $this->assertSame('Operarios del almacén norte', $result['data']['role']['descripcion']);

        $row = $this->roleRow('role-custom');
        $this->assertSame('ALMACEN NORTE', $row['nombre']);

        $entry = $this->audit->byOperacion('role.update');
        $this->assertNotNull($entry, 'Falta la auditoría role.update');
        $this->assertSame('UPDATE', $entry['tipo_cambio']);
        $this->assertSame('roles', $entry['modulo']);
        $this->assertSame('role', $entry['recurso']);
        $this->assertSame('role-custom', $entry['entidad_id']);
        $this->assertSame('ALMACEN', $entry['valores_anteriores']['nombre']);
        $this->assertSame('ALMACEN NORTE', $entry['valores_nuevos']['nombre']);
    }

    public function testReasignarPermisosDeRolCustomFuncionaYAudita(): void
    {
        $result = $this->controller->updatePermissions(
            $this->adminRequest(['body' => ['permisos' => [
                'users.user.view',
                'users.user.status',
            ]]]),
            ['id' => 'role-custom']
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['users.user.view', 'users.user.status'],
            $result['data']['role']['permisos']
        );

        $entry = $this->audit->byOperacion('role.permissions.update');
        $this->assertNotNull($entry, 'Falta la auditoría role.permissions.update');
        $this->assertSame('role-custom', $entry['entidad_id']);
        $this->assertSame(['users.user.view'], $entry['valores_anteriores']['permisos']);
    }

    public function testDesactivarRolCustomSiPermite(): void
    {
        $result = $this->controller->update(
            $this->adminRequest(['body' => ['activo' => false]]),
            ['id' => 'role-custom']
        );

        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['data']['role']['activo']);
        $this->assertSame('0', (string) $this->roleRow('role-custom')['activo']);
    }

    public function testRolNoExistenteDa404(): void
    {
        try {
            $this->controller->update(
                $this->adminRequest(['body' => ['nombre' => 'X']]),
                ['id' => 'role-fantasma']
            );
            $this->fail('Se esperaba 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('Rol no encontrado', $e->getMessage());
        }
    }

    public function testIndexYShowDevuelvenLosRoles(): void
    {
        $index = $this->controller->index($this->adminRequest());
        $this->assertSame(200, $index['status']);
        $this->assertCount(3, $index['data']['roles']);

        $show = $this->controller->show($this->adminRequest(), ['id' => 'role-admin']);
        $this->assertSame('ADMIN', $show['data']['role']['nombre']);
        $this->assertTrue($show['data']['role']['activo']);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<string, mixed>
     */
    private function roleRow(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, "El rol {$id} no existe");

        return $row;
    }
}
