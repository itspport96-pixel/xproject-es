<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\IncidentQueryService;
use App\Modules\Inventory\Application\Services\InvestigateIncidentUseCase;
use App\Modules\Inventory\Application\Services\ReportIncidentUseCase;
use App\Modules\Inventory\Application\Services\ResolveIncidentUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: Incidencias (CU-010).
 *
 * Endpoints:
 *  - GET  /api/inventory/incidents              → listado paginado + summary
 *  - GET  /api/inventory/incidents/{id}         → detalle (resolución y conteo)
 *  - POST /api/inventory/incidents/report       → reportar (Idempotency-Key OBLIGATORIO)
 *  - POST /api/inventory/incidents/{id}/investigate → ABIERTA → INVESTIGACION
 *  - POST /api/inventory/incidents/{id}/resolve     → RESUELTA | NO_RESUELTO
 *
 * Permisos (aplicados en routes):
 *  - inventory.incident.view    → index, show
 *  - inventory.incident.report  → report
 *  - inventory.incident.resolve → investigate, resolve
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO en report; opcional
 * en investigate/resolve (idempotencia por estado).
 *
 * Auditoría: cada escritura escribe su fila de dominio en `audit_logs`
 * DESPUÉS de confirmar la transacción (patrón ReturnController); un replay
 * NO escribe fila nueva.
 */
final class IncidentController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private ReportIncidentUseCase $reportUseCase;
    private InvestigateIncidentUseCase $investigateUseCase;
    private ResolveIncidentUseCase $resolveUseCase;
    private ?IncidentQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?ReportIncidentUseCase $report = null,
        ?InvestigateIncidentUseCase $investigate = null,
        ?ResolveIncidentUseCase $resolve = null,
        ?IncidentQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        $container = Container::getInstance();

        $this->reportUseCase = $report ?? $container->get(ReportIncidentUseCase::class);
        $this->investigateUseCase = $investigate ?? $container->get(InvestigateIncidentUseCase::class);
        $this->resolveUseCase = $resolve ?? $container->get(ResolveIncidentUseCase::class);
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/incidents
     *
     * Query string: estado, tipo, unidad_fisica_id, ubicacion_id,
     * fecha_desde, fecha_hasta, page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'tipo', 'unidad_fisica_id', 'ubicacion_id', 'fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(IncidentQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/incidents/{id}
     */
    public function show(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $incidentId = trim((string) ($params['id'] ?? ''));

        if ($incidentId === '') {
            throw new HttpException('Ruta inválida', 404);
        }

        $service = $this->queryService ?? Container::getInstance()->get(IncidentQueryService::class);
        $result = $service->detail((string) $claims['tenant_id'], $incidentId);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/incidents/report (CU-010) — Idempotency-Key obligatorio
     */
    public function report(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $key = $this->idempotencyKey($request);

        $response = $this->reportUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'incident.report', 'CREATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/incidents/{id}/investigate (CU-010)
     */
    public function investigate(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $incidentId = trim((string) ($params['id'] ?? ''));

        if ($incidentId === '') {
            throw new HttpException('Ruta inválida', 404);
        }

        $key = $this->idempotencyKey($request, false);

        $response = $this->investigateUseCase->execute(
            $incidentId,
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'incident.investigate', 'UPDATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/incidents/{id}/resolve (CU-010)
     */
    public function resolve(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $incidentId = trim((string) ($params['id'] ?? ''));

        if ($incidentId === '') {
            throw new HttpException('Ruta inválida', 404);
        }

        $key = $this->idempotencyKey($request, false);

        $response = $this->resolveUseCase->execute(
            $incidentId,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'incident.resolve', 'UPDATE', $key, $response);
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
        $incidencia = is_array($data['incidencia'] ?? null) ? $data['incidencia'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'incident',
            'entidad_id' => is_string($incidencia['incidencia_id'] ?? null) ? $incidencia['incidencia_id'] : null,
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
