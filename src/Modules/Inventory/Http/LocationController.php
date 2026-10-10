<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\LocationService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP para el agregado Location.
 *
 * Endpoints:
 *  - GET    /api/inventory/locations                      → index (listar)
 *  - GET    /api/inventory/locations/by-codigo/{codigo}   → findByCodigo
 *  - GET    /api/inventory/locations/with-capacity        → withCapacity
 *  - GET    /api/inventory/locations/children/{jerarquia} → children
 *  - GET    /api/inventory/locations/{id}                 → show
 *  - POST   /api/inventory/locations                      → store
 *  - PATCH  /api/inventory/locations/{id}                 → update
 *  - DELETE /api/inventory/locations/{id}                 → destroy
 *  - POST   /api/inventory/locations/{id}/activate        → activate
 *  - POST   /api/inventory/locations/{id}/deactivate      → deactivate
 *  - POST   /api/inventory/locations/{id}/restore         → restore
 *
 * Permisos requeridos (aplicados via PermissionMiddleware en routes):
 *  - inventory.location.view    → index, show, findByCodigo, withCapacity, children
 *  - inventory.location.manage  → store, update, destroy, activate, deactivate, restore
 */
final class LocationController
{
    private LocationService $service;
    private ?AuditLogger $audit;

    public function __construct(?LocationService $service = null, ?AuditLogger $audit = null)
    {
        $this->service = $service ?? Container::getInstance()->get(LocationService::class);
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * El logger se resuelve sin romper si el contenedor no está inicializado
     * (tests unitarios que inyectan el service directamente).
     */
    private function resolveAuditLogger(): ?AuditLogger
    {
        try {
            $container = Container::getInstance();

            return $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    /**
     * GET /api/inventory/locations
     * Lista ubicaciones con filtros y paginación.
     */
    public function index(array $request): array
    {
        $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        if (!empty($query['tipo'])) {
            $filters['tipo'] = $query['tipo'];
        }
        if (isset($query['activa'])) {
            $filters['activa'] = filter_var($query['activa'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($query['search'])) {
            $filters['search'] = $query['search'];
        }
        if (!empty($query['jerarquia'])) {
            $filters['jerarquia'] = $query['jerarquia'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->service->findAll($filters, $page, $perPage);

        return $this->json(200, $result);
    }

    /**
     * GET /api/inventory/locations/by-codigo/{codigo}
     * Busca una ubicación por código.
     */
    public function findByCodigo(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $codigo = $params['codigo'] ?? '';
        if ($codigo === '') {
            throw new HttpException('Código de ubicación requerido', 400);
        }

        $location = $this->service->findByCodigo($codigo);
        if ($location === null) {
            throw HttpException::notFound('Ubicación no encontrada');
        }

        return $this->json(200, ['location' => $location]);
    }

    /**
     * GET /api/inventory/locations/with-capacity
     * Lista ubicaciones con capacidad disponible.
     */
    public function withCapacity(array $request): array
    {
        $this->requireAuthenticated($request);

        $locations = $this->service->findWithCapacityAvailable();

        return $this->json(200, ['locations' => $locations]);
    }

    /**
     * GET /api/inventory/locations/children/{jerarquia}
     * Lista ubicaciones hijas de una jerarquía.
     */
    public function children(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $jerarquia = $params['jerarquia'] ?? '';
        if ($jerarquia === '') {
            throw new HttpException('Jerarquía requerida', 400);
        }

        $locations = $this->service->findChildren($jerarquia);

        return $this->json(200, ['locations' => $locations]);
    }

    /**
     * GET /api/inventory/locations/{id}
     * Detalle de una ubicación.
     */
    public function show(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $location = $this->service->findById($id);
        if ($location === null) {
            throw HttpException::notFound('Ubicación no encontrada');
        }

        return $this->json(200, ['location' => $location]);
    }

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    /**
     * POST /api/inventory/locations
     * Crea una nueva ubicación.
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $data = $request['body'] ?? [];

        $location = $this->service->create($data);

        $this->auditEvent('location.create', 'CREATE', $request, $claims, [
            'entidad_id' => is_array($location) ? ($location['id'] ?? null) : null,
            'valores_nuevos' => $location,
        ]);

        return $this->json(201, ['location' => $location]);
    }

    /**
     * PATCH /api/inventory/locations/{id}
     * Actualiza una ubicación existente.
     */
    public function update(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $before = $this->service->findById($id);
        $data = $request['body'] ?? [];

        $location = $this->service->update($id, $data);

        $this->auditEvent('location.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'valores_nuevos' => $location,
        ]);

        return $this->json(200, ['location' => $location]);
    }

    /**
     * DELETE /api/inventory/locations/{id}
     * Eliminación lógica de una ubicación.
     */
    public function destroy(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $before = $this->service->findById($id);
        $this->service->delete($id);

        $this->auditEvent('location.delete', 'DELETE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
        ]);

        return $this->json(200, ['message' => 'Ubicación eliminada correctamente']);
    }

    // ====================================================================
    // ACCIONES DE ESTADO
    // ====================================================================

    /**
     * POST /api/inventory/locations/{id}/activate
     * Activa una ubicación.
     */
    public function activate(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $location = $this->service->activate($id);

        $this->auditEvent('location.activate', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_nuevos' => $location,
        ]);

        return $this->json(200, ['location' => $location]);
    }

    /**
     * POST /api/inventory/locations/{id}/deactivate
     * Desactiva una ubicación.
     */
    public function deactivate(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $location = $this->service->deactivate($id);

        $this->auditEvent('location.deactivate', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_nuevos' => $location,
        ]);

        return $this->json(200, ['location' => $location]);
    }

    /**
     * POST /api/inventory/locations/{id}/restore
     * Restaura una ubicación previamente eliminada.
     */
    public function restore(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de ubicación requerido', 400);
        }

        $this->service->restore($id);

        $this->auditEvent('location.restore', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
        ]);

        return $this->json(200, ['message' => 'Ubicación restaurada correctamente']);
    }

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    /**
     * Escribe un registro de auditoría de dominio (spec §7.1).
     *
     * Sólo los éxitos: los fallos ya los registra el hook del Router
     * (AuditMiddleware) con su código y mensaje.
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
            'modulo' => 'inventory',
            'recurso' => 'location',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    /**
     * Valida que el request tenga claims válidos de usuario autenticado.
     *
     * @throws HttpException 401 si no está autenticado
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        return $claims;
    }

    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
