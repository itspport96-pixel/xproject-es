<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ReceiveDocumentUseCase;
use App\Modules\Inventory\Application\Services\ReceivingDocumentService;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP para los documentos de recepción planificada (plan) y la
 * recepción contra documento (v1.13.32: sin cegueza ni reconteo).
 *
 * Endpoints:
 *  - GET   /api/inventory/receiving/documents       → index (listar)
 *  - GET   /api/inventory/receiving/documents/pending → pending (para el receptor)
 *  - POST  /api/inventory/receiving/documents       → store (crear)
 *  - GET   /api/inventory/receiving/documents/{id}  → show
 *  - PATCH /api/inventory/receiving/documents/{id}  → update
 *  - POST  /api/inventory/receiving/documents/{id}/cancel → cancel
 *  - POST  /api/inventory/receiving/documents/{id}/receive → receive
 *
 * Permisos (aplicados via PermissionMiddleware en routes):
 *  - inventory.receiving.plan   → los 5 endpoints de planificación
 *  - inventory.receiving.create → pending y receive (recepción)
 */
final class ReceivingDocumentController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private ReceivingDocumentService $service;
    private ReceiveDocumentUseCase $receiveUseCase;
    private ?AuditLogger $audit;

    public function __construct(
        ?ReceivingDocumentService $service = null,
        ?ReceiveDocumentUseCase $receiveUseCase = null,
        ?AuditLogger $audit = null
    ) {
        $this->service = $service ?? Container::getInstance()->get(ReceivingDocumentService::class);
        $this->receiveUseCase = $receiveUseCase ?? Container::getInstance()->get(ReceiveDocumentUseCase::class);
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
     * GET /api/inventory/receiving/documents/pending
     *
     * Documentos PLANIFICADA/EN_RECEPCION del tenant con sus líneas y
     * cantidad_esperada, excluyendo los creados por el usuario autenticado
     * (v1.13.32: el receptor ve el esperado; la UI lo muestra bloqueado).
     */
    public function pending(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->service->findPendingForReceiver((string) $claims['user_id'], $page, $perPage);

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
    // RECEPCIÓN CONTRA DOCUMENTO
    // ====================================================================

    /**
     * POST /api/inventory/receiving/documents/{id}/receive
     *
     * El receptor envía lo que cuenta (`cantidad_recibida`); el esperado lo
     * conoce el documento (la UI lo muestra bloqueado y el payload no lo
     * admite). Requiere Idempotency-Key.
     */
    public function receive(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw new HttpException('ID de documento requerido', 400);
        }

        $key = $this->idempotencyKey($request);

        $response = $this->receiveUseCase->execute(
            (string) $id,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditReceiving($request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
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
     * Auditoría de dominio de la recepción contra documento (v1.13.32).
     *
     * Sólo en éxito: los errores (400/403/404/409/422) ya los registra el hook
     * del Router (AuditMiddleware) con su causa.
     *
     * `valores_anteriores` guarda el detalle interno `_auditoria` (número de
     * documento, ubicación, planificador, receptor, rechazo_total y el
     * detalle por línea con line_id, esperada y recibida). El marcador NO se
     * filtra al cliente: `useCaseResponse()` sólo copia `status` y `data`.
     */
    private function auditReceiving(array $request, array $claims, string $key, array $response): void
    {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $auditoria = is_array($response['_auditoria'] ?? null) ? $response['_auditoria'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => 'receiving.document.receive',
            'modulo' => 'inventory',
            'recurso' => 'receiving_document',
            'entidad_id' => is_string($data['documento_id'] ?? null) ? $data['documento_id'] : null,
            'tipo_cambio' => 'UPDATE',
            'resultado' => 'SUCCESS',
            'valores_nuevos' => $data,
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
            'detalles_adicionales' => [
                'idempotency_key' => $key,
                'codigo_respuesta' => $response['status'] ?? null,
                'documento_numero' => $auditoria['numero_documento'] ?? null,
                'ubicacion_codigo' => $auditoria['ubicacion_codigo'] ?? null,
                'creado_por' => $auditoria['creado_por'] ?? null,
                'recibido_por' => $auditoria['recibido_por'] ?? $claims['user_id'] ?? null,
                'rechazo_total' => $auditoria['rechazo_total'] ?? false,
                'lineas' => $auditoria['lineas'] ?? [],
            ],
        ]);
    }

    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
