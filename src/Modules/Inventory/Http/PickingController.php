<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\CompletePickingUseCase;
use App\Modules\Inventory\Application\Services\PickingQueryService;
use App\Modules\Inventory\Application\Services\StartPickingUseCase;
use App\Modules\Inventory\Application\Services\VerifyPickingUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Picking (CU-004).
 *
 * Endpoints:
 *  - GET  /api/inventory/picking              → listado paginado (v1.7.0)
 *  - POST /api/inventory/picking/start        → inicia el lote (EN_CURSO)
 *  - POST /api/inventory/picking/{id}/complete → lote COMPLETADA
 *  - POST /api/inventory/picking/{id}/verify  → lote VERIFICADA (supervisor)
 *
 * Permisos (aplicados en routes):
 *  - inventory.picking.view     → index
 *  - inventory.picking.create   → start
 *  - inventory.picking.confirm  → complete
 *  - inventory.picking.verify   → verify
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO en start. En
 * complete/verify es OPCIONAL: ambas operaciones están gated por estado (un
 * lote ya COMPLETADA/VERIFICADA devuelve 409 sin duplicar nada), mismo
 * criterio que release de reservas (v1.6.2).
 *
 * Auditoría: cada escritura escribe su fila de dominio en `audit_logs`
 * DESPUÉS de confirmar la transacción (patrón ReceivingController); los
 * errores ya los registra AuditMiddleware.
 */
final class PickingController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private StartPickingUseCase $startUseCase;
    private CompletePickingUseCase $completeUseCase;
    private VerifyPickingUseCase $verifyUseCase;
    private ?PickingQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?StartPickingUseCase $start = null,
        ?CompletePickingUseCase $complete = null,
        ?VerifyPickingUseCase $verify = null,
        ?PickingQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        if ($start === null || $complete === null || $verify === null) {
            $container = Container::getInstance();
            $start = $start ?? $container->get(StartPickingUseCase::class);
            $complete = $complete ?? $container->get(CompletePickingUseCase::class);
            $verify = $verify ?? $container->get(VerifyPickingUseCase::class);
        }

        $this->startUseCase = $start;
        $this->completeUseCase = $complete;
        $this->verifyUseCase = $verify;
        // Resuelto bajo demanda en index(): las escrituras (y sus tests) no
        // deben depender de que el contenedor tenga el servicio de consulta.
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/picking
     *
     * Query string: estado, id_lote_picking, fecha_desde, fecha_hasta,
     * page, per_page (máximo 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'id_lote_picking', 'fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(PickingQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/picking/start
     */
    public function start(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->startUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'picking.start', 'CREATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/picking/{id}/complete
     */
    public function complete(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $lote = (string) ($request['params']['id'] ?? '');
        $key = $this->idempotencyKey($request, false);

        $response = $this->completeUseCase->execute(
            $lote,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'picking.complete', 'UPDATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/picking/{id}/verify
     *
     * El supervisor es el usuario autenticado: debe ser distinto del
     * selector de cada operación del lote (422, spec CU-004).
     */
    public function verify(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $lote = (string) ($request['params']['id'] ?? '');
        $key = $this->idempotencyKey($request, false);

        $response = $this->verifyUseCase->execute(
            $lote,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'picking.verify', 'UPDATE', $key, $response);
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

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'picking',
            'entidad_id' => is_string($data['id_lote_picking'] ?? null) ? $data['id_lote_picking'] : null,
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
