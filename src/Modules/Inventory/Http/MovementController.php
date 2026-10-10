<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

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

    public function __construct(?MoveStockUseCase $useCase = null, ?MovementQueryService $queryService = null)
    {
        $this->useCase = $useCase ?? Container::getInstance()->get(MoveStockUseCase::class);
        $this->queryService = $queryService;
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

        return $this->useCaseResponse($response);
    }
}
