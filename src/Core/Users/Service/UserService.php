<?php

declare(strict_types=1);

namespace App\Core\Users\Service;

use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Exception\UserPreferencesException;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;

/**
 * Servicio de aplicación para el dominio Users.
 *
 * Responsabilidades:
 * - CRUD de usuarios con validación de reglas de negocio
 * - Hash y verificación de passwords
 * - Asignación y remoción de roles
 * - Validación de email único por tenant
 * - Soft delete
 */
final class UserService
{
    /**
     * Preferencias del usuario persistidas en users.preferencias (JSON).
     * Valores validos: idioma en es|en|pt|fr (whitelist de i18n) y tema en
     * dark|light|system (VALID_THEMES del frontend). Las claves desconocidas
     * que traiga el cliente se ignoran; las ya guardadas se conservan.
     */
    public const PREFERENCIAS_IDIOMA = ['es', 'en', 'pt', 'fr'];
    public const PREFERENCIAS_TEMA = ['dark', 'light', 'system'];
    private const PREFERENCIAS_CLAVES = ['idioma', 'tema'];

    private UserRepositoryInterface $users;
    private PasswordHasher $hasher;
    private DatabaseService $db;

    public function __construct(
        ?UserRepositoryInterface $users = null,
        ?PasswordHasher $hasher = null,
        ?DatabaseService $db = null
    ) {
        $container = Container::getInstance();
        $this->users = $users ?? $container->get(UserRepositoryInterface::class);
        $this->hasher = $hasher ?? $container->get(PasswordHasher::class);
        $this->db = $db ?? $container->get('database');
    }

    // ========================================================================
    // CREACIÓN
    // ========================================================================

    /**
     * Crea un nuevo usuario en un tenant.
     *
     * @param array $data Datos del usuario:
     *   - tenant_id (string, requerido)
     *   - email (string, requerido)
     *   - nombre (string, requerido)
     *   - apellido (string, requerido)
     *   - password (string, opcional; si falta se genera uno temporal con
     *     vigencia TEMP_PASSWORD_TTL_DAYS días y se devuelve una sola vez
     *     en `password_temporal`)
     *   - telefono (string, opcional)
     *
     * @return array Usuario creado (sin password). Si el password fue
     *         generado, incluye password_temporal y password_change_required
     * @throws UserException Si el email ya existe o datos inválidos
     */
    public function create(array $data): array
    {
        $this->validateCreateData($data);

        $tenantId = (string) $data['tenant_id'];
        $email = strtolower(trim((string) $data['email']));

        // Validar email único por tenant
        if ($this->users->findByEmail($email, $tenantId) !== null) {
            throw UserException::emailAlreadyExists($email);
        }

        // Password: si no viene, se genera uno temporal que obligará a
        // cambiarlo en el primer inicio de sesión (v1.11.0).
        $autogenerado = !isset($data['password']) || (string) $data['password'] === '';
        $password = $autogenerado ? $this->generateTemporaryPassword() : (string) $data['password'];

        // Validar fortaleza del password
        $this->validatePasswordStrength($password);

        // Hash del password
        $passwordHash = $this->hasher->hash($password);

        // Generar ID
        $id = $this->generateUuid();

        // Insertar usuario
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO users
                (id, tenant_id, email, nombre, apellido, password, estado, telefono, fecha_expiracion_password, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :email, :nombre, :apellido, :password, 'ACTIVO', :telefono, :fecha_expiracion_password, :created_at, :updated_at)";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'id'         => $id,
            'tenant_id'  => $tenantId,
            'email'      => $email,
            'nombre'     => trim((string) $data['nombre']),
            'apellido'   => trim((string) $data['apellido']),
            'password'   => $passwordHash,
            'telefono'   => isset($data['telefono']) ? trim((string) $data['telefono']) : null,
            'fecha_expiracion_password' => $autogenerado ? $this->tempPasswordExpiry() : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Retornar sin password (el temporal se expone una sola vez)
        return array_merge($this->sanitizeUser([
            'id'         => $id,
            'tenant_id'  => $tenantId,
            'email'      => $email,
            'nombre'     => trim((string) $data['nombre']),
            'apellido'   => trim((string) $data['apellido']),
            'estado'     => 'ACTIVO',
            'telefono'   => isset($data['telefono']) ? trim((string) $data['telefono']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]), [
            'password_change_required' => $autogenerado,
            'password_temporal'        => $autogenerado ? $password : null,
        ]);
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    /**
     * Busca un usuario por ID dentro de un tenant.
     */
    public function findById(string $id, string $tenantId): ?array
    {
        $user = $this->users->findById($id, $tenantId);
        return $user !== null ? $this->sanitizeUser($user) : null;
    }

    /**
     * Busca un usuario por id INCLUYendo el hash del password.
     * Uso interno exclusivo: verificación de credenciales (cambio de email
     * desde el perfil, change-password, etc). Nunca exponer en API responses.
     */
    public function findByIdWithPassword(string $id, string $tenantId): ?array
    {
        return $this->users->findById($id, $tenantId);
    }

    /**
     * Busca un usuario por email dentro de un tenant.
     */
    public function findByEmail(string $email, string $tenantId): ?array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)), $tenantId);
        return $user !== null ? $this->sanitizeUser($user) : null;
    }

    /**
     * Lista usuarios de un tenant con filtros opcionales.
     *
     * @param array $filters Filtros opcionales:
     *   - estado (string)
     *   - search (string, busca en email/nombre/apellido)
     *   - page (int, default 1)
     *   - per_page (int, default 20, max 100)
     *
     * @return array{data: array, pagination: array}
     */
    public function findAll(string $tenantId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['u.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['estado'])) {
            $where[] = 'u.estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (!empty($filters['search'])) {
            // PDO con emulación desactivada no permite repetir el mismo placeholder nombrado
            $where[] = '(u.email LIKE :search1 OR u.nombre LIKE :search2 OR u.apellido LIKE :search3)';
            $term = '%' . $filters['search'] . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
            $params['search3'] = $term;
        }

        $whereClause = implode(' AND ', $where);

        // Contar total (los borrados lógicos nunca se listan)
        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereClause} AND u.deleted_at IS NULL";
        $countStmt = $this->db->getConnection()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Obtener datos
        $sql = "SELECT u.id, u.tenant_id, u.email, u.nombre, u.apellido, u.estado,
                       u.telefono, u.avatar_url, u.ultimo_login, u.mfa_habilitado,
                       u.created_at, u.updated_at, u.deleted_at
                FROM users u
                WHERE {$whereClause} AND u.deleted_at IS NULL
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->getConnection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    // ========================================================================
    // ACTUALIZACIÓN
    // ========================================================================

    /**
     * Actualiza los datos de un usuario.
     *
     * @param array $data Campos a actualizar:
     *   - nombre (string, opcional)
     *   - apellido (string, opcional)
     *   - telefono (string, opcional)
     *   - estado (string, opcional: ACTIVO|INACTIVO|SUSPENDIDO)
     *   - email (string, opcional, valida unicidad)
     *
     * @return array Usuario actualizado
     * @throws UserException Si el usuario no existe o email duplicado
     */
    public function update(string $id, string $tenantId, array $data): array
    {
        $user = $this->users->findById($id, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($id);
        }

        $updates = [];
        $params = ['id' => $id, 'tenant_id' => $tenantId];

        // Validar y preparar email
        if (isset($data['email'])) {
            $newEmail = strtolower(trim((string) $data['email']));
            if ($newEmail !== $user['email']) {
                $this->validateEmail($newEmail);
                if ($this->users->findByEmail($newEmail, $tenantId) !== null) {
                    throw UserException::emailAlreadyExists($newEmail);
                }
                $updates[] = 'email = :email';
                $params['email'] = $newEmail;
            }
        }

        // Campos simples
        $simpleFields = ['nombre', 'apellido', 'telefono'];
        foreach ($simpleFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = :{$field}";
                $params[$field] = trim((string) $data[$field]);
            }
        }

        // Estado
        if (isset($data['estado'])) {
            $this->validateEstado((string) $data['estado']);
            $updates[] = 'estado = :estado';
            $params['estado'] = (string) $data['estado'];
        }

        if (empty($updates)) {
            return $this->sanitizeUser($user);
        }

        $updates[] = 'updated_at = :updated_at';
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return $this->sanitizeUser($this->users->findById($id, $tenantId));
    }

    // ========================================================================
    // AUTO-EDICIÓN DE PERFIL (PATCH /api/users/me)
    // ========================================================================

    /**
     * Actualiza el perfil propio del usuario (nombre, apellido, email).
     *
     * A diferencia de update() (admin), este método:
     * - Sólo admite nombre, apellido y email (no estado, teléfono, etc.)
     * - Resetea email_verificado a false si el email cambia (verificación
     *   diferida: el flujo SMTP se implementará cuando el tenant lo configure)
     * - La verificación de password_actual se hace en el controller
     *
     * @param array $input Campos a actualizar: nombre?, apellido?, email?
     * @return array Usuario actualizado (sanitizado, sin password)
     * @throws UserException 404 si no existe, 400 si datos inválidos,
     *         409 si el email ya está en uso
     */
    public function updateMe(string $userId, string $tenantId, array $input): array
    {
        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($userId);
        }

        $updates = [];
        $params = ['id' => $userId, 'tenant_id' => $tenantId];
        $emailChanged = false;

        // Email
        if (isset($input['email'])) {
            $newEmail = strtolower(trim((string) $input['email']));
            if ($newEmail !== $user['email']) {
                $this->validateEmail($newEmail);
                if ($this->users->findByEmail($newEmail, $tenantId) !== null) {
                    throw UserException::emailAlreadyExists($newEmail);
                }
                $updates[] = 'email = :email';
                $params['email'] = $newEmail;
                // Verificación diferida: email nuevo sin confirmar
                $updates[] = 'email_verificado = 0';
                $emailChanged = true;
            }
        }

        // Nombre
        if (isset($input['nombre'])) {
            $nombre = trim((string) $input['nombre']);
            $this->validateNombre($nombre);
            $updates[] = 'nombre = :nombre';
            $params['nombre'] = $nombre;
        }

        // Apellido
        if (isset($input['apellido'])) {
            $apellido = trim((string) $input['apellido']);
            $this->validateApellido($apellido);
            $updates[] = 'apellido = :apellido';
            $params['apellido'] = $apellido;
        }

        if (empty($updates)) {
            return $this->sanitizeUser($user);
        }

        $updates[] = 'updated_at = :updated_at';
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = 'UPDATE users SET ' . implode(', ', $updates)
             . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return $this->sanitizeUser($this->users->findById($userId, $tenantId));
    }

    // ========================================================================
    // PREFERENCIAS (GET/PATCH /api/users/me/preferences)
    // ========================================================================

    /**
     * Preferencias JSON del usuario dentro del tenant.
     *
     * @return array{idioma?: string, tema?: string} Claves conocidas y
     *         cualquier otra guardada previamente (se preservan tal cual).
     * @throws UserPreferencesException 404 si el usuario no existe
     */
    public function getPreferences(string $userId, string $tenantId): array
    {
        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw UserPreferencesException::userNotFound($userId);
        }

        return $this->decodePreferences($user['preferencias'] ?? null);
    }

    /**
     * Actualiza preferencias propias (merge: no borra claves no enviadas).
     *
     * Sólo se consideran `idioma` y `tema`; las claves desconocidas del body
     * se ignoran. Si el body no trae ninguna clave conocida no se escribe nada
     * (se devuelven las preferencias actuales).
     *
     * @param array $input Body JSON del PATCH
     * @return array Preferencias resultantes tras el merge
     * @throws UserPreferencesException 404 o 400 (valor fuera de la whitelist)
     */
    public function updatePreferences(string $userId, string $tenantId, array $input): array
    {
        $user = $this->users->findById($userId, $tenantId);
        if ($user === null) {
            throw UserPreferencesException::userNotFound($userId);
        }

        $actuales = $this->decodePreferences($user['preferencias'] ?? null);

        $cambios = [];
        foreach (self::PREFERENCIAS_CLAVES as $clave) {
            if (!array_key_exists($clave, $input)) {
                continue;
            }

            $valor = $input[$clave];
            $permitidos = $clave === 'idioma' ? self::PREFERENCIAS_IDIOMA : self::PREFERENCIAS_TEMA;

            if (!is_string($valor) || !in_array($valor, $permitidos, true)) {
                throw UserPreferencesException::invalidValue($clave, $valor, $permitidos);
            }

            $cambios[$clave] = $valor;
        }

        if ($cambios === []) {
            return $actuales;
        }

        $preferencias = array_merge($actuales, $cambios);

        $sql = "UPDATE users
                SET preferencias = :preferencias, updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'preferencias' => json_encode($preferencias, JSON_UNESCAPED_UNICODE),
            'updated_at'   => date('Y-m-d H:i:s'),
            'id'           => $userId,
            'tenant_id'    => $tenantId,
        ]);

        return $preferencias;
    }

    /**
     * Cambia el password de un usuario y limpia el marcador de cambio
     * forzado (v1.11.0): un cambio completado deja de exigir cambio.
     *
     * Política de caducidad del tenant (v1.14.1): si configuracion.seguridad
     * .caducidad_password_dias está definida, la nueva contraseña caduca a
     * los N días; si no, no caduca (fecha_expiracion_password NULL). El
     * login ya fuerza el cambio cuando la fecha vence (AuthController).
     *
     * @throws UserException Si el usuario no existe o password inválido
     */
    public function changePassword(string $id, string $tenantId, string $newPassword): void
    {
        $user = $this->users->findById($id, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($id);
        }

        $this->validatePasswordStrength($newPassword);
        $hash = $this->hasher->hash($newPassword);

        $sql = "UPDATE users
                SET password = :password, fecha_expiracion_password = :expira, updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'password'   => $hash,
            'expira'     => $this->passwordExpiryFromPolicy($tenantId),
            'updated_at' => date('Y-m-d H:i:s'),
            'id'         => $id,
            'tenant_id'  => $tenantId,
        ]);
    }

    /**
     * Calcula la fecha de expiración según configuracion.seguridad
     * .caducidad_password_dias del tenant (v1.14.1).
     *
     * @return string|null Fecha 'Y-m-d H:i:s' o null si no hay política
     */
    private function passwordExpiryFromPolicy(string $tenantId): ?string
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT configuracion FROM tenants WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $tenantId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        $config = json_decode((string) $raw, true);
        $dias = $config['seguridad']['caducidad_password_dias'] ?? null;
        if (!is_int($dias) || $dias < 1) {
            return null;
        }

        return date('Y-m-d H:i:s', time() + $dias * 86400);
    }

    /**
     * Cambia el estado de un usuario con motivo obligatorio (v1.11.0).
     *
     * Las protecciones de quién puede operar sobre quién (autocambio,
     * escalada de permisos, último ADMIN) viven en la capa HTTP
     * (UserController), que dispone de los claims del actor.
     *
     * @throws UserException 404 si no existe, 400 si el estado o el motivo
     *         no son válidos
     */
    public function updateStatus(string $id, string $tenantId, string $estado, string $motivo): array
    {
        $user = $this->users->findById($id, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($id);
        }

        $this->validateEstado($estado);

        if (trim($motivo) === '') {
            throw UserException::requiredField('motivo');
        }

        $sql = "UPDATE users SET estado = :estado, updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'estado'     => $estado,
            'updated_at' => date('Y-m-d H:i:s'),
            'id'         => $id,
            'tenant_id'  => $tenantId,
        ]);

        return $this->sanitizeUser($this->users->findById($id, $tenantId));
    }

    /**
     * Resetea el password de un usuario a uno temporal nuevo (v1.11.0).
     *
     * Marca fecha_expiracion_password con la vigencia TEMP_PASSWORD_TTL_DAYS
     * para que el usuario deba cambiarlo en el siguiente inicio de sesión.
     * Las sesiones activas las revoca la capa HTTP (SessionRevokerService).
     *
     * @return string Password temporal en texto plano (devuelto UNA sola vez)
     * @throws UserException 404 si el usuario no existe
     */
    public function resetPassword(string $id, string $tenantId): string
    {
        $user = $this->users->findById($id, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($id);
        }

        $password = $this->generateTemporaryPassword();
        $hash = $this->hasher->hash($password);

        $sql = "UPDATE users
                SET password = :password, fecha_expiracion_password = :expira, updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'password'   => $hash,
            'expira'     => $this->tempPasswordExpiry(),
            'updated_at' => date('Y-m-d H:i:s'),
            'id'         => $id,
            'tenant_id'  => $tenantId,
        ]);

        return $password;
    }

    /**
     * Soft delete de un usuario.
     */
    public function delete(string $id, string $tenantId): bool
    {
        $user = $this->users->findById($id, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($id);
        }

        $sql = "UPDATE users
                SET estado = 'INACTIVO', deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Fila cruda del usuario incluyendo el borrado lógico, para auditoría
     * del delete (estado posterior al soft delete).
     */
    public function findIncludingDeleted(string $id, string $tenantId): ?array
    {
        $sql = "SELECT * FROM users WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ========================================================================
    // ASIGNACIÓN DE ROLES
    // ========================================================================

    /**
     * Asigna un rol a un usuario en un tenant.
     *
     * @throws UserException Si el usuario o rol no existen, o ya está asignado
     */
    public function assignRole(string $userId, string $roleId, string $tenantId, ?string $assignedBy = null): void
    {
        // Validar usuario
        if (!$this->users->isActive($userId, $tenantId)) {
            throw UserException::userNotFound($userId);
        }

        // Validar rol existe
        $roleExists = $this->roleExists($roleId);
        if (!$roleExists) {
            throw new \RuntimeException("El rol con ID '{$roleId}' no existe");
        }

        // Verificar si ya está asignado
        $sql = "SELECT COUNT(*) FROM role_assignments
                WHERE user_id = :uid AND role_id = :rid AND tenant_id = :tid";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'rid' => $roleId, 'tid' => $tenantId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException("El rol ya está asignado al usuario");
        }

        // Insertar asignación
        $sql = "INSERT INTO role_assignments
                (id, user_id, role_id, tenant_id, asignado_por_user_id, fecha_asignacion, created_at, updated_at)
                VALUES
                (:id, :uid, :rid, :tid, :assigned_by, NOW(), NOW(), NOW())";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'id'          => $this->generateUuid(),
            'uid'         => $userId,
            'rid'         => $roleId,
            'tid'         => $tenantId,
            'assigned_by' => $assignedBy,
        ]);
    }

    /**
     * Remueve un rol de un usuario en un tenant.
     *
     * @return bool true si borro la asignacion directa; false si no existia
     *              fila en role_assignments (rol heredado de grupo o ya
     *              ausente) - el caller decide la respuesta (v1.13.20: 409).
     */
    public function removeRole(string $userId, string $roleId, string $tenantId): bool
    {
        $sql = "DELETE FROM role_assignments
                WHERE user_id = :uid AND role_id = :rid AND tenant_id = :tid";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'rid' => $roleId, 'tid' => $tenantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Obtiene los roles asignados a un usuario en un tenant.
     *
     * @return array<int, array{id: string, nombre: string, tipo: string}>
     */
    public function getUserRoles(string $userId, string $tenantId): array
    {
        $sql = "SELECT r.id, r.nombre, r.tipo
                FROM role_assignments ra
                INNER JOIN roles r ON r.id = ra.role_id
                WHERE ra.user_id = :uid
                  AND ra.tenant_id = :tid
                  AND r.activo = 1
                  AND r.deleted_at IS NULL
                  AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())
                ORDER BY r.nombre ASC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private function validateCreateData(array $data): void
    {
        // password es opcional (v1.11.0): si falta se genera uno temporal
        $required = ['tenant_id', 'email', 'nombre', 'apellido'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw UserException::requiredField($field);
            }
        }

        $this->validateEmail((string) $data['email']);

        if (strlen((string) $data['nombre']) > 100) {
            throw (new UserException('Nombre excede longitud máxima (100)', 0, null, 'nombre'))->withMessageKey('users.nombre_max_length');
        }
        if (strlen((string) $data['apellido']) > 100) {
            throw (new UserException('Apellido excede longitud máxima (100)', 0, null, 'apellido'))->withMessageKey('users.apellido_max_length');
        }
    }

    private function validateEmail(string $email): void
    {
        $email = trim($email);
        if ($email === '') {
            throw (new UserException('Email no puede estar vacío', 0, null, 'email'))->withMessageKey('users.email_required');
        }
        if (strlen($email) > 255) {
            throw (new UserException('Email excede longitud máxima (255)', 0, null, 'email'))->withMessageKey('users.email_max_length');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw (new UserException('Email inválido', 0, null, 'email'))->withMessageKey('users.email_invalid');
        }
    }

    private function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw (new UserException('Nombre no puede estar vacío', 0, null, 'nombre'))->withMessageKey('users.nombre_required');
        }
        if (strlen($nombre) > 100) {
            throw (new UserException('Nombre excede longitud máxima (100)', 0, null, 'nombre'))->withMessageKey('users.nombre_max_length');
        }
    }

    private function validateApellido(string $apellido): void
    {
        $apellido = trim($apellido);
        if ($apellido === '') {
            throw (new UserException('Apellido no puede estar vacío', 0, null, 'apellido'))->withMessageKey('users.apellido_required');
        }
        if (strlen($apellido) > 100) {
            throw (new UserException('Apellido excede longitud máxima (100)', 0, null, 'apellido'))->withMessageKey('users.apellido_max_length');
        }
    }

    private function validateEstado(string $estado): void
    {
        $valid = ['ACTIVO', 'INACTIVO', 'SUSPENDIDO', 'PENDIENTE_CONFIRMACION'];
        if (!in_array($estado, $valid, true)) {
            throw new UserException("Estado inválido: {$estado}", 0, null, 'estado');
        }
    }

    /**
     * Valida fortaleza del password:
     * - Mínimo 8 caracteres
     * - Al menos 1 mayúscula
     * - Al menos 1 minúscula
     * - Al menos 1 número
     */
    private function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < 8) {
            throw (new UserException('El password debe tener mínimo 8 caracteres', 0, null, 'password'))->withMessageKey('auth.password_min_length');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw (new UserException('El password debe contener al menos una mayúscula', 0, null, 'password'))->withMessageKey('auth.password_uppercase');
        }
        if (!preg_match('/[a-z]/', $password)) {
            throw (new UserException('El password debe contener al menos una minúscula', 0, null, 'password'))->withMessageKey('auth.password_lowercase');
        }
        if (!preg_match('/[0-9]/', $password)) {
            throw (new UserException('El password debe contener al menos un número', 0, null, 'password'))->withMessageKey('auth.password_digit');
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Elimina el password del array de usuario antes de retornarlo.
     */
    private function sanitizeUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }
        unset($user['password']);
        return $user;
    }

    /**
     * Decodifica la columna users.preferencias (JSON) a array.
     * Nulo o JSON invalido => array vacío (sin preferencias guardadas).
     */
    private function decodePreferences(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Verifica si un rol existe (independiente de tenant, los roles son globales).
     */
    private function roleExists(string $roleId): bool
    {
        $sql = "SELECT COUNT(*) FROM roles WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $roleId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Genera un password temporal de 12 caracteres que cumple la política
     * (mínimo 8, mayúscula, minúscula y dígito). Alfabeto sin ambiguos
     * (0/O/1/l/I): los caracteres garantizados se barajean al final.
     */
    private function generateTemporaryPassword(): string
    {
        $mayus = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minus = 'abcdefghijkmnpqrstuvwxyz';
        $digitos = '23456789';
        $todos = $mayus . $minus . $digitos;

        $password = $mayus[random_int(0, strlen($mayus) - 1)]
            . $minus[random_int(0, strlen($minus) - 1)]
            . $digitos[random_int(0, strlen($digitos) - 1)];

        for ($i = 0; $i < 9; $i++) {
            $password .= $todos[random_int(0, strlen($todos) - 1)];
        }

        $chars = str_split($password);
        shuffle($chars);

        return implode('', $chars);
    }

    /**
     * Caducidad del password temporal: TEMP_PASSWORD_TTL_DAYS (default 7).
     */
    private function tempPasswordExpiry(): string
    {
        $days = (int) ($_ENV['TEMP_PASSWORD_TTL_DAYS'] ?? 7);
        if ($days < 1) {
            $days = 7;
        }

        return date('Y-m-d H:i:s', time() + $days * 86400);
    }

    /**
     * Genera un UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
