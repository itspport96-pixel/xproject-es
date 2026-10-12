<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Http\UserController;
use App\Core\Users\Service\UserService;
use App\Shared\Exceptions\HttpException;
use Tests\Support\PlatformControlSqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Capa HTTP de /api/users (deuda v1.13.3: UserController sin tests).
 *
 *  - GET /users/{id}/roles responde con la procedencia (direct|group|temporary,
 *    con group_id/group_name para el grupo) desde
 *    AuthorizationService::getUserRolesWithOrigin, la misma fuente que usan
 *    login y /auth/me.
 *  - DELETE /users/{id}/roles/{roleId}: 200 si borra la asignacion directa;
 *    409 ROLE_NOT_DIRECT si el rol no esta asignado directamente (v1.13.20).
 *  - GET /users enriquece cada fila con `roles` para la columna Roles de
 *    la tabla (v1.13.3), sin el JSON de permisos.
 *  - PATCH /users no puede bajar al único ADMIN activo (guarda v1.13.2)
 *    y audita valores_anteriores en el cambio (v1.13.1). Como
 *    assertCanActOnUser exige rol ADMIN para tocar a otro ADMIN, la única
 *    vía que llega a la guarda con un solo admin es la auto-actualización;
 *    por eso el actor de los tests es el propio objetivo.
 */
final class UserControllerTest extends PlatformControlSqliteTestCase
{
    private const USER = 'u-target-pcs';

    private UserController $controller;
    private RecordingAuditLogger $audit;
    private mixed $prevGet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prevGet = $_GET;
        $_GET = [];

        $this->seedUser(self::USER);

        $service = new UserService(
            new UserRepository($this->db),
            $this->createStub(PasswordHasher::class),
            $this->db
        );

        $jwt = $this->createStub(JwtService::class);
        $jwt->method('decodeToken')->willReturn((object) [
            'type' => 'access',
            'user_id' => self::USER,
            'tenant_id' => self::TENANT,
            'roles' => [],
        ]);

        $this->audit = new RecordingAuditLogger();

        $this->controller = new UserController($service, $jwt, $this->authz, $this->audit);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-de-test';
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_POST = [];
        $_GET = $this->prevGet;

        parent::tearDown();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function estadoDe(string $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT estado FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $estado = $stmt->fetchColumn();

        return $estado === false ? null : (string) $estado;
    }

    // ====================================================================
    // GET /users/{id}/roles — procedencia
    // ====================================================================

    public function testGetRolesDevuelveLaProcedenciaDirectaYDeGrupo(): void
    {
        $this->seedRole('role-directo', 'OPERADOR', []);
        $this->seedRole('role-grupo', 'AUDITOR', []);
        $this->assignRole(self::USER, 'role-directo');

        $groupId = $this->seedGroup('group-pcs-1');
        $this->addGroupMember($groupId, self::USER);
        $this->attachGroupRole($groupId, 'role-grupo');

        $result = $this->controller->getRoles(['id' => self::USER]);

        $this->assertSame(200, $result['status']);

        $origenes = [];
        foreach ($result['data']['roles'] as $rol) {
            $origenes[$rol['id']] = $rol['origen'];
        }
        ksort($origenes);

        $this->assertSame(
            ['role-directo' => 'direct', 'role-grupo' => 'group'],
            $origenes
        );

        $porId = array_column($result['data']['roles'], null, 'id');
        $this->assertSame('OPERADOR', $porId['role-directo']['nombre']);
        $this->assertSame('CUSTOM', $porId['role-grupo']['tipo']);

        // v1.13.20: el origen group trae id y nombre del grupo para que la
        // UI pueda ofrecer "Quitar del grupo"; el directo no los lleva.
        $this->assertSame('group-pcs-1', $porId['role-grupo']['group_id']);
        $this->assertSame('Grupo group-pcs-1', $porId['role-grupo']['group_name']);
        $this->assertArrayNotHasKey('group_id', $porId['role-directo']);
        $this->assertArrayNotHasKey('group_name', $porId['role-directo']);
    }

    public function testGetRolesPrefiereElOrigenDirectoCuandoTambienVieneDeGrupo(): void
    {
        $this->seedRole('role-ambos', 'OPERADOR', []);
        $this->assignRole(self::USER, 'role-ambos');

        $groupId = $this->seedGroup('group-pcs-1');
        $this->addGroupMember($groupId, self::USER);
        $this->attachGroupRole($groupId, 'role-ambos');

        $result = $this->controller->getRoles(['id' => self::USER]);

        $this->assertCount(1, $result['data']['roles']);
        $this->assertSame('direct', $result['data']['roles'][0]['origen']);
    }

    public function testGetRolesMarcaComoTemporaryLaAsignacionConCaducidad(): void
    {
        $this->seedRole('role-temp', 'OPERADOR', []);
        $this->assignRole(self::USER, 'role-temp', '2030-01-01 00:00:00');

        $result = $this->controller->getRoles(['id' => self::USER]);

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['data']['roles']);
        $this->assertSame('temporary', $result['data']['roles'][0]['origen']);
        $this->assertStringStartsWith(
            '2030-01-01',
            (string) $result['data']['roles'][0]['fecha_expiracion']
        );
    }

    // ====================================================================
    // GET /users — columna Roles de la tabla
    // ====================================================================

    public function testIndexAnadeLaColumnaRolesAFilaDeUsuario(): void
    {
        $this->seedUser('u-directo-pcs');
        $this->seedUser('u-grupo-pcs');
        $this->seedUser('u-sin-roles-pcs');

        $this->seedRole('role-directo', 'OPERADOR', []);
        $this->assignRole('u-directo-pcs', 'role-directo');

        $this->seedRole('role-grupo', 'AUDITOR', []);
        $groupId = $this->seedGroup('group-pcs-1');
        $this->addGroupMember($groupId, 'u-grupo-pcs');
        $this->attachGroupRole($groupId, 'role-grupo');

        $_GET = ['per_page' => '50'];
        $result = $this->controller->index();

        $this->assertSame(200, $result['status']);

        $porId = [];
        foreach ($result['data']['data'] as $row) {
            $this->assertArrayHasKey('roles', $row, 'Cada fila debe traer roles para la columna');
            $porId[$row['id']] = $row['roles'];
        }

        $this->assertCount(1, $porId['u-directo-pcs']);
        $this->assertSame('direct', $porId['u-directo-pcs'][0]['origen']);
        $this->assertSame('OPERADOR', $porId['u-directo-pcs'][0]['nombre']);

        $this->assertCount(1, $porId['u-grupo-pcs']);
        $this->assertSame('group', $porId['u-grupo-pcs'][0]['origen']);

        $this->assertSame([], $porId['u-sin-roles-pcs']);

        foreach ($porId as $roles) {
            foreach ($roles as $rol) {
                $this->assertSame(
                    ['id', 'nombre', 'tipo', 'origen'],
                    array_keys($rol),
                    'La columna no debe arrastrar el JSON de permisos'
                );
            }
        }
    }

    // ====================================================================
    // DELETE /users/{id}/roles — 200 directo / 409 heredado (v1.13.20)
    // ====================================================================

    public function testRemoveRoleDirectoDevuelve200YBorraLaAsignacion(): void
    {
        $this->seedRole('role-directo', 'OPERADOR', []);
        $this->assignRole(self::USER, 'role-directo');

        $result = $this->controller->removeRole(
            ['id' => self::USER, 'roleId' => 'role-directo'],
            $this->request()
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            [],
            $this->controller->getRoles(['id' => self::USER])['data']['roles'],
            'La asignacion directa debe desaparecer del listado'
        );

        $entrada = $this->audit->byOperacion('user.role.remove');
        $this->assertNotNull($entrada, 'La baja directa debe quedar auditada');
        $this->assertSame('role-directo', $entrada['valores_anteriores']['role_id']);
    }

    public function testRemoveRoleHeredadoDeGrupoDevuelve409RoleNotDirect(): void
    {
        $this->seedRole('role-grupo', 'AUDITOR', []);
        $groupId = $this->seedGroup('group-pcs-1');
        $this->addGroupMember($groupId, self::USER);
        $this->attachGroupRole($groupId, 'role-grupo');

        try {
            $this->controller->removeRole(
                ['id' => self::USER, 'roleId' => 'role-grupo'],
                $this->request()
            );
            $this->fail('Se esperaba UserException 409 ROLE_NOT_DIRECT');
        } catch (UserException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('conflict', $e->getCategory());
            $this->assertSame('ROLE_NOT_DIRECT', $e->getErrorCode());
        }

        // El rol sigue donde estaba: nada se borró en silencio.
        $roles = $this->controller->getRoles(['id' => self::USER])['data']['roles'];
        $this->assertCount(1, $roles);
        $this->assertSame('group', $roles[0]['origen']);

        // Sin cambio de estado no hay auditoría de éxito.
        $this->assertNull($this->audit->byOperacion('user.role.remove'));
    }

    // ====================================================================
    // PATCH /users — guarda del último admin activo (v1.13.2)
    // ====================================================================

    public function testUpdateNoPuedeBajarAlUnicoAdminActivo(): void
    {
        $this->seedRole('role-admin', 'ADMIN', [], 'BUILT_IN');
        $this->assignRole(self::USER, 'role-admin');

        $_POST = ['estado' => 'INACTIVO'];

        try {
            $this->controller->update(['id' => self::USER], $this->request());
            $this->fail('Se esperaba UserException 403 para el único admin');
        } catch (UserException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('forbidden', $e->getCategory());
        }

        $this->assertSame('ACTIVO', $this->estadoDe(self::USER));
        $this->assertNull(
            $this->audit->byOperacion('user.update'),
            'La operación denegada no debe auditar éxito'
        );
    }

    public function testUpdatePermiteBajarElEstadoConMasDeUnAdminActivo(): void
    {
        $this->seedUser('u-admin2-pcs');
        $this->seedRole('role-admin', 'ADMIN', [], 'BUILT_IN');
        $this->assignRole(self::USER, 'role-admin');
        $this->assignRole('u-admin2-pcs', 'role-admin');

        $_POST = ['estado' => 'INACTIVO'];

        $result = $this->controller->update(['id' => self::USER], $this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame('INACTIVO', $this->estadoDe(self::USER));

        $entrada = $this->audit->byOperacion('user.update');
        $this->assertNotNull($entrada, 'El cambio debe quedar auditado');
        $this->assertSame('ACTIVO', $entrada['valores_anteriores']['estado'], 'Debe registrar el estado previo');
        $this->assertSame('INACTIVO', $entrada['valores_nuevos']['estado']);
    }

    public function testUpdateSinClaimsDa401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No autenticado');

        $this->controller->update(['id' => self::USER]);
    }
}
