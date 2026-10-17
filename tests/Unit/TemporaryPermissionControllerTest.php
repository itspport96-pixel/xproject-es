<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Exception\TemporaryPermissionException;
use App\Core\Authorization\Http\TemporaryPermissionController;
use App\Core\Authorization\Repository\PermissionRepository;
use App\Core\Authorization\Repository\TemporaryPermissionRepository;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Security\Service\RateLimiter;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\RedisService;
use Tests\Support\PlatformControlSqliteTestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Capa HTTP de POST/GET/DELETE /api/temporary-permissions (§5.3, v1.11.0).
 *
 * Cubre con los repositorios y AuthorizationService REALES (SQLite):
 *  - Campos obligatorios y validaciones de fecha con `field`/`category`
 *    en TemporaryPermissionException (envelope de public/index.php).
 *  - Orden de validaciones: forma (400) antes que duplicado (409).
 *  - Escalada actor >= beneficiario (sólo ADMIN sobre ADMIN).
 *  - CRITICAL sólo admin-equivalente.
 *  - Éxito: persistencia, auditoría de dominio y revocación.
 */
final class TemporaryPermissionControllerTest extends PlatformControlSqliteTestCase
{
    private const P_VIEW = 'users.user.view';
    private const P_CREATE = 'users.user.create';
    private const P_STATUS = 'users.user.status';
    private const P_RESET = 'users.password.reset';
    private const P_CRITICAL = 'groups.group.delete';

    private const U_ACTOR = 'u-actor';
    private const U_TARGET = 'u-target';
    private const U_WEAK = 'u-weak';
    private const U_TARGET2 = 'u-target2';
    private const U_GUEST = 'u-guest';

    private TemporaryPermissionController $controller;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermission(self::P_VIEW);
        $this->seedPermission(self::P_CREATE);
        $this->seedPermission(self::P_STATUS);
        $this->seedPermission(self::P_RESET, 'MEDIUM', false);
        $this->seedPermission(self::P_CRITICAL, 'CRITICAL');

        $this->seedRole('role-admin', 'ADMIN', [
            self::P_VIEW, self::P_CREATE, self::P_STATUS, self::P_RESET, self::P_CRITICAL,
        ], 'BUILT_IN');
        $this->seedRole('role-weak', 'WEAK', [self::P_VIEW]);
        $this->seedRole('role-user2', 'TARGET', [self::P_VIEW, self::P_CREATE]);

        $this->seedUser(self::U_ACTOR);
        $this->seedUser(self::U_TARGET);
        $this->seedUser(self::U_WEAK);
        $this->seedUser(self::U_TARGET2);
        $this->seedUser(self::U_GUEST);

        $this->assignRole(self::U_ACTOR, 'role-admin');
        $this->assignRole(self::U_WEAK, 'role-weak');
        $this->assignRole(self::U_TARGET2, 'role-user2');

        $this->audit = new RecordingAuditLogger();

        $this->controller = new TemporaryPermissionController(
            new TemporaryPermissionRepository($this->db),
            new UserRepository($this->db),
            new PermissionRepository($this->db),
            $this->authz,
            $this->audit,
            new RateLimiter($this->createStub(RedisService::class))
        );
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Body válido por defecto: target sin roles + permiso MEDIUM activo.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'user_id' => self::U_TARGET,
            'permission_id' => 'perm-' . str_replace('.', '-', self::P_VIEW),
            'motivo' => 'Cambio de turno',
            'duracion_horas' => 24,
        ], $overrides);
    }

    /**
     * Lanza store() y devuelve la excepción TemporaryPermissionException.
     *
     * @param array<string, mixed> $body
     */
    private function storeFails(array $body, ?array $claims = null): TemporaryPermissionException
    {
        try {
            $this->controller->store($this->request(['body' => $body], $claims));
        } catch (TemporaryPermissionException $e) {
            return $e;
        }

        $this->fail('Se esperaba TemporaryPermissionException y no se lanzó nada');
    }

    /**
     * Lanza store() y devuelve la excepción HttpException genérica
     * (escalada, 401...).
     *
     * @param array<string, mixed> $body
     */
    private function storeHttpFails(array $body, ?array $claims = null): HttpException
    {
        try {
            $this->controller->store($this->request(['body' => $body], $claims));
        } catch (HttpException $e) {
            return $e;
        }

        $this->fail('Se esperaba HttpException y no se lanzó nada');
    }

    // ====================================================================
    // CAMPOS OBLIGATORIOS (400 + field)
    // ====================================================================

    public function testSinUserIdDa400ConCampoUser_id(): void
    {
        $e = $this->storeFails($this->body(['user_id' => '']));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('user_id', $e->getField());
        $this->assertSame('validation', $e->getCategory());
        $this->assertSame('user_id es obligatorio.', $e->getMessage());
    }

    public function testSinPermissionIdDa400ConCampoPermission_id(): void
    {
        $e = $this->storeFails($this->body(['permission_id' => '']));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('permission_id', $e->getField());
        $this->assertSame('validation', $e->getCategory());
    }

    public function testSinMotivoDa400ConCampoMotivo(): void
    {
        $e = $this->storeFails($this->body(['motivo' => '  ']));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('motivo', $e->getField());
        $this->assertSame('validation', $e->getCategory());
    }

    public function testRecursoIdDemasiadoLargoDa400(): void
    {
        $e = $this->storeFails($this->body(['recurso_id' => str_repeat('x', 256)]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('recurso_id', $e->getField());
    }

    // ====================================================================
    // TEMPORALIDAD (400 + field fecha_expiracion/duracion_horas)
    // ====================================================================

    public function testSinFechaNiDuracionDa400(): void
    {
        $e = $this->storeFails($this->body(['fecha_expiracion' => '', 'duracion_horas' => '']));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertStringContainsString('Indica fecha_expiracion', $e->getMessage());
    }

    public function testFechaYDuracionJuntasDa400(): void
    {
        $e = $this->storeFails($this->body([
            'fecha_expiracion' => gmdate('Y-m-d H:i:s', time() + 3600),
            'duracion_horas' => 24,
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertStringContainsString('sólo uno', $e->getMessage());
    }

    public function testDuracionMinutosYHorasJuntasDa400(): void
    {
        $e = $this->storeFails($this->body([
            'duracion_horas' => 24,
            'duracion_minutos' => 5,
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertStringContainsString('sólo uno', $e->getMessage());
        $this->assertSame(0, $this->countRows('temporary_permissions'));
    }

    public function testDuracionMinutosInvalidaDa400ConCampoDuracion_minutos(): void
    {
        $e = $this->storeFails($this->body(['duracion_horas' => '', 'duracion_minutos' => 0]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('duracion_minutos', $e->getField());
        $this->assertStringContainsString('entero positivo', $e->getMessage());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('duracionesInvalidas')]
    public function testDuracionInvalidaDa400ConCampoDuracion_horas(string $duracion): void
    {
        $e = $this->storeFails($this->body(['duracion_horas' => $duracion]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('duracion_horas', $e->getField());
        $this->assertStringContainsString('entero positivo', $e->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function duracionesInvalidas(): array
    {
        return [
            'cero' => ['0'],
            'negativo' => ['-3'],
            'texto' => ['manana'],
            'decimal' => ['2.5'],
        ];
    }

    public function testFechaPasadaDa400(): void
    {
        $e = $this->storeFails($this->body([
            'duracion_horas' => '',
            'fecha_expiracion' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertStringContainsString('futura', $e->getMessage());
    }

    public function testFormatoDeFechaInvalidoDa400(): void
    {
        $e = $this->storeFails($this->body([
            'duracion_horas' => '',
            'fecha_expiracion' => 'no-es-una-fecha',
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
    }

    public function testFueraDelMaximoDeDiasDa400(): void
    {
        // TEMP_PERMISSION_MAX_DAYS por defecto: 30 días
        $e = $this->storeFails($this->body([
            'duracion_horas' => '',
            'fecha_expiracion' => gmdate('Y-m-d H:i:s', time() + 40 * 86400),
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertStringContainsString('30 días', $e->getMessage());
    }

    // ====================================================================
    // ENTIDADES (404 / 400)
    // ====================================================================

    public function testUsuarioInexistenteEnElTenantDa404(): void
    {
        $e = $this->storeFails($this->body(['user_id' => 'u-noexiste']));

        $this->assertSame(404, $e->getCode());
        $this->assertSame('user_id', $e->getField());
        $this->assertSame('not_found', $e->getCategory());
        $this->assertSame('Usuario no encontrado en este tenant.', $e->getMessage());
    }

    public function testPermisoInexistenteDa404(): void
    {
        $e = $this->storeFails($this->body(['permission_id' => 'perm-inexistente']));

        $this->assertSame(404, $e->getCode());
        $this->assertSame('permission_id', $e->getField());
        $this->assertSame('not_found', $e->getCategory());
    }

    public function testPermisoInactivoDa400(): void
    {
        $e = $this->storeFails($this->body([
            'permission_id' => 'perm-' . str_replace('.', '-', self::P_RESET),
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('permission_id', $e->getField());
        $this->assertStringContainsString('inactivo', $e->getMessage());
    }

    // ====================================================================
    // DUPLICADOS (409) Y ORDEN DE VALIDACIONES
    // ====================================================================

    public function testDuplicadoVigenteDa409(): void
    {
        $this->seedTemporaryPermission(
            self::U_TARGET,
            'perm-' . str_replace('.', '-', self::P_VIEW),
            gmdate('Y-m-d H:i:s', time() + 2 * 86400)
        );

        $e = $this->storeFails($this->body());

        $this->assertSame(409, $e->getCode());
        $this->assertSame('permission_id', $e->getField());
        $this->assertSame('conflict', $e->getCategory());
        $this->assertStringContainsString('vigente', $e->getMessage());
        $this->assertSame(1, $this->countRows('temporary_permissions'));
    }

    public function testLaValidacionDeFechaVaAntesQueElDuplicado(): void
    {
        // Con duplicado vigente Y fecha pasada: debe mandar el 400 (forma),
        // no el 409 (estado), tal como exige el ajuste de la spec.
        $this->seedTemporaryPermission(
            self::U_TARGET,
            'perm-' . str_replace('.', '-', self::P_VIEW),
            gmdate('Y-m-d H:i:s', time() + 2 * 86400)
        );

        $e = $this->storeFails($this->body([
            'duracion_horas' => '',
            'fecha_expiracion' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]));

        $this->assertSame(400, $e->getCode());
        $this->assertSame('fecha_expiracion', $e->getField());
        $this->assertSame('validation', $e->getCategory());
    }

    public function testUnTemporalExpiradoNoBloqueaUnoNuevo(): void
    {
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $this->seedTemporaryPermission(
            self::U_TARGET,
            $permissionId,
            gmdate('Y-m-d H:i:s', time() - 3600),
            true
        );

        $result = $this->controller->store($this->request(['body' => $this->body()], $this->claims(self::U_ACTOR)));

        $this->assertSame(201, $result['status']);
        $this->assertSame(2, $this->countRows('temporary_permissions'));
    }

    // ====================================================================
    // ESCALADA Y CRITICAL (403)
    // ====================================================================

    public function testActorSinLosPermisosDelBeneficiarioDa403(): void
    {
        // u-weak sólo tiene users.user.view; u-target2 tiene view+create
        $e = $this->storeHttpFails(
            $this->body(['user_id' => self::U_TARGET2]),
            $this->claims(self::U_WEAK)
        );

        $this->assertSame(403, $e->getCode());
        $this->assertStringContainsString('permisos efectivos', $e->getMessage());
        $this->assertSame(0, $this->countRows('temporary_permissions'));
    }

    public function testNoAdminNoPuedeOtorgarUnPermisoCritical(): void
    {
        // u-guest y u-target no tienen roles: la escalada pasa, pero el
        // permiso es CRITICAL y u-guest no es ADMIN.
        $e = $this->storeFails(
            $this->body([
                'user_id' => self::U_TARGET,
                'permission_id' => 'perm-' . str_replace('.', '-', self::P_CRITICAL),
            ]),
            $this->claims(self::U_GUEST)
        );

        $this->assertSame(403, $e->getCode());
        $this->assertSame('forbidden', $e->getCategory());
        $this->assertStringContainsString('rol ADMIN', $e->getMessage());
        $this->assertSame(0, $this->countRows('temporary_permissions'));
    }

    public function testSinClaimsDa401(): void
    {
        try {
            $this->controller->store(['body' => $this->body()]);
            $this->fail('Se esperaba 401 sin claims');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('No autorizado', $e->getMessage());
        }
    }

    // ====================================================================
    // ÉXITO
    // ====================================================================

    public function testAltaCorrectaPersisteYAudita(): void
    {
        $result = $this->controller->store(
            $this->request(['body' => $this->body()], $this->claims(self::U_ACTOR))
        );

        $this->assertSame(201, $result['status']);

        $row = $result['data']['temporary_permission'];
        $this->assertIsArray($row);
        $this->assertSame(self::U_TARGET, $row['user_id']);
        $this->assertSame('perm-' . str_replace('.', '-', self::P_VIEW), $row['permission_id']);
        $this->assertSame(self::P_VIEW, $row['permission_identificador']);
        $this->assertSame('MEDIUM', $row['permission_criticidad']);
        $this->assertSame('Cambio de turno', $row['motivo']);
        $this->assertSame('1', (string) $row['activo']);
        $this->assertSame(self::U_ACTOR, $row['granted_by_user_id']);

        $persisted = $this->temporaryPermissionRow((string) $row['id']);
        $this->assertNotNull($persisted);
        $this->assertSame('1', (string) $persisted['activo']);

        $entry = $this->audit->byOperacion('temporary_permission.grant');
        $this->assertNotNull($entry, 'Falta la auditoría del otorgamiento');
        $this->assertSame('CREATE', $entry['tipo_cambio']);
        $this->assertSame('permisos_temporales', $entry['modulo']);
        $this->assertSame('temporary_permission', $entry['recurso']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame(self::TENANT, $entry['tenant_id']);
        $this->assertSame(self::U_ACTOR, $entry['usuario_id']);
        $this->assertSame($row['id'], $entry['entidad_id']);
        $this->assertSame('Cambio de turno', $entry['valores_nuevos']['motivo']);
        $this->assertSame(self::P_VIEW, $entry['valores_nuevos']['identificador']);
    }

    public function testAltaConDuracionHorasFijaLaExpiracion(): void
    {
        $result = $this->controller->store($this->request(
            ['body' => $this->body(['duracion_horas' => 2])],
            $this->claims(self::U_ACTOR)
        ));

        $this->assertSame(201, $result['status']);

        $expiracion = strtotime((string) $result['data']['temporary_permission']['fecha_expiracion']);
        $this->assertGreaterThan(time() + 115 * 60, $expiracion, 'Debe expirar en ~2 horas');
        $this->assertLessThan(time() + 125 * 60, $expiracion, 'Debe expirar en ~2 horas');
    }

    public function testAltaConDuracionMinutosFijaLaExpiracion(): void
    {
        $result = $this->controller->store($this->request(
            ['body' => $this->body(['duracion_horas' => '', 'duracion_minutos' => 5])],
            $this->claims(self::U_ACTOR)
        ));

        $this->assertSame(201, $result['status']);

        $expiracion = strtotime((string) $result['data']['temporary_permission']['fecha_expiracion']);
        $this->assertGreaterThan(time() + 4 * 60, $expiracion, 'Debe expirar en ~5 minutos');
        $this->assertLessThan(time() + 6 * 60, $expiracion, 'Debe expirar en ~5 minutos');
    }

    public function testAltaConRecursoId(): void
    {
        $result = $this->controller->store($this->request(
            ['body' => $this->body(['recurso_id' => 'almacen-norte'])],
            $this->claims(self::U_ACTOR)
        ));

        $this->assertSame(201, $result['status']);
        $this->assertSame(
            'almacen-norte',
            $result['data']['temporary_permission']['recurso_id']
        );
    }

    // ====================================================================
    // LISTADO
    // ====================================================================

    public function testIndexDevuelveSoloVigentesPorDefecto(): void
    {
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_STATUS), gmdate('Y-m-d H:i:s', time() - 3600));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_CREATE), gmdate('Y-m-d H:i:s', time() + 86400), false);

        $result = $this->controller->index($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['total'], 'Sólo el temporal activo y no expirado');
        $this->assertSame(self::P_VIEW, $result['data']['temporary_permissions'][0]['permission_identificador']);
    }

    public function testIndexConIncludeInactiveDevuelveTodo(): void
    {
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_STATUS), gmdate('Y-m-d H:i:s', time() - 3600));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_CREATE), gmdate('Y-m-d H:i:s', time() + 86400), false);

        $result = $this->controller->index($this->request([
            'query' => ['include_inactive' => 'true'],
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(3, $result['data']['total']);
    }

    public function testIndexFiltraPorUsuarioBeneficiario(): void
    {
        $this->seedUser('u-other');
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));
        $this->seedTemporaryPermission('u-other', $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));

        $result = $this->controller->index($this->request([
            'query' => ['user_id' => self::U_TARGET],
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(self::U_TARGET, $result['data']['temporary_permissions'][0]['user_id']);
    }

    public function testIndexPaginaElListadoConBloquePagination(): void
    {
        // v1.13.3: paginación real. Tres vigentes con expiraciones distintas
        // (la ordenación es fecha_expiracion DESC, así que el orden es estable).
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_VIEW), gmdate('Y-m-d H:i:s', time() + 3600));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_STATUS), gmdate('Y-m-d H:i:s', time() + 7200));
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_CREATE), gmdate('Y-m-d H:i:s', time() + 10800));

        $p1 = $this->controller->index($this->request(['query' => ['page' => 1, 'per_page' => 2]]));

        $this->assertSame(200, $p1['status']);
        $this->assertCount(2, $p1['data']['temporary_permissions'], 'La página sólo debe traer per_page filas');
        $this->assertSame(3, $p1['data']['total'], 'total es el total del filtro, no el de la página');
        $this->assertSame(
            ['total' => 3, 'page' => 1, 'per_page' => 2, 'has_next' => true],
            $p1['data']['pagination']
        );

        $p2 = $this->controller->index($this->request(['query' => ['page' => 2, 'per_page' => 2]]));

        $this->assertCount(1, $p2['data']['temporary_permissions']);
        $this->assertSame(3, $p2['data']['total']);
        $this->assertSame(
            ['total' => 3, 'page' => 2, 'per_page' => 2, 'has_next' => false],
            $p2['data']['pagination']
        );
        $this->assertNotSame(
            $p1['data']['temporary_permissions'][0]['id'],
            $p2['data']['temporary_permissions'][0]['id'],
            'Las páginas no deben solapar filas'
        );
    }

    public function testIndexMasAllaDeLaUltimaPaginaDevuelveVaciaConElTotal(): void
    {
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_VIEW), gmdate('Y-m-d H:i:s', time() + 3600));

        $result = $this->controller->index($this->request(['query' => ['page' => 5, 'per_page' => 2]]));

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['temporary_permissions']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(
            ['total' => 1, 'page' => 5, 'per_page' => 2, 'has_next' => false],
            $result['data']['pagination']
        );
    }

    public function testIndexSinPaginacionExplicitaUsaLosDefaults(): void
    {
        $this->seedTemporaryPermission(self::U_TARGET, 'perm-' . str_replace('.', '-', self::P_VIEW), gmdate('Y-m-d H:i:s', time() + 3600));

        $result = $this->controller->index($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['total' => 1, 'page' => 1, 'per_page' => 20, 'has_next' => false],
            $result['data']['pagination']
        );
    }

    public function testElTotalPaginadoRespetaElFiltroPorUsuario(): void
    {
        $this->seedUser('u-other');
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 3600));
        $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 7200));
        $this->seedTemporaryPermission('u-other', $permissionId, gmdate('Y-m-d H:i:s', time() + 10800));

        $result = $this->controller->index($this->request([
            'query' => ['user_id' => self::U_TARGET, 'per_page' => 1],
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['data']['temporary_permissions']);
        $this->assertSame(2, $result['data']['pagination']['total'], 'Sólo los del beneficiario');
        $this->assertTrue($result['data']['pagination']['has_next']);
    }

    // ====================================================================
    // REVOCACIÓN
    // ====================================================================

    public function testRevocarDesactivaYAudita(): void
    {
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $id = $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));

        $result = $this->controller->revoke($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertSame($id, $result['data']['temporary_permission_id']);

        $row = $this->temporaryPermissionRow($id);
        $this->assertNotNull($row);
        $this->assertSame('0', (string) $row['activo']);

        $entry = $this->audit->byOperacion('temporary_permission.revoke');
        $this->assertNotNull($entry, 'Falta la auditoría de la revocación');
        $this->assertSame('UPDATE', $entry['tipo_cambio']);
        $this->assertSame('permisos_temporales', $entry['modulo']);
        $this->assertSame($id, $entry['entidad_id']);
        $this->assertSame(self::U_TARGET, $entry['valores_anteriores']['user_id']);
    }

    public function testRevocarDosVecesDa409(): void
    {
        $permissionId = 'perm-' . str_replace('.', '-', self::P_VIEW);
        $id = $this->seedTemporaryPermission(self::U_TARGET, $permissionId, gmdate('Y-m-d H:i:s', time() + 86400));

        $this->controller->revoke($this->request(), ['id' => $id]);

        try {
            $this->controller->revoke($this->request(), ['id' => $id]);
            $this->fail('Se esperaba 409 en la segunda revocación');
        } catch (TemporaryPermissionException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('id', $e->getField());
            $this->assertSame('conflict', $e->getCategory());
            $this->assertStringContainsString('ya está revocado', $e->getMessage());
        }
    }

    public function testRevocarInexistenteDa404(): void
    {
        try {
            $this->controller->revoke($this->request(), ['id' => 'tp-noexiste']);
            $this->fail('Se esperaba 404');
        } catch (TemporaryPermissionException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('not_found', $e->getCategory());
            $this->assertSame('Permiso temporal no encontrado', $e->getMessage());
        }
    }

    public function testRevocarSinIdDa400(): void
    {
        try {
            $this->controller->revoke($this->request(), []);
            $this->fail('Se esperaba 400');
        } catch (TemporaryPermissionException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('id', $e->getField());
        }
    }
}
