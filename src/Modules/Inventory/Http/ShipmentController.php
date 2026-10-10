<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\AuthorizeShipmentUseCase;
use App\Modules\Inventory\Application\Services\LoadShipmentUseCase;
use App\Modules\Inventory\Application\Services\ShipmentQueryService;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Transporte / Shipment (CU-005, CU-006).
 *
 * Endpoints:
 *  - GET  /api/inventory/shipments        → listado paginado (v1.7.0)
 *  - GET  /api/inventory/shipments/{id}   → detalle con items (v1.7.0)
 *  - POST /api/inventory/shipments/load   → carga unidades (CU-005)
 *  - POST /api/inventory/shipments/authorize → autoriza la salida (CU-006)
 *
 * Permisos (aplicados en routes):
 *  - inventory.shipping.view    → index, show
 *  - inventory.shipping.create  → load
 *  - inventory.shipping.confirm → authorize
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO en load y authorize.
 *
 * Auditoría: cada escritura escribe su fila de dominio en `audit_logs`
 * DESPUÉS de confirmar la transacción (patrón ReceivingController); los
 * errores ya los registra AuditMiddleware.
 */
final class ShipmentController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private LoadShipmentUseCase $loadUseCase;
    private AuthorizeShipmentUseCase $authorizeUseCase;
    private ?ShipmentQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?LoadShipmentUseCase $load = null,
        ?AuthorizeShipmentUseCase $authorize = null,
        ?ShipmentQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        if ($load === null || $authorize === null) {
            $container = Container::getInstance();
            $load = $load ?? $container->get(LoadShipmentUseCase::class);
            $authorize = $authorize ?? $container->get(AuthorizeShipmentUseCase::class);
        }

        $this->loadUseCase = $load;
        $this->authorizeUseCase = $authorize;
        // Resuelto bajo demanda en index/show: las escrituras (y sus tests)
        // no deben depender de que el contenedor tenga el servicio de consulta.
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/shipments
     *
     * Query string: estado, fecha_desde, fecha_hasta, page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(ShipmentQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/shipments/{id}
     */
    public function show(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $shipmentId = (string) ($request['params']['id'] ?? '');

        if ($shipmentId === '') {
            throw \App\Modules\Inventory\Domain\Exceptions\InventoryException::requiredField('shipment_id');
        }

        $service = $this->queryService ?? Container::getInstance()->get(ShipmentQueryService::class);
        $result = $service->show((string) $claims['tenant_id'], $shipmentId);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/shipments/load (CU-005)
     */
    public function load(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->loadUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'shipment.load', 'CREATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/shipments/authorize (CU-006)
     */
    public function authorize(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->authorizeUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'shipment.authorize', 'UPDATE', $key, $response);
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
            'recurso' => 'shipment',
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
