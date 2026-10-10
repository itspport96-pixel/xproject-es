<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\Services\ReleaseReservationUseCase;
use App\Modules\Inventory\Application\Services\ReservationQueryService;
use App\Modules\Inventory\Application\Services\ReserveStockUseCase;
use App\Modules\Inventory\Http\Concerns\ExtractsIdempotencyKey;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;

/**
 * Controlador HTTP: Reservas de inventario (CU-003).
 *
 * Endpoints:
 *  - GET  /api/inventory/reservations           → listado paginado (v1.6.2)
 *  - POST /api/inventory/reservations           → crea la reserva
 *  - POST /api/inventory/reservations/{id}/release → cancela la reserva
 *
 * Permisos (aplicados en routes):
 *  - inventory.reservation.view    → index
 *  - inventory.reservation.create  → store
 *  - inventory.reservation.cancel  → release
 *
 * Idempotencia (spec R7): `Idempotency-Key` OBLIGATORIO al crear (replay con
 * la misma clave; clave + cuerpo distinto → 409).
 *
 * Release IDEMPOTENTE POR ESTADO: la cancelación es un cambio de estado de la
 * reserva (ACTIVA → CANCELADA), no una operación con Efectos repetibles. Una
 * segunda llamada sobre una reserva ya cancelada devuelve **409**
 * (`reservationNotActive`) y NO duplica nada, por lo que la clave NO aporta
 * reintentos y por eso es OPCIONAL en release: si se envía, se comporta igual
 * que en crear (replay de la respuesta original); si no, la operación se
 * ejecuta normalmente. El mismo criterio aplica a la expiración E7.
 */
final class ReservationController
{
    use ExtractsIdempotencyKey;
    use ReturnsUseCaseResult;

    private ReserveStockUseCase $reserveUseCase;
    private ReleaseReservationUseCase $releaseUseCase;
    private ?ReservationQueryService $queryService;

    public function __construct(
        ?ReserveStockUseCase $reserve = null,
        ?ReleaseReservationUseCase $release = null,
        ?ReservationQueryService $query = null
    ) {
        if ($reserve === null || $release === null) {
            $container = Container::getInstance();
            $reserve = $reserve ?? $container->get(ReserveStockUseCase::class);
            $release = $release ?? $container->get(ReleaseReservationUseCase::class);
        }

        $this->reserveUseCase = $reserve;
        $this->releaseUseCase = $release;
        // Resuelto bajo demanda en index(): store/release (y sus tests) no
        // deben depender de que el contenedor tenga el servicio de consulta.
        $this->queryService = $query;
    }

    /**
     * GET /api/inventory/reservations
     *
     * Query string: estado, producto_id, stock_unit_id, vencimiento_desde,
     * vencimiento_hasta, page, per_page (máximo 100).
     */
    public function index(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        foreach (['estado', 'producto_id', 'stock_unit_id', 'vencimiento_desde', 'vencimiento_hasta'] as $field) {
            if (!empty($query[$field])) {
                $filters[$field] = $query[$field];
            }
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $service = $this->queryService ?? Container::getInstance()->get(ReservationQueryService::class);
        $result = $service->list((string) $claims['tenant_id'], $filters, $page, $perPage);

        return ['status' => 200, 'data' => $result];
    }

    /**
     * POST /api/inventory/reservations
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->reserveUseCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/reservations/{id}/release
     *
     * Idempotente POR ESTADO (no por clave): una segunda llamada sobre una
     * reserva ya CANCELADA devuelve 409, por eso `Idempotency-Key` es
     * opcional aquí. Con clave, el replay devuelve la respuesta original.
     */
    public function release(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $reservaId = (string) ($request['params']['id'] ?? '');
        $key = $this->idempotencyKey($request, false);

        $response = $this->releaseUseCase->execute(
            $reservaId,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        return $this->useCaseResponse($response);
    }
}
