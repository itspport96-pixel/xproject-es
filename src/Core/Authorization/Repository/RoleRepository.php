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
 * El campo `permisos` es un JSON array de strings (identificadores).
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

        return $row ? Role::fromArray($row) : null;
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

        return $row ? Role::fromArray($row) : null;
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

        return array_map(fn(array $row) => Role::fromArray($row), $rows);
    }

    public function findByTipo(string $tipo): array
    {
        $sql = "SELECT * FROM roles WHERE tipo = :tipo AND activo = 1 AND deleted_at IS NULL ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tipo' => $tipo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Role::fromArray($row), $rows);
    }

    public function findByPermisos(array $permissionIds): array
    {
        if (empty($permissionIds)) {
            return [];
        }

        // Busca roles que contengan AL MENOS UNO de los permisos indicados.
        // MariaDB: JSON_CONTAINS(permisos, '"inventory.item.view"')
        $conditions = [];
        $params = [];
        foreach ($permissionIds as $i => $pid) {
            $conditions[] = "JSON_CONTAINS(permisos, :pid{$i})";
            $params["pid{$i}"] = '"' . $pid . '"';
        }

        $sql = "SELECT * FROM roles
                WHERE activo = 1
                  AND deleted_at IS NULL
                  AND (" . implode(' OR ', $conditions) . ")
                ORDER BY nombre ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Role::fromArray($row), $rows);
    }

    public function getBuiltInRoles(): array
    {
        return $this->findByTipo(Role::TYPE_BUILT_IN);
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    public function save(Role $role): void
    {
        if ($this->existsByNombre($role->nombre(), $role->tenantId())) {
            throw new AuthorizationException("El rol '{$role->nombre()}' ya existe", 409);
        }

        $sql = "INSERT INTO roles (id, nombre, descripcion, tipo, tenant_id, activo, permisos, created_at, updated_at)
                VALUES (:id, :nombre, :descripcion, :tipo, :tenant_id, :activo, :permisos, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'          => $role->id(),
            'nombre'      => $role->nombre(),
            'descripcion' => $role->descripcion(),
            'tipo'        => $role->tipo(),
            'tenant_id'   => $role->tenantId(),
            'activo'      => $role->activo() ? 1 : 0,
            'permisos'    => json_encode($role->permisos(), JSON_UNESCAPED_UNICODE),
            'created_at'  => $role->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'  => $role->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
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
                    permisos = :permisos,
                    updated_at = :updated_at
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'          => $role->id(),
            'nombre'      => $role->nombre(),
            'descripcion' => $role->descripcion(),
            'activo'      => $role->activo() ? 1 : 0,
            'permisos'    => json_encode($role->permisos(), JSON_UNESCAPED_UNICODE),
            'updated_at'  => $role->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
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
