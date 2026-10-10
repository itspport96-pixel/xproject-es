<?php

declare(strict_types=1);

namespace App\Core\Users\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\SessionRevokerService;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

final class UserController
{
    private UserService $userService;
    private JwtService $jwt;
    private AuthorizationService $authz;
    private ?AuditLogger $audit;
    private ?SessionRevokerService $sessions;
    private ?DatabaseService $db;
    private ?IdempotencyService $idem;

    public function __construct(
        ?UserService $userService = null,
        ?JwtService $jwt = null,
        ?AuthorizationService $authz = null,
        ?AuditLogger $audit = null,
        ?SessionRevokerService $sessions = null,
        ?DatabaseService $db = null
    ) {
        $container = Container::getInstance();
        $this->userService = $userService ?? $container->get(UserService::class);
        $this->jwt = $jwt ?? $container->get('jwt');
        $this->authz = $authz ?? $container->get(AuthorizationService::class);
        $this->audit = $audit ?? ($container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null);
        $this->sessions = $sessions;
        $this->db = $db;
        $this->idem = null;
    }

    // ========================================================================
    // GET /api/users
    // ========================================================================

    public function index(): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = $claims['tenant_id'];

        $allowedFilters = ['page', 'per_page', 'estado', 'search', 'include_deleted'];
        $filters = array_intersect_key($_GET, array_flip($allowedFilters));

        // Normalizar a booleano ('false' no debe activar el modo borrados).
        $includeDeleted = isset($filters['include_deleted'])
            && in_array(strtolower((string) $filters['include_deleted']), ['1', 'true'], true);
        $filters['include_deleted'] = $includeDeleted;

        // v1.13.0: listar borrados exige el mismo permiso que borrar.
        if ($includeDeleted) {
            $denegado = !$this->authz->hasPermission(
                (string) $claims['user_id'],
                (string) $tenantId,
                'users.user.delete',
                false
            );
            if ($denegado) {
                throw new HttpException('Se requiere el permiso users.user.delete para listar usuarios eliminados', 403);
            }
        }

        $result = $this->userService->findAll($tenantId, $filters);

        // v1.13.3: columna Roles de la tabla. Misma fuente que
        // GET /users/{id}/roles (origen directo/grupo); sólo lo que pinta
        // la UI (sin el JSON de permisos, que multiplaría el payload).
        foreach ($result['data'] as &$row) {
            $row['roles'] = array_map(
                static fn(array $role): array => [
                    'id'     => (string) $role['id'],
                    'nombre' => (string) $role['nombre'],
                    'tipo'   => (string) $role['tipo'],
                    'origen' => (string) $role['origen'],
                ],
                $this->authz->getUserRolesWithOrigin((string) $row['id'], (string) $tenantId)
            );
        }
        unset($row);

        return ['status' => 200, 'data' => $result];
    }

    // ========================================================================
    // GET /api/users/me/preferences
    // ========================================================================

    public function mePreferences(): array
    {
        $claims = $this->requireAuthenticated();

        $preferencias = $this->userService->getPreferences(
            (string) $claims['user_id'],
            (string) $claims['tenant_id']
        );

        return ['status' => 200, 'data' => ['preferencias' => $preferencias]];
    }

    // ========================================================================
    // PATCH /api/users/me/preferences
    // ========================================================================

    public function updateMePreferences(array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $userId = (string) $claims['user_id'];
        $tenantId = (string) $claims['tenant_id'];

        $anteriores = $this->userService->getPreferences($userId, $tenantId);
        $preferencias = $this->userService->updatePreferences($userId, $tenantId, $this->getInputData());

        $this->auditEvent('user.preferences.update', 'UPDATE', $request, $claims, [
            'entidad_id' => $userId,
            'recurso' => 'user.preferences',
            'valores_anteriores' => ['preferencias' => $anteriores],
            'valores_nuevos' => ['preferencias' => $preferencias],
        ]);

        return ['status' => 200, 'data' => ['preferencias' => $preferencias]];
    }

    // ========================================================================
    // GET /api/users/{id}
    // ========================================================================

    public function show(array $params): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = $claims['tenant_id'];
        $userId = $params['id'] ?? null;

        if (!$userId) {
            throw new HttpException('ID de usuario requerido', 400);
        }

        $user = $this->userService->findById($userId, $tenantId);
        if (!$user) {
            throw new HttpException('Usuario no encontrado', 404);
        }

        return ['status' => 200, 'data' => ['user' => $user]];
    }

    // ========================================================================
    // POST /api/users
    // ========================================================================

    public function store(array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $data = $this->getInputData();

        // Forzar el tenant_id del contexto autenticado
        $data['tenant_id'] = $tenantId;

        $key = $this->idempotencyKey($request);
        $operacion = 'POST /api/users';

        // Con Idempotency-Key: la clave y la creación corren en la misma
        // transacción (patrón de la recepción). El replay devuelve la
        // respuesta original con _replay para que AuditMiddleware no duplique.
        if ($key !== null) {
            $idem = $this->idempotencyService();
            $hash = IdempotencyService::requestHash($data);
            $tenantKey = $tenantId;
            $actorId = (string) $claims['user_id'];

            $result = $this->databaseService()->transaction(
                function () use ($idem, $key, $operacion, $hash, $tenantKey, $actorId, $data): array {
                    $replay = $idem->tryBegin($tenantKey, $key, $operacion, $hash, $actorId);

                    if ($replay !== null) {
                        return $replay + ['_replay' => true];
                    }

                    $user = $this->userService->create($data);
                    $response = ['status' => 201, 'data' => ['user' => $user]];
                    $idem->complete($tenantKey, $key, $operacion, $response, 201);

                    return $response;
                }
            );

            if (empty($result['_replay'])) {
                $this->auditUserCreate($request, $claims, $result['data']['user'] ?? null);
            }

            return $result;
        }

        $user = $this->userService->create($data);

        $this->auditUserCreate($request, $claims, $user);

        return ['status' => 201, 'data' => ['user' => $user]];
    }

    /**
     * Auditoría del alta: nunca persiste password_temporal (texto plano,
     * se entrega una sola vez al cliente).
     */
    private function auditUserCreate(array $request, array $claims, ?array $user): void
    {
        $auditable = $user;
        if (is_array($auditable)) {
            unset($auditable['password_temporal']);
        }

        $this->auditEvent('user.create', 'CREATE', $request, $claims, [
            'entidad_id' => is_array($auditable) ? ($auditable['id'] ?? null) : null,
            'valores_nuevos' => $auditable,
        ]);
    }

    // ========================================================================
    // PATCH /api/users/{id}
    // ========================================================================

    public function update(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = $params['id'] ?? null;

        if (!$userId) {
            throw new HttpException('ID de usuario requerido', 400);
        }

        $before = $this->userService->findById((string) $userId, $tenantId);
        if ($before === null) {
            throw UserException::userNotFound((string) $userId);
        }

        // v1.13.1: única escritura de usuarios que no aplicaba la escalada
        // actor >= afectado (destroy/status/reset/roles ya la tenían); las
        // acciones sobre uno mismo saltan dentro de assertCanActOnUser.
        $this->authz->assertCanActOnUser($actorId, $tenantId, (string) $userId);

        $data = $this->getInputData();

        // v1.13.2: misma guarda que updateStatus — PATCH no puede bajar al
        // único administrador activo del tenant cambiándole el estado.
        if (
            isset($data['estado'])
            && (string) $data['estado'] !== 'ACTIVO'
            && $this->authz->isActiveAdmin((string) $userId, $tenantId)
            && $this->authz->countActiveAdmins($tenantId) <= 1
        ) {
            throw new UserException(
                'No se puede desactivar el único administrador activo del tenant.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $user = $this->userService->update($userId, $tenantId, $data);

        $this->auditEvent('user.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $userId,
            'valores_anteriores' => $before,
            'valores_nuevos' => $user,
        ]);

        return ['status' => 200, 'data' => ['user' => $user]];
    }

    // ========================================================================
    // DELETE /api/users/{id}
    // ========================================================================

    public function destroy(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = (string) ($params['id'] ?? '');

        if ($userId === '') {
            throw new HttpException('ID de usuario requerido', 400);
        }

        if ($userId === $actorId) {
            throw new UserException(
                'No puedes eliminarte a ti mismo.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $target = $this->userService->findById($userId, $tenantId);
        if ($target === null) {
            throw UserException::userNotFound($userId);
        }

        $this->authz->assertCanActOnUser($actorId, $tenantId, $userId);

        if ($this->authz->isActiveAdmin($userId, $tenantId) && $this->authz->countActiveAdmins($tenantId) <= 1) {
            throw new UserException(
                'No se puede eliminar el único administrador activo del tenant.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $this->userService->delete($userId, $tenantId);

        // El usuario eliminado pierde permisos efectivos: vaciar su cache
        $this->authz->invalidateCache($userId, $tenantId);

        // v1.13.1: auditoría de dominio del borrado (antes sólo quedaba la
        // fila genérica de la ruta en AuditMiddleware); valores previos.
        $this->auditEvent('user.delete', 'DELETE', $request, $claims, [
            'entidad_id' => $userId,
            'valores_anteriores' => $target,
        ]);

        return ['status' => 204, 'data' => null];
    }

    // ========================================================================
    // POST /api/users/{id}/restore  (v1.13.0, hueco de restauración)
    // ========================================================================

    public function restore(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = (string) ($params['id'] ?? '');

        if ($userId === '') {
            throw new HttpException('ID de usuario requerido', 400);
        }

        $this->authz->assertCanActOnUser($actorId, $tenantId, $userId);

        $this->userService->restore($userId, $tenantId);

        // El usuario restaurado vuelve a tener permisos efectivos: su cache
        // puede estar vacío o obsoleto, se invalida por seguridad.
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('user.restore', 'EXECUTE', $request, $claims, [
            'entidad_id' => $userId,
        ]);

        return ['status' => 200, 'data' => ['message' => 'Usuario restaurado correctamente']];
    }

    // ========================================================================
    // POST /api/users/{id}/status  (v1.11.0)
    // ========================================================================

    public function updateStatus(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = (string) ($params['id'] ?? '');

        if ($userId === '') {
            throw new HttpException('ID de usuario requerido', 400);
        }

        $data = $this->getInputData();
        $estado = isset($data['estado']) ? trim((string) $data['estado']) : '';
        $motivo = isset($data['motivo']) ? trim((string) $data['motivo']) : '';

        if ($estado === '') {
            throw UserException::requiredField('estado');
        }
        if ($motivo === '') {
            throw UserException::requiredField('motivo');
        }

        if ($userId === $actorId) {
            throw new UserException(
                'No puedes cambiar tu propio estado.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $target = $this->userService->findById($userId, $tenantId);
        if ($target === null) {
            throw UserException::userNotFound($userId);
        }

        $this->authz->assertCanActOnUser($actorId, $tenantId, $userId);

        if (
            $estado !== 'ACTIVO'
            && $this->authz->isActiveAdmin($userId, $tenantId)
            && $this->authz->countActiveAdmins($tenantId) <= 1
        ) {
            throw new UserException(
                'No se puede desactivar el único administrador activo del tenant.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $estadoAnterior = (string) $target['estado'];
        $user = $this->userService->updateStatus($userId, $tenantId, $estado, $motivo);

        $this->auditEvent('user.status.change', 'UPDATE', $request, $claims, [
            'entidad_id' => $userId,
            'valores_anteriores' => ['estado' => $estadoAnterior],
            'valores_nuevos' => ['estado' => $user['estado'] ?? $estado],
            'detalles_adicionales' => ['motivo' => $motivo],
        ]);

        return ['status' => 200, 'data' => ['user' => $user]];
    }

    // ========================================================================
    // POST /api/users/{id}/password/reset  (v1.11.0)
    // ========================================================================

    public function resetPassword(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = (string) ($params['id'] ?? '');

        if ($userId === '') {
            throw new HttpException('ID de usuario requerido', 400);
        }

        if ($userId === $actorId) {
            throw new UserException(
                'No puedes resetear tu propia contraseña; usa POST /api/auth/change-password.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $target = $this->userService->findById($userId, $tenantId);
        if ($target === null) {
            throw UserException::userNotFound($userId);
        }

        $this->authz->assertCanActOnUser($actorId, $tenantId, $userId);

        $passwordTemporal = $this->userService->resetPassword($userId, $tenantId);

        // Todas las sesiones del objetivo quedan invalidadas (refresh + denylist)
        $this->resolveSessions()->revokeAllForUser($userId, null, 'admin_reset');

        $this->auditEvent('user.password.reset', 'UPDATE', $request, $claims, [
            'entidad_id' => $userId,
            'valores_nuevos' => ['hash_changed' => true],
            'detalles_adicionales' => ['password_temporal_entregado' => true],
        ]);

        return ['status' => 200, 'data' => [
            'message' => 'Contraseña reseteada; el usuario deberá cambiarla en el siguiente inicio de sesión',
            'password_temporal' => $passwordTemporal,
            'password_change_required' => true,
        ]];
    }

    // ========================================================================
    // POST /api/users/{id}/roles
    // ========================================================================

    public function assignRole(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = $params['id'] ?? null;
        $data = $this->getInputData();
        $roleId = $data['role_id'] ?? null;

        if (!$userId || !$roleId) {
            throw new HttpException('ID de usuario y role_id son requeridos', 400);
        }

        $target = $this->userService->findById((string) $userId, $tenantId);
        if ($target === null) {
            throw UserException::userNotFound((string) $userId);
        }

        // Aislamiento (v1.11.1): el rol debe ser BUILT_IN o del tenant actual
        $this->authz->assertRoleBelongsToTenant((string) $roleId, $tenantId);

        // Escalada (v1.11.0): actor >= afectado y actor ⊇ permisos del rol
        $this->authz->assertCanActOnUser($actorId, $tenantId, (string) $userId);
        $this->authz->assertCanAssignRole($actorId, $tenantId, (string) $roleId);

        $this->userService->assignRole($userId, $roleId, $tenantId, $claims['user_id']);

        // El usuario afectado cambia de permisos efectivos: vaciar su cache
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('user.role.assign', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $userId,
            'valores_nuevos' => ['role_id' => $roleId, 'user_id' => $userId],
        ]);

        return ['status' => 200, 'data' => ['message' => 'Rol asignado correctamente']];
    }

    // ========================================================================
    // DELETE /api/users/{id}/roles/{roleId}
    // ========================================================================

    public function removeRole(array $params, array $request = []): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $userId = $params['id'] ?? null;
        $roleId = $params['roleId'] ?? null;

        if (!$userId || !$roleId) {
            throw new HttpException('ID de usuario y roleId son requeridos', 400);
        }

        $target = $this->userService->findById((string) $userId, $tenantId);
        if ($target === null) {
            throw UserException::userNotFound((string) $userId);
        }

        // Escalada (v1.11.0): actor >= afectado
        $this->authz->assertCanActOnUser($actorId, $tenantId, (string) $userId);

        // Último ADMIN: no se puede quitar el rol ADMIN al único admin activo
        if (
            $this->authz->roleName((string) $roleId) === 'ADMIN'
            && $this->authz->isActiveAdmin((string) $userId, $tenantId)
            && $this->authz->countActiveAdmins($tenantId) <= 1
        ) {
            throw new UserException(
                'No se puede quitar el rol ADMIN al único administrador activo del tenant.',
                403,
                null,
                null,
                UserException::CATEGORY_FORBIDDEN
            );
        }

        $this->userService->removeRole($userId, $roleId, $tenantId);

        // El usuario afectado pierde permisos efectivos: vaciar su cache
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('user.role.remove', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $userId,
            'valores_anteriores' => ['role_id' => $roleId, 'user_id' => $userId],
        ]);

        return ['status' => 200, 'data' => ['message' => 'Rol removido correctamente']];
    }

    // ========================================================================
    // GET /api/users/{id}/roles
    // ========================================================================

    public function getRoles(array $params): array
    {
        $claims = $this->requireAuthenticated();
        $tenantId = $claims['tenant_id'];
        $userId = $params['id'] ?? null;

        if (!$userId) {
            throw new HttpException('ID de usuario requerido', 400);
        }

        $roles = $this->authz->getUserRolesWithOrigin((string) $userId, (string) $tenantId);

        return ['status' => 200, 'data' => ['roles' => $roles]];
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * Header Idempotency-Key para el alta de usuarios (v1.11.0, opcional).
     * Case-insensitive, máximo 64 caracteres y charset [A-Za-z0-9._:-],
     * igual que el trait de Inventory pero con UserException (400/field).
     *
     * @return string|null null si el header no está presente
     */
    private function idempotencyKey(array $request): ?string
    {
        $headers = $request['headers'] ?? [];
        $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];

        $key = $headers['idempotency-key'] ?? null;
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            return null;
        }

        if (strlen($key) > 64) {
            throw new UserException(
                'Idempotency-Key: máximo 64 caracteres.',
                400,
                null,
                'idempotency-key'
            );
        }

        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw new UserException(
                'Idempotency-Key: sólo letras, números y los caracteres . _ : -.',
                400,
                null,
                'idempotency-key'
            );
        }

        return $key;
    }

    private function idempotencyService(): IdempotencyService
    {
        return $this->idem ??= new IdempotencyService($this->databaseService()->getConnection());
    }

    private function databaseService(): DatabaseService
    {
        return $this->db ??= Container::getInstance()->get('database');
    }

    private function resolveSessions(): SessionRevokerService
    {
        return $this->sessions ??= Container::getInstance()->get(SessionRevokerService::class);
    }

    /**
     * Extrae y valida los claims del JWT token.
     *
     * @throws HttpException 401 si no hay token o es inválido
     */
    private function requireAuthenticated(): array
    {
        $token = $this->extractBearerToken();

        if ($token === '') {
            throw new HttpException('No autenticado', 401);
        }

        try {
            $claims = (array) $this->jwt->decodeToken($token);
        } catch (\Throwable $e) {
            throw new HttpException('Token inválido o expirado', 401);
        }

        if (empty($claims['tenant_id']) || empty($claims['user_id'])) {
            throw new HttpException('Claims incompletos', 401);
        }

        return $claims;
    }

    /**
     * Extrae el Bearer token del header Authorization.
     */
    private function extractBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Escribe un registro de auditoría de dominio (spec §7.1).
     *
     * Sólo se registran los éxitos: los fallos (400/401/403/404/409/422...)
     * ya quedan registrados con su causa por el hook del Router que instala
     * AuditMiddleware, evitando filas duplicadas por operación.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $entry
     */
    private function auditEvent(
        string $operacion,
        string $tipoCambio,
        array $request,
        array $claims,
        array $entry = []
    ): void {
        if ($this->audit === null) {
            return;
        }

        $this->audit->record(array_merge([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'users',
            'recurso' => 'user',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    /**
     * Lee los datos de entrada desde php://input (JSON) o $_POST (formulario).
     */
    private function getInputData(): array
    {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);

        if (is_array($json)) {
            return $json;
        }

        return $_POST;
    }
}
