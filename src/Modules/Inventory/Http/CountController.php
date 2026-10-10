<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\CompleteCountUseCase;
use App\Modules\Inventory\Application\Services\CountQueryService;
use App\Modules\Inventory\Application\Services\ReconcileCountUseCase;
use App\Modules\Inventory\Application\Services\RecordCountResultsUseCase;
use App\Modules\Inventory\Application\Services\StartCountUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: conteos físicos (CU-011).
 *
 * Endpoints:
 *  - GET  /api/inventory/counts                → listado paginado + summary
 *  - GET  /api/inventory/counts/{id}           → detalle (teórica, resultados,
 *                                                discrepancias, ajustes)
 *  - GET  /api/inventory/counts/{id}/sheet     → lista teórica; `?format=csv`
 *                                                devuelve text/csv descargable
 *  - POST /api/inventory/counts/start          → PENDIENTE + instantánea
 *  - POST /api/inventory/counts/{id}/results   → parcial → EN_CURSO
 *  - POST /api/inventory/counts/{id}/complete  → COMPLETADO + discrepancias
 *  - POST /api/inventory/counts/{id}/reconcile → AJUSTAR/JUSTIFICAR
 *
 * Permisos (aplicados en routes):
 *  - inventory.count.view      → index, show, sheet
 *  - inventory.count.create    → start, results, complete
 *  - inventory.count.reconcile → reconcile
 *
 * Idempotencia (spec R7): OBLIGATORIA en start y reconcile; results/complete
 * no usan clave (su protección es el estado: no se puede completar dos veces
 * ni registrar resultados de un conteo ya completado).
 *
 * Auditoría: fila de dominio escrita DESPUÉS de confirmar la transacción;
 * un replay NO escribe fila nueva.
 */
final class CountController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private StartCountUseCase $startUseCase;
    private RecordCountResultsUseCase $resultsUseCase;
    private CompleteCountUseCase $completeUseCase;
    private ReconcileCountUseCase $reconcileUseCase;
    private ?CountQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?StartCountUseCase $start = null,
        ?RecordCountResultsUseCase $results = null,
        ?CompleteCountUseCase $complete = null,
        ?ReconcileCountUseCase $reconcile = null,
        ?CountQueryService $query = null,
        ?AuditLogger $audit = null
    ) {
        $container = Container::getInstance();

        $this->startUseCase = $start ?? $container->get(StartCountUseCase::class);
        $this->resultsUseCase = $results ?? $container->get(RecordCountResultsUseCase::class);
        $this->completeUseCase = $complete ?? $container->get(CompleteCountUseCase::class);
        $this->reconcileUseCase = $reconcile ?? $container->get(ReconcileCountUseCase::class);
        $this->queryService = $query;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * GET /api/inventory/counts
     *
     * Query string: estado, ubicacion_id, fecha_desde, fecha_hasta,
     * page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'ubicacion_id', 'fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(CountQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/counts/{id}
     */
    public function show(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $countId = $this->countId($params);

        $service = $this->queryService ?? Container::getInstance()->get(CountQueryService::class);
        $result = $service->detail((string) $claims['tenant_id'], $countId);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/counts/{id}/sheet[?format=csv]
     *
     * JSON por defecto; con `format=csv` emite text/csv (BOM UTF-8) y termina:
     * es un GET, así que AuditMiddleware no audita estas rutas y no se pierde
     * ningún hook de auditoría. La generación del CSV vive en
     * CountQueryService::sheetCsv() (unit-testeable).
     */
    public function sheet(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $countId = $this->countId($params);

        $service = $this->queryService ?? Container::getInstance()->get(CountQueryService::class);

        $formatValue = $request['query']['format'] ?? '';
        $format = is_string($formatValue) ? strtolower(trim($formatValue)) : '';

        if ($format === 'csv') {
            $csv = $service->sheetCsv((string) $claims['tenant_id'], $countId);

            if (PHP_SAPI !== 'cli') {
                header('Content-Type: text/csv; charset=utf-8');
                header(
                    'Content-Disposition: attachment; filename="conteo-' .
                    preg_replace('/[^A-Za-z0-9._-]/', '_', $countId) . '.csv"'
                );
                header('Content-Length: ' . strlen("\xEF\xBB\xBF" . $csv));
            }

            echo "\xEF\xBB\xBF" . $csv;
            exit;
        }

        return ['status' => 200, 'data' => $service->sheet((string) $claims['tenant_id'], $countId)];
    }

    /**
     * POST /api/inventory/counts/start (CU-011) — Idempotency-Key obligatorio
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
            $this->auditOperation($request, $claims, 'inventory.count.start', 'CREATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/counts/{id}/results (CU-011)
     */
    public function results(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $countId = $this->countId($params);

        $response = $this->resultsUseCase->execute(
            $countId,
            $request['body'] ?? [],
            (string) $claims['user_id']
        );

        $this->auditOperation($request, $claims, 'inventory.count.results', 'UPDATE', null, $response);

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/counts/{id}/complete (CU-011)
     */
    public function complete(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $countId = $this->countId($params);

        $response = $this->completeUseCase->execute(
            $countId,
            (string) $claims['user_id']
        );

        $this->auditOperation($request, $claims, 'inventory.count.complete', 'UPDATE', null, $response);

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/counts/{id}/reconcile (CU-011) — Idempotency-Key obligatorio
     */
    public function reconcile(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $countId = $this->countId($params);
        $key = $this->idempotencyKey($request);

        $body = $request['body'] ?? [];
        $input = is_array($body) ? $body : [];
        // El id del conteo viaja SIEMPRE por la ruta: el body sólo lleva
        // `acciones` (la ruta es la fuente de verdad, no el payload).
        $input['conteo_id'] = $countId;

        $response = $this->reconcileUseCase->execute(
            $input,
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditOperation($request, $claims, 'inventory.count.reconcile', 'UPDATE', $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param array<string, mixed> $params
     *
     * @throws HttpException 404 si falta el id en la ruta
     */
    private function countId(array $params): string
    {
        $countId = trim((string) ($params['id'] ?? ''));

        if ($countId === '') {
            throw new HttpException('Ruta inválida', 404);
        }

        return $countId;
    }

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
        if ($this->audit === null || $this->isReplay($response)) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $conteo = is_array($data['conteo'] ?? null) ? $data['conteo'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'inventory_count',
            'entidad_id' => is_string($conteo['conteo_id'] ?? null) ? $conteo['conteo_id'] : null,
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
