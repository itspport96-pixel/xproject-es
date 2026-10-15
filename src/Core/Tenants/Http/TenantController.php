<?php

declare(strict_types=1);

namespace App\Core\Tenants\Http;

use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Service\TenantContext;
use App\Core\Tenants\Service\TenantService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;

/**
 * Controlador HTTP para gestión del tenant actual.
 *
 * El ADMIN de un tenant solo puede ver y gestionar SU propio tenant.
 * No hay endpoints para listar todos los tenants (eso será GLOBAL_ADMIN).
 *
 * Endpoints:
 * - GET    /api/tenant          → Ver mi tenant
 * - PATCH  /api/tenant          → Actualizar mi tenant
 * - POST   /api/tenant/activate → Activar mi tenant (si está PENDIENTE)
 * - GET    /api/tenant/stats    → Estadísticas del tenant
 */
final class TenantController
{
    private TenantService $tenantService;
    private TenantContext $tenantContext;
    private DatabaseService $db;
    private AuthorizationService $authz;

    public function __construct(
        ?TenantService $tenantService = null,
        ?TenantContext $tenantContext = null,
        ?DatabaseService $db = null,
        ?AuthorizationService $authz = null
    ) {
        $container = Container::getInstance();
        $this->tenantService = $tenantService ?? $container->get(TenantService::class);
        $this->tenantContext = $tenantContext ?? $container->get(TenantContext::class);
        $this->db = $db ?? $container->get('database');
        $this->authz = $authz ?? $container->get(AuthorizationService::class);
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * GET /api/tenant
     * Retorna los datos del tenant actual.
     */
    public function show(array $request): array
    {
        $claims = $this->requireAdmin($request);
        $tenant = $this->tenantContext->getCurrentTenant();

        return $this->json(200, [
            'tenant'             => $tenant->toPublicArray(),
            'plataforma_version' => (string) ($_ENV['APP_VERSION'] ?? ''),
        ]);
    }

    /**
     * GET /api/tenant/stats
     * Retorna estadísticas básicas del tenant actual.
     */
    public function stats(array $request): array
    {
        $claims = $this->requireAdmin($request);
        $tenantId = $this->tenantContext->getTenantId();

        $stats = [
            'usuarios'       => $this->countUsers($tenantId),
            'usuarios_activos' => $this->countActiveUsers($tenantId),
            'roles_asignados' => $this->countRoleAssignments($tenantId),
            'productos'      => $this->countProducts($tenantId),
            'ubicaciones'    => $this->countLocations($tenantId),
            'unidades_fisicas' => $this->countStockUnits($tenantId),
        ];

        return $this->json(200, ['stats' => $stats]);
    }

    // ========================================================================
    // ACTUALIZACIÓN
    // ========================================================================

    /**
     * PATCH /api/tenant
     * Actualiza los datos del tenant actual.
     * Campos permitidos: nombre_interno, nombre_publico, email_contacto,
     * telefono, pais, zona_horaria, idioma_default, logo_url, dominio_custom,
     * configuracion, metadatos.
     */
    public function update(array $request): array
    {
        $claims = $this->requireAdmin($request);
        $tenantId = $this->tenantContext->getTenantId();
        $data = $request['body'] ?? [];

        // Solo permitir campos específicos (no permitir cambiar estado, plan, owner)
        $allowedFields = [
            'nombre_interno',
            'nombre_publico',
            'email_contacto',
            'telefono',
            'pais',
            'zona_horaria',
            'idioma_default',
            'logo_url',
            'dominio_custom',
            'configuracion',
            'metadatos',
        ];

        $filteredData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($filteredData)) {
            throw (new HttpException('No se proporcionaron campos válidos para actualizar', 400))->withMessageKey('common.no_valid_fields');
        }

        try {
            $tenant = $this->tenantService->update($tenantId, $filteredData);
            return $this->json(200, ['tenant' => $tenant]);
        } catch (TenantException $e) {
            $http = new HttpException($e->getMessage(), $e->getCode() ?: 400, $e);
            if ($e->getMessageKey() !== null) {
                $http->withMessageKey($e->getMessageKey(), $e->getMessageParams());
            }
            throw $http;
        }
    }

    /**
     * POST /api/tenant/activate
     * Activa el tenant si está en estado PENDIENTE_ACTIVACION.
     */
    public function activate(array $request): array
    {
        $claims = $this->requireAdmin($request);
        $tenantId = $this->tenantContext->getTenantId();

        try {
            $tenant = $this->tenantService->activate($tenantId);
            return $this->json(200, [
                'tenant' => $tenant,
                'message' => 'Tenant activado correctamente',
            ]);
        } catch (TenantException $e) {
            throw new HttpException($e->getMessage(), $e->getCode() ?: 400, $e);
        }
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * Valida que el usuario tenga rol ADMIN.
     *
     * v1.12.1: se comprueba en la BD (role_assignments vigente), nunca en
     * los claims del token: quitar ADMIN surte efecto en la siguiente
     * petición, no al caducar el token.
     *
     * @throws HttpException 401 si no está autenticado
     * @throws HttpException 403 si no es ADMIN
     */
    private function requireAdmin(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        if (!$this->authz->hasAdminRole((string) $claims['user_id'], (string) $claims['tenant_id'])) {
            throw (new HttpException('Se requiere rol ADMIN para gestionar el tenant', 403))->withMessageKey('tenants.admin_required');
        }

        return $claims;
    }

    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }

    // ========================================================================
    // QUERIES DE ESTADÍSTICAS
    // ========================================================================

    private function countUsers(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE tenant_id = :tid AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private function countActiveUsers(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM users
                WHERE tenant_id = :tid
                  AND estado = 'ACTIVO'
                  AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private function countRoleAssignments(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM role_assignments
                WHERE tenant_id = :tid
                  AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private function countProducts(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM products
                WHERE tenant_id = :tid
                  AND activo = 1
                  AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private function countLocations(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM locations
                WHERE tenant_id = :tid
                  AND activa = 1
                  AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private function countStockUnits(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM stock_units
                WHERE tenant_id = :tid
                  AND estado IN ('DISPONIBLE', 'RESERVADO')";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }
}
