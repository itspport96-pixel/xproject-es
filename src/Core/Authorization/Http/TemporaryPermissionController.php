<?php

declare(strict_types=1);

namespace App\Core\Authorization\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Entity\TemporaryPermission;
use App\Core\Authorization\Exception\TemporaryPermissionException;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\TemporaryPermissionRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Security\Service\RateLimiter;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use DateTimeImmutable;
use Exception;

/**
 * Permisos temporales (spec §5.3, v1.11.0).
 *
 * Permisos de ruta (sembrados por PlatformControlPermissionSeeder):
 *   temporary.permission.view   → ADMIN, MANAGER, USER, VIEWER
 *   temporary.permission.grant  → sólo ADMIN
 *   temporary.permission.revoke → ADMIN, MANAGER
 *
 * Reglas (ajustes aprobados):
 *   - Otorgar exige escalada: actor con >= permisos efectivos del usuario
 *     beneficiario y sólo ADMIN sobre ADMIN (assertCanActOnUser).
 *   - Otorgar un permiso CRITICAL exige actor con rol ADMIN vigente
 *     (admin-equivalente) y tiene rate limit 2 intentos/hora por actor.
 *   - Revocar sólo requiere el permiso de la ruta (sin escalada).
 *   - Temporalidad: TEMP_PERMISSION_MAX_DAYS (default 30) limita la vigencia.
 */
final class TemporaryPermissionController
{
    private TemporaryPermissionRepositoryInterface $temporales;
    private UserRepositoryInterface $users;
    private PermissionRepositoryInterface $permissions;
    private AuthorizationService $authz;
    private ?AuditLogger $audit;
    private ?RateLimiter $rateLimiter;

    public function __construct(
        ?TemporaryPermissionRepositoryInterface $temporales = null,
        ?UserRepositoryInterface $users = null,
        ?PermissionRepositoryInterface $permissions = null,
        ?AuthorizationService $authz = null,
        ?AuditLogger $audit = null,
        ?RateLimiter $rateLimiter = null
    ) {
        $container = Container::getInstance();
        $this->temporales = $temporales ?? $container->get(TemporaryPermissionRepositoryInterface::class);
        $this->users      = $users      ?? $container->get(UserRepositoryInterface::class);
        $this->permissions = $permissions ?? $container->get(PermissionRepositoryInterface::class);
        $this->authz      = $authz      ?? $container->get(AuthorizationService::class);
        $this->audit      = $audit ?? ($container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null);
        $this->rateLimiter = $rateLimiter;
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * GET /api/temporary-permissions
     * Filtros: ?user_id=UUID&include_inactive=true&page=N&per_page=N
     * Por defecto sólo vigentes (activos y no expirados).
     * v1.13.3: paginación real; responde temporary_permissions, total
     * (total global del filtro) y pagination (contrato de la tabla de
     * usuarios).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $query = (array) ($request['query'] ?? []);

        $userId = isset($query['user_id']) ? trim((string) $query['user_id']) : '';
        $userId = $userId !== '' ? $userId : null;

        $soloVigentes = (($query['include_inactive'] ?? 'false') !== 'true');

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $rows = $this->temporales->findAll($tenantId, $userId, $soloVigentes, $page, $perPage);
        $total = $this->temporales->count($tenantId, $userId, $soloVigentes);

        return $this->json(200, [
            'temporary_permissions' => $rows,
            'total'                 => $total,
            'pagination'            => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'has_next' => ($page - 1) * $perPage + count($rows) < $total,
            ],
        ]);
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    /**
     * POST /api/temporary-permissions
     * Body: user_id, permission_id, motivo, (fecha_expiracion XOR duracion_horas),
     *       recurso_id (opcional).
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $actorId = (string) $claims['user_id'];
        $body = (array) ($request['body'] ?? []);

        $userId = trim((string) ($body['user_id'] ?? ''));
        if ($userId === '') {
            throw TemporaryPermissionException::invalidField('user_id', 'user_id es obligatorio.');
        }

        $permissionId = trim((string) ($body['permission_id'] ?? ''));
        if ($permissionId === '') {
            throw TemporaryPermissionException::invalidField('permission_id', 'permission_id es obligatorio.');
        }

        $motivo = trim((string) ($body['motivo'] ?? ''));
        if ($motivo === '') {
            throw TemporaryPermissionException::invalidField('motivo', 'motivo es obligatorio.');
        }

        $recursoId = trim((string) ($body['recurso_id'] ?? ''));
        $recursoId = $recursoId !== '' ? $recursoId : null;
        if ($recursoId !== null && strlen($recursoId) > 255) {
            throw TemporaryPermissionException::invalidField('recurso_id', 'recurso_id admite máximo 255 caracteres.');
        }

        // El beneficiario debe existir en el mismo tenant.
        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw TemporaryPermissionException::notFound('Usuario no encontrado en este tenant.', 'user_id');
        }

        $permission = $this->permissions->findById($permissionId);
        if ($permission === null) {
            throw TemporaryPermissionException::notFound('Permiso no encontrado.', 'permission_id');
        }
        if (!$permission->isActive()) {
            throw TemporaryPermissionException::invalidField('permission_id', 'No se puede otorgar un permiso inactivo.');
        }

        // Temporalidad (antes del chequeo de duplicado: las validaciones de
        // forma van primero y deben devolver 400, no 409).
        $expiracion = $this->resolveExpiracion($body);
        $now = new DateTimeImmutable('now');
        if ($expiracion <= $now) {
            throw TemporaryPermissionException::invalidField('fecha_expiracion', 'La fecha de expiración debe ser futura.');
        }

        $maxDays = (int) ($_ENV['TEMP_PERMISSION_MAX_DAYS'] ?? 30);
        if ($maxDays > 0 && $expiracion > $now->modify("+{$maxDays} days")) {
            throw TemporaryPermissionException::invalidField(
                'fecha_expiracion',
                "No se pueden otorgar permisos temporales por más de {$maxDays} días."
            );
        }

        // Duplicado vigente (mismo usuario + permiso + recurso) -> 409.
        if ($this->temporales->existsVigente($userId, $permissionId, $recursoId, $tenantId)) {
            throw TemporaryPermissionException::conflict(
                'El usuario ya tiene ese permiso temporal vigente.',
                'permission_id'
            );
        }

        // Escalada (ajuste aprobado): actor >= permisos efectivos del
        // beneficiario; sólo ADMIN sobre ADMIN.
        $this->authz->assertCanActOnUser($actorId, $tenantId, $userId);

        // CRITICAL: sólo admin-equivalente.
        if ($permission->isCritical() && !$this->authz->isActiveAdmin($actorId, $tenantId)) {
            throw TemporaryPermissionException::forbidden(
                'Sólo un usuario con rol ADMIN puede otorgar permisos temporales CRITICAL.'
            );
        }

        // Rate limit de otorgamiento CRITICAL: 2 intentos/hora por actor.
        // Sólo se consume si todas las validaciones previas pasaron.
        if ($permission->isCritical()) {
            $this->rateLimiter()->attempt(
                'temp_permission_grant_critical:' . $actorId,
                2,
                3600
            );
        }

        $temporaryPermission = TemporaryPermission::create(
            id: $this->generateUuid(),
            userId: $userId,
            permissionId: $permissionId,
            recursoId: $recursoId,
            grantedByUserId: $actorId,
            motivo: $motivo,
            fechaInicio: $now,
            fechaExpiracion: $expiracion,
        );
        $this->temporales->save($temporaryPermission);

        // El beneficiario gana el permiso: refrescar su cache.
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('temporary_permission.grant', 'CREATE', $request, $claims, [
            'entidad_id' => $temporaryPermission->id(),
            'valores_nuevos' => [
                'user_id'          => $userId,
                'permission_id'    => $permissionId,
                'identificador'    => $permission->identificador(),
                'recurso_id'       => $recursoId,
                'motivo'           => $motivo,
                'fecha_expiracion' => $expiracion->format('Y-m-d H:i:s'),
            ],
        ]);

        $row = $this->temporales->findById($temporaryPermission->id(), $tenantId);

        return $this->json(201, ['temporary_permission' => $row]);
    }

    /**
     * DELETE /api/temporary-permissions/{id}
     *
     * Revocar sólo requiere el permiso de la ruta (temporary.permission.revoke),
     * sin escalada (ajuste aprobado).
     */
    public function revoke(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];

        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            throw TemporaryPermissionException::invalidField('id', 'ID de permiso temporal requerido.');
        }

        $row = $this->temporales->findById($id, $tenantId);
        if ($row === null) {
            throw TemporaryPermissionException::notFound();
        }

        if (!((bool) $row['activo']) || !$this->temporales->deactivate($id)) {
            throw TemporaryPermissionException::conflict('El permiso temporal ya está revocado.', 'id');
        }

        // El beneficiario pierde el permiso: refrescar su cache.
        $this->authz->invalidateCache((string) $row['user_id'], $tenantId);

        $this->auditEvent('temporary_permission.revoke', 'UPDATE', $request, $claims, [
            'entidad_id' => $id,
            'valores_anteriores' => [
                'user_id'       => (string) $row['user_id'],
                'permission_id' => (string) $row['permission_id'],
                'activo'        => 1,
            ],
        ]);

        return $this->json(200, [
            'message'                 => 'Permiso temporal revocado',
            'temporary_permission_id' => $id,
        ]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Resuelve la fecha de expiración desde fecha_expiracion (parseable) o
     * duracion_horas (entero positivo). Exclusión mutua.
     *
     * @param array<string, mixed> $body
     * @throws TemporaryPermissionException 400 si falta, es inválida o son exclusivos.
     */
    private function resolveExpiracion(array $body): DateTimeImmutable
    {
        $fecha = trim((string) ($body['fecha_expiracion'] ?? ''));
        $duracion = trim((string) ($body['duracion_horas'] ?? ''));

        $tieneFecha = $fecha !== '';
        $tieneDuracion = $duracion !== '';

        if ($tieneFecha && $tieneDuracion) {
            throw TemporaryPermissionException::invalidField(
                'fecha_expiracion',
                'Indica sólo uno de fecha_expiracion o duracion_horas.'
            );
        }
        if (!$tieneFecha && !$tieneDuracion) {
            throw TemporaryPermissionException::invalidField(
                'fecha_expiracion',
                'Indica fecha_expiracion o duracion_horas.'
            );
        }

        if ($tieneDuracion) {
            $horas = filter_var($duracion, FILTER_VALIDATE_INT);
            if ($horas === false || (int) $horas <= 0) {
                throw TemporaryPermissionException::invalidField(
                    'duracion_horas',
                    'duracion_horas debe ser un entero positivo (horas).'
                );
            }

            return (new DateTimeImmutable('now'))->modify('+' . (int) $horas . ' hours');
        }

        try {
            return new DateTimeImmutable($fecha);
        } catch (Exception) {
            throw TemporaryPermissionException::invalidField('fecha_expiracion', 'Formato de fecha_expiracion inválido.');
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     * @throws HttpException 401 si no está autenticado.
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        return $claims;
    }

    private function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = Container::getInstance()->get(RateLimiter::class);
        }

        return $this->rateLimiter;
    }

    /**
     * Auditoría de dominio (spec §7.1), sólo éxitos.
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
            'modulo' => 'permisos_temporales',
            'recurso' => 'temporary_permission',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, data: array<string, mixed>}
     */
    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
