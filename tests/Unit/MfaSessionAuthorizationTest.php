<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Authorization\Repository\PermissionRepository;
use App\Core\Authorization\Repository\RoleRepository;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\RedisService;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Regla CRITICAL/req_mfa => MFA de SESION (v1.19.7).
 *
 * Antes la exigencia dependia del estado de la cuenta (mfa_habilitado) y el
 * claim del token ni existia: cualquier permiso CRITICAL se ejercia sin MFA.
 * Ahora:
 *  - el login emite el claim 'mfa' (AuthController),
 *  - PermissionMiddleware lo pasa a AuthorizationService.hasPermission(),
 *  - alli la regla decide (requiere_mfa estricto; CRITICAL exige sesion con
 *    MFA; sin MFA en la cuenta o sin contexto de sesion: tolerancia).
 *
 * AuthorizationService y repositorios REALES sobre SQLite; el resto de la
 * suite usa el authz con repositorio de permisos sustituido (que devolveria
 * null y desactivaria la regla), por eso se construye uno propio aqui.
 */
final class MfaSessionAuthorizationTest extends PlatformControlSqliteTestCase
{
    private const P_CRITICAL = 'groups.group.delete';
    private const P_NORMAL = 'users.user.view';
    private const P_REQUIERE_MFA = 'users.user.status';

    private const U_NO_MFA = 'u-sin-mfa';
    private const U_CON_MFA = 'u-con-mfa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermission(self::P_CRITICAL, 'CRITICAL');
        $this->seedPermission(self::P_NORMAL, 'MEDIUM');
        $this->seedPermission(self::P_REQUIERE_MFA, 'HIGH');
        // requiere_mfa = 1: el flag estricto del catalogo.
        $this->pdo->prepare('UPDATE permissions SET requiere_mfa = 1 WHERE identificador = ?')
            ->execute([self::P_REQUIERE_MFA]);

        $this->seedRole('role-crit', 'CRIT', [self::P_CRITICAL, self::P_NORMAL, self::P_REQUIERE_MFA]);

        $this->seedUser(self::U_NO_MFA);
        $this->seedUser(self::U_CON_MFA);
        $this->pdo->prepare('UPDATE users SET mfa_habilitado = 1, mfa_metodo = ? WHERE id = ?')
            ->execute(['TOTP', self::U_CON_MFA]);

        $this->assignRole(self::U_NO_MFA, 'role-crit');
        $this->assignRole(self::U_CON_MFA, 'role-crit');

        $this->authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            new RoleRepository($this->db),
            new PermissionRepository($this->db),
            $this->db,
            $this->createStub(RedisService::class)
        );
    }

    // ====================================================================
    // REGLA EN AuthorizationService
    // ====================================================================

    public function testPermisoCriticoConCuentaSinMfaSeToleraEnFase1(): void
    {
        $this->assertTrue(
            $this->authz->hasPermission(self::U_NO_MFA, self::TENANT, self::P_CRITICAL, true, false),
            'Sin ninguna cuenta con MFA no se puede bloquear la accion (fase 1)'
        );
    }

    public function testPermisoCriticoExigeMfaDeSesionSiLaCuentaTieneMfa(): void
    {
        $this->assertTrue(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_CRITICAL, true, true),
            'La sesion que verifico MFA en el login ejerce el permiso'
        );
        $this->assertFalse(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_CRITICAL, true, false),
            'La sesion que no verifico MFA no ejerce un permiso CRITICAL'
        );
    }

    public function testPermisoCriticoSinContextoDeSesionSeTolera(): void
    {
        $this->assertTrue(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_CRITICAL, true, null),
            'Token antiguo o llamada interna (sin claim): transicion sin bloqueos'
        );
    }

    public function testPermisoOrdinarioNoExigeMfaDeSesion(): void
    {
        $this->assertTrue(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_NORMAL, true, false)
        );
        $this->assertTrue(
            $this->authz->hasPermission(self::U_NO_MFA, self::TENANT, self::P_NORMAL, true, false)
        );
    }

    public function testFlagRequiereMfaSigueSiendoEstricto(): void
    {
        // Cuenta sin MFA y permiso con requiere_mfa = 1: denegado (semantica
        // anterior, se conserva) tenga o no la sesion el claim a true.
        $this->assertFalse(
            $this->authz->hasPermission(self::U_NO_MFA, self::TENANT, self::P_REQUIERE_MFA, true, null)
        );
        $this->assertFalse(
            $this->authz->hasPermission(self::U_NO_MFA, self::TENANT, self::P_REQUIERE_MFA, true, true)
        );
        $this->assertTrue(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_REQUIERE_MFA, true, true)
        );
        $this->assertFalse(
            $this->authz->hasPermission(self::U_CON_MFA, self::TENANT, self::P_REQUIERE_MFA, true, false)
        );
    }

    // ====================================================================
    // PermissionMiddleware: reparto del claim
    // ====================================================================

    public function testMiddlewareConClaimMfaFalsoDeniegaElCritico(): void
    {
        $middleware = new PermissionMiddleware(self::P_CRITICAL, $this->authz);

        try {
            $middleware->handle($this->requestWithClaim(false));
            $this->fail('Se esperaba 403 por sesion sin MFA');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('auth.mfa_session_required', $e->getMessageKey());
        }
    }

    public function testMiddlewareConClaimMfaVerdaderoPermiteElCritico(): void
    {
        $middleware = new PermissionMiddleware(self::P_CRITICAL, $this->authz);
        $request = $this->requestWithClaim(true);

        $this->assertSame($request, $middleware->handle($request));
    }

    public function testMiddlewareSinClaimDeMfaPermitePorTransicion(): void
    {
        $middleware = new PermissionMiddleware(self::P_CRITICAL, $this->authz);
        $request = $this->requestWithClaim(null);

        $this->assertSame($request, $middleware->handle($request));
    }

    public function testMiddlewareDeniegaNormalmenteCuandoNoTieneElPermiso(): void
    {
        $middleware = new PermissionMiddleware('roles.role.delete', $this->authz);

        try {
            $middleware->handle($this->requestWithClaim(true));
            $this->fail('Se esperaba 403 por permiso inexistente');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertNull($e->getMessageKey(), 'La denegacion por permiso conserva su 403 de siempre');
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param bool|null $mfa claim 'mfa' de la sesion; null simula un token antiguo
     * @return array<string, mixed>
     */
    private function requestWithClaim(?bool $mfa): array
    {
        $claims = [
            'user_id' => self::U_CON_MFA,
            'tenant_id' => self::TENANT,
        ];

        if ($mfa !== null) {
            $claims['mfa'] = $mfa;
        }

        return ['user' => $claims];
    }
}
