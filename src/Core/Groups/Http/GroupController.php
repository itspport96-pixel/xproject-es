<?php

declare(strict_types=1);

namespace App\Core\Groups\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Groups\Entity\UserGroup;
use App\Core\Groups\Exception\GroupException;
use App\Core\Groups\Repository\UserGroupRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * Gestión de grupos de usuarios (spec §4.2, v1.11.0).
 *
 * Permisos de ruta (sembrados por PlatformControlPermissionSeeder):
 *   groups.group.view / create / edit / delete / manage_members / manage_roles
 *
 * Escalada (ajuste aprobado): asignar un rol a un grupo exige que el actor
 * posea los permisos del rol destino y los permisos efectivos de CADA
 * miembro del grupo, y sólo un ADMIN puede hacerlo sobre miembros ADMIN.
 */
final class GroupController
{
    private UserGroupRepositoryInterface $groups;
    private UserRepositoryInterface $users;
    private RoleRepositoryInterface $roles;
    private AuthorizationService $authz;
    private ?IdempotencyService $idem;
    private ?DatabaseService $db;
    private ?AuditLogger $audit;

    public function __construct(
        ?UserGroupRepositoryInterface $groups = null,
        ?UserRepositoryInterface $users = null,
        ?RoleRepositoryInterface $roles = null,
        ?AuthorizationService $authz = null,
        ?IdempotencyService $idem = null,
        ?DatabaseService $db = null,
        ?AuditLogger $audit = null
    ) {
        $container = Container::getInstance();
        $this->groups = $groups ?? $container->get(UserGroupRepositoryInterface::class);
        $this->users  = $users  ?? $container->get(UserRepositoryInterface::class);
        $this->roles  = $roles  ?? $container->get(RoleRepositoryInterface::class);
        $this->authz  = $authz  ?? $container->get(AuthorizationService::class);
        $this->idem   = $idem;
        $this->db     = $db;
        $this->audit  = $audit ?? ($container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null);
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * GET /api/groups
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $soloActivos = (($request['query']['include_inactive'] ?? 'false') !== 'true');

        $groups = $this->groups->findAll((string) $claims['tenant_id'], $soloActivos);

        // v1.13.0: conteo de miembros en la misma consulta (fin del N+1).
        $counts = $this->groups->memberCounts((string) $claims['tenant_id']);

        return $this->json(200, [
            'groups' => array_map(static function (UserGroup $g) use ($counts): array {
                $arr = $g->toArray();
                $arr['miembros'] = (int) ($counts[$g->id()] ?? 0);

                return $arr;
            }, $groups),
        ]);
    }

    /**
     * GET /api/groups/{id}
     */
    public function show(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $group = $this->findGroup($params, $claims);

        return $this->json(200, ['group' => $group->toArray()]);
    }

    /**
     * GET /api/groups/{id}/members
     */
    public function members(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $group = $this->findGroup($params, $claims);

        $members = $this->groups->listMembers($group->id());

        return $this->json(200, [
            'group_id' => $group->id(),
            'members'  => $members,
            'total'    => count($members),
        ]);
    }

    /**
     * GET /api/groups/{id}/roles
     */
    public function roles(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $group = $this->findGroup($params, $claims);

        $roles = $this->groups->listRoles($group->id());

        return $this->json(200, [
            'group_id' => $group->id(),
            'roles'    => $roles,
            'total'    => count($roles),
        ]);
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    /**
     * POST /api/groups (Idempotency-Key opcional, patrón POST /api/users)
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $data = (array) ($request['body'] ?? []);

        $key = $this->idempotencyKey($request);
        $operacion = 'POST /api/groups';
        $propietarioId = (string) $claims['user_id'];

        // Las validaciones (nombre vacío/duplicado) corren DENTRO del cierre:
        // en un replay de idempotencia el grupo ya existe y no deben dispararse.
        $create = function () use ($tenantId, $data, $propietarioId): array {
            $nombre = trim((string) ($data['nombre'] ?? ''));
            $descripcion = isset($data['descripcion']) ? (string) $data['descripcion'] : null;

            if ($nombre === '') {
                throw GroupException::requiredField('nombre');
            }
            if ($this->groups->existsByNombre($tenantId, $nombre)) {
                throw GroupException::nombreDuplicado($nombre);
            }

            $group = UserGroup::create(
                id: $this->generateUuid(),
                tenantId: $tenantId,
                nombre: $nombre,
                descripcion: $descripcion,
                propietarioId: $propietarioId,
            );
            $this->groups->save($group);

            return ['status' => 201, 'data' => ['group' => $group->toArray()]];
        };

        if ($key !== null) {
            $idem = $this->idempotencyService();
            $hash = IdempotencyService::requestHash($data);
            $actorId = (string) $claims['user_id'];

            $result = $this->databaseService()->transaction(
                function () use ($idem, $key, $operacion, $hash, $tenantId, $actorId, $create): array {
                    $replay = $idem->tryBegin($tenantId, $key, $operacion, $hash, $actorId);

                    if ($replay !== null) {
                        return $replay + ['_replay' => true];
                    }

                    $response = $create();
                    $idem->complete($tenantId, $key, $operacion, $response, 201);

                    return $response;
                }
            );

            if (empty($result['_replay'])) {
                $this->auditEvent('group.create', 'CREATE', $request, $claims, [
                    'entidad_id' => $result['data']['group']['id'] ?? null,
                    'valores_nuevos' => $result['data']['group'] ?? null,
                ]);
            }

            return $result;
        }

        $response = $create();
        $this->auditEvent('group.create', 'CREATE', $request, $claims, [
            'entidad_id' => $response['data']['group']['id'] ?? null,
            'valores_nuevos' => $response['data']['group'] ?? null,
        ]);

        return $response;
    }

    /**
     * PATCH /api/groups/{id}
     */
    public function update(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);

        $data = (array) ($request['body'] ?? []);
        $before = $group->toArray();

        if (isset($data['nombre'])) {
            $nombre = trim((string) $data['nombre']);
            if ($this->groups->existsByNombre($tenantId, $nombre, $group->id())) {
                throw GroupException::nombreDuplicado($nombre);
            }
            $group->actualizarNombre($nombre);
        }
        if (array_key_exists('descripcion', $data)) {
            $group->actualizarDescripcion(
                $data['descripcion'] !== null ? (string) $data['descripcion'] : null
            );
        }
        if (isset($data['activo'])) {
            (bool) $data['activo'] ? $group->activar() : $group->desactivar();
        }

        $this->groups->update($group);

        // El estado (activo) altera los permisos heredados por los miembros.
        if ($before['activo'] !== $group->activo()) {
            $this->authz->invalidateCacheForGroup($group->id(), $tenantId);
        }

        $this->auditEvent('group.update', 'UPDATE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_anteriores' => $before,
            'valores_nuevos' => $group->toArray(),
        ]);

        return $this->json(200, ['group' => $group->toArray()]);
    }

    /**
     * DELETE /api/groups/{id} (soft delete)
     */
    public function destroy(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);

        $this->groups->softDelete($group->id());
        $this->authz->invalidateCacheForGroup($group->id(), $tenantId);

        $after = $this->groups->findRawIncludingDeleted($group->id());
        $this->auditEvent('group.delete', 'DELETE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_anteriores' => $group->toArray(),
            'detalles_adicionales' => ['valores_despues' => $after],
        ]);

        return $this->json(200, ['message' => 'Grupo eliminado correctamente']);
    }

    /**
     * POST /api/groups/{id}/members
     */
    public function addMember(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);

        $userId = (string) (($request['body']['user_id'] ?? '') ?: '');
        if ($userId === '') {
            throw GroupException::requiredField('user_id');
        }

        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw GroupException::userNotFound($userId);
        }

        $this->groups->addMember(
            $this->generateUuid(),
            $group->id(),
            $userId,
            (string) $claims['user_id']
        );

        // El miembro gana los permisos heredados del grupo: refrescar su cache.
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('group.members.add', 'UPDATE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_nuevos' => ['user_id' => $userId],
        ]);

        return $this->json(201, [
            'group_id' => $group->id(),
            'user_id'  => $userId,
        ]);
    }

    /**
     * DELETE /api/groups/{id}/members/{userId}
     */
    public function removeMember(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);

        $userId = (string) ($params['userId'] ?? '');
        if ($userId === '') {
            throw GroupException::requiredField('user_id');
        }

        $this->groups->removeMember($group->id(), $userId);

        // El miembro pierde los permisos heredados del grupo.
        $this->authz->invalidateCache($userId, $tenantId);

        $this->auditEvent('group.members.remove', 'UPDATE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_anteriores' => ['user_id' => $userId],
        ]);

        return $this->json(200, ['message' => 'Miembro eliminado del grupo']);
    }

    /**
     * POST /api/groups/{id}/roles (con escalada)
     */
    public function assignRole(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);
        $actorId = (string) $claims['user_id'];

        $roleId = (string) (($request['body']['role_id'] ?? '') ?: '');
        if ($roleId === '') {
            throw GroupException::requiredField('role_id');
        }

        $role = $this->roles->findById($roleId, $tenantId);
        if ($role === null || $role->isDeleted()) {
            throw GroupException::roleNotFound($roleId);
        }

        // Escalada: el rol debe estar contenido en los permisos del actor...
        $this->authz->assertCanAssignRole($actorId, $tenantId, $roleId);

        // ...y el actor debe poder actuar sobre cada miembro del grupo
        // (permisos efectivos >= miembro; sólo ADMIN sobre miembros ADMIN).
        foreach ($this->groups->memberUserIds($group->id()) as $memberId) {
            $this->authz->assertCanActOnUser($actorId, $tenantId, $memberId);
        }

        $this->groups->assignRole($this->generateUuid(), $group->id(), $roleId);

        // Los miembros ganan permisos: invalidar el cache de todos.
        $this->authz->invalidateCacheForGroup($group->id(), $tenantId);

        $this->auditEvent('group.roles.assign', 'UPDATE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_nuevos' => ['role_id' => $roleId],
        ]);

        return $this->json(201, [
            'group_id' => $group->id(),
            'role_id'  => $roleId,
        ]);
    }

    /**
     * DELETE /api/groups/{id}/roles/{roleId}
     *
     * Quitar privilegios: sólo requiere el permiso de la ruta (manage_roles),
     * sin escalada (ajuste aprobado).
     */
    public function removeRole(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $tenantId = (string) $claims['tenant_id'];
        $group = $this->findGroup($params, $claims);

        $roleId = (string) ($params['roleId'] ?? '');
        if ($roleId === '') {
            throw GroupException::requiredField('role_id');
        }

        $this->groups->removeRole($group->id(), $roleId);

        $this->authz->invalidateCacheForGroup($group->id(), $tenantId);

        $this->auditEvent('group.roles.remove', 'UPDATE', $request, $claims, [
            'entidad_id' => $group->id(),
            'valores_anteriores' => ['role_id' => $roleId],
        ]);

        return $this->json(200, ['message' => 'Rol eliminado del grupo']);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * @param array<string, mixed> $request
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

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $claims
     * @throws GroupException 404 si el grupo no existe en el tenant.
     */
    private function findGroup(array $params, array $claims): UserGroup
    {
        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            throw GroupException::invalidField('id', 'ID de grupo requerido');
        }

        $group = $this->groups->findById($id, (string) $claims['tenant_id']);
        if ($group === null || $group->isDeleted()) {
            throw GroupException::groupNotFound($id);
        }

        return $group;
    }

    /**
     * Header Idempotency-Key opcional (mismo comportamiento que UserController).
     *
     * @return string|null null si la cabecera no está presente.
     * @throws GroupException 400 si es inválida.
     */
    private function idempotencyKey(array $request): ?string
    {
        $headers = (array) ($request['headers'] ?? []);
        $headers = array_change_key_case($headers, CASE_LOWER);

        $key = $headers['idempotency-key'] ?? null;
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            return null;
        }
        if (strlen($key) > 64) {
            throw GroupException::idempotencyKeyInvalid('Máximo 64 caracteres.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw GroupException::idempotencyKeyInvalid('Sólo se permiten letras, números y los caracteres . _ : -.');
        }

        return $key;
    }

    private function idempotencyService(): IdempotencyService
    {
        if ($this->idem === null) {
            $this->idem = Container::getInstance()->get(IdempotencyService::class);
        }

        return $this->idem;
    }

    private function databaseService(): DatabaseService
    {
        if ($this->db === null) {
            $this->db = Container::getInstance()->get('database');
        }

        return $this->db;
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
            'modulo' => 'grupos',
            'recurso' => 'group',
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
