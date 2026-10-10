<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ConfirmDeliveryUseCase;
use App\Modules\Inventory\Application\Services\DeliveryQueryService;
use App\Modules\Inventory\Application\Services\RegisterDeliveryFailureUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Entregas (CU-007, CU-008).
 *
 * Endpoints:
 *  - GET  /api/inventory/deliveries          → listado paginado (v1.8.0)
 *  - POST /api/inventory/deliveries/confirm  → confirma la entrega (CU-007)
 *  - POST /api/inventory/deliveries/failed   → registra el fallo (CU-008)
 *
 * Permisos (aplicados en routes):
 *  - inventory.delivery.view    → index
 *  - inventory.delivery.confirm → confirm, failed
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO en confirm y failed.
 *
 * Auditoría: cada escritura escribe su fila de dominio en `audit_logs`
 * DESPUÉS de confirmar la transacción (patrón ShipmentController); los
 * errores ya los registra AuditMiddleware. Un replay NO escribe fila nueva.
 */
final class DeliveryController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private ConfirmDeliveryUseCase $confirmUseCase;
    private RegisterDeliveryFailureUseCase $failureUseCase;
    private ?DeliveryQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?ConfirmDeliveryUseCase $confirm = null,
        ?RegisterDeliveryFailureUseCase $failure = null,
        ?DeliveryQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        if ($confirm === null || $failure === null) {
            $container = Container::getInstance();
            $confirm = $confirm ?? $container->get(ConfirmDeliveryUseCase::class);
            $failure = $failure ?? $container->get(RegisterDeliveryFailureUseCase::class);
        }

        $this->confirmUseCase = $confirm;
        $this->failureUseCase = $failure;
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/deliveries
     *
     * Query string: estado, fecha_desde, fecha_hasta, shipment_id,
     * unidad_fisica_id, page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'fecha_desde', 'fecha_hasta', 'shipment_id', 'unidad_fisica_id'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(DeliveryQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/deliveries/confirm (CU-007)
     */
    public function confirm(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->confirmUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'delivery.confirm', 'UPDATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/deliveries/failed (CU-008)
     */
    public function failed(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->failureUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'delivery.failed', 'UPDATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    // ====================================================================
    // AUDITORÍA DE DOMINIO
    // ====================================================================

    /**
     * Fila de auditoría por operación de escritura, sólo en éxito (los
     * errores ya los registra AuditMiddleware con su causa).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $response
     */
    private function auditOperation(
        array $request,
        array $claims,
        string $operacion,
        string $tipoCambio,
        ?string $key,
        array $response
    ): void {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $shipment = is_array($data['shipment'] ?? null) ? $data['shipment'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'delivery',
            'entidad_id' => is_string($shipment['shipment_id'] ?? null) ? $shipment['shipment_id'] : null,
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'valores_nuevos' => $data,
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
            'detalles_adicionales' => [
                'idempotency_key' => $key,
                'codigo_respuesta' => $response['status'] ?? null,
            ],
        ]);
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
}
