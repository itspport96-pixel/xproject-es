<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Exception\PasswordChangeRequiredException;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\JwtService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\InvalidTokenException;
use App\Shared\Exceptions\TokenExpiredException;
use App\Shared\Services\DatabaseService;
use Dotenv\Dotenv;
use Firebase\JWT\ExpiredException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AuthenticationMiddleware contra MySQL REAL (xproject_e2e, regla 16).
 *
 * Sin SQLite: getAuthState se valida de verdad contra el motor real. Se salta
 * (skip) si MySQL no está disponible.
 *
 * Cubre el contrato que depende de datos vivos de BD (getAuthState) y de la
 * allowlist de cambio forzado de contraseña:
 *  - sin token / claims incompletos / tipo refresh -> 401.
 *  - usuario INACTIVO o soft-deleteado -> 401.
 *  - password_change_forced (temporal) marcado -> 403 password_change_required,
 *    salvo rutas de la allowlist (exactas y prefijo /api/locales).
 *  - fecha_expiracion_password VENCIDA (caducidad) -> 403 password_change_required.
 *  - fecha_expiracion_password FUTURA (caducidad no vencida) -> NO 403
 *    (regresión del bucle infinito v1.17.0).
 *  - token expirado (ExpiredException de Firebase) -> TokenExpiredException.
 *  - happy path: inyecta los claims en $request['user'].
 *
 * Los tokens se stubban en JwtService (el decode real está en JwtServiceTest);
 * el repositorio de usuarios es el REAL sobre MySQL porque getAuthState es
 * la comprobación que queremos cubrir.
 */
final class AuthenticationMiddlewareTest extends TestCase
{
    private const USER = 'u-mw-pcs';
    private const PATH = '/api/users';

    private PDO $pdo;
    private DatabaseService $db;
    private string $tenantId;
    /** @var int<string> ids de usuarios sembrados (para limpieza) */
    private array $seeded = [];
    private mixed $prevAuth;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->pdo = $this->connectE2e();
        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->prevAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->tenantId = $this->anyTenantId();
        $this->seeded = [];
        $this->seedUser(self::USER, 'ACTIVO', $this->tenantId);
    }

    protected function tearDown(): void
    {
        if ($this->prevAuth === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $this->prevAuth;
        }

        foreach ($this->seeded as $id) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }

        Container::resetInstance();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param array<string, mixed> $claims Claims que devolverá decodeToken
     * @param \Throwable|null      $throw  Excepción que lanzará decodeToken
     */
    private function middleware(array $claims, ?\Throwable $throw = null): AuthenticationMiddleware
    {
        $jwt = $this->createStub(JwtService::class);
        if ($throw !== null) {
            $jwt->method('decodeToken')->willThrowException($throw);
        } else {
            $jwt->method('decodeToken')->willReturn((object) $claims);
        }

        return new AuthenticationMiddleware($jwt, new UserRepository($this->db));
    }

    /** @return array<string, mixed> */
    private function accessClaims(array $overrides = []): array
    {
        return array_merge([
            'type' => 'access',
            'user_id' => self::USER,
            'tenant_id' => $this->tenantId,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed> Request con header Bearer y path
     */
    private function authRequest(array $claims = [], string $path = self::PATH): array
    {
        return [
            'headers' => ['authorization' => 'Bearer token-de-test'],
            'path' => $path,
            'query' => [],
            'body' => [],
            'claims' => $claims,
        ];
    }

    private function seedUser(string $id, string $estado = 'ACTIVO', ?string $tenantId = null): void
    {
        $this->pdo->prepare(
            'DELETE FROM users WHERE id = ?'
        )->execute([$id]);

        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $id,
            $tenantId ?? $this->tenantId,
            $id . '@mw-test.local',
            'MW',
            'Test',
            'hash-de-test-no-valido',
            $estado,
        ]);

        $this->seeded[] = $id;
    }

    private function connectE2e(): PDO
    {
        $base = dirname(__DIR__, 2);
        (Dotenv::createImmutable($base))->safeLoad();

        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? '');
        $dbname = 'xproject_e2e';

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL xproject_e2e no disponible: ' . $e->getMessage());
        }

        return $pdo;
    }

    private function anyTenantId(): string
    {
        $id = $this->pdo->query('SELECT id FROM tenants LIMIT 1')->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            $this->markTestSkipped('No hay tenant en xproject_e2e');
        }

        return (string) $id;
    }

    // ====================================================================
    // TOKEN
    // ====================================================================

    public function testSinTokenDevuelve401(): void
    {
        $middleware = $this->middleware($this->accessClaims());
        $request = $this->authRequest();
        unset($request['headers']['authorization']);

        try {
            $middleware->handle($request);
            $this->fail('Se esperaba InvalidTokenException');
        } catch (InvalidTokenException $e) {
            $this->assertSame('Token no proporcionado', $e->getMessage());
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testHeaderSinFormatoBearerDevuelve401(): void
    {
        $middleware = $this->middleware($this->accessClaims());
        $request = $this->authRequest();
        $request['headers']['authorization'] = 'Basic dXNlcjpwYXNz';

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Token no proporcionado');

        $middleware->handle($request);
    }

    public function testTokenExpiradoDevuelveTokenExpiredException401(): void
    {
        $middleware = $this->middleware(
            $this->accessClaims(),
            new ExpiredException('The token has expired')
        );

        try {
            $middleware->handle($this->authRequest());
            $this->fail('Se esperaba TokenExpiredException');
        } catch (TokenExpiredException $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testTokenRefreshDevuelve401(): void
    {
        $middleware = $this->middleware($this->accessClaims(['type' => 'refresh']));

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Tipo de token inválido');

        $middleware->handle($this->authRequest());
    }

    public function testClaimsSinUserIdDevuelven401(): void
    {
        $middleware = $this->middleware([
            'type' => 'access',
            'tenant_id' => $this->tenantId,
        ]);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Claims incompletos en el token');

        $middleware->handle($this->authRequest());
    }

    // ====================================================================
    // ESTADO DE LA CUENTA (getAuthState real sobre MySQL)
    // ====================================================================

    public function testHappyPathInyectaLosClaimsEnElRequest(): void
    {
        $claims = $this->accessClaims();
        $middleware = $this->middleware($claims);

        $result = $middleware->handle($this->authRequest());

        $this->assertSame($claims, $result['user'], 'Debe inyectar los claims completos');
        $this->assertSame(self::USER, $result['user']['user_id']);
        $this->assertSame($this->tenantId, $result['user']['tenant_id']);
    }

    public function testUsuarioInactivoDevuelve401(): void
    {
        $this->seedUser('u-mw-inactivo-pcs', 'INACTIVO');
        $middleware = $this->middleware($this->accessClaims(['user_id' => 'u-mw-inactivo-pcs']));

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Usuario inactivo o eliminado');

        $middleware->handle($this->authRequest());
    }

    public function testUsuarioSoftDeleteadoDevuelve401(): void
    {
        $this->pdo->prepare('UPDATE users SET deleted_at = :now WHERE id = :id')
            ->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Usuario inactivo o eliminado');

        $middleware->handle($this->authRequest());
    }

    public function testUsuarioDeOtroTenantDevuelve401(): void
    {
        $otroTenant = 'tenant-otro-mw-' . bin2hex(random_bytes(4));
        $this->seedUser('u-mw-otro-tenant-pcs', 'ACTIVO', $otroTenant);
        $middleware = $this->middleware($this->accessClaims(['user_id' => 'u-mw-otro-tenant-pcs']));

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Usuario inactivo o eliminado');

        $middleware->handle($this->authRequest());
    }

    // ====================================================================
    // CAMBIO FORZADO DE CONTRASEÑA
    // ====================================================================

    public function testPasswordChangeForcedEnRutaNormalDevuelve403(): void
    {
        $this->pdo->prepare('UPDATE users SET password_change_forced = :exp WHERE id = :id')
            ->execute(['exp' => gmdate('Y-m-d H:i:s', time() + 3600), 'id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        try {
            $middleware->handle($this->authRequest());
            $this->fail('Se esperaba PasswordChangeRequiredException');
        } catch (PasswordChangeRequiredException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('password_change_required', $e->getCategory());
            $this->assertSame('password', $e->getField());
        }
    }

    public function testCaducidadVencidaDevuelve403PasswordChangeRequired(): void
    {
        $this->pdo->prepare('UPDATE users SET fecha_expiracion_password = :exp WHERE id = :id')
            ->execute(['exp' => gmdate('Y-m-d H:i:s', time() - 60), 'id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        try {
            $middleware->handle($this->authRequest());
            $this->fail('Se esperaba PasswordChangeRequiredException');
        } catch (PasswordChangeRequiredException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('password_change_required', $e->getCategory());
        }
    }

    public function testCaducidadFuturaNoFuerzaCambio(): void
    {
        $this->pdo->prepare('UPDATE users SET fecha_expiracion_password = :exp WHERE id = :id')
            ->execute(['exp' => gmdate('Y-m-d H:i:s', time() + 3600), 'id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        $result = $middleware->handle($this->authRequest());

        $this->assertSame(self::USER, $result['user']['user_id'], 'Caducidad futura NO debe forzar cambio (regresión del bucle v1.17.0)');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowlistProvider')]
    public function testPasswordChangeForcedPermiteLasRutasDeLaAllowlist(string $path): void
    {
        $this->pdo->prepare('UPDATE users SET password_change_forced = :exp WHERE id = :id')
            ->execute(['exp' => gmdate('Y-m-d H:i:s', time() + 3600), 'id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        $result = $middleware->handle($this->authRequest([], $path));

        $this->assertSame(self::USER, $result['user']['user_id'], "Debe dejar pasar {$path}");
    }

    /** @return array<string, array{0: string}> */
    public static function allowlistProvider(): array
    {
        return [
            'ruta exacta change-password' => ['/api/auth/change-password'],
            'ruta exacta preferences' => ['/api/users/me/preferences'],
            'ruta exacta auth/me' => ['/api/auth/me'],
            'prefijo /api/locales' => ['/api/locales/es.json'],
        ];
    }

    public function testSinMarcadoresNoAplicaCambioForzado(): void
    {
        $middleware = $this->middleware($this->accessClaims());

        $result = $middleware->handle($this->authRequest());

        $this->assertSame(self::USER, $result['user']['user_id']);
    }
}
