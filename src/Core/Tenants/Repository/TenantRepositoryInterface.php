<?php

declare(strict_types=1);

namespace App\Core\Tenants\Repository;

use App\Core\Tenants\Entity\Tenant;

/**
 * Contrato de persistencia para el aggregate Tenant.
 *
 * Los tenants son la raíz del aislamiento multi-tenant.
 * NO se filtran por tenant_id (ellos son el tenant).
 * nombre_interno y dominio_custom son únicos globales.
 */
interface TenantRepositoryInterface
{
    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * Busca un tenant por su ID.
     *
     * @return Tenant|null Null si no existe o está eliminado lógicamente.
     */
    public function findById(string $id): ?Tenant;

    /**
     * Busca un tenant por su nombre interno (case-insensitive, único).
     */
    public function findByNombreInterno(string $nombreInterno): ?Tenant;

    /**
     * Busca un tenant por su dominio custom (case-insensitive, único si asignado).
     */
    public function findByDominioCustom(string $dominio): ?Tenant;

    /**
     * Busca el tenant cuyo owner es el usuario indicado.
     *
     * @return array<int, Tenant> Lista de tenants del owner (puede ser más de uno).
     */
    public function findByOwnerUserId(string $ownerUserId): array;

    /**
     * Lista todos los tenants.
     *
     * @param bool $soloActivos Si true, excluye tenants con deleted_at.
     * @return array<int, Tenant>
     */
    public function findAll(bool $soloActivos = true): array;

    /**
     * Lista tenants filtrados por estado.
     *
     * @param string $estado ACTIVO|SUSPENDIDO|PENDIENTE_ACTIVACION|CANCELED
     * @return array<int, Tenant>
     */
    public function findByEstado(string $estado): array;

    /**
     * Lista tenants filtrados por plan.
     *
     * @param string $plan FREE|STARTER|PRO|ENTERPRISE
     * @return array<int, Tenant>
     */
    public function findByPlan(string $plan): array;

    /**
     * Lista tenants que están activos y no han expirado.
     * Útil para operaciones que requieren tenants operativos.
     *
     * @return array<int, Tenant>
     */
    public function findOperativos(): array;

    /**
     * Lista tenants con fecha de expiración próxima (próximos N días).
     *
     * @param int $dias Número de días hacia adelante.
     * @return array<int, Tenant>
     */
    public function findPorExpirar(int $dias = 30): array;

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    /**
     * Persiste un tenant nuevo (INSERT).
     *
     * @throws \RuntimeException Si nombre_interno o dominio_custom ya existen.
     */
    public function save(Tenant $tenant): void;

    /**
     * Actualiza un tenant existente (UPDATE).
     *
     * @throws \RuntimeException Si el tenant no existe.
     */
    public function update(Tenant $tenant): void;

    /**
     * Eliminación lógica del tenant.
     * Marca deleted_at y estado = CANCELED.
     */
    public function softDelete(string $id): void;

    /**
     * Restaura un tenant previamente eliminado.
     * Limpia deleted_at y estado = PENDIENTE_ACTIVACION.
     */
    public function restore(string $id): void;

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    /**
     * Verifica si ya existe un tenant con el nombre interno dado.
     */
    public function existsByNombreInterno(string $nombreInterno): bool;

    /**
     * Verifica si ya existe un tenant con el dominio custom dado.
     */
    public function existsByDominioCustom(string $dominio): bool;

    /**
     * Verifica si un tenant existe por ID (sin importar estado).
     */
    public function existsById(string $id): bool;

    /**
     * Cuenta tenants.
     *
     * @param bool $soloActivos Si true, solo cuenta no eliminados.
     */
    public function count(bool $soloActivos = true): int;

    /**
     * Cuenta tenants por estado.
     */
    public function countByEstado(string $estado): int;

    /**
     * Cuenta tenants por plan.
     */
    public function countByPlan(string $plan): int;
}
