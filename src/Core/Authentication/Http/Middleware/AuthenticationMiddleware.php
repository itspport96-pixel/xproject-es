<?php
declare(strict_types=1);

namespace App\Core\Authentication\Http\Middleware;

use App\Core\Authentication\Exception\PasswordChangeRequiredException;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authentication\Service\JwtService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\InvalidTokenException;
use App\Shared\Exceptions\TokenExpiredException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use DomainException;
use UnexpectedValueException;

/**
 * AuthenticationMiddleware - Valida JWT Bearer token en cada request protegida.
 *
 * Responsabilidades:
 * - Extraer el token del header Authorization (Bearer)
 * - Decodificar y validar el JWT
 * - Verificar que sea un token de tipo "access" (no refresh)
 * - Verificar que el usuario exista, esté activo y no esté eliminado
 * - Bloquear con 403 password_change_required cuando el usuario tiene el
 *   marcador password_change_forced (temporal) o fecha_expiracion_password
 *   vencida (caducidad), salvo allowlist de rutas
 * - Aplicar la ventana de inactividad (Redis session:act:{fam}):
 *   rechazar si venció o la sesión fue cerrada; renovar si está activa
 * - Inyectar los claims del usuario en $request['user']
 *
 * Orden de ejecución:
 *   AuthenticationMiddleware → TenantMiddleware → PermissionMiddleware → Controller
 *
 * Uso en rutas:
 *   $router->get('/api/users', [UserController::class, 'index'], [
 *       AuthenticationMiddleware::class,
 *       PermissionMiddleware::class . ':users.user.view'
 *   ]);
 *
 * Flujo:
 *   1. Extrae token de $request['headers']['authorization'] (o fallback a $_SERVER)
 *   2. Decodifica JWT con JwtService
 *   3. Valida que type === 'access'
 *   4. Valida claims mínimos y que el usuario esté activo
 *   5. Valida/renueva la ventana de inactividad de la sesión
 *   6. Inyecta claims en $request['user']
 *   7. Retorna $request modificado
 */
class AuthenticationMiddleware
{
    /**
     * Allowlist del cambio forzado de contraseña (v1.11.0): rutas a las que
     * puede llamarse con password_change_forced marcada o caducidad vencida.
     * Todo lo demás responde 403 category=password_change_required.
     */
    private const PASSWORD_CHANGE_ALLOWED_PATHS = [
        '/api/auth/login',
        '/api/auth/refresh',
        '/api/auth/logout',
        '/api/auth/me',
        '/api/auth/ping',
        '/api/auth/change-password',
        '/api/users/me/preferences',
    ];

    private const PASSWORD_CHANGE_ALLOWED_PREFIXES = [
        '/api/locales',
    ];

    private JwtService $jwt;
    private UserRepositoryInterface $users;

    public function __construct(?JwtService $jwt = null, ?UserRepositoryInterface $users = null)
    {
        $container = Container::getInstance();
        $this->jwt = $jwt ?? $container->get(JwtService::class);
        $this->users = $users ?? $container->get(UserRepositoryInterface::class);
    }

    /**
     * Procesa la request, validando el token JWT.
     *
     * @param array $request Request con headers, body, query, etc.
     * @return array Request enriquecido con 'user' (claims del JWT)
     * @throws InvalidTokenException Si el token es inválido o no se proporciona
     * @throws TokenExpiredException Si el token expiró
     */
    public function handle(array $request): array
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw new InvalidTokenException('Token no proporcionado', 401);
        }

        try {
            $decoded = $this->jwt->decodeToken($token);
            $claims = (array) $decoded;

            // Verificar que sea un token de tipo "access" (no refresh)
            if (($claims['type'] ?? 'access') !== 'access') {
                throw new InvalidTokenException('Tipo de token inválido', 401);
            }

            // Validar claims mínimos
            if (empty($claims['user_id']) || empty($claims['tenant_id'])) {
                throw new InvalidTokenException('Claims incompletos en el token', 401);
            }

            // Usuario activo + marcadores de cambio de contraseña (v1.11.0)
            $state = $this->users->getAuthState((string) $claims['user_id'], (string) $claims['tenant_id']);

            if ($state === null || $state['estado'] !== 'ACTIVO') {
                throw new InvalidTokenException('Usuario inactivo o eliminado', 401);
            }

            // password_change_forced: cambio forzado temporal (admin reset /
            // password autogenerada). Cualquier valor puesto exige cambio.
            // fecha_expiracion_password: expiración forward de caducidad
            // (v1.14.1). Solo fuerza cuando está en el PASADO (vencida); una
            // fecha futura significa contraseña aún válida. Esto corrige el
            // bucle infinito v1.17.0 (antes cualquier no-nulo forzaba cambio).
            $caducidadVencida = !empty($state['fecha_expiracion_password'])
                && $state['fecha_expiracion_password'] < date('Y-m-d H:i:s');
            $debeCambiar = !empty($state['password_change_forced']) || $caducidadVencida;

            if ($debeCambiar && !$this->isPasswordChangeAllowed($request)) {
                throw new PasswordChangeRequiredException();
            }

            // Ventana de inactividad: rechazar si venció; renovar si está activa
            $sessionId = (string) ($claims['fam'] ?? '');
            if ($sessionId !== '') {
                $this->enforceSessionActivity($sessionId);
            }

            // Inyectar claims en el request para uso posterior
            $request['user'] = $claims;

            return $request;
        } catch (ExpiredException $e) {
            throw new TokenExpiredException('Token expired', 401, $e);
        } catch (SignatureInvalidException | UnexpectedValueException | DomainException $e) {
            throw new InvalidTokenException('Invalid token: ' . $e->getMessage(), 401, $e);
        }
    }

    /**
     * ¿Pertenece la ruta a la allowlist de cambio forzado de contraseña?
     *
     * Rutas exactas (auth mínima + preferencias propias) y prefijo
     * /api/locales (catálogo público de traducciones, GET/PATCH).
     */
    private function isPasswordChangeAllowed(array $request): bool
    {
        $path = (string) ($request['path'] ?? '');

        if (in_array($path, self::PASSWORD_CHANGE_ALLOWED_PATHS, true)) {
            return true;
        }

        foreach (self::PASSWORD_CHANGE_ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrae el Bearer token del request.
     *
     * Orden de búsqueda:
     *   1. $request['headers']['authorization'] (inyectado por Router)
     *   2. $request['headers']['Authorization'] (fallback case-sensitive)
     *   3. $_SERVER['HTTP_AUTHORIZATION'] (fallback directo)
     *   4. $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] (fallback Apache mod_rewrite)
     *
     * @param array $request Request con headers
     * @return string|null Token extraído o null si no existe
     */
    private function extractToken(array $request): ?string
    {
        // 1. Buscar en $request['headers'] (inyectado por Router)
        $header = $request['headers']['authorization']
            ?? $request['headers']['Authorization']
            ?? null;

        // 2. Fallback directo a $_SERVER (por si el Router no capturó el header)
        if ($header === null) {
            $header = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
        }

        // Validar formato Bearer
        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    /**
     * Ventana deslizante de inactividad (Redis session:act:{fam}):
     * - clave ausente y Redis vivo  -> sesión vencida por inactividad -> 401
     * - clave en 0                   -> sesión cerrada (denylist de logout) -> 401
     * - clave con timestamp          -> actividad reciente -> renueva TTL
     * - Redis caído                  -> fail-open (política A), deja pasar
     */
    private function enforceSessionActivity(string $sessionId): void
    {
        $timeout = (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800);
        if ($timeout <= 0) {
            return;
        }

        $redis = Container::getInstance()->get('redis');
        $value = $redis->get('session:act:' . $sessionId);

        if ($value === null) {
            if ($redis->ping()) {
                error_log("[Auth] Request rechazada por inactividad: session={$sessionId}");
                throw new InvalidTokenException('Sesión expirada por inactividad', 401);
            }
            error_log('[Auth] Activity check skipped (fail-open): Redis no disponible');
            return;
        }

        if ((int) $value === 0) {
            error_log("[Auth] Request rechazada: sesión cerrada (denylist) session={$sessionId}");
            throw new InvalidTokenException('Sesión cerrada', 401);
        }

        $redis->set('session:act:' . $sessionId, time(), $timeout);
    }
}
