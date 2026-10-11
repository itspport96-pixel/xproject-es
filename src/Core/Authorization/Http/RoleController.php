<?php

declare(strict_types=1);

namespace App\Core\Authorization\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Authorization\Entity\Role;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP para la gestión de roles.
 *
 * Los roles llevan tenant_id: los BUILT_IN (tenant_id NULL) son visibles
 * para todos los tenants; los CUSTOM sólo se listan/leen/editan/borrar
 * desde su propio tenant.
 * Solo usuarios con rol ADMIN pueden crear/modificar/eliminar roles.
 * Los roles BUILT_IN no se pueden eliminar ni modificar su nombre/tipo.
 */
final class RoleController
{
    private RoleRepositoryInterface $roles;
    private AuthorizationService $auth;
    private ?AuditLogger $audit;

    public function __construct(
        ?RoleRepositoryInterface $roles = null,
        ?AuthorizationService $auth = null,
        ?AuditLogger $audit = null
    ) {
        $container = Container::getInstance();
        $this->roles = $roles ?? $container->get(RoleRepositoryInterface::class);
        $this->auth  = $auth  ?? $container->get(AuthorizationService::class);
        $this->audit = $audit ?? ($container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null);
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * GET /api/roles
     * Lista los roles visibles: BUILT_IN + CUSTOM del tenant actual.
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $soloActivos = ($request['query']['include_inactive'] ?? 'false') !== 'true';

        $roles = $this->roles->findAll($soloActivos, (string) $claims['tenant_id']);

        return $this->json(200, [
            'roles' => array_map(fn(Role $r) => $r->toArray(), $roles),
        ]);
    }

    /**
     * GET /api/roles/{id}
     * Detalle de un rol específico.
     */
    public function show(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de rol requerido', 400);
        }

        $role = $this->roles->findById($id, (string) $claims['tenant_id']);
        if ($role === null) {
            throw new HttpException('Rol no encontrado', 404);
        }

        return $this->json(200, ['role' => $role->toArray()]);
    }

    /**
     * GET /api/roles/{id}/permissions
     * Lista los permisos asignados a un rol.
     */
    public function getPermissions(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de rol requerido', 400);
        }

        $role = $this->roles->findById($id, (string) $claims['tenant_id']);
        if ($role === null) {
            throw new HttpException('Rol no encontrado', 404);
        }

        return $this->json(200, [
            'role_id'   => $role->id(),
            'nombre'    => $role->nombre(),
            'permisos'  => $role->permisos(),
        ]);
    }

    // ========================================================================
    // ESCRITURA (solo ADMIN)
    // ========================================================================

    /**
     * POST /api/roles
     * Crea un nuevo rol CUSTOM.
     */
    public function store(array $request): array
    {
        $claims = $this->requireAdmin($request);
        $data = $request['body'] ?? [];

        $nombre      = (string) ($data['nombre'] ?? '');
        $descripcion = isset($data['descripcion']) ? (string) $data['descripcion'] : null;
        $permisos    = (array) ($data['permisos'] ?? []);
        $tenantId    = (string) $claims['tenant_id'];

        if ($nombre === '') {
            throw new HttpException('El nombre del rol es obligatorio', 400);
        }

        // Unicidad en el ámbito visible: BUILT_IN (cualquier tenant) + este tenant
        if ($this->roles->existsByNombre($nombre, $tenantId)) {
            throw new HttpException("El rol '{$nombre}' ya existe", 409);
        }

        $role = Role::create(
            id: $this->generateUuid(),
            nombre: $nombre,
            descripcion: $descripcion,
            tipo: Role::TYPE_CUSTOM,
            permisos: $permisos,
            tenantId: $tenantId,
        );

        $this->roles->save($role);

        // Rol recién creado: sin role_assignments, nada que invalidar en cache.

        $this->auditEvent('role.create', 'CREATE', $request, $claims, [
            'entidad_id' => $role->id(),
            'valores_nuevos' => $role->toArray(),
        ]);

        return $this->json(201, ['role' => $role->toArray()]);
    }

    /**
     * PATCH /api/roles/{id}
     * Actualiza un rol CUSTOM (nombre, descripción, activo).
     */
    public function update(array $request, array $params): array
    {
        $claims = $this->requireAdmin($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de rol requerido', 400);
        }

        $role = $this->roles->findById($id, (string) $claims['tenant_id']);
        if ($role === null) {
            throw new HttpException('Rol no encontrado', 404);
        }

        $data = $request['body'] ?? [];
        $before = $role->toArray();

        // Guard: los roles BUILT_IN no son renombrables (spec §5.1).
        if (isset($data['nombre']) && $role->isBuiltIn()) {
            throw new HttpException('Los roles BUILT_IN no pueden ser renombrados', 403);
        }

        // Guard: el rol ADMIN no puede desactivarse (perdería todos sus permisos
        // efectivos; extensión de la regla "ADMIN no puede perder permisos").
        if ($role->nombre() === Role::BUILT_IN_ADMIN
            && array_key_exists('activo', $data) && !(bool) $data['activo']) {
            throw new HttpException('El rol ADMIN no puede desactivarse', 403);
        }

        // Solo se puede modificar nombre y descripción de roles CUSTOM
        if (isset($data['nombre'])) {
            $role->actualizarNombre((string) $data['nombre']);
        }
        if (array_key_exists('descripcion', $data)) {
            $role->actualizarDescripcion(
                $data['descripcion'] !== null ? (string) $data['descripcion'] : null
            );
        }
        if (isset($data['activo'])) {
            (bool) $data['activo'] ? $role->activar() : $role->desactivar();
        }

        $this->roles->update($role);

        // Invalidar cache
        $this->auth->invalidateCacheForRole((string) $id, (string) $claims['tenant_id']);

        $this->auditEvent('role.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'valores_nuevos' => $role->toArray(),
        ]);

        return $this->json(200, ['role' => $role->toArray()]);
    }

    /**
     * PATCH /api/roles/{id}/permissions
     * Reemplaza los permisos de un rol CUSTOM.
     */
    public function updatePermissions(array $request, array $params): array
    {
        $claims = $this->requireAdmin($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de rol requerido', 400);
        }

        $role = $this->roles->findById($id, (string) $claims['tenant_id']);
        if ($role === null) {
            throw new HttpException('Rol no encontrado', 404);
        }

        $permisos = (array) ($request['body']['permisos'] ?? []);
        $permisos = array_map('strval', $permisos);
        $before = $role->permisos();

        // Guard: el rol ADMIN no puede perder permisos (spec ajuste F3).
        if ($role->nombre() === Role::BUILT_IN_ADMIN) {
            $perdidos = array_diff($before, $permisos);
            if ($perdidos !== []) {
                throw new HttpException('El rol ADMIN no puede perder permisos', 403);
            }
        }

        $role->reemplazarPermisos($permisos);

        $this->roles->update($role);

        // Invalidar cache (los permisos cambiaron, todos los usuarios con este rol se ven afectados)
        $this->auth->invalidateCacheForRole((string) $id, (string) $claims['tenant_id']);

        $this->auditEvent('role.permissions.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => ['permisos' => $before],
            'valores_nuevos' => ['permisos' => $permisos],
        ]);

        return $this->json(200, ['role' => $role->toArray()]);
    }

    /**
     * DELETE /api/roles/{id}
     * Soft delete de un rol CUSTOM.
     */
    public function destroy(array $request, array $params): array
    {
        $claims = $this->requireAdmin($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de rol requerido', 400);
        }

        $role = $this->roles->findById($id, (string) $claims['tenant_id']);
        if ($role === null) {
            throw new HttpException('Rol no encontrado', 404);
        }

        $this->roles->softDelete($id, (string) $claims['tenant_id']);

        // Invalidar cache
        $this->auth->invalidateCacheForRole((string) $id, (string) $claims['tenant_id']);

        // v1.13.1: auditoría de dominio del borrado con los permisos previos
        // del rol (antes sólo quedaba la fila genérica de la ruta).
        $after = $this->roles->findRawIncludingDeleted((string) $id);
        $this->auditEvent('role.delete', 'DELETE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $role->toArray(),
            'detalles_adicionales' => ['valores_despues' => $after],
        ]);

        return $this->json(200, ['message' => 'Rol eliminado correctamente']);
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * Valida que el request tenga claims válidos.
     *
     * @throws HttpException 401 si no está autenticado.
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        return $claims;
    }

    /**
     * Valida que el usuario tenga rol ADMIN.
     * La gestión de roles es crítica (visible por todos los tenants vía
     * BUILT_IN y con efecto en asignaciones) → sólo ADMIN puede gestionarla.
     *
     * v1.12.1: se comprueba en la BD (role_assignments vigente), nunca en
     * los claims del token: quitar ADMIN surte efecto en la siguiente
     * petición, no al caducar el token.
     *
     * @throws HttpException 403 si no es ADMIN.
     */
    private function requireAdmin(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        if (!$this->auth->hasAdminRole((string) $claims['user_id'], (string) $claims['tenant_id'])) {
            throw new HttpException('Se requiere rol ADMIN para gestionar roles', 403);
        }

        return $claims;
    }

    /**
     * Escribe un registro de auditoría de dominio (spec §7.1).
     *
     * Sólo los éxitos: los fallos ya los registra el hook del Router que
     * instala AuditMiddleware (con su código y mensaje de error).
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
            'modulo' => 'roles',
            'recurso' => 'role',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

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
