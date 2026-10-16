<?php

declare(strict_types=1);

namespace App\Core\Tenants\Service;

use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Security\Service\EncryptionService;
use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Users\Service\UserService;
use App\Modules\Inventory\Application\Services\TransitLocationService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;

/**
 * Servicio de aplicación para el dominio Tenants.
 *
 * Responsabilidades:
 * - CRUD de tenants con validación de reglas de negocio
 * - Transiciones de estado (activar, suspender, cancelar)
 * - Creación de tenant con admin fundador (orquestación transaccional)
 * - Asignación/remoción de owner
 * - Validación de unicidad (nombre_interno, dominio_custom)
 * - Invalidación de cache tras cambios
 */
final class TenantService
{
    private TenantRepositoryInterface $tenants;
    private UserService $users;
    private UserRepositoryInterface $userRepo;
    private PasswordHasher $hasher;
    private DatabaseService $db;
    private RedisService $redis;
    private EncryptionService $encryption;

    private const CACHE_PREFIX = 'tenant:';
    private const CACHE_TTL = 300; // 5 minutos

    public function __construct(
        ?TenantRepositoryInterface $tenants = null,
        ?UserService $users = null,
        ?UserRepositoryInterface $userRepo = null,
        ?PasswordHasher $hasher = null,
        ?DatabaseService $db = null,
        ?RedisService $redis = null,
        ?EncryptionService $encryption = null
    ) {
        $container = Container::getInstance();
        $this->tenants = $tenants ?? $container->get(TenantRepositoryInterface::class);
        $this->users = $users ?? $container->get(UserService::class);
        $this->userRepo = $userRepo ?? $container->get(UserRepositoryInterface::class);
        $this->hasher = $hasher ?? $container->get(PasswordHasher::class);
        $this->db = $db ?? $container->get('database');
        $this->redis = $redis ?? $container->get(RedisService::class);
        $this->encryption = $encryption ?? $container->get(EncryptionService::class);
    }

    // ========================================================================
    // CREACIÓN
    // ========================================================================

    /**
     * Crea un nuevo tenant.
     *
     * @param array $data Datos del tenant:
     *   - nombre_publico (string, requerido)
     *   - nombre_interno (string, requerido, único, slug)
     *   - plan (string, opcional, default: FREE)
     *   - email_contacto (string, opcional)
     *   - telefono (string, opcional)
     *   - pais (string, opcional)
     *   - zona_horaria (string, opcional)
     *   - idioma_default (string, opcional)
     *   - dominio_custom (string, opcional, único si asignado)
     *   - configuracion (array, opcional)
     *   - metadatos (array, opcional)
     *
     * @return array Tenant creado
     * @throws TenantException Si nombre_interno o dominio_custom ya existen
     */
    public function create(array $data): array
    {
        $this->validateCreateData($data);

        $nombreInterno = strtolower(trim((string) $data['nombre_interno']));

        // Validar unicidad de nombre_interno
        if ($this->tenants->existsByNombreInterno($nombreInterno)) {
            throw TenantException::nombreInternoAlreadyExists($nombreInterno);
        }

        // Validar unicidad de dominio_custom si se proporciona
        if (!empty($data['dominio_custom'])) {
            $dominio = strtolower(trim((string) $data['dominio_custom']));
            if ($this->tenants->existsByDominioCustom($dominio)) {
                throw TenantException::dominioCustomAlreadyExists($dominio);
            }
        }

        $tenant = Tenant::create(
            id: $this->generateUuid(),
            nombrePublico: (string) $data['nombre_publico'],
            nombreInterno: $nombreInterno,
            plan: (string) ($data['plan'] ?? Tenant::PLAN_FREE),
            emailContacto: isset($data['email_contacto']) ? (string) $data['email_contacto'] : null,
            telefono: isset($data['telefono']) ? (string) $data['telefono'] : null,
            pais: isset($data['pais']) ? (string) $data['pais'] : null,
            zonaHoraria: isset($data['zona_horaria']) ? (string) $data['zona_horaria'] : null,
            idiomaDefault: isset($data['idioma_default']) ? (string) $data['idioma_default'] : null,
            dominioCustom: isset($data['dominio_custom']) ? (string) $data['dominio_custom'] : null,
            configuracion: isset($data['configuracion']) ? (array) $data['configuracion'] : null,
            metadatos: isset($data['metadatos']) ? (array) $data['metadatos'] : null,
        );

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $this->tenants->save($tenant);
            // Cada tenant nace con su ubicación virtual TRANSITO (v1.7.1):
            // sin ella `POST /shipments/authorize` respondería 404.
            $this->seedTransitLocation($tenant->id());
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e instanceof TenantException
                ? $e
                : new TenantException('Error al crear el tenant: ' . $e->getMessage(), 500, $e);
        }

        return $tenant->toPublicArray();
    }

    /**
     * Crea un tenant con su admin fundador en una transacción atómica.
     *
     * Este método orquesta:
     * 1. Creación del tenant
     * 2. Creación del usuario admin
     * 3. Asignación del rol ADMIN al usuario
     * 4. Asignación del owner_user_id al tenant
     *
     * Si cualquier paso falla, hace rollback completo.
     *
     * @param array $tenantData Datos del tenant (ver create())
     * @param array $adminData Datos del admin:
     *   - email (string, requerido)
     *   - nombre (string, requerido)
     *   - apellido (string, requerido)
     *   - password (string, requerido, texto plano)
     *   - telefono (string, opcional)
     *
     * @return array{tenant: array, admin: array} Tenant y admin creados
     * @throws TenantException Si falla la creación
     */
    public function createWithOwner(array $tenantData, array $adminData): array
    {
        $this->validateCreateData($tenantData);
        $this->validateAdminData($adminData);

        $nombreInterno = strtolower(trim((string) $tenantData['nombre_interno']));
        $email = strtolower(trim((string) $adminData['email']));

        // Validar unicidades antes de la transacción
        if ($this->tenants->existsByNombreInterno($nombreInterno)) {
            throw TenantException::nombreInternoAlreadyExists($nombreInterno);
        }

        if (!empty($tenantData['dominio_custom'])) {
            $dominio = strtolower(trim((string) $tenantData['dominio_custom']));
            if ($this->tenants->existsByDominioCustom($dominio)) {
                throw TenantException::dominioCustomAlreadyExists($dominio);
            }
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            // 1. Crear tenant
            $tenantId = $this->generateUuid();
            $tenant = Tenant::create(
                id: $tenantId,
                nombrePublico: (string) $tenantData['nombre_publico'],
                nombreInterno: $nombreInterno,
                plan: (string) ($tenantData['plan'] ?? Tenant::PLAN_FREE),
                emailContacto: isset($tenantData['email_contacto']) ? (string) $tenantData['email_contacto'] : null,
                telefono: isset($tenantData['telefono']) ? (string) $tenantData['telefono'] : null,
                pais: isset($tenantData['pais']) ? (string) $tenantData['pais'] : null,
                zonaHoraria: isset($tenantData['zona_horaria']) ? (string) $tenantData['zona_horaria'] : null,
                idiomaDefault: isset($tenantData['idioma_default']) ? (string) $tenantData['idioma_default'] : null,
                dominioCustom: isset($tenantData['dominio_custom']) ? (string) $tenantData['dominio_custom'] : null,
                configuracion: isset($tenantData['configuracion']) ? (array) $tenantData['configuracion'] : null,
                metadatos: isset($tenantData['metadatos']) ? (array) $tenantData['metadatos'] : null,
            );

            $this->tenants->save($tenant);

            // Ubicación virtual TRANSITO del tenant (v1.7.1), en la MISMA
            // transacción: si falla, no se crea el tenant.
            $this->seedTransitLocation($tenantId);

            // 2. Crear usuario admin
            $adminUserId = $this->generateUuid();
            $passwordHash = $this->hasher->hash((string) $adminData['password']);
            $now = date('Y-m-d H:i:s');

            $sql = "INSERT INTO users
                    (id, tenant_id, email, nombre, apellido, password, estado, telefono, email_verificado, created_at, updated_at)
                    VALUES
                    (:id, :tenant_id, :email, :nombre, :apellido, :password, 'ACTIVO', :telefono, 1, :created_at, :updated_at)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id'         => $adminUserId,
                'tenant_id'  => $tenantId,
                'email'      => $email,
                'nombre'     => trim((string) $adminData['nombre']),
                'apellido'   => trim((string) $adminData['apellido']),
                'password'   => $passwordHash,
                'telefono'   => isset($adminData['telefono']) ? trim((string) $adminData['telefono']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 3. Asignar rol ADMIN al usuario
            $adminRoleId = $this->getBuiltInRoleId('ADMIN');
            if ($adminRoleId === null) {
                throw (new TenantException('El rol BUILT_IN ADMIN no existe en el sistema', 500))->withMessageKey('tenants.admin_role_missing');
            }

            $sql = "INSERT INTO role_assignments
                    (id, user_id, role_id, tenant_id, fecha_asignacion, created_at, updated_at)
                    VALUES
                    (:id, :user_id, :role_id, :tenant_id, NOW(), NOW(), NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id'        => $this->generateUuid(),
                'user_id'   => $adminUserId,
                'role_id'   => $adminRoleId,
                'tenant_id' => $tenantId,
            ]);

            // 4. Asignar owner_user_id al tenant
            $tenant->asignarOwner($adminUserId);
            $this->tenants->update($tenant);

            $pdo->commit();

            // Invalidar cache
            $this->invalidateTenantCache($tenantId);

            return [
                'tenant' => $tenant->toPublicArray(),
                'admin'  => [
                    'id'       => $adminUserId,
                    'email'    => $email,
                    'nombre'   => trim((string) $adminData['nombre']),
                    'apellido' => trim((string) $adminData['apellido']),
                ],
            ];

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new TenantException(
                'Error al crear tenant con owner: ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * Busca un tenant por ID.
     */
    public function findById(string $id): ?array
    {
        $tenant = $this->tenants->findById($id);
        return $tenant !== null ? $tenant->toPublicArray() : null;
    }

    /**
     * Busca un tenant por nombre_interno.
     */
    public function findByNombreInterno(string $nombreInterno): ?array
    {
        $tenant = $this->tenants->findByNombreInterno($nombreInterno);
        return $tenant !== null ? $tenant->toPublicArray() : null;
    }

    /**
     * Busca un tenant por dominio_custom.
     */
    public function findByDominioCustom(string $dominio): ?array
    {
        $tenant = $this->tenants->findByDominioCustom($dominio);
        return $tenant !== null ? $tenant->toPublicArray() : null;
    }

    /**
     * Lista todos los tenants.
     *
     * @param bool $soloActivos Si true, excluye tenants eliminados
     * @return array<int, array>
     */
    public function findAll(bool $soloActivos = true): array
    {
        $tenants = $this->tenants->findAll($soloActivos);
        return array_map(fn(Tenant $t) => $t->toPublicArray(), $tenants);
    }

    /**
     * Lista tenants filtrados por estado.
     */
    public function findByEstado(string $estado): array
    {
        $tenants = $this->tenants->findByEstado($estado);
        return array_map(fn(Tenant $t) => $t->toPublicArray(), $tenants);
    }

    /**
     * Lista tenants filtrados por plan.
     */
    public function findByPlan(string $plan): array
    {
        $tenants = $this->tenants->findByPlan($plan);
        return array_map(fn(Tenant $t) => $t->toPublicArray(), $tenants);
    }

    /**
     * Lista tenants operativos (activos y no expirados).
     */
    public function findOperativos(): array
    {
        $tenants = $this->tenants->findOperativos();
        return array_map(fn(Tenant $t) => $t->toPublicArray(), $tenants);
    }

    // ========================================================================
    // ACTUALIZACIÓN
    // ========================================================================

    /**
     * Actualiza los datos de un tenant.
     *
     * @param array $data Campos a actualizar:
     *   - nombre_interno (string, opcional, único global; slug)
     *   - nombre_publico (string, opcional)
     *   - email_contacto (string, opcional)
     *   - telefono (string, opcional)
     *   - pais (string, opcional)
     *   - zona_horaria (string, opcional)
     *   - idioma_default (string, opcional)
     *   - dominio_custom (string, opcional, null para remover)
     *   - configuracion (array, opcional)
     *   - metadatos (array, opcional)
     *
     * @return array Tenant actualizado
     * @throws TenantException Si el tenant no existe o dominio_custom duplicado
     */
    public function update(string $id, array $data): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        // Validar unicidad de dominio_custom si cambió
        if (isset($data['dominio_custom']) && $data['dominio_custom'] !== $tenant->dominioCustom()) {
            if ($data['dominio_custom'] !== null) {
                $dominio = strtolower(trim((string) $data['dominio_custom']));
                if ($this->tenants->existsByDominioCustom($dominio)) {
                    throw TenantException::dominioCustomAlreadyExists($dominio);
                }
            }
        }

        // Actualizar campos
        if (isset($data['nombre_publico'])) {
            $tenant->actualizarNombrePublico((string) $data['nombre_publico']);
        }

        // Nombre interno (settings): normaliza y valida slug; la unicidad
        // (por nombre_interno y por nombre_interno_hmac) la resuelve el
        // repositorio antes de escribir.
        if (isset($data['nombre_interno'])) {
            $nombreInterno = strtolower(trim((string) $data['nombre_interno']));
            if ($nombreInterno !== $tenant->nombreInterno()) {
                $tenant->actualizarNombreInterno($nombreInterno);
            }
        }

        if (array_key_exists('email_contacto', $data) ||
            array_key_exists('telefono', $data) ||
            array_key_exists('pais', $data) ||
            array_key_exists('zona_horaria', $data) ||
            array_key_exists('idioma_default', $data)) {
            $tenant->actualizarContacto(
                $data['email_contacto'] ?? $tenant->emailContacto(),
                $data['telefono'] ?? $tenant->telefono(),
                array_key_exists('pais', $data) ? self::normalizePais($data['pais']) : $tenant->pais(),
                $data['zona_horaria'] ?? $tenant->zonaHoraria(),
                $data['idioma_default'] ?? $tenant->idiomaDefault()
            );
        }

        if (array_key_exists('dominio_custom', $data)) {
            if ($data['dominio_custom'] === null) {
                $tenant->removerDominioCustom();
            } else {
                $tenant->asignarDominioCustom((string) $data['dominio_custom']);
            }
        }

        if (array_key_exists('logo_url', $data)) {
            $logo = $data['logo_url'] === null ? null : trim((string) $data['logo_url']);
            if ($logo !== null && $logo !== '' && !self::isValidLogoUrl($logo)) {
                throw TenantException::invalidLogoUrl();
            }
            $tenant->actualizarLogo($logo);
        }

        if (isset($data['configuracion'])) {
            if (!is_array($data['configuracion'])) {
                throw TenantException::invalidConfiguracion('configuracion');
            }
            $cambios = $data['configuracion'];
            // SMTP: la password llega en texto plano y se guarda cifrada;
            // null/vacío = no tocar la password ya almacenada (v1.14.1).
            if (isset($cambios['smtp']) && is_array($cambios['smtp'])) {
                $pwd = $cambios['smtp']['password'] ?? null;
                if ($pwd === null || $pwd === '') {
                    unset($cambios['smtp']['password']);
                } else {
                    $cambios['smtp']['password'] = $this->encryption->encryptForStorage((string) $pwd);
                }
            }
            $merged = self::mergeConfiguracion($tenant->configuracion() ?? [], $cambios);
            self::validateConfiguracion($merged);
            $tenant->actualizarConfiguracion($merged === [] ? null : $merged);
        }

        if (isset($data['metadatos'])) {
            $tenant->actualizarMetadatos((array) $data['metadatos']);
        }

        $this->tenants->update($tenant);

        // Invalidar cache
        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    /**
     * Cambia el plan de un tenant.
     */
    public function changePlan(string $id, string $plan): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $tenant->cambiarPlan($plan);
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    // ========================================================================
    // TRANSICIONES DE ESTADO
    // ========================================================================

    /**
     * Activa un tenant (desde PENDIENTE_ACTIVACION o SUSPENDIDO).
     */
    public function activate(string $id): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $tenant->activar();
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    /**
     * Suspende un tenant (solo desde ACTIVO).
     */
    public function suspend(string $id): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $tenant->suspender();
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    /**
     * Cancela un tenant (estado terminal).
     */
    public function cancel(string $id): array
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $tenant->cancelar();
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    /**
     * Soft delete de un tenant.
     */
    public function delete(string $id): bool
    {
        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $this->tenants->softDelete($id);

        $this->invalidateTenantCache($id);

        return true;
    }

    /**
     * Restaura un tenant previamente eliminado.
     */
    public function restore(string $id): array
    {
        $this->tenants->restore($id);

        $tenant = $this->tenants->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $this->invalidateTenantCache($id);

        return $tenant->toPublicArray();
    }

    // ========================================================================
    // ASIGNACIÓN DE OWNER
    // ========================================================================

    /**
     * Asigna un owner al tenant.
     */
    public function assignOwner(string $tenantId, string $ownerUserId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($tenantId);
        }

        // Validar que el usuario exista
        $user = $this->userRepo->findById($ownerUserId, $tenantId);
        if ($user === null) {
            throw TenantException::ownerNotFound($ownerUserId);
        }

        $tenant->asignarOwner($ownerUserId);
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($tenantId);

        return $tenant->toPublicArray();
    }

    /**
     * Remueve el owner del tenant.
     */
    public function removeOwner(string $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($tenantId);
        }

        $tenant->asignarOwner(null);
        $this->tenants->update($tenant);

        $this->invalidateTenantCache($tenantId);

        return $tenant->toPublicArray();
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private function validateCreateData(array $data): void
    {
        $required = ['nombre_publico', 'nombre_interno'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new TenantException("El campo '{$field}' es obligatorio", 400);
            }
        }

        if (strlen((string) $data['nombre_publico']) > 255) {
            throw (new TenantException('nombre_publico excede longitud máxima (255)', 400))->withMessageKey('tenants.nombre_publico_max_length');
        }

        if (strlen((string) $data['nombre_interno']) > 255) {
            throw (new TenantException('nombre_interno excede longitud máxima (255)', 400))->withMessageKey('tenants.nombre_interno_max_length');
        }

        if (!empty($data['email_contacto']) && !filter_var($data['email_contacto'], FILTER_VALIDATE_EMAIL)) {
            throw TenantException::invalidEmailContacto((string) $data['email_contacto']);
        }
    }

    private function validateAdminData(array $data): void
    {
        $required = ['email', 'nombre', 'apellido', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new TenantException("El campo '{$field}' del admin es obligatorio", 400);
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw (new TenantException('Email del admin inválido', 400))->withMessageKey('tenants.admin_email_invalid');
        }

        if (strlen((string) $data['password']) < 8) {
            throw (new TenantException('Password del admin debe tener mínimo 8 caracteres', 400))->withMessageKey('tenants.admin_password_min_length');
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Siembra las ubicaciones fijas del tenant: virtual TRANSITO (spec
     * CU-006) y zona DAÑADOS (spec CU-009, v1.8.0).
     *
     * Se resuelve DESDE EL CONTENEDOR en el momento del uso (no en el
     * constructor): el módulo de inventario se registra después que este
     * servicio en `bootstrap.php`, y una dependencia opcional en la firma
     * obligaría a tocar todas las construcciones de TenantService.
     *
     * Cualquier fallo se traduce en TenantException para que el alta de tenant
     * no quede a medias (un tenant sin TRANSITO no puede autorizar envíos y
     * uno sin DAÑADOS no puede registrar devoluciones dañadas).
     */
    private function seedTransitLocation(string $tenantId): void
    {
        try {
            $service = Container::getInstance()->get(TransitLocationService::class);
            $service->ensureForTenant($tenantId);
            $service->ensureDamagedZoneForTenant($tenantId);
        } catch (TenantException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw TenantException::transitLocationSeedFailed($tenantId, $e->getMessage());
        }
    }

    /**
     * Obtiene el ID del rol BUILT_IN por nombre.
     */
    private function getBuiltInRoleId(string $nombre): ?string
    {
        $sql = "SELECT id FROM roles WHERE nombre = :nombre AND tipo = 'BUILT_IN' AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['nombre' => $nombre]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (string) $id : null;
    }

    /**
     * Invalida el cache de un tenant específico.
     */
    public const FORMATOS_FECHA = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD-MM-YYYY', 'DD.MM.YYYY'];

    /** Mismo catalogo que el selector de Configuracion > Preferencias regionales. */
    public const MONEDAS = ['MXN', 'USD', 'EUR', 'COP', 'ARS', 'CLP', 'PEN', 'BRL', 'GTQ', 'CRC', 'UYU', 'BOB', 'PYG', 'DOP', 'GBP', 'CAD'];

    /** Cifrados admitidos por el formulario SMTP (v1.14.1). */
    public const SMTP_ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /**
     * Fusion por secciones (v1.14.0): un PATCH parcial no borra el resto de
     * la configuracion; una clave con null se elimina y una seccion vacia
     * desaparece.
     */
    private static function mergeConfiguracion(array $actual, array $cambios): array
    {
        foreach ($cambios as $seccion => $valor) {
            if (is_array($valor) && is_array($actual[$seccion] ?? null)) {
                $valor = array_replace($actual[$seccion], $valor);
            }
            if (is_array($valor)) {
                $valor = array_filter($valor, static fn($v) => $v !== null);
            }
            if ($valor === null || $valor === []) {
                unset($actual[$seccion]);
            } else {
                $actual[$seccion] = $valor;
            }
        }
        foreach ($actual as $seccion => $valor) {
            if (is_array($valor)) {
                $valor = array_filter($valor, static fn($v) => $v !== null);
                if ($valor === []) {
                    unset($actual[$seccion]);
                    continue;
                }
                $actual[$seccion] = $valor;
            }
        }
        return $actual;
    }

    /** http(s) externo o ruta propia de subida (uploads/tenants/...). */
    private static function isValidLogoUrl(string $url): bool
    {
        if (strlen($url) > 255) {
            return false;
        }
        if (preg_match('#^uploads/tenants/[0-9a-f\-]{36}/logo-[0-9]+\.(png|jpg|webp)$#', $url)) {
            return true;
        }
        return (bool) preg_match('#^https?://[^\s"\'<>]+$#i', $url);
    }

    /** Valida las claves conocidas de configuracion (perfil/apariencia/regional/smtp/seguridad). */
    private static function validateConfiguracion(array $config): void
    {
        $perfil = $config['perfil'] ?? [];
        $apariencia = $config['apariencia'] ?? [];
        $regional = $config['regional'] ?? [];
        $smtp = $config['smtp'] ?? [];
        $seguridad = $config['seguridad'] ?? [];
        if (!is_array($perfil) || !is_array($apariencia) || !is_array($regional)
            || !is_array($smtp) || !is_array($seguridad)) {
            throw TenantException::invalidConfiguracion('configuracion');
        }

        $direccion = $perfil['direccion'] ?? null;
        if ($direccion !== null && (!is_string($direccion) || mb_strlen($direccion) > 500)) {
            throw TenantException::invalidConfiguracion('perfil.direccion');
        }

        $color = $apariencia['color_primario'] ?? null;
        if ($color !== null && (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color))) {
            throw TenantException::invalidConfiguracion('apariencia.color_primario');
        }

        $moneda = $regional['moneda'] ?? null;
        if ($moneda !== null && (!is_string($moneda) || !in_array($moneda, self::MONEDAS, true))) {
            throw TenantException::invalidConfiguracion('regional.moneda');
        }

        $formato = $regional['formato_fecha'] ?? null;
        if ($formato !== null && !in_array($formato, self::FORMATOS_FECHA, true)) {
            throw TenantException::invalidConfiguracion('regional.formato_fecha');
        }

        self::validateSmtp($smtp);
        self::validateSeguridad($seguridad);
    }

    /** Sección smtp: host/puerto/encryption/username/password/from_* (v1.14.1). */
    private static function validateSmtp(array $smtp): void
    {
        $host = $smtp['host'] ?? null;
        if ($host !== null && (!is_string($host) || trim($host) === '' || strlen($host) > 255)) {
            throw TenantException::invalidConfiguracion('smtp.host');
        }

        $puerto = $smtp['puerto'] ?? null;
        if ($puerto !== null && (!is_int($puerto) || $puerto < 1 || $puerto > 65535)) {
            throw TenantException::invalidConfiguracion('smtp.puerto');
        }

        $enc = $smtp['encryption'] ?? null;
        if ($enc !== null && (!is_string($enc) || !in_array($enc, self::SMTP_ENCRYPTIONS, true))) {
            throw TenantException::invalidConfiguracion('smtp.encryption');
        }

        $username = $smtp['username'] ?? null;
        if ($username !== null && (!is_string($username) || strlen($username) > 255)) {
            throw TenantException::invalidConfiguracion('smtp.username');
        }

        $password = $smtp['password'] ?? null;
        // Límite generoso: el valor almacenado es el cifrado base64 (iv+tag+texto).
        if ($password !== null && (!is_string($password) || strlen($password) > 1024)) {
            throw TenantException::invalidConfiguracion('smtp.password');
        }

        $fromEmail = $smtp['from_email'] ?? null;
        if ($fromEmail !== null && (!is_string($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL))) {
            throw TenantException::invalidConfiguracion('smtp.from_email');
        }

        $fromName = $smtp['from_name'] ?? null;
        if ($fromName !== null && (!is_string($fromName) || strlen($fromName) > 255)) {
            throw TenantException::invalidConfiguracion('smtp.from_name');
        }
    }

    /** Sección seguridad: caducidad de contraseña en días (v1.14.1). */
    private static function validateSeguridad(array $seguridad): void
    {
        $dias = $seguridad['caducidad_password_dias'] ?? null;
        if ($dias !== null && (!is_int($dias) || $dias < 1 || $dias > 365)) {
            throw TenantException::invalidConfiguracion('seguridad.caducidad_password_dias');
        }
    }

    /**
     * Normaliza el país a código ISO 3166-1 alpha-2 (v1.14.1).
     * null/vacío se aceptan (campo opcional).
     *
     * @throws TenantException Si no es un código alpha-2
     */
    private static function normalizePais($pais): ?string
    {
        if ($pais === null || $pais === '') {
            return null;
        }
        $pais = strtoupper(trim((string) $pais));
        if (!preg_match('/^[A-Z]{2}$/', $pais)) {
            throw TenantException::invalidPais($pais);
        }
        return $pais;
    }

    private function invalidateTenantCache(string $tenantId): void
    {
        $cacheKey = self::CACHE_PREFIX . $tenantId;
        $this->redis->delete($cacheKey);
    }

    /**
     * Genera un UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
