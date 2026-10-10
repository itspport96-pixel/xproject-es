<?php
declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Entity\Secret;

/**
 * Contrato de persistencia para el aggregate Secret.
 *
 * Los secretos están aislados por tenant_id (filtrado obligatorio).
 * El nombre es único por tenant (no global).
 * El valor_encriptado NUNCA se expone en listados (solo en getDecryptable).
 */
interface SecretRepositoryInterface
{
    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * Busca un secreto por su ID dentro de un tenant.
     *
     * @return Secret|null Null si no existe, está inactivo o pertenece a otro tenant.
     */
    public function findById(string $id, string $tenantId): ?Secret;

    /**
     * Busca un secreto por nombre único dentro de un tenant.
     *
     * @return Secret|null Null si no existe.
     */
    public function findByNombre(string $nombre, string $tenantId): ?Secret;

    /**
     * Lista todos los secretos de un tenant (SIN valor_encriptado).
     *
     * @param array $filters Filtros opcionales:
     *   - tipo (string): API_KEY, PASSWORD, ENCRYPTION_KEY, OAUTH_TOKEN, OTRO
     *   - activo (bool): default true
     *   - owner_user_id (string): filtrar por owner
     *   - rotacion_requerida (bool): solo los que requieren rotación
     * @param int $page Página actual (default: 1)
     * @param int $perPage Registros por página (default: 20, max: 100)
     * @return array{data: array, pagination: array}
     */
    public function findAll(
        string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 20
    ): array;

    /**
     * Lista secretos filtrados por tipo.
     *
     * @return array<int, Secret>
     */
    public function findByTipo(string $tenantId, string $tipo): array;

    /**
     * Lista secretos cuyo owner es el usuario indicado.
     *
     * @return array<int, Secret>
     */
    public function findByOwner(string $tenantId, string $ownerUserId): array;

    /**
     * Lista secretos con rotación vencida (proxima_rotacion < NOW()).
     * Útil para cron jobs de rotación automática.
     *
     * @return array<int, Secret>
     */
    public function findRotationOverdue(string $tenantId): array;

    /**
     * Lista secretos cuya rotación vence en los próximos N días.
     * Útil para alertas preventivas.
     *
     * @param int $withinDays Días hacia adelante (default: 7)
     * @return array<int, Secret>
     */
    public function findRotationDueSoon(string $tenantId, int $withinDays = 7): array;

    /**
     * Obtiene un secreto con su valor_encriptado para desencriptar.
     * ADVERTENCIA: Este método debe estar auditado (se registra en secrets_access_log).
     *
     * @return Secret|null Null si no existe o no pertenece al tenant.
     */
    public function getDecryptable(string $id, string $tenantId): ?Secret;

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    /**
     * Persiste un secreto nuevo (INSERT).
     *
     * @throws \RuntimeException Si el nombre ya existe en el tenant.
     */
    public function save(Secret $secret): void;

    /**
     * Actualiza un secreto existente (UPDATE).
     * NO permite modificar valor_encriptado directamente (usar rotate).
     *
     * @throws \RuntimeException Si el secreto no existe.
     */
    public function update(Secret $secret): void;

    /**
     * Rota el valor_encriptado de un secreto.
     * Actualiza ultima_rotacion y recalcula proxima_rotacion.
     *
     * @param string $id ID del secreto
     * @param string $tenantId ID del tenant
     * @param string $nuevoValorEncriptado Nuevo valor ya encriptado (AES-256-GCM + base64)
     * @param int|null $rotationDays Días hasta próxima rotación (si aplica)
     * @return bool True si se rotó correctamente
     * @throws \RuntimeException Si el secreto no existe.
     */
    public function rotate(
        string $id,
        string $tenantId,
        string $nuevoValorEncriptado,
        ?int $rotationDays = null
    ): bool;

    /**
     * Desactiva un secreto (revocación inmediata).
     * No lo elimina, solo marca activo=0.
     *
     * @return bool True si se desactivó
     */
    public function deactivate(string $id, string $tenantId): bool;

    /**
     * Reactiva un secreto previamente desactivado.
     *
     * @return bool True si se reactivó
     */
    public function activate(string $id, string $tenantId): bool;

    /**
     * Elimina físicamente un secreto (hard delete).
     * ADVERTENCIA: Operación irreversible. Usar solo en casos extremos.
     *
     * @return bool True si se eliminó
     */
    public function hardDelete(string $id, string $tenantId): bool;

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    /**
     * Verifica si ya existe un secreto con el nombre dado en el tenant.
     */
    public function existsByNombre(string $nombre, string $tenantId): bool;

    /**
     * Verifica si un secreto existe por ID (sin importar estado).
     */
    public function existsById(string $id, string $tenantId): bool;

    /**
     * Cuenta secretos de un tenant.
     *
     * @param array $filters Filtros opcionales (tipo, activo, owner_user_id)
     */
    public function count(string $tenantId, array $filters = []): int;

    /**
     * Cuenta secretos con rotación vencida.
     */
    public function countRotationOverdue(string $tenantId): int;

    /**
     * Cuenta secretos cuya rotación vence en los próximos N días.
     */
    public function countRotationDueSoon(string $tenantId, int $withinDays = 7): int;
}
