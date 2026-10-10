<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\RegisterReturnUseCase;
use App\Modules\Inventory\Application\Services\ReturnQueryService;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Devoluciones (CU-009).
 *
 * Endpoints:
 *  - GET  /api/inventory/returns          → listado paginado (v1.8.0)
 *  - POST /api/inventory/returns/register → registra la devolución (CU-009)
 *
 * Permisos (aplicados en routes):
 *  - inventory.return.view    → index
 *  - inventory.return.create  → register
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO en register.
 *
 * Auditoría: cada escritura escribe su fila de dominio en `audit_logs`
 * DESPUÉS de confirmar la transacción (patrón ShipmentController); los
 * errores ya los registra AuditMiddleware. Un replay NO escribe fila nueva.
 */
final class ReturnController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private RegisterReturnUseCase $registerUseCase;
    private ?ReturnQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?RegisterReturnUseCase $register = null,
        ?ReturnQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        if ($register === null) {
            $register = Container::getInstance()->get(RegisterReturnUseCase::class);
        }

        $this->registerUseCase = $register;
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/returns
     *
     * Query string: estado (estado_al_devolver), motivo, fecha_desde,
     * fecha_hasta, shipment_id, unidad_fisica_id, page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'motivo', 'fecha_desde', 'fecha_hasta', 'shipment_id', 'unidad_fisica_id'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(ReturnQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/returns/register (CU-009)
     */
    public function register(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->registerUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'return.register', 'CREATE', $key, $response);
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
        $devolucion = is_array($data['devolucion'] ?? null) ? $data['devolucion'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'return',
            'entidad_id' => is_string($devolucion['return_id'] ?? null) ? $devolucion['return_id'] : null,
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
