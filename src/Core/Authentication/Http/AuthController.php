<?php
declare(strict_types=1);

namespace App\Core\Authentication\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authentication\Service\SessionRevokerService;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Security\Service\InputSanitizer;
use App\Core\Security\Service\RateLimiter;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\SecurityException;
use App\Shared\Services\DatabaseService;
use PDO;

class AuthController
{
    private JwtService $jwt;
    private PasswordHasher $hasher;
    private UserRepositoryInterface $users;
    private DatabaseService $db;
    private InputSanitizer $sanitizer;
    private RateLimiter $rateLimiter;
    private ?AuditLogger $audit;
    private ?UserService $userService;
    private ?SessionRevokerService $sessions;
    private AuthorizationService $authz;

    public function __construct(
        ?JwtService $jwt = null,
        ?PasswordHasher $hasher = null,
        ?UserRepositoryInterface $users = null,
        ?DatabaseService $db = null,
        ?InputSanitizer $sanitizer = null,
        ?RateLimiter $rateLimiter = null,
        ?AuditLogger $audit = null,
        ?UserService $userService = null,
        ?SessionRevokerService $sessions = null,
        ?AuthorizationService $authz = null
    ) {
        $container = Container::getInstance();
        $this->jwt = $jwt ?? $container->get('jwt');
        $this->hasher = $hasher ?? $container->get('password_hasher');
        $this->users = $users ?? $container->get(UserRepositoryInterface::class);
        $this->db = $db ?? $container->get('database');
        $this->sanitizer = $sanitizer ?? $container->get(InputSanitizer::class);
        $this->rateLimiter = $rateLimiter ?? $container->get(RateLimiter::class);
        $this->audit = $audit ?? ($container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null);
        $this->userService = $userService;
        $this->sessions = $sessions;
        $this->authz = $authz ?? $container->get(AuthorizationService::class);
    }

    /**
     * Login (spec §7.1 + CU-000).
     *
     * $request sólo se usa para contexto de auditoría (ip, user-agent,
     * X-Request-Id); el body se sigue leyendo de php://input.
     */
    public function login(array $request = []): array
    {
        $clientIp = $this->getClientIp();
        $rateLimitKey = $this->rateLimiter->keyForIp($clientIp, 'login');

        try {
            $this->rateLimiter->attempt($rateLimitKey, 5, 300);
        } catch (SecurityException $e) {
            usleep(random_int(50000, 150000));
            return $this->jsonError('Demasiados intentos. Espera unos minutos.', 429);
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $email = $this->sanitizer->sanitizeEmail(is_string($data['email'] ?? null) ? (string) $data['email'] : '');
        $password = is_string($data['password'] ?? null) ? trim((string) $data['password']) : '';
        $tenantId = $this->sanitizer->sanitizeString((string) ($data['tenant_id'] ?? ''));

        if ($email === '' || $password === '') {
            return $this->jsonError('Email y password son obligatorios', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            usleep(random_int(50000, 150000));
            return $this->jsonError('Credenciales inválidas', 401);
        }

        if ($tenantId === '') {
            $tenantId = $this->deduceTenantIdFromEmail($email);
            if ($tenantId === null) {
                usleep(random_int(50000, 150000));
                return $this->jsonError('Credenciales inválidas', 401);
            }
        }

        // v1.13.2: bloqueo por CUENTA además de por IP — la clave identifica
        // al objetivo (tenant + email), así que un atacante con muchas IPs
        // no puede fuerza-brutar una cuenta concreta. Cuentan todos los
        // intentos; el login exitoso limpia la clave más abajo. Umbral 3/300s
        // (4to fallo seguido => 429): al quedar por debajo del límite IP (5),
        // el residuo del contador IP queda en 4 y el siguiente login con
        // éxito — que limpia todas las claves — sigue pasando sin bloquearse.
        $accountKey = $this->rateLimiter->keyForAccount($tenantId, $email);
        try {
            $this->rateLimiter->attempt($accountKey, 3, 300);
        } catch (SecurityException $e) {
            usleep(random_int(50000, 150000));
            return $this->jsonError('Demasiados intentos. Espera unos minutos.', 429);
        }

        $user = $this->users->findByEmail($email, $tenantId);
        $passwordValid = false;
        if ($user !== null) {
            $passwordValid = $this->hasher->verify($password, $user['password']);
        }

        if ($user === null || !$passwordValid) {
            $this->auditLogin($request, $tenantId, $user !== null ? (string) $user['id'] : null, [
                'resultado' => 'FAILURE',
                'razon_fallo' => 'Credenciales inválidas',
            ], $email);
            usleep(random_int(50000, 150000));
            return $this->jsonError('Credenciales inválidas', 401);
        }

        if (!$this->users->isActive($user['id'], $tenantId)) {
            $this->auditLogin($request, $tenantId, (string) $user['id'], [
                'resultado' => 'FAILURE',
                'razon_fallo' => 'Cuenta inactiva o suspendida',
            ], $email);
            return $this->jsonError('Cuenta inactiva o suspendida', 403);
        }

        // Password temporal / cambio de contraseña obligatorio (v1.11.0):
        // el login se concede pero el token queda bloqueado (403
        // password_change_required) salvo en la allowlist de cambio. Si la
        // vigencia ya venció, ni siquiera se concede el login.
        $passwordChangeRequired = false;
        if (!empty($user['fecha_expiracion_password'])) {
            $passwordChangeRequired = true;
            if ($user['fecha_expiracion_password'] < date('Y-m-d H:i:s')) {
                $this->auditLogin($request, $tenantId, (string) $user['id'], [
                    'resultado' => 'FAILURE',
                    'razon_fallo' => 'Password temporal expirado',
                ], $email);
                return $this->jsonError(
                    'El password temporal ha expirado; contacta con un administrador para reiniciarlo',
                    403
                );
            }
        }

        if (!$this->isTenantActive($tenantId)) {
            $this->auditLogin($request, $tenantId, (string) $user['id'], [
                'resultado' => 'FAILURE',
                'razon_fallo' => 'El tenant no está activo',
            ], $email);
            return $this->jsonError('El tenant no está activo', 403);
        }

        $this->rateLimiter->clear($rateLimitKey);
        $this->rateLimiter->clear($this->rateLimiter->httpKey('login', 'ip:' . $clientIp));
        $this->rateLimiter->clear($accountKey);

        // v1.12.1: roles y permisos efectivos se calculan con
        // AuthorizationService (directos + grupos + temporales vigentes);
        // el contenido del token no es la autoridad.
        $roles = $this->authz->getUserRolesWithOrigin($user['id'], $tenantId);
        $permisos = $this->authz->getUserPermissions($user['id'], $tenantId);

        $sessionId = $this->generateUuid();
        $refreshJti = $this->generateUuid();

        $accessToken = $this->jwt->generateToken([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'tenant_id' => $tenantId,
            'roles' => $roles,
            'jti' => $this->generateUuid(),
            'fam' => $sessionId,
        ]);

        $refreshToken = $this->jwt->generateRefreshToken([
            'user_id' => $user['id'],
            'tenant_id' => $tenantId,
            'jti' => $refreshJti,
            'fam' => $sessionId,
        ]);

        $this->storeRefreshToken($refreshJti, $user['id'], $tenantId, $sessionId);
        $this->touchSession($sessionId);
        $this->users->updateLastLogin($user['id']);

        $this->auditLogin($request, $tenantId, (string) $user['id'], [
            'resultado' => 'SUCCESS',
            'detalles_adicionales' => [
                'email' => $user['email'],
                'roles' => $roles,
            ],
        ], $user['email']);

        return $this->json([
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->jwt->getExpiration(),
            ],
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'nombre' => $user['nombre'],
                'apellido' => $user['apellido'],
                'roles' => $roles,
                'permisos' => $permisos,
                'tenant_id' => $tenantId,
                'preferencias' => $this->decodePreferences($user['preferencias'] ?? null),
            ],
            'tenant_id' => $tenantId,
            'password_change_required' => $passwordChangeRequired,
        ], 200);
    }

    public function refresh(array $request = []): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;
        $refreshToken = trim(is_string($data['refresh_token'] ?? null) ? (string) $data['refresh_token'] : '');

        if ($refreshToken === '') {
            return $this->jsonError('Refresh token requerido', 400);
        }

        try {
            $decoded = (array) $this->jwt->decodeToken($refreshToken);
        } catch (\Throwable $e) {
            return $this->jsonError('Refresh token inválido o expirado', 401);
        }

        if (($decoded['type'] ?? '') !== 'refresh') {
            return $this->jsonError('Tipo de token inválido', 401);
        }

        $userId = $this->sanitizer->sanitizeString((string) ($decoded['user_id'] ?? ''));
        $tenantId = $this->sanitizer->sanitizeString((string) ($decoded['tenant_id'] ?? ''));
        $refreshJti = $this->sanitizer->sanitizeString((string) ($decoded['jti'] ?? ''));
        $sessionId = $this->sanitizer->sanitizeString((string) ($decoded['fam'] ?? ''));

        if ($userId === '' || $tenantId === '' || $refreshJti === '' || $sessionId === '') {
            return $this->jsonError('Claims incompletos', 401);
        }

        $user = $this->users->findById($userId, $tenantId);
        if ($user === null || !$this->users->isActive($userId, $tenantId)) {
            return $this->jsonError('Usuario no válido', 401);
        }

        $stored = $this->findRefreshToken($refreshJti, $userId, $tenantId);
        if ($stored === null) {
            return $this->jsonError('Refresh token inválido', 401);
        }

        if ((int) $stored['used'] === 1 || (int) $stored['revoked'] === 1) {
            $this->revokeFamily($sessionId, 'reuse');

            // Evento de seguridad crítico (spec §7.2): la familia completa se
            // revoca y queda registrado quién reutilizó el refresh token.
            $this->auditEvent('auth.refresh.reuse', 'EXECUTE', $request, [
                'tenant_id' => $tenantId,
                'usuario_id' => $userId,
                'entidad_id' => $userId,
                'resultado' => 'FAILURE',
                'razon_fallo' => 'Reutilización de refresh token detectada; familia de tokens revocada',
                'detalles_adicionales' => ['motivo' => 'reuse', 'family_id' => $sessionId],
            ]);

            return $this->jsonError('Refresh token inválido', 401);
        }

        if ($stored['expires_at'] < date('Y-m-d H:i:s')) {
            $this->revokeFamily($sessionId, 'expired');
            return $this->jsonError('Refresh token expirado', 401);
        }

        if (!$this->sessionIsActive($sessionId)) {
            $this->revokeFamily($sessionId, 'inactivity');
            return $this->jsonError('Sesión expirada por inactividad', 401);
        }

        $newRefreshJti = $this->rotateRefreshToken($refreshJti, $sessionId, $userId, $tenantId);
        $this->touchSession($sessionId);

        // v1.12.1: los claims del nuevo access token reflejan los roles
        // efectivos vigentes (directos + de grupo), no los del login.
        $roles = $this->authz->getUserRolesWithOrigin($userId, $tenantId);

        return $this->json([
            'tokens' => [
                'access_token' => $this->jwt->generateToken([
                    'user_id' => $userId,
                    'email' => $user['email'],
                    'tenant_id' => $tenantId,
                    'roles' => $roles,
                    'jti' => $this->generateUuid(),
                    'fam' => $sessionId,
                ]),
                'refresh_token' => $this->jwt->generateRefreshToken([
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'jti' => $newRefreshJti,
                    'fam' => $sessionId,
                ]),
                'token_type' => 'Bearer',
                'expires_in' => $this->jwt->getExpiration(),
            ]
        ], 200);
    }

    public function logout(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (is_array($claims) && !empty($claims['fam'])) {
            $sessionId = (string) $claims['fam'];
            $this->revokeFamily($sessionId, 'logout');
            $this->markSessionClosed($sessionId);
        }

        $this->auditEvent('auth.logout', 'EXECUTE', $request, [
            'tenant_id' => is_array($claims) ? ($claims['tenant_id'] ?? null) : null,
            'usuario_id' => is_array($claims) ? ($claims['user_id'] ?? null) : null,
            'entidad_id' => is_array($claims) ? ($claims['user_id'] ?? null) : null,
            'resultado' => 'SUCCESS',
        ]);

        return $this->json(['message' => 'Sesión cerrada correctamente'], 200);
    }

    /**
     * POST /api/auth/change-password (v1.11.0).
     *
     * Valida la contraseña actual, aplica la política de fortaleza, limpia
     * el marcador fecha_expiracion_password y revoca el resto de sesiones
     * del usuario (la actual se conserva). Es la única vía de salida del
     * cambio forzado: la allowlist del middleware permite esta ruta con el
     * marcador activo.
     */
    public function changePassword(array $request = []): array
    {
        $claims = $request['user'] ?? null;
        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            return $this->jsonError('No autenticado', 401);
        }

        $userId = (string) $claims['user_id'];
        $tenantId = (string) $claims['tenant_id'];

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $actual = is_string($data['password_actual'] ?? null) ? (string) $data['password_actual'] : '';
        $nuevo = is_string($data['password_nuevo'] ?? null) ? (string) $data['password_nuevo'] : '';

        if ($actual === '') {
            throw UserException::requiredField('password_actual');
        }
        if ($nuevo === '') {
            throw UserException::requiredField('password_nuevo');
        }

        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($userId);
        }

        if (!$this->hasher->verify($actual, $user['password'])) {
            throw new UserException('La contraseña actual no coincide.', 400, null, 'password_actual');
        }

        $this->resolveUserService()->changePassword($userId, $tenantId, $nuevo);

        // El resto de sesiones del usuario quedan invalidadas; la actual no.
        $sessionId = is_string($claims['fam'] ?? null) && $claims['fam'] !== ''
            ? (string) $claims['fam']
            : null;
        $this->resolveSessions()->revokeAllForUser($userId, $sessionId, 'password_change');

        $this->auditEvent('auth.password.change', 'UPDATE', $request, [
            'tenant_id' => $tenantId,
            'usuario_id' => $userId,
            'entidad_id' => $userId,
            'recurso' => 'password',
            'detalles_adicionales' => ['hash_changed' => true],
        ]);

        return $this->json(['message' => 'Contraseña actualizada correctamente'], 200);
    }

    private function resolveUserService(): UserService
    {
        return $this->userService ??= Container::getInstance()->get(UserService::class);
    }

    private function resolveSessions(): SessionRevokerService
    {
        return $this->sessions ??= Container::getInstance()->get(SessionRevokerService::class);
    }

    /**
     * Keep-alive de sesión: el middleware de autenticación renueva la clave
     * de actividad (session:act) antes de llegar aquí. Cero trabajo de BD.
     */
    public function ping(): array
    {
        return $this->json(['pong' => true], 200);
    }

    public function me(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = '';

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            $token = trim($matches[1]);
        }

        if ($token === '') {
            return $this->jsonError('No autenticado', 401);
        }

        try {
            $claims = (array) $this->jwt->decodeToken($token);
        } catch (\Throwable $e) {
            return $this->jsonError('Token inválido', 401);
        }

        // Defensa en profundidad (misma semántica que AuthenticationMiddleware)
        if (($claims['type'] ?? 'access') !== 'access') {
            return $this->jsonError('Tipo de token inválido', 401);
        }

        $userId = (string) ($claims['user_id'] ?? '');
        $tenantId = (string) ($claims['tenant_id'] ?? '');

        if ($userId === '' || $tenantId === '') {
            return $this->jsonError('Claims incompletos', 401);
        }

        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            return $this->jsonError('Usuario no encontrado', 404);
        }

        if (!$this->users->isActive($userId, $tenantId)) {
            return $this->jsonError('Usuario inactivo o eliminado', 401);
        }

        // v1.12.1: /auth/me deja de leer los claims del token (que podrian
        // estar obsoletos) y expone la situacion real: roles con su origen
        // (directo o grupo), permisos efectivos (incluye grupos y temporales
        // vigentes) y el detalle de los permisos temporales.
        return $this->json([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'nombre' => $user['nombre'],
                'apellido' => $user['apellido'],
                'preferencias' => $this->decodePreferences($user['preferencias'] ?? null),
            ],
            'roles' => $this->authz->getUserRolesWithOrigin($userId, $tenantId),
            'permisos' => $this->authz->getUserPermissions($userId, $tenantId),
            'permisos_temporales' => $this->authz->getActiveTemporaryPermissions($userId),
            'tenant_id' => $tenantId,
        ], 200);
    }

    /**
     * Decodifica users.preferencias (JSON) para exponerla en login y /auth/me.
     * Nulo o JSON invalido => array vacío.
     */
    private function decodePreferences(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function storeRefreshToken(string $jti, string $userId, string $tenantId, string $sessionId): void
    {
        $refreshTtl = (int) ($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800);

        $sql = "INSERT INTO refresh_tokens
                (id, user_id, tenant_id, family_id, expires_at, ip_address, user_agent)
                VALUES (:id, :user_id, :tenant_id, :family_id, :expires_at, :ip, :ua)";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'id' => $jti,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'family_id' => $sessionId,
            'expires_at' => date('Y-m-d H:i:s', time() + $refreshTtl),
            'ip' => $this->getClientIp(),
            'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        $this->db->getConnection()
            ->prepare("DELETE FROM refresh_tokens WHERE expires_at < NOW()")
            ->execute();
    }

    private function findRefreshToken(string $jti, string $userId, string $tenantId): ?array
    {
        $sql = "SELECT * FROM refresh_tokens
                WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id
                LIMIT 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $jti, 'user_id' => $userId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function revokeFamily(string $sessionId, string $reason): void
    {
        $sql = "UPDATE refresh_tokens SET revoked = 1, revoked_reason = :reason, updated_at = NOW()
                WHERE family_id = :fam AND revoked = 0";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['reason' => $reason, 'fam' => $sessionId]);
    }

    private function rotateRefreshToken(string $oldJti, string $sessionId, string $userId, string $tenantId): string
    {
        $newJti = $this->generateUuid();

        $sql = "UPDATE refresh_tokens SET used = 1, revoked_reason = 'rotated', updated_at = NOW()
                WHERE id = :id AND revoked = 0";
        $this->db->getConnection()->prepare($sql)->execute(['id' => $oldJti]);

        $this->storeRefreshToken($newJti, $userId, $tenantId, $sessionId);

        return $newJti;
    }

    private function sessionIsActive(string $sessionId): bool
    {
        $timeout = (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800);
        if ($timeout <= 0) {
            return true;
        }

        $redis = Container::getInstance()->get('redis');
        $value = $redis->get('session:act:' . $sessionId);

        if ($value === null) {
            if (!$redis->ping()) {
                error_log('[Auth] Refresh inactivity check skipped (fail-open): Redis no disponible');
                return true;
            }
            return false;
        }

        // 0 = sesión cerrada explícitamente (denylist de logout)
        return (int) $value !== 0;
    }

    private function markSessionClosed(string $sessionId): void
    {
        $accessTtl = (int) ($_ENV['JWT_EXPIRATION'] ?? 3600);
        if ($accessTtl > 0) {
            Container::getInstance()->get('redis')->set('session:act:' . $sessionId, 0, $accessTtl);
        }
    }

    private function touchSession(string $sessionId): void
    {
        $timeout = (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800);
        if ($timeout > 0) {
            Container::getInstance()->get('redis')->set('session:act:' . $sessionId, time(), $timeout);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function deduceTenantIdFromEmail(string $email): ?string
    {
        $sql = "SELECT tenant_id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['email' => $email]);
        $tenantId = $stmt->fetchColumn();
        return $tenantId !== false ? (string) $tenantId : null;
    }

    private function isTenantActive(string $tenantId): bool
    {
        $sql = "SELECT estado FROM tenants WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $tenantId]);
        return $stmt->fetchColumn() === 'ACTIVO';
    }

    /**
     * Obtiene la IP real del cliente.
     * X-Forwarded-For es manipulable por el cliente: sólo se confía
     * cuando la conexión proviene de un proxy declarado en TRUSTED_PROXIES.
     */
    private function getClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))));

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim((string) $ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Auditoría de login (spec §7.1): éxito y fallo con causa explícita.
     *
     * El login no tiene claims (no hay token todavía), por lo que el tenant
     * se resuelve en este punto: sin él la fila no podría persistirse porque
     * audit_logs.tenant_id es NOT NULL con FK.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $entry
     */
    private function auditLogin(array $request, string $tenantId, ?string $userId, array $entry, string $email): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->record(array_merge([
            'tenant_id' => $tenantId,
            'usuario_id' => $userId,
            'operacion' => 'auth.login',
            'modulo' => 'auth',
            'recurso' => 'login',
            'entidad_id' => $userId,
            'tipo_cambio' => 'EXECUTE',
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? $this->getClientIp(),
            'user_agent' => $request['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            'request_id' => $request['request_id'] ?? null,
            'detalles_adicionales' => ['email' => $email],
        ], $entry));
    }

    /**
     * Auditoría genérica de eventos de autenticación (logout, reuse de refresh).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $entry
     */
    private function auditEvent(string $operacion, string $tipoCambio, array $request, array $entry): void
    {
        if ($this->audit === null || ($entry['tenant_id'] ?? null) === null) {
            return;
        }

        $this->audit->record(array_merge([
            'operacion' => $operacion,
            'modulo' => 'auth',
            'recurso' => 'session',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? $this->getClientIp(),
            'user_agent' => $request['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    private function json(array $data, int $status = 200): array
    {
        return ['status' => $status, 'data' => $data];
    }

    private function jsonError(string $message, int $status): array
    {
        return ['status' => $status, 'error' => $message];
    }
}
