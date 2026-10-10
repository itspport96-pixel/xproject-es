<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Security\Entity\Secret;
use App\Core\Security\Repository\SecretRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Container\Container;
use App\Shared\Exceptions\SecurityException;
use App\Shared\Services\DatabaseService;

/**
 * SecretsVaultService - Orquestador del dominio SecretsVault.
 *
 * Responsabilidades:
 * - CRUD de secretos con encriptación automática (AES-256-GCM)
 * - Rotación de secretos (manual y automática)
 * - Desencriptación bajo demanda (solo para operaciones autorizadas)
 * - Validación de ownership y permisos
 * - Aislamiento multi-tenant garantizado
 * - Auditoría de accesos (lectura de valores desencriptados)
 *
 * Reglas de negocio:
 * - El valor NUNCA se expone en listados
 * - Solo se desencripta vía getDecrypted() con auditoría
 * - Rotación = nuevo valor encriptado + actualización de timestamps
 * - Soft delete no aplica: se marca activo=0
 */
final class SecretsVaultService
{
    private SecretRepositoryInterface $repository;
    private EncryptionService $encryption;
    private TenantContext $tenantContext;
    private DatabaseService $db;

    public function __construct(
        ?SecretRepositoryInterface $repository = null,
        ?EncryptionService $encryption = null,
        ?TenantContext $tenantContext = null,
        ?DatabaseService $db = null
    ) {
        $container = Container::getInstance();
        $this->repository = $repository ?? $container->get(SecretRepositoryInterface::class);
        $this->encryption = $encryption ?? $container->get(EncryptionService::class);
        $this->tenantContext = $tenantContext ?? $container->get(TenantContext::class);
        $this->db = $db ?? $container->get('database');
    }

    // ========================================================================
    // CREACIÓN
    // ========================================================================

    /**
     * Crea un nuevo secreto en el tenant actual.
     *
     * @param array $data Datos del secreto:
     *   - nombre (string, requerido, slug único por tenant)
     *   - tipo (string, requerido: API_KEY|PASSWORD|ENCRYPTION_KEY|OAUTH_TOKEN|OTRO)
     *   - valor (string, requerido, texto plano)
     *   - descripcion (string, opcional)
     *   - rotacion_requerida (bool, opcional, default: false)
     *   - rotation_days (int, opcional, default: 90)
     *   - owner_user_id (string, opcional)
     *
     * @return array Secreto creado (SIN valor desencriptado)
     * @throws SecurityException Si el nombre ya existe o datos inválidos
     */
    public function create(array $data): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $this->validateCreateData($data);

        $nombre = strtolower(trim((string) $data['nombre']));

        // Validar unicidad de nombre en el tenant
        if ($this->repository->existsByNombre($nombre, $tenantId)) {
            throw new SecurityException(
                "El secreto '{$nombre}' ya existe en este tenant",
                409
            );
        }

        // Encriptar el valor
        $valorEncriptado = $this->encryption->encrypt((string) $data['valor']);

        $rotationDays = isset($data['rotation_days']) ? (int) $data['rotation_days'] : 90;
        $rotacionRequerida = (bool) ($data['rotacion_requerida'] ?? false);

        $secret = Secret::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            nombre: $nombre,
            tipo: (string) $data['tipo'],
            valorEncriptado: $valorEncriptado,
            descripcion: isset($data['descripcion']) ? (string) $data['descripcion'] : null,
            rotacionRequerida: $rotacionRequerida,
            ownerUserId: isset($data['owner_user_id']) ? (string) $data['owner_user_id'] : null,
            rotationDays: $rotacionRequerida ? $rotationDays : null,
        );

        $this->repository->save($secret);

        // Registrar en audit log
        $this->logAccess($secret->id(), 'CREATE');

        return $secret->toPublicArray();
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * Busca un secreto por ID (SIN valor desencriptado).
     */
    public function findById(string $id): ?array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secret = $this->repository->findById($id, $tenantId);
        return $secret !== null ? $secret->toPublicArray() : null;
    }

    /**
     * Busca un secreto por nombre (SIN valor desencriptado).
     */
    public function findByNombre(string $nombre): ?array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secret = $this->repository->findByNombre($nombre, $tenantId);
        return $secret !== null ? $secret->toPublicArray() : null;
    }

    /**
     * Lista secretos del tenant actual (SIN valores desencriptados).
     *
     * @param array $filters Filtros opcionales:
     *   - tipo (string)
     *   - activo (bool)
     *   - owner_user_id (string)
     *   - rotacion_requerida (bool)
     * @param int $page Página actual
     * @param int $perPage Registros por página
     * @return array{data: array, pagination: array}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        return $this->repository->findAll($tenantId, $filters, $page, $perPage);
    }

    /**
     * Obtiene el valor DESENCRIPTADO de un secreto.
     *
     * ADVERTENCIA: Esta operación se audita.
     * Solo usar cuando sea estrictamente necesario (ej: consumir API externa).
     *
     * @param string $id ID del secreto
     * @return string Valor desencriptado (texto plano)
     * @throws SecurityException Si el secreto no existe o está inactivo
     */
    public function getDecrypted(string $id): string
    {
        $tenantId = $this->tenantContext->getTenantId();

        // Obtener secreto con valor_encriptado
        $secret = $this->repository->getDecryptable($id, $tenantId);
        if ($secret === null) {
            throw SecurityException::secretNotFound($id);
        }

        if (!$secret->isActive()) {
            throw new SecurityException("El secreto '{$secret->nombre()}' está inactivo", 403);
        }

        // Desencriptar
        $valorPlano = $this->encryption->decrypt($secret->valorEncriptado());

        // Auditar acceso
        $this->logAccess($id, 'READ');

        return $valorPlano;
    }

    /**
     * Obtiene el valor desencriptado por nombre.
     */
    public function getDecryptedByNombre(string $nombre): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secret = $this->repository->findByNombre($nombre, $tenantId);

        if ($secret === null) {
            throw SecurityException::secretNotFound($nombre);
        }

        return $this->getDecrypted($secret->id());
    }

    // ========================================================================
    // ACTUALIZACIÓN
    // ========================================================================

    /**
     * Actualiza metadatos del secreto (NO el valor).
     *
     * @param string $id ID del secreto
     * @param array $data Campos a actualizar:
     *   - descripcion (string|null)
     *   - rotacion_requerida (bool)
     *   - rotation_days (int)
     *   - owner_user_id (string|null)
     *
     * @return array Secreto actualizado
     * @throws SecurityException Si el secreto no existe
     */
    public function update(string $id, array $data): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secret = $this->repository->findById($id, $tenantId);

        if ($secret === null) {
            throw SecurityException::secretNotFound($id);
        }

        if (array_key_exists('descripcion', $data)) {
            $secret->actualizarDescripcion(
                $data['descripcion'] !== null ? (string) $data['descripcion'] : null
            );
        }

        if (isset($data['rotacion_requerida'])) {
            if ((bool) $data['rotacion_requerida']) {
                $days = isset($data['rotation_days']) ? (int) $data['rotation_days'] : 90;
                $secret->habilitarRotacion($days);
            } else {
                $secret->deshabilitarRotacion();
            }
        }

        if (array_key_exists('owner_user_id', $data)) {
            $secret->asignarOwner(
                $data['owner_user_id'] !== null ? (string) $data['owner_user_id'] : null
            );
        }

        $this->repository->update($secret);
        $this->logAccess($id, 'UPDATE');

        return $secret->toPublicArray();
    }

    // ========================================================================
    // ROTACIÓN
    // ========================================================================

    /**
     * Rota el valor de un secreto con un nuevo valor.
     *
     * @param string $id ID del secreto
     * @param string $nuevoValorPlano Nuevo valor en texto plano
     * @param int|null $rotationDays Días hasta próxima rotación (si aplica)
     *
     * @return array Secreto rotado
     * @throws SecurityException Si el secreto no existe
     */
    public function rotate(string $id, string $nuevoValorPlano, ?int $rotationDays = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secret = $this->repository->findById($id, $tenantId);

        if ($secret === null) {
            throw SecurityException::secretNotFound($id);
        }

        if ($nuevoValorPlano === '') {
            throw new SecurityException('El nuevo valor no puede estar vacío', 400);
        }

        // Encriptar nuevo valor
        $nuevoValorEncriptado = $this->encryption->encrypt($nuevoValorPlano);

        // Rotar en repositorio
        $this->repository->rotate($id, $tenantId, $nuevoValorEncriptado, $rotationDays);

        // Auditar
        $this->logAccess($id, 'ROTATE');

        // Retornar secreto actualizado
        $updated = $this->repository->findById($id, $tenantId);
        return $updated !== null ? $updated->toPublicArray() : [];
    }

    // ========================================================================
    // ACTIVACIÓN / DESACTIVACIÓN
    // ========================================================================

    /**
     * Desactiva un secreto (revocación inmediata).
     */
    public function deactivate(string $id): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        $result = $this->repository->deactivate($id, $tenantId);
        if ($result) {
            $this->logAccess($id, 'DEACTIVATE');
        }
        return $result;
    }

    /**
     * Reactiva un secreto previamente desactivado.
     */
    public function activate(string $id): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        $result = $this->repository->activate($id, $tenantId);
        if ($result) {
            $this->logAccess($id, 'ACTIVATE');
        }
        return $result;
    }

    /**
     * Elimina físicamente un secreto (hard delete).
     * ADVERTENCIA: Operación irreversible.
     */
    public function hardDelete(string $id): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        $result = $this->repository->hardDelete($id, $tenantId);
        if ($result) {
            $this->logAccess($id, 'DELETE');
        }
        return $result;
    }

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    /**
     * Lista secretos con rotación vencida.
     */
    public function findRotationOverdue(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secrets = $this->repository->findRotationOverdue($tenantId);
        return array_map(fn(Secret $s) => $s->toPublicArray(), $secrets);
    }

    /**
     * Lista secretos cuya rotación vence en los próximos N días.
     */
    public function findRotationDueSoon(int $withinDays = 7): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $secrets = $this->repository->findRotationDueSoon($tenantId, $withinDays);
        return array_map(fn(Secret $s) => $s->toPublicArray(), $secrets);
    }

    /**
     * Cuenta secretos del tenant actual.
     */
    public function count(array $filters = []): int
    {
        $tenantId = $this->tenantContext->getTenantId();
        return $this->repository->count($tenantId, $filters);
    }

    /**
     * Cuenta secretos con rotación vencida.
     */
    public function countRotationOverdue(): int
    {
        $tenantId = $this->tenantContext->getTenantId();
        return $this->repository->countRotationOverdue($tenantId);
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private function validateCreateData(array $data): void
    {
        $required = ['nombre', 'tipo', 'valor'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new SecurityException("El campo '{$field}' es obligatorio", 400);
            }
        }

        if (!in_array($data['tipo'], Secret::VALID_TYPES, true)) {
            throw new SecurityException(
                "Tipo de secreto inválido: '{$data['tipo']}'. Válidos: " . implode(', ', Secret::VALID_TYPES),
                400
            );
        }

        if (strlen((string) $data['valor']) > 5000) {
            throw new SecurityException('El valor excede longitud máxima (5000 caracteres)', 400);
        }
    }

    // ========================================================================
    // AUDITORÍA
    // ========================================================================

    /**
     * Registra acceso a un secreto en secrets_access_log.
     * Si la tabla no existe, falla silenciosamente.
     */
    private function logAccess(string $secretId, string $accion): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            $sql = "INSERT INTO secrets_access_log
                    (id, secret_id, user_id, timestamp, accion, ip_address, user_agent, resultado)
                    VALUES
                    (:id, :secret_id, :user_id, NOW(), :accion, :ip, :ua, 'SUCCESS')";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([
                'id' => $this->generateUuid(),
                'secret_id' => $secretId,
                'user_id' => $userId,
                'accion' => $accion,
                'ip' => $ip,
                'ua' => substr($userAgent, 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Silencioso: la tabla puede no existir aún
            error_log("SecretsVault logAccess failed: {$e->getMessage()}");
        }
    }

    private function getCurrentUserId(): ?string
    {
        try {
            return \App\Core\Authentication\Service\AuthenticationContext::userId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
