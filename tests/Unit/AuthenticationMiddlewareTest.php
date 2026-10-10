<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Exception\PasswordChangeRequiredException;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\JwtService;
use App\Shared\Exceptions\InvalidTokenException;
use App\Shared\Exceptions\TokenExpiredException;
use Firebase\JWT\ExpiredException;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * AuthenticationMiddleware (deuda v1.13.3: sólo se testaba que las rutas
 * lo declararan, nunca su comportamiento).
 *
 * Cubre el contrato que depende de datos vivos de BD (getAuthState) y de la
 * allowlist de cambio forzado de contraseña (v1.11.0):
 *  - sin token / claims incompletos / tipo refresh -> 401.
 *  - usuario INACTIVO o soft-deleteado -> 401.
 *  - fecha_expiracion_password marcada -> 403 password_change_required,
 *    salvo rutas de la allowlist (exactas y prefijo /api/locales).
 *  - token expirado (ExpiredException de Firebase) -> TokenExpiredException.
 *  - happy path: inyecta los claims en $request['user'].
 *
 * Los tokens se stubban en JwtService (el decode real está en JwtServiceTest);
 * el repositorio de usuarios es el REAL sobre SQLite porque getAuthState es
 * la comprobación que queremos cubrir.
 */
final class AuthenticationMiddlewareTest extends PlatformControlSqliteTestCase
{
    private const USER = 'u-mw-pcs';
    private const PATH = '/api/users';

    private mixed $prevAuth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prevAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->seedUser(self::USER);
    }

    protected function tearDown(): void
    {
        if ($this->prevAuth === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $this->prevAuth;
        }

        parent::tearDown();
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
            'tenant_id' => self::TENANT,
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
            'tenant_id' => self::TENANT,
        ]);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Claims incompletos en el token');

        $middleware->handle($this->authRequest());
    }

    // ====================================================================
    // ESTADO DE LA CUENTA (getAuthState real sobre SQLite)
    // ====================================================================

    public function testHappyPathInyectaLosClaimsEnElRequest(): void
    {
        $claims = $this->accessClaims();
        $middleware = $this->middleware($claims);

        $result = $middleware->handle($this->authRequest());

        $this->assertSame($claims, $result['user'], 'Debe inyectar los claims completos');
        $this->assertSame(self::USER, $result['user']['user_id']);
        $this->assertSame(self::TENANT, $result['user']['tenant_id']);
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
        $this->seedUser('u-mw-otro-tenant-pcs', 'ACTIVO', 'tenant-otro');
        $middleware = $this->middleware($this->accessClaims(['user_id' => 'u-mw-otro-tenant-pcs']));

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('Usuario inactivo o eliminado');

        $middleware->handle($this->authRequest());
    }

    // ====================================================================
    // CAMBIO FORZADO DE CONTRASEÑA (v1.11.0)
    // ====================================================================

    public function testPasswordExpiradaEnRutaNormalDevuelve403PasswordChangeRequired(): void
    {
        $this->pdo->prepare('UPDATE users SET fecha_expiracion_password = :exp WHERE id = :id')
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

    #[\PHPUnit\Framework\Attributes\DataProvider('allowlistProvider')]
    public function testPasswordExpiradaPermiteLasRutasDeLaAllowlist(string $path): void
    {
        $this->pdo->prepare('UPDATE users SET fecha_expiracion_password = :exp WHERE id = :id')
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

    public function testPasswordExpiradaNoAplicaSiLaMarcaEstaVacia(): void
    {
        $this->pdo->prepare("UPDATE users SET fecha_expiracion_password = '' WHERE id = :id")
            ->execute(['id' => self::USER]);

        $middleware = $this->middleware($this->accessClaims());

        $result = $middleware->handle($this->authRequest());

        $this->assertSame(self::USER, $result['user']['user_id']);
    }
}
