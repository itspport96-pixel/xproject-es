<?php

declare(strict_types=1);

namespace App\Core\Audit\Http;

use App\Core\Audit\Service\AuditLogQueryService;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Container\Container;

/**
 * AuditLogController - lectura de la bitácora de auditoría (spec §7.1).
 *
 * GET /api/audit/logs
 *  - Requiere permiso `audit.log.view` (verificado en la ruta).
 *  - El tenant se toma SIEMPRE de la sesión (claims), nunca del query string.
 */
final class AuditLogController
{
    private AuditLogQueryService $service;
    private TenantContext $tenantContext;

    public function __construct(
        ?AuditLogQueryService $service = null,
        ?TenantContext $tenantContext = null
    ) {
        $container = Container::getInstance();
        $this->service = $service ?? $container->get(AuditLogQueryService::class);
        $this->tenantContext = $tenantContext ?? $container->get(TenantContext::class);
    }

    public function index(array $request): array
    {
        $tenantId = $this->resolveTenantId($request);

        $data = $this->service->search($request['query'] ?? [], $tenantId);

        return [
            'status' => 200,
            'message' => 'Bitácora de auditoría obtenida correctamente',
            'data' => $data,
        ];
    }

    private function resolveTenantId(array $request): string
    {
        $claims = $request['user'] ?? null;
        if (is_array($claims) && !empty($claims['tenant_id'])) {
            return (string) $claims['tenant_id'];
        }

        $tenant = $request['tenant'] ?? null;
        if (is_array($tenant) && !empty($tenant['id'])) {
            return (string) $tenant['id'];
        }

        return $this->tenantContext->getTenantId();
    }
}
