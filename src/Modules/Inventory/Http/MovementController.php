<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\MovementQueryService;
use App\Modules\Inventory\Application\Services\MoveStockUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Movimiento interno de inventario (CU-002).
 *
 * Endpoints:
 *  - GET  /api/inventory/movements → listado paginado con filtros (R3, v1.10.0)
 *  - POST /api/inventory/movements → mueve una unidad entre ubicaciones
 *
 * Permisos (aplicado en routes):
 *  - GET  → inventory.stock.view (lectura de stock, mismo criterio que
 *           /stock y /stock/{id}/history; decisión v1.10.0 anotada en CHANGELOG)
 *  - POST → inventory.movement.create
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO en el
 * POST. Misma clave + mismo cuerpo → respuesta original (replay); misma
 * clave + cuerpo distinto → 409.
 */
final class MovementController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private MoveStockUseCase $useCase;
    private ?MovementQueryService $queryService;
    private ?AuditLogger $audit;

    public function __construct(
        ?MoveStockUseCase $useCase = null,
        ?MovementQueryService $queryService = null,
        ?AuditLogger $audit = null
    ) {
        $this->useCase = $useCase ?? Container::getInstance()->get(MoveStockUseCase::class);
        $this->queryService = $queryService;
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * El logger se resuelve sin romper si el contenedor no está inicializado
     * (mismo patrón que ReceivingController).
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

    /**
     * GET /api/inventory/movements
     *
     * Filtros (query string): unidad_fisica_id, ubicacion_origen_id,
     * ubicacion_destino_id, motivo, es_correccion, fecha_desde, fecha_hasta,
     * page, per_page (máx. 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['unidad_fisica_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'motivo', 'es_correccion', 'fecha_desde', 'fecha_hasta'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $filters[$key] = $query[$key];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(MovementQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/movements
     */
    public function create(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->useCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditMovement($request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * Auditoría de dominio del movimiento interno (CU-002, FIX-B4).
     *
     * Sólo en éxito: los errores (400/404/409/422) ya los registra el hook
     * del Router (AuditMiddleware) con su causa.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $response
     */
    private function auditMovement(array $request, array $claims, string $key, array $response): void
    {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $movimiento = is_array($data['movimiento'] ?? null) ? $data['movimiento'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => 'inventory.movement.create',
            'modulo' => 'inventory',
            'recurso' => 'movement',
            'entidad_id' => is_string($movimiento['movimiento_id'] ?? null) ? $movimiento['movimiento_id'] : null,
            'tipo_cambio' => 'CREATE',
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
}
