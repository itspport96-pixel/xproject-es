<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\AuthController;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Security\Service\InputSanitizer;
use App\Core\Security\Service\RateLimiter;
use App\Core\Users\Exception\UserPreferencesException;
use App\Core\Users\Http\UserController;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingAuditLogger;

/**
 * Capa HTTP del endpoint de preferencias (v1.10.3):
 *  - GET/PATCH /api/users/me/preferences responden con `preferencias`
 *  - PATCH audita el cambio (user.preferences.update, valores antes/despues)
 *  - PATCH invalido no genera fila de dominio (la audita el hook del Router)
 *  - GET /api/auth/me expone users.preferencias en `user` (sincronia login/me)
 */
final class UserPreferencesEndpointTest extends TestCase
{
    private const TENANT = 'tenant-epref-1';
    private const USER = 'user-epref-1';

    private PDO $pdo;
    private DatabaseService $db;
    private UserController $controller;
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        Container::setInstance(new Container());

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                email TEXT,
                email_hmac TEXT,
                nombre TEXT,
                apellido TEXT,
                password TEXT,
                estado TEXT DEFAULT \'ACTIVO\' NOT NULL,
                telefono TEXT,
                preferencias TEXT,
                ultimo_login TEXT,
                mfa_habilitado INTEGER DEFAULT 0,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )'
        );

        // Función NOW() de MySQL para las consultas de AuthorizationService.
        $this->pdo->sqliteCreateFunction(
            'now',
            static fn(): string => gmdate('Y-m-d H:i:s'),
            0
        );

        // Tablas que consulta /auth/me (v1.12.1: roles con origen, permisos
        // efectivos y permisos temporales se calculan en cada peticion).
        // Vacías: el usuario de este test no tiene roles ni temporales.
        $this->pdo->exec(
            "CREATE TABLE roles (
                id TEXT PRIMARY KEY, nombre TEXT, tipo TEXT, tenant_id TEXT,
                activo INTEGER DEFAULT 1, deleted_at TEXT
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE role_assignments (
                id TEXT PRIMARY KEY, user_id TEXT, role_id TEXT, tenant_id TEXT,
                fecha_expiracion TEXT
            )"
        );
        $this->pdo->exec(
            'CREATE TABLE group_members (id TEXT PRIMARY KEY, group_id TEXT, user_id TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE group_roles (id TEXT PRIMARY KEY, group_id TEXT, role_id TEXT)'
        );
        $this->pdo->exec(
            "CREATE TABLE user_groups (
                id TEXT PRIMARY KEY, tenant_id TEXT, nombre TEXT,
                activo INTEGER DEFAULT 1, deleted_at TEXT
            )"
        );
        $this->pdo->exec(
            'CREATE TABLE permissions (id TEXT PRIMARY KEY, identificador TEXT, activo INTEGER DEFAULT 1)'
        );
        $this->pdo->exec(
            'CREATE TABLE role_permissions (
                role_id TEXT NOT NULL,
                permission_id TEXT NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE temporary_permissions (
                id TEXT PRIMARY KEY, user_id TEXT, permission_id TEXT,
                motivo TEXT, fecha_inicio TEXT, fecha_expiracion TEXT,
                activo INTEGER DEFAULT 1
            )"
        );

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $service = new UserService(
            new UserRepository($this->db),
            $this->createStub(PasswordHasher::class),
            $this->db
        );
        $this->audit = new RecordingAuditLogger();

        $authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $this->createStub(RedisService::class)
        );

        $this->controller = new UserController($service, $this->jwtStub(), $authz, $this->audit);

        $this->seedUser(self::USER, self::TENANT);

        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        Container::resetInstance();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function jwtStub(): JwtService
    {
        $jwt = $this->createStub(JwtService::class);
        $jwt->method('decodeToken')->willReturn((object) [
            'type' => 'access',
            'user_id' => self::USER,
            'tenant_id' => self::TENANT,
            'roles' => [],
        ]);

        return $jwt;
    }

    private function authController(): AuthController
    {
        $authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $this->createStub(RedisService::class)
        );

        return new AuthController(
            $this->jwtStub(),
            $this->createStub(PasswordHasher::class),
            new UserRepository($this->db),
            $this->db,
            new InputSanitizer(),
            new RateLimiter($this->createStub(RedisService::class)),
            $this->audit,
            null,
            null,
            $authz
        );
    }

    private function seedUser(string $id, string $tenantId, ?string $preferencias = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, preferencias, created_at, updated_at)
             VALUES (:id, :tenant, :email, :nombre, :apellido, :password, \'ACTIVO\', :preferencias, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant' => $tenantId,
            'email' => "{$id}@example.test",
            'nombre' => 'Usuario',
            'apellido' => 'Endpoint',
            'password' => password_hash('Abcdef1!', PASSWORD_BCRYPT),
            'preferencias' => $preferencias,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    private function setPreferencias(string $json): void
    {
        $this->pdo->prepare('UPDATE users SET preferencias = :p WHERE id = :id')
            ->execute(['p' => $json, 'id' => self::USER]);
    }

    private function withBearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-de-prueba';
    }

    /**
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'user' => ['user_id' => self::USER, 'tenant_id' => self::TENANT],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'request_id' => 'req-pref-1',
        ], $overrides);
    }

    // ====================================================================
    // GET /api/users/me/preferences
    // ====================================================================

    public function testGetDevuelvePreferenciasDelPropioUsuario(): void
    {
        $this->withBearer();
        $this->setPreferencias('{"idioma":"fr","tema":"system"}');

        $result = $this->controller->mePreferences();

        $this->assertSame(200, $result['status']);
        $this->assertSame(['idioma' => 'fr', 'tema' => 'system'], $result['data']['preferencias']);
    }

    public function testGetSinPreferenciasDevuelveObjetoVacio(): void
    {
        $this->withBearer();

        $result = $this->controller->mePreferences();

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['preferencias']);
    }

    public function testGetSinTokenDa401(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->mePreferences();
    }

    // ====================================================================
    // PATCH /api/users/me/preferences
    // ====================================================================

    public function testPatchPersisteYAuditaElCambio(): void
    {
        $this->withBearer();
        $_POST = ['idioma' => 'en', 'tema' => 'dark'];

        $result = $this->controller->updateMePreferences($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(['idioma' => 'en', 'tema' => 'dark'], $result['data']['preferencias']);

        $guardado = json_decode(
            (string) $this->pdo->query('SELECT preferencias FROM users WHERE id = ' . $this->pdo->quote(self::USER))
                ->fetchColumn(),
            true
        );
        $this->assertSame(['idioma' => 'en', 'tema' => 'dark'], $guardado);

        $entry = $this->audit->byOperacion('user.preferences.update');
        $this->assertNotNull($entry, 'Falta la auditoria de dominio del cambio');
        $this->assertSame('UPDATE', $entry['tipo_cambio']);
        $this->assertSame('users', $entry['modulo']);
        $this->assertSame('user.preferences', $entry['recurso']);
        $this->assertSame(self::TENANT, $entry['tenant_id']);
        $this->assertSame(self::USER, $entry['usuario_id']);
        $this->assertSame(self::USER, $entry['entidad_id']);
        $this->assertSame('SUCCESS', $entry['resultado']);
        $this->assertSame('127.0.0.1', $entry['ip_address']);
        $this->assertSame('req-pref-1', $entry['request_id']);
        $this->assertSame([], $entry['valores_anteriores']['preferencias']);
        $this->assertSame(['idioma' => 'en', 'tema' => 'dark'], $entry['valores_nuevos']['preferencias']);
    }

    public function testPatchMergeConservaLoPrevioYAuditaAntesYDespues(): void
    {
        $this->withBearer();
        $this->setPreferencias('{"idioma":"pt","otra_clave":7}');
        $_POST = ['tema' => 'light'];

        $result = $this->controller->updateMePreferences($this->request());

        $this->assertSame(['idioma' => 'pt', 'otra_clave' => 7, 'tema' => 'light'], $result['data']['preferencias']);

        $entry = $this->audit->byOperacion('user.preferences.update');
        $this->assertNotNull($entry);
        $this->assertSame('pt', $entry['valores_anteriores']['preferencias']['idioma']);
        $this->assertSame('light', $entry['valores_nuevos']['preferencias']['tema']);
    }

    public function testPatchInvalidoNoAuditaNiPersiste(): void
    {
        $this->withBearer();
        $_POST = ['idioma' => 'de'];

        try {
            $this->controller->updateMePreferences($this->request());
            $this->fail('Debio lanzar 400');
        } catch (UserPreferencesException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idioma', $e->getField());
        }

        $this->assertSame([], $this->audit->entries, 'Los fallos los audita el hook del Router');
        $this->assertNull(
            $this->pdo->query('SELECT preferencias FROM users WHERE id = ' . $this->pdo->quote(self::USER))
                ->fetchColumn()
        );
    }

    public function testPatchClavesDesconocidasSeIgnoran(): void
    {
        $this->withBearer();
        $_POST = ['color' => 'azul'];

        $result = $this->controller->updateMePreferences($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['preferencias']);
        $this->assertNull(
            $this->pdo->query('SELECT preferencias FROM users WHERE id = ' . $this->pdo->quote(self::USER))
                ->fetchColumn()
        );
    }

    public function testPatchSinTokenDa401(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->updateMePreferences($this->request());
    }

    // ====================================================================
    // GET /api/auth/me expone preferencias (sincronia login/me)
    // ====================================================================

    public function testAuthMeDevuelvePreferenciasEnElUsuario(): void
    {
        $this->withBearer();
        $this->setPreferencias('{"idioma":"pt"}');

        $result = $this->authController()->me();

        $this->assertSame(200, $result['status']);
        $this->assertSame(['idioma' => 'pt'], $result['data']['user']['preferencias']);
    }

    public function testAuthMeSinPreferenciasDevuelveVacio(): void
    {
        $this->withBearer();

        $result = $this->authController()->me();

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['user']['preferencias']);
    }
}
