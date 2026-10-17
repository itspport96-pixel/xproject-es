<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\Role;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Shared\Services\DatabaseService;
use PDO;
use PDOException;

/**
 * Implementación MariaDB del repositorio de roles.
 *
 * `roles.tenant_id` NULL identifica a los BUILT_IN (globales) y un valor
 * concreto a los CUSTOM de ese tenant. Los métodos con $tenantId sólo
 * devuelven roles visibles desde ese tenant (BUILT_IN + propio tenant).
 * Los permisos viven en la junction `role_permissions` (N5e).
 */
final class RoleRepository implements RoleRepositoryInterface
{
    private PDO $pdo;

    public function __construct(DatabaseService $db)
    {
        $this->pdo = $db->getConnection();
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    public function findById(string $id, ?string $tenantId = null): ?Role
    {
        $sql = "SELECT * FROM roles WHERE id = :id AND deleted_at IS NULL";
        $params = ['id' => $id];

        if ($tenantId !== null) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = :tid)";
            $params['tid'] = $tenantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }
        $row['permisos'] = $this->fetchPermisos([$id])[$id] ?? [];
        return Role::fromArray($row);
    }

    public function findByNombre(string $nombre, ?string $tenantId = null): ?Role
    {
        $sql = "SELECT * FROM roles WHERE UPPER(nombre) = UPPER(:nombre) AND deleted_at IS NULL";
        $params = ['nombre' => trim($nombre)];

        if ($tenantId !== null) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = :tid)";
            $params['tid'] = $tenantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }
        $row['permisos'] = $this->fetchPermisos([(string) $row['id']])[(string) $row['id']] ?? [];
        return Role::fromArray($row);
    }

    /**
     * @param bool $soloActivos Si true, excluye roles con activo=0.
     */
    public function findAll(bool $soloActivos = true, ?string $tenantId = null): array
    {
        $sql = "SELECT * FROM roles WHERE";
        if ($soloActivos) {
            $sql .= " activo = 1 AND deleted_at IS NULL";
        } else {
            $sql .= " deleted_at IS NULL";
        }

        $params = [];
        if ($tenantId !== null) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = :tid)";
            $params['tid'] = $tenantId;
        }

        $sql .= " ORDER BY tipo ASC, nombre ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    public function findByTipo(string $tipo): array
    {
        $sql = "SELECT * FROM roles WHERE tipo = :tipo AND activo = 1 AND deleted_at IS NULL ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tipo' => $tipo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    public function findByPermisos(array $permissionIds): array
    {
        if (empty($permissionIds)) {
            return [];
        }

        // Busca roles que contengan AL MENOS UNO de los permisos indicados.
        // Via junction role_permissions + permissions (identificador).
        $conditions = [];
        $params = [];
        foreach ($permissionIds as $i => $pid) {
            $conditions[] = "p.identificador = :pid{$i}";
            $params["pid{$i}"] = $pid;
        }

        $sql = "SELECT DISTINCT r.*
                FROM roles r
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE r.activo = 1
                  AND r.deleted_at IS NULL
                  AND (" . implode(' OR ', $conditions) . ")
                ORDER BY r.nombre ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    public function getBuiltInRoles(): array
    {
        return $this->findByTipo(Role::TYPE_BUILT_IN);
    }

    /**
     * Hidrata el campo 'permisos' de cada fila desde la junction
     * role_permissions (permisos identificadores activos), y devuelve
     * los roles como entidades.
     */
    private function hydrateRows(array $rows): array
    {
        $ids = array_map(fn(array $r) => (string) $r['id'], $rows);
        $permMap = $this->fetchPermisos($ids);

        return array_map(function (array $row) use ($permMap) {
            $id = (string) $row['id'];
            $row['permisos'] = $permMap[$id] ?? [];
            return Role::fromArray($row);
        }, $rows);
    }

    /**
     * @param string[] $roleIds
     * @return array<string, string[]> mapa roleId => identificadores activos
     */
    private function fetchPermisos(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $sql = "SELECT rp.role_id, p.identificador
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id IN ({$placeholders})
                  AND p.activo = 1
                ORDER BY p.identificador ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($roleIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['role_id']][] = (string) $row['identificador'];
        }
        return $map;
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    public function save(Role $role): void
    {
        if ($this->existsByNombre($role->nombre(), $role->tenantId())) {
            throw new AuthorizationException("El rol '{$role->nombre()}' ya existe", 409);
        }

        $sql = "INSERT INTO roles (id, nombre, descripcion, tipo, tenant_id, activo, created_at, updated_at)
                VALUES (:id, :nombre, :descripcion, :tipo, :tenant_id, :activo, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'          => $role->id(),
            'nombre'      => $role->nombre(),
            'descripcion' => $role->descripcion(),
            'tipo'        => $role->tipo(),
            'tenant_id'   => $role->tenantId(),
            'activo'      => $role->activo() ? 1 : 0,
            'created_at'  => $role->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'  => $role->updatedAt()?->format('Y-m-d H:i:s'),
        ]);

        $this->syncJunctionPermissions($role->id(), $role->permisos());
    }

    public function update(Role $role): void
    {
        if (!$this->existsById($role->id())) {
            throw AuthorizationException::roleNotFound($role->id());
        }

        $sql = "UPDATE roles
                SET nombre = :nombre,
                    descripcion = :descripcion,
                    activo = :activo,
                    updated_at = :updated_at
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'          => $role->id(),
            'nombre'      => $role->nombre(),
            'descripcion' => $role->descripcion(),
            'activo'      => $role->activo() ? 1 : 0,
            'updated_at'  => $role->updatedAt()?->format('Y-m-d H:i:s'),
        ]);

        $this->syncJunctionPermissions($role->id(), $role->permisos());
    }

    private function syncJunctionPermissions(string $roleId, array $identifiers): void
    {
        // Map identificador -> permission_id
        $permMap = [];
        $rows = $this->pdo->query('SELECT id, identificador FROM permissions')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $permMap[$r['identificador']] = $r['id'];
        }

        // Delete existing junction rows
        $del = $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid');
        $del->execute(['rid' => $roleId]);

        // Insert current permissions
        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)'
        );
        foreach ($identifiers as $ident) {
            if (isset($permMap[$ident])) {
                $insert->execute(['rid' => $roleId, 'pid' => $permMap[$ident]]);
            }
        }
    }

    public function softDelete(string $id, ?string $tenantId = null): void
    {
        $role = $this->findById($id, $tenantId);
        if ($role === null) {
            throw AuthorizationException::roleNotFound($id);
        }
        if ($role->isBuiltIn()) {
            throw AuthorizationException::cannotDeleteBuiltInRole();
        }

        $sql = "UPDATE roles
                SET activo = 0,
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
    }

    public function findRawIncludingDeleted(string $id): ?array
    {
        $sql = "SELECT * FROM roles WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Borrado físico de las asignaciones de un rol (usuarios y grupos).
     *
     * Se ejecuta tras el soft delete del rol (v1.17.6): la plataforma no
     * restaura registros borrados, así que sus asignaciones no deben
     * sobrevivir huérfanas en role_assignments ni group_roles. El caller
     * debe invalidar la caché de permisos ANTES de purgar, porque la
     * invalidación por rol localiza a los portadores en esas mismas tablas.
     *
     * @return array{usuarios: int, grupos: int} recuentos borrados (auditoría)
     */
    public function purgeAssignments(string $roleId): array
    {
        $stmt = $this->pdo->prepare('DELETE FROM role_assignments WHERE role_id = :rid');
        $stmt->execute(['rid' => $roleId]);
        $usuarios = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM group_roles WHERE role_id = :rid');
        $stmt->execute(['rid' => $roleId]);
        $grupos = $stmt->rowCount();

        return ['usuarios' => $usuarios, 'grupos' => $grupos];
    }

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    public function existsByNombre(string $nombre, ?string $tenantId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM roles WHERE UPPER(nombre) = UPPER(:nombre) AND deleted_at IS NULL";
        $params = ['nombre' => trim($nombre)];

        if ($tenantId !== null) {
            $sql .= " AND (tenant_id IS NULL OR tenant_id = :tid)";
            $params['tid'] = $tenantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id): bool
    {
        $sql = "SELECT COUNT(*) FROM roles WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(bool $soloActivos = true): int
    {
        $sql = "SELECT COUNT(*) FROM roles";
        if ($soloActivos) {
            $sql .= " WHERE activo = 1 AND deleted_at IS NULL";
        } else {
            $sql .= " WHERE deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
