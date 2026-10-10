<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authorization\Entity\Role;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Core\Authorization\Http\RoleController;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepository;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Groups\Exception\GroupException;
use App\Core\Groups\Http\GroupController;
use App\Core\Groups\Repository\UserGroupRepository;
use App\Core\Users\Http\UserController;
use App\Core\Users\Service\UserService;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\RedisService;
use PDO;
use Tests\Support\PlatformControlSqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Aislamiento de roles por tenant (v1.11.1).
 *
 * Regla: roles.tenant_id NULL = BUILT_IN (visibles para todos los tenants);
 * con valor = CUSTOM (visibles/editables/asignables sólo por su tenant).
 */
final class RoleTenantIsolationTest extends PlatformControlSqliteTestCase
{
    private const OTHER_TENANT = 'tenant-other-9';
    private const U_ADMIN = 'u-admin-iso';
    private const U_TARGET = 'u-target-iso';

    private RoleController $controller;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRole('r-bi-admin', 'ADMIN', ['users.user.view'], 'BUILT_IN');
        $this->seedRole('r-bi-manager', 'MANAGER', ['users.user.view'], 'BUILT_IN');
        $this->seedRole('r-bi-user', 'USER', ['users.user.view'], 'BUILT_IN');
        $this->seedRole('r-bi-viewer', 'VIEWER', [], 'BUILT_IN');
        $this->seedRole('r-propio', 'ROL PROPIO', ['users.user.view'], 'CUSTOM', true, self::TENANT);
        $this->seedRole('r-ajeno', 'ROL AJENO', ['users.user.view'], 'CUSTOM', true, self::OTHER_TENANT);

        $this->seedUser(self::U_ADMIN);
        $this->seedUser(self::U_TARGET);

        // requireAdmin consulta la BD (v1.12.1): el admin necesita la
        // asignación real; el claim 'roles' ya no es la autoridad.
        $this->assignRole(self::U_ADMIN, 'r-bi-admin');

        $this->audit = new RecordingAuditLogger();
        $this->controller = new RoleController(
            new RoleRepository($this->db),
            $this->authz,
            $this->audit
        );
    }

    private function adminRequest(array $overrides = []): array
    {
        return $this->request($overrides, $this->claims(self::U_ADMIN, [['nombre' => 'ADMIN']]));
    }

    private function assertStatus(callable $fn, int $esperado, string $mensaje = ''): void
    {
        try {
            $fn();
            $this->fail("Se esperaba un {$esperado} pero no se lanzó nada");
        } catch (HttpException $e) {
            $this->assertSame($esperado, $e->getCode(), $mensaje);
        }
    }

    // ====================================================================
    // Repositorio: visibilidad
    // ====================================================================

    public function testFindAllConTenantSoloDevuelveBuiltInYLosDelPropio(): void
    {
        $nombres = array_map(
            fn(Role $r): string => $r->nombre(),
            $this->authzRoles()->findAll(true, self::TENANT)
        );

        $this->assertContains('ROL PROPIO', $nombres);
        $this->assertContains('ADMIN', $nombres);
        $this->assertNotContains('ROL AJENO', $nombres, 'Un rol CUSTOM de otro tenant no debe listarse');
    }

    public function testFindAllSinTenantSigueSiendoGlobal(): void
    {
        $nombres = array_map(fn(Role $r): string => $r->nombre(), $this->authzRoles()->findAll(true));

        $this->assertContains('ROL PROPIO', $nombres);
        $this->assertContains('ROL AJENO', $nombres);
    }

    public function testFindByIdNoCruzaTenants(): void
    {
        $repo = $this->authzRoles();

        $this->assertNotNull($repo->findById('r-propio', self::TENANT), 'El rol propio es visible');
        $this->assertNotNull($repo->findById('r-bi-admin', self::TENANT), 'BUILT_IN visible para todos');
        $this->assertNull($repo->findById('r-ajeno', self::TENANT), 'El rol de otro tenant no es visible');
        $this->assertNotNull($repo->findById('r-ajeno', self::OTHER_TENANT));
    }

    public function testExistsByNombreUsaElAmbitoVisible(): void
    {
        $repo = $this->authzRoles();

        $this->assertTrue($repo->existsByNombre('ROL PROPIO', self::TENANT), 'Duplicado en el propio tenant');
        $this->assertTrue($repo->existsByNombre('ADMIN', self::TENANT), 'El nombre de un BUILT_IN está reservado');
        $this->assertFalse(
            $repo->existsByNombre('ROL AJENO', self::TENANT),
            'Un nombre usado por otro tenant no bloquea en el propio'
        );
        $this->assertTrue($repo->existsByNombre('ROL AJENO', self::OTHER_TENANT));
    }

    public function testSaveNoPermiteDuplicadoPropioPeroSiNombreDeOtroTenant(): void
    {
        $repo = $this->authzRoles();

        try {
            $repo->save(Role::create(
                id: 'r-dup',
                nombre: 'ROL PROPIO',
                permisos: [],
                tenantId: self::TENANT
            ));
            $this->fail('Se esperaba 409 por nombre duplicado en el propio tenant');
        } catch (AuthorizationException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $repo->save(Role::create(
            id: 'r-heredado-nombre',
            nombre: 'ROL AJENO',
            permisos: [],
            tenantId: self::TENANT
        ));

        $row = $this->roleRow('r-heredado-nombre');
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertSame('CUSTOM', $row['tipo']);
    }

    public function testSoftDeleteNoCruzaTenants(): void
    {
        $repo = $this->authzRoles();

        $this->assertStatus(
            fn() => $repo->softDelete('r-ajeno', self::TENANT),
            404,
            'No se debe poder borrar el rol de otro tenant'
        );
        $this->assertNull($this->roleRow('r-ajeno')['deleted_at'], 'El rol ajeno queda intacto');
    }

    public function testRestoreNoCruzaTenants(): void
    {
        $repo = $this->authzRoles();
        $repo->softDelete('r-propio', self::TENANT);
        $this->pdo
            ->prepare("UPDATE roles SET deleted_at = '2026-01-01 00:00:00' WHERE id = 'r-ajeno'")
            ->execute();

        $this->assertStatus(
            fn() => $repo->restore('r-ajeno', self::TENANT),
            404,
            'No se debe poder restaurar el rol de otro tenant'
        );
        $this->assertNotNull($this->roleRow('r-ajeno')['deleted_at']);

        $repo->restore('r-propio', self::TENANT);
        $this->assertNull($this->roleRow('r-propio')['deleted_at']);
    }

    public function testIndiceUnicoBloqueaDuplicadoEnElMismoTenant(): void
    {
        $this->expectException(\PDOException::class);

        $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, tipo, tenant_id, activo, permisos, created_at, updated_at)
             VALUES (:id, :nombre, :tipo, :tenant, 1, NULL, :now, :now)'
        )->execute([
            'id' => 'r-idx',
            'nombre' => 'ROL PROPIO',
            'tipo' => 'CUSTOM',
            'tenant' => self::TENANT,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testIndiceUnicoPermiteMismoNombreEnOtroTenant(): void
    {
        $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, tipo, tenant_id, activo, permisos, created_at, updated_at)
             VALUES (:id, :nombre, :tipo, :tenant, 1, NULL, :now, :now)'
        )->execute([
            'id' => 'r-idx-ok',
            'nombre' => 'ROL PROPIO',
            'tipo' => 'CUSTOM',
            'tenant' => self::OTHER_TENANT,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->assertSame('ROL PROPIO', $this->roleRow('r-idx-ok')['nombre']);
    }

    // ====================================================================
    // Entidad
    // ====================================================================

    public function testCustomSinTenantEsRechazado(): void
    {
        $this->assertStatus(
            fn() => Role::create(id: 'r-x', nombre: 'SIN TENANT', permisos: []),
            400,
            'Un rol CUSTOM sin tenant_id no puede crearse'
        );
    }

    public function testBuiltInFuerzaTenantNulo(): void
    {
        $role = Role::create(
            id: 'r-bi-nuevo',
            nombre: 'NUEVO BUILT',
            tipo: Role::TYPE_BUILT_IN,
            permisos: [],
            tenantId: self::TENANT
        );

        $this->assertNull($role->tenantId());
        $this->assertTrue($role->usableByTenant(self::OTHER_TENANT));
    }

    // ====================================================================
    // RoleController
    // ====================================================================

    public function testIndexListaBuiltInYLosDelTenantActual(): void
    {
        $resp = $this->controller->index($this->adminRequest());
        $nombres = array_column($resp['data']['roles'], 'nombre');

        $this->assertSame(200, $resp['status']);
        $this->assertCount(5, $nombres, '4 BUILT_IN + 1 CUSTOM propio');
        $this->assertContains('ROL PROPIO', $nombres);
        $this->assertNotContains('ROL AJENO', $nombres);
    }

    public function testShowDeOtroTenantDa404(): void
    {
        $this->assertStatus(
            fn() => $this->controller->show($this->adminRequest(), ['id' => 'r-ajeno']),
            404,
            'GET de un rol ajeno debe dar 404'
        );

        $resp = $this->controller->show($this->adminRequest(), ['id' => 'r-bi-admin']);
        $this->assertSame(200, $resp['status']);
    }

    public function testUpdateDeOtroTenantDa404YNoModifica(): void
    {
        $this->assertStatus(
            fn() => $this->controller->update(
                $this->adminRequest(['body' => ['nombre' => 'HACKEADO']]),
                ['id' => 'r-ajeno']
            ),
            404
        );

        $this->assertSame('ROL AJENO', $this->roleRow('r-ajeno')['nombre']);
    }

    public function testUpdatePermissionsDeOtroTenantDa404(): void
    {
        $this->assertStatus(
            fn() => $this->controller->updatePermissions(
                $this->adminRequest(['body' => ['permisos' => []]]),
                ['id' => 'r-ajeno']
            ),
            404
        );

        $this->assertSame(
            '["users.user.view"]',
            $this->roleRow('r-ajeno')['permisos'],
            'Los permisos del rol ajeno no cambian'
        );
    }

    public function testDestroyDeOtroTenantDa404YNoBorra(): void
    {
        $this->assertStatus(
            fn() => $this->controller->destroy($this->adminRequest(), ['id' => 'r-ajeno']),
            404
        );

        $this->assertNull($this->roleRow('r-ajeno')['deleted_at']);
    }

    public function testRestoreEndpointDeOtroTenantDa404(): void
    {
        $this->pdo
            ->prepare("UPDATE roles SET deleted_at = '2026-01-01 00:00:00' WHERE id = 'r-ajeno'")
            ->execute();

        $this->assertStatus(
            fn() => $this->controller->restore($this->adminRequest(), ['id' => 'r-ajeno']),
            404
        );

        $this->assertNotNull($this->roleRow('r-ajeno')['deleted_at']);
    }

    public function testStoreRechazaNombreDeBuiltInYDuplicadoPropio(): void
    {
        $this->assertStatus(
            fn() => $this->controller->store($this->adminRequest(['body' => ['nombre' => 'ADMIN']])),
            409
        );

        $this->assertStatus(
            fn() => $this->controller->store($this->adminRequest(['body' => ['nombre' => 'ROL PROPIO']])),
            409
        );

        $this->assertSame(6, $this->countRows('roles'));
    }

    public function testStoreAdmiteNombreUsadoPorOtroTenantYSelloSuTenant(): void
    {
        $resp = $this->controller->store($this->adminRequest([
            'body' => ['nombre' => 'ROL AJENO', 'permisos' => ['users.user.view']],
        ]));

        $this->assertSame(201, $resp['status']);
        $id = $resp['data']['role']['id'];
        $row = $this->roleRow($id);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertSame('CUSTOM', $row['tipo']);
    }

    // ====================================================================
    // Asignación de roles a usuarios y grupos
    // ====================================================================

    public function testAsignarRolDeOtroTenantAUsuarioDa404(): void
    {
        $controller = $this->userController(new RecordingAuditLogger());
        $_POST = ['role_id' => 'r-ajeno'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-de-prueba';

        try {
            $this->assertStatus(
                fn() => $controller->assignRole(['id' => self::U_TARGET], []),
                404
            );
            // setUp siembra la asignación de ADMIN de U_ADMIN; sólo cuenta
            // las del usuario destino.
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM role_assignments WHERE user_id = :uid'
            );
            $stmt->execute(['uid' => self::U_TARGET]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'No debe insertar la asignación');
        } finally {
            unset($_POST['role_id'], $_SERVER['HTTP_AUTHORIZATION']);
        }
    }

    public function testAsignarBuiltInAUsuarioFuncionaDesdeCualquierTenant(): void
    {
        $audit = new RecordingAuditLogger();
        $controller = $this->userController($audit);
        $_POST = ['role_id' => 'r-bi-viewer'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-de-prueba';

        try {
            $resp = $controller->assignRole(['id' => self::U_TARGET], []);

            $this->assertSame(200, $resp['status']);
            // 1 del intento + la de ADMIN sembrada en setUp.
            $this->assertSame(2, $this->countRows('role_assignments'));
            $this->assertSame('user.role.assign', $audit->entries[0]['operacion'] ?? null);
        } finally {
            unset($_POST['role_id'], $_SERVER['HTTP_AUTHORIZATION']);
        }
    }

    public function testAsignarRolDeOtroTenantAGrupoDa404(): void
    {
        $this->seedGroup('g-iso');
        $controller = new GroupController(
            new UserGroupRepository($this->db),
            new UserRepository($this->db),
            new RoleRepository($this->db),
            $this->authz,
            null,
            $this->db,
            null
        );

        $this->assertStatus(
            fn() => $controller->assignRole(
                $this->request(['body' => ['role_id' => 'r-ajeno']], $this->claims(self::U_ADMIN)),
                ['id' => 'g-iso']
            ),
            404,
            'Un rol ajeno no debe poder asignarse a un grupo'
        );
    }

    // ====================================================================
    // Cache por tenant
    // ====================================================================

    public function testInvalidateCacheForRoleSoloAfectaAlTenantIndicado(): void
    {
        $spy = new SpyRedisService([]);
        $authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            new RoleRepository($this->db),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $spy
        );

        // U_ADMIN ya tiene r-bi-admin en TENANT (sembrado en setUp para
        // requireAdmin); ra-2 es el carrier de otro tenant.
        $this->seedAssignment('ra-2', self::U_TARGET, 'r-bi-admin', self::OTHER_TENANT);

        $authz->invalidateCacheForRole('r-bi-admin', self::TENANT);

        $this->assertSame(
            ['auth:perms:' . self::TENANT . ':' . self::U_ADMIN],
            $spy->deleted,
            'Sólo se invalida el portador del tenant que opera'
        );
    }

    // ====================================================================
    // Helpers
    // ====================================================================

    private function authzRoles(): RoleRepository
    {
        return new RoleRepository($this->db);
    }

    private function roleRow(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, "La fila {$id} debe existir");

        return $row;
    }

    private function seedAssignment(string $id, string $userId, string $roleId, string $tenantId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO role_assignments
                (id, user_id, role_id, tenant_id, asignado_por_user_id,
                 fecha_asignacion, fecha_expiracion, created_at, updated_at)
             VALUES (:id, :user_id, :role_id, :tenant, NULL, :now, NULL, :now, :now)'
        )->execute([
            'id' => $id,
            'user_id' => $userId,
            'role_id' => $roleId,
            'tenant' => $tenantId,
            'now' => $now,
        ]);
    }

    private function userController(RecordingAuditLogger $audit): UserController
    {
        $service = new UserService(
            new UserRepository($this->db),
            $this->createStub(PasswordHasher::class),
            $this->db
        );

        $jwt = $this->createStub(JwtService::class);
        $jwt->method('decodeToken')->willReturn((object) [
            'type' => 'access',
            'user_id' => self::U_ADMIN,
            'tenant_id' => self::TENANT,
            'roles' => [['nombre' => 'ADMIN']],
        ]);

        $authz = new AuthorizationService(
            new UserRepository($this->db),
            new RoleRepository($this->db),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $this->createStub(RedisService::class)
        );

        return new UserController($service, $jwt, $authz, $audit);
    }
}

final class SpyRedisService extends RedisService
{
    /** @var array<int, string> */
    public array $deleted = [];

    public function delete(string $key): bool
    {
        $this->deleted[] = $key;

        return true;
    }
}
