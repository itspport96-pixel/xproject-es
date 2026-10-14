<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\Services\DashboardService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP del dashboard de inventario (spec §9 "Lazy Loading").
 *
 * Endpoints:
 *  - GET /api/inventory/dashboard/critical  → CAPA 1 (primer render)
 *  - GET /api/inventory/dashboard/secondary → CAPA 2 (~5s después)
 *
 * Permisos requeridos (aplicados via PermissionMiddleware en routes):
 *  - inventory.stock.view → ambos (lo tienen ADMIN, MANAGER, USER y VIEWER)
 */
final class DashboardController
{
    private DashboardService $service;

    public function __construct(?DashboardService $service = null)
    {
        $this->service = $service ?? Container::getInstance()->get(DashboardService::class);
    }

    /**
     * GET /api/inventory/dashboard/critical
     * Información de tenant, contadores y tabla de stock resumida.
     */
    public function critical(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        return $this->json(200, $this->service->critical($claims['tenant_id']));
    }

    /**
     * GET /api/inventory/dashboard/secondary
     * Ubicaciones disponibles, reservas activas, tránsito e incidencias.
     */
    public function secondary(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        return $this->json(200, $this->service->secondary($claims['tenant_id']));
    }

    /**
     * Valida que el request tenga claims válidos de usuario autenticado.
     *
     * @throws HttpException 401 si no está autenticado
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        return $claims;
    }

    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
