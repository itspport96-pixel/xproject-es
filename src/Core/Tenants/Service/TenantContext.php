<?php

declare(strict_types=1);

namespace App\Core\Tenants\Service;

use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Shared\Container\Container;

/**
 * TenantContext - Servicio singleton que provee el tenant actual del request.
 *
 * Responsabilidades:
 *  - Almacenar el tenant_id del request actual (inyectado por TenantMiddleware).
 *  - Cargar lazy el Tenant completo desde BD.
 *  - Validar que el tenant esté operativo (ACTIVO y no expirado).
 *  - Proveer acceso al tenant desde cualquier capa de la aplicación.
 *
 * Flujo de uso:
 *  1. TenantMiddleware extrae tenant_id del JWT y llama setTenantId().
 *  2. Cualquier servicio/controller llama getCurrentTenant() o getTenantId().
 *  3. El contexto valida el estado del tenant la primera vez (cached).
 *  4. Al finalizar el request, se puede llamar clear() (opcional).
 */
final class TenantContext
{
    private TenantRepositoryInterface $repository;
    private ?string $tenantId = null;
    private ?Tenant $tenant = null;
    private bool $validated = false;

    public function __construct(?TenantRepositoryInterface $repository = null)
    {
        $this->repository = $repository ?? Container::getInstance()->get(TenantRepositoryInterface::class);
    }

    // ========================================================================
    // INYECCIÓN (llamado por TenantMiddleware)
    // ========================================================================

    /**
     * Establece el tenant_id del request actual.
     * Debe ser llamado por TenantMiddleware tras extraer el claim del JWT.
     *
     * @throws TenantException Si el tenant_id es inválido o el tenant no existe.
     */
    public function setTenantId(string $tenantId): void
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw (new TenantException('El tenant_id no puede estar vacío.', 400))->withMessageKey('tenants.tenant_id_required');
        }

        $this->tenantId = $tenantId;
        $this->tenant = null;
        $this->validated = false;
    }

    /**
     * Limpia el contexto (útil entre requests en CLI o tests).
     */
    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenant = null;
        $this->validated = false;
    }

    // ========================================================================
    // API PÚBLICA
    // ========================================================================

    /**
     * Retorna el tenant_id del request actual.
     *
     * @throws TenantException Si no se ha establecido el tenant_id.
     */
    public function getTenantId(): string
    {
        if ($this->tenantId === null) {
            throw (new TenantException('El contexto de tenant no ha sido inicializado.', 400))->withMessageKey('tenants.context_not_initialized');
        }

        return $this->tenantId;
    }

    /**
     * Retorna el Tenant completo del request actual.
     * Valida que el tenant esté operativo la primera vez que se llama.
     *
     * @throws TenantException Si el tenant no existe, está suspendido, cancelado o expirado.
     */
    public function getCurrentTenant(): Tenant
    {
        $tenantId = $this->getTenantId();

        // Lazy load + cache
        if ($this->tenant === null) {
            $tenant = $this->repository->findById($tenantId);
            if ($tenant === null) {
                throw TenantException::tenantNotFound($tenantId);
            }
            $this->tenant = $tenant;
        }

        // Validación de estado (solo una vez por request)
        if (!$this->validated) {
            $this->validateOperativo($this->tenant);
            $this->validated = true;
        }

        return $this->tenant;
    }

    /**
     * Retorna el Tenant completo sin validar estado operativo.
     * Útil para casos donde se necesita leer el tenant aunque esté suspendido
     * (ej: panel de administración, recuperación de datos).
     *
     * @throws TenantException Si el tenant no existe.
     */
    public function getCurrentTenantUnsafe(): Tenant
    {
        $tenantId = $this->getTenantId();

        if ($this->tenant === null) {
            $tenant = $this->repository->findById($tenantId);
            if ($tenant === null) {
                throw TenantException::tenantNotFound($tenantId);
            }
            $this->tenant = $tenant;
        }

        return $this->tenant;
    }

    /**
     * Verifica si el contexto ha sido inicializado.
     */
    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Valida que el tenant_id proporcionado coincida con el del contexto.
     *
     * Este método implementa el contrato TenantContextPort::validateTenantOwnership
     * de la especificación de Inventory.
     *
     * Uso típico en módulos:
     *   $context->validateTenantOwnership($tenantIdDelRecurso);
     *
     * @throws TenantException Si el tenant_id no coincide.
     */
    public function validateTenantOwnership(string $tenantId): bool
    {
        $current = $this->getTenantId();

        if ($current !== $tenantId) {
            throw new TenantException(
                "Violación de aislamiento: el recurso pertenece al tenant '{$tenantId}' " .
                "pero el contexto actual es '{$current}'.",
                403
            );
        }

        return true;
    }

    /**
     * Retorna información resumida del tenant actual (para claims JWT, logs, etc.).
     *
     * @return array{id: string, nombre_publico: string, plan: string, estado: string}
     */
    public function getTenantSummary(): array
    {
        $tenant = $this->getCurrentTenant();

        return [
            'id'              => $tenant->id(),
            'nombre_publico'  => $tenant->nombrePublico(),
            'plan'            => $tenant->plan(),
            'estado'          => $tenant->estado(),
        ];
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    /**
     * Valida que el tenant pueda operar (activo y no expirado).
     *
     * @throws TenantException Si el tenant no puede operar.
     */
    private function validateOperativo(Tenant $tenant): void
    {
        if ($tenant->isDeleted()) {
            throw TenantException::tenantNotFound($tenant->id());
        }

        if ($tenant->isCanceled()) {
            throw TenantException::tenantCanceled($tenant->id());
        }

        if ($tenant->isSuspended()) {
            throw TenantException::tenantSuspended($tenant->id());
        }

        if ($tenant->isPending()) {
            throw TenantException::tenantPendingActivation($tenant->id());
        }

        if ($tenant->hasExpired()) {
            throw new TenantException(
                "El tenant '{$tenant->id()}' ha expirado.",
                403
            );
        }
    }
}
