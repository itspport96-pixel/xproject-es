<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base de tests de la capa de control (v1.11.0, spec §4/§5).
 *
 * Monta en SQLite en memoria las tablas que usa la escalada, los guards de
 * roles, los permisos temporales y los seeders de permisos, con los
 * repositorios y servicios REALES:
 *
     *  - permissions / roles / role_assignments  (catálogo; roles con
     *    tenant_id NULL = BUILT_IN globales, valor = CUSTOM del tenant)
 *  - users / temporary_permissions           (beneficiarios y temporales)
 *  - user_groups / group_members / group_roles (grupos §4.2)
 *
 * Se registra la función NOW() de MySQL para que las consultas con
 * `> NOW()` de AuthorizationService y los repositorios funcionen igual
 * que contra la BD viva.
 */
abstract class PlatformControlSqliteTestCase extends TestCase
{
    protected const TENANT = 'tenant-pcs-1';

    protected PDO $pdo;
    protected DatabaseService $db;
    protected AuthorizationService $authz;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction(
            'now',
            static fn(): string => gmdate('Y-m-d H:i:s'),
            0
        );

        $this->createSchema();

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        Container::setInstance(new Container());

        $this->authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $this->createStub(RedisService::class)
        );
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ====================================================================
    // HELPERS DE FIXTURES
    // ====================================================================

    /** @return string id del usuario creado */
    protected function seedUser(
        string $id,
        string $estado = 'ACTIVO',
        ?string $tenantId = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (:id, :tenant, :email, :nombre, :apellido, :password, :estado, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant' => $tenantId ?? self::TENANT,
            'email' => $id . '@example.test',
            'nombre' => 'Usuario',
            'apellido' => $id,
            'password' => password_hash('Abcdef1!', PASSWORD_BCRYPT, ['cost' => 4]),
            'estado' => $estado,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * Crea un permiso en el catálogo.
     *
     * @return string id del permiso
     */
    protected function seedPermission(
        string $identificador,
        string $criticidad = 'MEDIUM',
        bool $activo = true
    ): string {
        [$modulo, $recurso, $accion] = explode('.', $identificador) + [null, null, null];
        $id = 'perm-' . str_replace('.', '-', $identificador);

        $stmt = $this->pdo->prepare(
            'INSERT INTO permissions
                (id, identificador, nombre, descripcion, modulo, recurso, accion,
                 criticidad, requiere_mfa, activo, metadata, created_at)
             VALUES
                (:id, :identificador, :nombre, NULL, :modulo, :recurso, :accion,
                 :criticidad, 0, :activo, NULL, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'identificador' => $identificador,
            'nombre' => 'Permiso ' . $identificador,
            'modulo' => $modulo,
            'recurso' => $recurso,
            'accion' => $accion,
            'criticidad' => $criticidad,
            'activo' => $activo ? 1 : 0,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function seedRole(
        string $id,
        string $nombre,
        array $permisos,
        string $tipo = 'CUSTOM',
        bool $activo = true,
        ?string $tenantId = null
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, descripcion, tipo, tenant_id, activo, permisos, created_at, updated_at)
             VALUES (:id, :nombre, NULL, :tipo, :tenant_id, :activo, :permisos, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'tenant_id' => $tipo === 'BUILT_IN' ? null : $tenantId,
            'activo' => $activo ? 1 : 0,
            'permisos' => json_encode(array_values($permisos), JSON_UNESCAPED_UNICODE),
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function assignRole(string $userId, string $roleId, ?string $fechaExpiracion = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_assignments
                (id, user_id, role_id, tenant_id, asignado_por_user_id,
                 fecha_asignacion, fecha_expiracion, created_at, updated_at)
             VALUES (:id, :user_id, :role_id, :tenant, NULL, :now, :expiracion, :now, :now)'
        );
        $stmt->execute([
            'id' => 'ra-' . substr(md5($userId . $roleId), 0, 12),
            'user_id' => $userId,
            'role_id' => $roleId,
            'tenant' => self::TENANT,
            'now' => gmdate('Y-m-d H:i:s'),
            'expiracion' => $fechaExpiracion,
        ]);
    }

    /** @return string id del grupo */
    protected function seedGroup(string $id, bool $activo = true, ?string $tenantId = null): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_groups (id, tenant_id, nombre, descripcion, propietario_id,
                                      activo, created_at, updated_at)
             VALUES (:id, :tenant, :nombre, NULL, NULL, :activo, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant' => $tenantId ?? self::TENANT,
            'nombre' => 'Grupo ' . $id,
            'activo' => $activo ? 1 : 0,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function addGroupMember(string $groupId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO group_members (id, group_id, user_id, added_at, added_by_user_id)
             VALUES (:id, :group_id, :user_id, :now, NULL)'
        );
        $stmt->execute([
            'id' => 'gm-' . substr(md5($groupId . $userId), 0, 12),
            'group_id' => $groupId,
            'user_id' => $userId,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    protected function attachGroupRole(string $groupId, string $roleId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO group_roles (id, group_id, role_id, assigned_at)
             VALUES (:id, :group_id, :role_id, :now)'
        );
        $stmt->execute([
            'id' => 'gr-' . substr(md5($groupId . $roleId), 0, 12),
            'group_id' => $groupId,
            'role_id' => $roleId,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Fila de temporary_permissions directa (para duplicados/expirados).
     *
     * @return string id del registro
     */
    protected function seedTemporaryPermission(
        string $userId,
        string $permissionId,
        string $fechaExpiracion,
        bool $activo = true,
        ?string $recursoId = null
    ): string {
        $id = 'tp-' . substr(md5($userId . $permissionId . $fechaExpiracion . ($recursoId ?? '')), 0, 12);
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO temporary_permissions
                (id, user_id, permission_id, recurso_id, granted_by_user_id, motivo,
                 fecha_inicio, fecha_expiracion, activo, created_at, updated_at)
             VALUES
                (:id, :user_id, :permission_id, :recurso_id, NULL, :motivo,
                 :fecha_inicio, :fecha_expiracion, :activo, :now, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'recurso_id' => $recursoId,
            'motivo' => 'Semilla de test',
            'fecha_inicio' => $now,
            'fecha_expiracion' => $fechaExpiracion,
            'activo' => $activo ? 1 : 0,
            'now' => $now,
        ]);

        return $id;
    }

    protected function temporaryPermissionRow(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM temporary_permissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    protected function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    protected function claims(string $userId, array $roles = [], ?string $tenantId = null): array
    {
        return [
            'user_id' => $userId,
            'tenant_id' => $tenantId ?? self::TENANT,
            'roles' => $roles,
        ];
    }

    /**
     * Request HTTP mínimo (mismo formato que UserPreferencesEndpointTest).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function request(array $overrides = [], ?array $claims = null): array
    {
        return array_merge([
            'user' => $claims ?? $this->claims('u-admin'),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'request_id' => 'req-pcs-1',
        ], $overrides);
    }

    // ====================================================================
    // ESQUEMA SQLite (equivalente a database/schema/schema.sql)
    // ====================================================================

    private function createSchema(): void
    {
        $ddl = [
            'CREATE TABLE permissions (
                id TEXT PRIMARY KEY,
                identificador TEXT NOT NULL UNIQUE,
                nombre TEXT NOT NULL,
                descripcion TEXT,
                modulo TEXT,
                recurso TEXT,
                accion TEXT,
                criticidad TEXT DEFAULT \'MEDIUM\',
                requiere_mfa INTEGER DEFAULT 0,
                activo INTEGER DEFAULT 1,
                metadata TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE roles (
                id TEXT PRIMARY KEY,
                nombre TEXT NOT NULL,
                descripcion TEXT,
                tipo TEXT DEFAULT \'CUSTOM\',
                tenant_id TEXT,
                activo INTEGER DEFAULT 1,
                permisos TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT,
                nombre_activo TEXT GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN nombre ELSE NULL END) STORED,
                UNIQUE (tenant_id, nombre_activo)
            )',
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                email TEXT NOT NULL,
                nombre TEXT,
                apellido TEXT,
                password TEXT NOT NULL,
                estado TEXT DEFAULT \'ACTIVO\',
                email_verificado INTEGER DEFAULT 0,
                telefono TEXT,
                avatar_url TEXT,
                ultimo_login TEXT,
                fecha_expiracion_password TEXT,
                mfa_habilitado INTEGER DEFAULT 0,
                mfa_metodo TEXT,
                preferencias TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT,
                email_activo TEXT GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN email ELSE NULL END) STORED,
                UNIQUE (tenant_id, email_activo)
            )',
            'CREATE TABLE temporary_permissions (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                permission_id TEXT NOT NULL,
                recurso_id TEXT,
                granted_by_user_id TEXT,
                motivo TEXT,
                fecha_inicio TEXT DEFAULT CURRENT_TIMESTAMP,
                fecha_expiracion TEXT NOT NULL,
                activo INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )',
            'CREATE TABLE role_assignments (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                role_id TEXT NOT NULL,
                tenant_id TEXT NOT NULL,
                asignado_por_user_id TEXT,
                fecha_asignacion TEXT DEFAULT CURRENT_TIMESTAMP,
                fecha_expiracion TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE user_groups (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                nombre TEXT,
                descripcion TEXT,
                propietario_id TEXT,
                activo INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                deleted_at TEXT
            )',
            'CREATE TABLE group_members (
                id TEXT PRIMARY KEY,
                group_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                added_at TEXT DEFAULT CURRENT_TIMESTAMP,
                added_by_user_id TEXT,
                UNIQUE (group_id, user_id)
            )',
            'CREATE TABLE group_roles (
                id TEXT PRIMARY KEY,
                group_id TEXT NOT NULL,
                role_id TEXT NOT NULL,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (group_id, role_id)
            )',
            // v1.13.3: tablas que usa AuthController::login (isTenantActive y
            // storeRefreshToken) y el override de rate limits (spec §6.3).
            'CREATE TABLE tenants (
                id TEXT PRIMARY KEY,
                nombre_publico TEXT,
                nombre_interno TEXT,
                estado TEXT DEFAULT \'ACTIVO\',
                plan TEXT DEFAULT \'FREE\',
                owner_user_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                deleted_at TEXT
            )',
            'CREATE TABLE refresh_tokens (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                tenant_id TEXT NOT NULL,
                family_id TEXT NOT NULL,
                used INTEGER NOT NULL DEFAULT 0,
                revoked INTEGER NOT NULL DEFAULT 0,
                revoked_reason TEXT,
                expires_at TEXT NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )',
            'CREATE TABLE rate_limit_config (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                endpoint TEXT NOT NULL,
                max_requests INTEGER NOT NULL DEFAULT 100,
                tiempo_ventana INTEGER NOT NULL DEFAULT 60,
                tipo_limit TEXT NOT NULL DEFAULT \'POR_USUARIO\',
                acciones_al_exceder TEXT,
                activo INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )',
        ];

        foreach ($ddl as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
