<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\BlockStockUseCase;
use App\Modules\Inventory\Application\Services\ReservationExpirationService;
use App\Modules\Inventory\Application\Services\StockHistoryQueryService;
use App\Modules\Inventory\Application\Services\StockQueryService;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: Stock actual (R1) + historial y bloqueo (v1.10.0).
 *
 * Endpoints:
 *  - GET  /api/inventory/stock          → resumen + listado de unidades con
 *    cantidades física / reservada / en tránsito / disponible
 *  - GET  /api/inventory/stock/summary  → stock total por producto en una sola
 *    consulta agregada (Sprint 6, 0b)
 *  - GET  /api/inventory/stock/{id}/history → línea de tiempo de la unidad
 *    (eventos + movimientos, R3)
 *  - POST /api/inventory/stock/{id}/block    → bloquear unidad (E4)
 *  - POST /api/inventory/stock/{id}/unblock  → desbloquear unidad (E4)
 *
 * Permisos (aplicado en routes):
 *  - GET  → inventory.stock.view
 *  - POST block/unblock → inventory.stock.block
 *
 * Filtros (query string): producto_id, ubicacion_id, estado, search, page, per_page
 */
final class StockController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private StockQueryService $query;
    private ?StockHistoryQueryService $historyService;
    private ?BlockStockUseCase $blockUseCase;
    private ?AuditLogger $audit;
    private ?ReservationExpirationService $expirationService;

    public function __construct(
        ?StockQueryService $query = null,
        ?StockHistoryQueryService $historyService = null,
        ?BlockStockUseCase $blockUseCase = null,
        ?AuditLogger $audit = null,
        ?ReservationExpirationService $expirationService = null
    ) {
        $this->query = $query ?? Container::getInstance()->get(StockQueryService::class);
        $this->historyService = $historyService;
        $this->blockUseCase = $blockUseCase;
        $this->audit = $audit ?? $this->resolveAuditLogger();
        $this->expirationService = $expirationService;
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
     * GET /api/inventory/stock
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        // Expiración perezosa: las reservas vencidas se reflejan como
        // DISPONIBLE en el listado de stock sin esperar a un cron.
        $expirationService = $this->expirationService ?? Container::getInstance()->get(ReservationExpirationService::class);
        $expirationService->expireIfNeeded();

        $query = $request['query'] ?? [];
        $filters = [];

        if (!empty($query['producto_id'])) {
            $filters['producto_id'] = $query['producto_id'];
        }
        if (!empty($query['ubicacion_id'])) {
            $filters['ubicacion_id'] = $query['ubicacion_id'];
        }
        if (!empty($query['estado'])) {
            $filters['estado'] = $query['estado'];
        }
        if (!empty($query['search'])) {
            $filters['search'] = $query['search'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->query->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/stock/summary?producto_id[]=...&producto_id[]=...
     *
     * Stock total por producto en UNA consulta agregada (Sprint 6, 0b).
     */
    public function summary(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $productoId = $query['producto_id'] ?? [];
        $productoIds = is_array($productoId) ? $productoId : [$productoId];

        $result = $this->query->summaryByProduct((string) $claims['tenant_id'], $productoIds);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * GET /api/inventory/stock/{id}/history
     *
     * Línea de tiempo de la unidad (v1.10.0, R3): fusión de stock_events y
     * movements ordenada por fecha descendente, paginada.
     */
    public function history(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 50)));

        $service = $this->historyService ?? Container::getInstance()->get(StockHistoryQueryService::class);
        $result = $service->history(
            (string) $claims['tenant_id'],
            (string) ($params['id'] ?? ''),
            $page,
            $perPage
        );

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/stock/{id}/block
     *
     * Bloquea la unidad (v1.10.0, E4). Idempotency-Key opcional.
     */
    public function block(array $request, array $params): array
    {
        return $this->blockAction($request, $params, BlockStockUseCase::ACCION_BLOQUEAR);
    }

    /**
     * POST /api/inventory/stock/{id}/unblock
     *
     * Desbloquea la unidad (v1.10.0, E4). Idempotency-Key opcional.
     */
    public function unblock(array $request, array $params): array
    {
        return $this->blockAction($request, $params, BlockStockUseCase::ACCION_DESBLOQUEAR);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $params
     */
    private function blockAction(array $request, array $params, string $accion): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request, false);

        $useCase = $this->blockUseCase ?? Container::getInstance()->get(BlockStockUseCase::class);
        $response = $useCase->execute(
            (string) ($params['id'] ?? ''),
            ['accion' => $accion],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditBlock($request, $claims, $params, $accion, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * Auditoría de dominio del bloqueo/desbloqueo de unidad (E4, FIX-B4).
     *
     * Sólo en éxito: los errores (400/404/409/422) ya los registra el hook
     * del Router (AuditMiddleware) con su causa.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, string> $params
     * @param array<string, mixed> $response
     */
    private function auditBlock(array $request, array $claims, array $params, string $accion, ?string $key, array $response): void
    {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $accion === BlockStockUseCase::ACCION_BLOQUEAR
                ? 'inventory.stock.block'
                : 'inventory.stock.unblock',
            'modulo' => 'inventory',
            'recurso' => 'stock_unit',
            'entidad_id' => is_string($params['id'] ?? null) ? $params['id'] : null,
            'tipo_cambio' => 'UPDATE',
            'resultado' => 'SUCCESS',
            'valores_anteriores' => is_string($data['estado_anterior'] ?? null) ? ['estado' => $data['estado_anterior']] : null,
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

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        return $claims;
    }
}
