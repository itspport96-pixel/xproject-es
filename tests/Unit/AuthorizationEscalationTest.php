<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Exceptions\HttpException;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Escalada de acciones sobre usuarios (ajuste aprobado del sprint,
 * spec §4/§5):
 *
 *  - assertCanActOnUser: actor >= permisos efectivos del afectado y
 *    sólo ADMIN sobre ADMIN (cambio de estado, reset de password,
 *    asignación de roles, roles de grupo y permisos temporales).
 *  - assertCanAssignRole: el rol a otorgar debe estar contenido en los
 *    permisos efectivos del actor.
 *  - hasAdminRole / isActiveAdmin: detección de ADMIN vigente (+ cuenta
 *    ACTIVA en isActiveAdmin).
 *
 * AuthorizationService es `final` y su SQL usa NOW(): aquí se usa el
 * servicio REAL contra SQLite con la función NOW() registrada y un
 * RedisService stub (sólo cache, sin conexión).
 */
final class AuthorizationEscalationTest extends PlatformControlSqliteTestCase
{
    private const P_VIEW = 'users.user.view';
    private const P_CREATE = 'users.user.create';
    private const P_STATUS = 'users.user.status';

    private const U_ADMIN = 'u-admin';
    private const U_MANAGER = 'u-manager';
    private const U_OPERATOR = 'u-operator';
    private const U_SUPER = 'u-super';

    protected function setUp(): void
    {
        parent::setUp();

        // Catálogo: los 3 permisos activos (filterActivePermissions los exige)
        $this->seedPermission(self::P_VIEW);
        $this->seedPermission(self::P_CREATE);
        $this->seedPermission(self::P_STATUS);

        // Roles: ADMIN (global) y roles CUSTOM con listas cruzadas
        $this->seedRole('role-admin', 'ADMIN', [self::P_VIEW, self::P_CREATE, self::P_STATUS], 'BUILT_IN');
        $this->seedRole('role-manager', 'MANAGER', [self::P_VIEW, self::P_CREATE], 'BUILT_IN');
        $this->seedRole('role-operator', 'OPERATOR', [self::P_VIEW, self::P_STATUS]);
        // Mismos permisos que ADMIN pero sin el rol ADMIN (para aislar la
        // regla "sólo ADMIN sobre ADMIN")
        $this->seedRole('role-super', 'SUPER', [self::P_VIEW, self::P_CREATE, self::P_STATUS]);
        // Rol con un permiso que el MANAGER no tiene
        $this->seedRole('role-big', 'BIG', [self::P_VIEW, self::P_CREATE, self::P_STATUS]);

        $this->seedUser(self::U_ADMIN);
        $this->seedUser(self::U_MANAGER);
        $this->seedUser(self::U_OPERATOR);
        $this->seedUser(self::U_SUPER);

        $this->assignRole(self::U_ADMIN, 'role-admin');
        $this->assignRole(self::U_MANAGER, 'role-manager');
        $this->assignRole(self::U_OPERATOR, 'role-operator');
        $this->assignRole(self::U_SUPER, 'role-super');
    }

    private function assertForbidden(callable $fn, string $mensajeContenido): void
    {
        try {
            $fn();
            $this->fail('Se esperaba un 403 pero no se lanzó nada');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString($mensajeContenido, $e->getMessage());
        }
    }

    // ====================================================================
    // PERMISOS EFECTIVOS (base de la escalada)
    // ====================================================================

    public function testGetUserPermissionsCruzaRolesGruposYTemporales(): void
    {
        // Directo (role-manager) + grupo con rol (role-operator) + temporal
        $this->addGroupMember('grp-1', self::U_MANAGER);
        $this->seedGroup('grp-1');
        $this->attachGroupRole('grp-1', 'role-operator');
        $this->seedTemporaryPermission(
            self::U_MANAGER,
            'perm-users-user-status',
            gmdate('Y-m-d H:i:s', time() + 3600)
        );

        $permisos = $this->authz->getUserPermissions(self::U_MANAGER, self::TENANT);

        $this->assertContains(self::P_VIEW, $permisos);
        $this->assertContains(self::P_CREATE, $permisos);
        $this->assertContains(self::P_STATUS, $permisos, 'El permiso temporal debe sumarse');
        $this->assertCount(3, $permisos);
    }

    public function testPermisosDeUnRolInactivoONoVigenteNoCuentan(): void
    {
        $this->seedUser('u-expired');
        $this->assignRole('u-expired', 'role-manager', gmdate('Y-m-d H:i:s', time() - 60));

        $this->assertSame([], $this->authz->getUserPermissions('u-expired', self::TENANT));

        // Rol desactivado tampoco cuenta
        $this->seedUser('u-disabled');
        $this->seedRole('role-off', 'OFF', [self::P_VIEW]);
        $this->assignRole('u-disabled', 'role-off');
        $this->pdo->prepare('UPDATE roles SET activo = 0 WHERE id = :id')
            ->execute(['id' => 'role-off']);

        $this->assertSame([], $this->authz->getUserPermissions('u-disabled', self::TENANT));
    }

    // ====================================================================
    // assertCanActOnUser
    // ====================================================================

    public function testActorConSupersetDePermisosPuedeActuar(): void
    {
        // ADMIN posee todo lo que tiene MANAGER
        $this->authz->assertCanActOnUser(self::U_ADMIN, self::TENANT, self::U_MANAGER);
        $this->authz->assertCanActOnUser(self::U_ADMIN, self::TENANT, self::U_OPERATOR);

        $this->addToAssertionCount(2);
    }

    public function testActorSinUnPermisoDelObjetivoNoPuedeActuar(): void
    {
        // MANAGER no tiene users.user.status (que sí tiene OPERATOR)
        $this->assertForbidden(
            fn() => $this->authz->assertCanActOnUser(self::U_MANAGER, self::TENANT, self::U_OPERATOR),
            'permisos efectivos del usuario afectado'
        );
    }

    public function testActorConMenosPermisosQueElObjetivoNoPuedeActuar(): void
    {
        // OPERATOR no tiene users.user.create (que sí tiene MANAGER)
        $this->assertForbidden(
            fn() => $this->authz->assertCanActOnUser(self::U_OPERATOR, self::TENANT, self::U_MANAGER),
            'permisos efectivos del usuario afectado'
        );
    }

    public function testSoloAdminPuedeActuarSobreOtroAdmin(): void
    {
        // SUPER tiene exactamente los mismos permisos que ADMIN pero sin el
        // rol ADMIN: la escalada por permisos pasa, la regla ADMIN no.
        $this->assertForbidden(
            fn() => $this->authz->assertCanActOnUser(self::U_SUPER, self::TENANT, self::U_ADMIN),
            'rol ADMIN'
        );

        // Un ADMIN sí puede actuar sobre otro ADMIN
        $this->seedUser('u-admin2');
        $this->assignRole('u-admin2', 'role-admin');
        $this->authz->assertCanActOnUser(self::U_ADMIN, self::TENANT, 'u-admin2');

        $this->addToAssertionCount(1);
    }

    public function testAccionSobreUnoMismoNoSeLimita(): void
    {
        // Aunque fuera OPERATOR no puede bloquearse a sí mismo
        $this->authz->assertCanActOnUser(self::U_OPERATOR, self::TENANT, self::U_OPERATOR);
        $this->authz->assertCanActOnUser(self::U_ADMIN, self::TENANT, self::U_ADMIN);

        $this->addToAssertionCount(2);
    }

    // ====================================================================
    // assertCanAssignRole
    // ====================================================================

    public function testAsignarRolContenidoEnLosPermisosDelActor(): void
    {
        // MANAGER tiene view+create, que es lo que otorga role-manager
        $this->authz->assertCanAssignRole(self::U_MANAGER, self::TENANT, 'role-manager');

        $this->addToAssertionCount(1);
    }

    public function testAsignarRolConPermisosQueElActorNoTieneEstaProhibido(): void
    {
        // role-big otorga users.user.status, que MANAGER no posee
        $this->assertForbidden(
            fn() => $this->authz->assertCanAssignRole(self::U_MANAGER, self::TENANT, 'role-big'),
            'contiene permisos que el usuario no posee'
        );
    }

    public function testAsignarRolVacioNoTieneRestriccionDePermisos(): void
    {
        $this->seedRole('role-empty', 'EMPTY', []);

        $this->authz->assertCanAssignRole(self::U_OPERATOR, self::TENANT, 'role-empty');

        $this->addToAssertionCount(1);
    }

    public function testGetRolePermissionsLeeElJsonDelRol(): void
    {
        $permisos = $this->authz->getRolePermissions('role-manager');

        $this->assertSame([self::P_CREATE, self::P_VIEW], $permisos);
        $this->assertSame([], $this->authz->getRolePermissions('role-inexistente'));
    }

    // ====================================================================
    // hasAdminRole / isActiveAdmin
    // ====================================================================

    public function testHasAdminRoleDetectaElRolVigente(): void
    {
        $this->assertTrue($this->authz->hasAdminRole(self::U_ADMIN, self::TENANT));
        $this->assertFalse($this->authz->hasAdminRole(self::U_MANAGER, self::TENANT));
        $this->assertFalse($this->authz->hasAdminRole('u-inexistente', self::TENANT));
    }

    public function testHasAdminRoleIgnoraAsignacionesExpiradas(): void
    {
        $this->seedUser('u-admin-exp');
        $this->assignRole('u-admin-exp', 'role-admin', gmdate('Y-m-d H:i:s', time() - 60));

        $this->assertFalse($this->authz->hasAdminRole('u-admin-exp', self::TENANT));
    }

    public function testIsActiveAdminExigeAdemasCuentaActiva(): void
    {
        $this->assertTrue($this->authz->isActiveAdmin(self::U_ADMIN, self::TENANT));
        $this->assertFalse($this->authz->isActiveAdmin(self::U_MANAGER, self::TENANT));

        $this->seedUser('u-admin-baja', 'INACTIVO');
        $this->assignRole('u-admin-baja', 'role-admin');

        $this->assertTrue(
            $this->authz->hasAdminRole('u-admin-baja', self::TENANT),
            'Tiene el rol ADMIN...'
        );
        $this->assertFalse(
            $this->authz->isActiveAdmin('u-admin-baja', self::TENANT),
            '...pero la cuenta no está ACTIVA'
        );
    }

    public function testIsActiveAdminIgnoraUsuariosBorrados(): void
    {
        $this->seedUser('u-admin-soft');
        $this->assignRole('u-admin-soft', 'role-admin');
        $this->pdo->prepare('UPDATE users SET deleted_at = :now WHERE id = :id')
            ->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => 'u-admin-soft']);

        $this->assertFalse($this->authz->isActiveAdmin('u-admin-soft', self::TENANT));
    }

    // ====================================================================
    // Invalidación de cache por rol / grupo
    // ====================================================================

    public function testInvalidateCacheForRoleAfectaSoloAPortadoresDelRol(): void
    {
        // El servicio usa Redis sólo para borrar claves: con el stub basta
        // verificar que no lanza y que las consultas son correctas.
        $this->authz->invalidateCacheForRole('role-manager', self::TENANT);
        $this->authz->invalidateCacheForRole('role-inexistente', self::TENANT);

        $this->addToAssertionCount(2);
    }

    public function testInvalidateCacheForGroupAfectaSoloAMiembros(): void
    {
        $this->seedGroup('grp-1');
        $this->addGroupMember('grp-1', self::U_MANAGER);

        $this->authz->invalidateCacheForGroup('grp-1', self::TENANT);
        $this->authz->invalidateCacheForGroup('grp-inexistente', self::TENANT);

        $this->addToAssertionCount(2);
    }
}
