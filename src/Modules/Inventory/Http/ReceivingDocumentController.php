<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ReceivingDocumentService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP para los documentos de recepción planificada (BLOQUE R1).
 *
 * Endpoints:
 *  - GET   /api/inventory/receiving/documents       → index (listar)
 *  - POST  /api/inventory/receiving/documents       → store (crear)
 *  - GET   /api/inventory/receiving/documents/{id}  → show
 *  - PATCH /api/inventory/receiving/documents/{id}  → update
 *  - POST  /api/inventory/receiving/documents/{id}/cancel → cancel
 *
 * Permisos (aplicados via PermissionMiddleware en routes):
 *  - inventory.receiving.plan → los 5 endpoints
 */
final class ReceivingDocumentController
{
    private ReceivingDocumentService $service;
    private ?AuditLogger $audit;

    public function __construct(?ReceivingDocumentService $service = null, ?AuditLogger $audit = null)
    {
        $this->service = $service ?? Container::getInstance()->get(ReceivingDocumentService::class);
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

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
     * GET /api/inventory/receiving/documents
     */
    public function index(array $request): array
    {
        $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        if (!empty($query['estado'])) {
            $filters['estado'] = $query['estado'];
        }
        if (!empty($query['search'])) {
            $filters['search'] = $query['search'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->service->findAll($filters, $page, $perPage);

        return $this->json(200, $result);
    }

    /**
     * GET /api/inventory/receiving/documents/{id}
     */
    public function show(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de documento requerido', 400);
        }

        $document = $this->service->findById($id);
        if ($document === null) {
            throw HttpException::notFound('Documento de recepción no encontrado');
        }

        return $this->json(200, ['documento' => $document]);
    }

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    /**
     * POST /api/inventory/receiving/documents
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $data = $request['body'] ?? [];
        $actorId = (string) $claims['user_id'];

        $document = $this->service->create($data, $actorId);

        $this->auditEvent('receiving.document.create', 'CREATE', $request, $claims, [
            'entidad_id' => $document['id'] ?? null,
            'valores_nuevos' => $document,
        ]);

        return $this->json(201, ['documento' => $document]);
    }

    /**
     * PATCH /api/inventory/receiving/documents/{id}
     */
    public function update(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de documento requerido', 400);
        }

        $before = $this->service->findById($id);
        $data = $request['body'] ?? [];

        $document = $this->service->update($id, $data);

        $this->auditEvent('receiving.document.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'valores_nuevos' => $document,
        ]);

        return $this->json(200, ['documento' => $document]);
    }

    /**
     * POST /api/inventory/receiving/documents/{id}/cancel
     */
    public function cancel(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de documento requerido', 400);
        }

        $before = $this->service->findById($id);
        $document = $this->service->cancel($id);

        $this->auditEvent('receiving.document.cancel', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'valores_nuevos' => $document,
        ]);

        return $this->json(200, ['documento' => $document]);
    }

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    /**
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
            'recurso' => 'receiving_document',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    /**
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
