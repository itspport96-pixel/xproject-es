<?php

declare(strict_types=1);

namespace App\Core\Tenants\Middleware;

use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Middleware de extracción y validación de tenant.
 *
 * Responsabilidades:
 *  - Extraer tenant_id de los claims del JWT (inyectados por AuthenticationMiddleware).
 *  - Setear el TenantContext para uso en toda la request.
 *  - Validar que el tenant exista en BD.
 *  - Inyectar el tenant en el request para uso posterior.
 *
 * Orden de ejecución:
 *   AuthenticationMiddleware → TenantMiddleware → PermissionMiddleware → Controller
 *
 * Uso en rutas:
 *   'middleware' => [
 *       AuthenticationMiddleware::class,
 *       TenantMiddleware::class,
 *       PermissionMiddleware::class . ':inventory.item.view'
 *   ]
 */
final class TenantMiddleware
{
    private TenantContext $context;
    private TenantRepositoryInterface $repository;

    public function __construct(
        ?TenantContext $context = null,
        ?TenantRepositoryInterface $repository = null
    ) {
        $container = Container::getInstance();
        $this->context = $context ?? $container->get(TenantContext::class);
        $this->repository = $repository ?? $container->get(TenantRepositoryInterface::class);
    }

    /**
     * Procesa la request, extrayendo y validando el tenant.
     *
     * @param array $request Request con claims del usuario (inyectados por AuthenticationMiddleware).
     * @return array Request enriquecido con 'tenant' (datos del tenant actual).
     * @throws HttpException 401 si no hay claims de tenant.
     * @throws HttpException 403 si el tenant no existe o no puede operar.
     */
    public function handle(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims)) {
            throw new HttpException('No autorizado: faltan claims de usuario', 401);
        }

        $tenantId = $claims['tenant_id'] ?? null;

        if (!is_string($tenantId) || $tenantId === '') {
            throw new HttpException('No autorizado: faltan claims de tenant', 401);
        }

        // Validar que el tenant exista en BD (falla temprano)
        $tenant = $this->repository->findById($tenantId);
        if ($tenant === null) {
            throw new HttpException("El tenant '{$tenantId}' no existe", 403);
        }

        // Setear el contexto de tenant para toda la request
        $this->context->setTenantId($tenantId);

        // Validar que el tenant pueda operar (activo y no expirado)
        // Esto lanza TenantException si el tenant está suspendido/cancelado/pendiente/expirado
        try {
            $tenantOperativo = $this->context->getCurrentTenant();
        } catch (TenantException $e) {
            throw new HttpException($e->getMessage(), $e->getCode() ?: 403, $e);
        }

        // Inyectar el tenant en el request para uso en controllers/services
        $request['tenant'] = [
            'id'              => $tenantOperativo->id(),
            'nombre_publico'  => $tenantOperativo->nombrePublico(),
            'nombre_interno'  => $tenantOperativo->nombreInterno(),
            'plan'            => $tenantOperativo->plan(),
            'estado'          => $tenantOperativo->estado(),
            'zona_horaria'    => $tenantOperativo->zonaHoraria(),
            'idioma_default'  => $tenantOperativo->idiomaDefault(),
        ];

        return $request;
    }
}
