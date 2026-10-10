<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\Permission;
use App\Core\Authorization\Exception\AuthorizationException;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Implementación MariaDB del repositorio de permisos.
 *
 * La tabla `permissions` NO tiene updated_at ni deleted_at.
 * Los permisos son globales (sin tenant_id).
 * Los permisos asignados a roles viven en roles.permisos (JSON).
 */
final class PermissionRepository implements PermissionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(DatabaseService $db)
    {
        $this->pdo = $db->getConnection();
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    public function findById(string $id): ?Permission
    {
        $sql = "SELECT * FROM permissions WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Permission::fromArray($row) : null;
    }

    public function findByIdentificador(string $identificador): ?Permission
    {
        $sql = "SELECT * FROM permissions WHERE identificador = :identificador";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['identificador' => strtolower(trim($identificador))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Permission::fromArray($row) : null;
    }

    public function findAll(bool $soloActivos = true): array
    {
        $sql = "SELECT * FROM permissions";
        if ($soloActivos) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY modulo ASC, recurso ASC, accion ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findByModulo(string $modulo): array
    {
        $sql = "SELECT * FROM permissions
                WHERE modulo = :modulo AND activo = 1
                ORDER BY recurso ASC, accion ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['modulo' => strtolower(trim($modulo))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findByRecurso(string $recurso): array
    {
        $sql = "SELECT * FROM permissions
                WHERE recurso = :recurso AND activo = 1
                ORDER BY modulo ASC, accion ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['recurso' => strtolower(trim($recurso))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findByCriticidad(string $criticidad): array
    {
        $sql = "SELECT * FROM permissions
                WHERE criticidad = :criticidad AND activo = 1
                ORDER BY modulo ASC, recurso ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['criticidad' => strtoupper(trim($criticidad))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findRequiringMfa(): array
    {
        $sql = "SELECT * FROM permissions
                WHERE requiere_mfa = 1 AND activo = 1
                ORDER BY modulo ASC, recurso ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findByPattern(string $pattern): array
    {
        // Convierte patrón con wildcards (*) a LIKE SQL
        // "inventory.*.*" → "inventory.%.%"
        // "inventory.item.*" → "inventory.item.%"
        // "inventory.item.view" → "inventory.item.view" (exacto)
        $like = str_replace('*', '%', strtolower(trim($pattern)));

        $sql = "SELECT * FROM permissions
                WHERE identificador LIKE :pattern AND activo = 1
                ORDER BY identificador ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pattern' => $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    public function findByIdentificadores(array $identificadores): array
    {
        if (empty($identificadores)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($identificadores as $i => $id) {
            $key = "id{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = strtolower(trim($id));
        }

        $sql = "SELECT * FROM permissions
                WHERE identificador IN (" . implode(',', $placeholders) . ")
                ORDER BY identificador ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => Permission::fromArray($row), $rows);
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    public function save(Permission $permission): void
    {
        if ($this->existsByIdentificador($permission->identificador())) {
            throw new AuthorizationException("El permiso '{$permission->identificador()}' ya existe", 409);
        }

        // La tabla NO tiene updated_at
        $sql = "INSERT INTO permissions
                (id, identificador, nombre, descripcion, modulo, recurso, accion,
                 criticidad, requiere_mfa, activo, metadata, created_at)
                VALUES
                (:id, :identificador, :nombre, :descripcion, :modulo, :recurso, :accion,
                 :criticidad, :requiere_mfa, :activo, :metadata, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'            => $permission->id(),
            'identificador' => $permission->identificador(),
            'nombre'        => $permission->nombre(),
            'descripcion'   => $permission->descripcion(),
            'modulo'        => $permission->modulo(),
            'recurso'       => $permission->recurso(),
            'accion'        => $permission->accion(),
            'criticidad'    => $permission->criticidad(),
            'requiere_mfa'  => $permission->requiereMfa() ? 1 : 0,
            'activo'        => $permission->activo() ? 1 : 0,
            'metadata'      => $permission->metadata() !== null
                ? json_encode($permission->metadata(), JSON_UNESCAPED_UNICODE)
                : null,
            'created_at'    => $permission->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(Permission $permission): void
    {
        if (!$this->existsById($permission->id())) {
            throw AuthorizationException::permissionNotFound($permission->id());
        }

        // La tabla NO tiene updated_at
        $sql = "UPDATE permissions
                SET identificador = :identificador,
                    nombre = :nombre,
                    descripcion = :descripcion,
                    modulo = :modulo,
                    recurso = :recurso,
                    accion = :accion,
                    criticidad = :criticidad,
                    requiere_mfa = :requiere_mfa,
                    activo = :activo,
                    metadata = :metadata
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'            => $permission->id(),
            'identificador' => $permission->identificador(),
            'nombre'        => $permission->nombre(),
            'descripcion'   => $permission->descripcion(),
            'modulo'        => $permission->modulo(),
            'recurso'       => $permission->recurso(),
            'accion'        => $permission->accion(),
            'criticidad'    => $permission->criticidad(),
            'requiere_mfa'  => $permission->requiereMfa() ? 1 : 0,
            'activo'        => $permission->activo() ? 1 : 0,
            'metadata'      => $permission->metadata() !== null
                ? json_encode($permission->metadata(), JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    public function delete(string $id): void
    {
        if (!$this->existsById($id)) {
            throw AuthorizationException::permissionNotFound($id);
        }

        if ($this->isAssignedToAnyRole($id)) {
            throw new AuthorizationException("No se puede eliminar el permiso '{$id}' porque está asignado a uno o más roles", 409);
        }

        // Hard delete: la tabla NO tiene deleted_at
        $sql = "DELETE FROM permissions WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
    }

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    public function existsByIdentificador(string $identificador): bool
    {
        $sql = "SELECT COUNT(*) FROM permissions WHERE identificador = :identificador";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['identificador' => strtolower(trim($identificador))]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id): bool
    {
        $sql = "SELECT COUNT(*) FROM permissions WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(bool $soloActivos = true): int
    {
        $sql = "SELECT COUNT(*) FROM permissions";
        if ($soloActivos) {
            $sql .= " WHERE activo = 1";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function isAssignedToAnyRole(string $permissionId): bool
    {
        // Los permisos asignados viven en roles.permisos como JSON array de identificadores.
        // Primero obtenemos el identificador del permiso por su ID.
        $sql = "SELECT identificador FROM permissions WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $permissionId]);
        $identificador = $stmt->fetchColumn();

        if ($identificador === false) {
            return false;
        }

        // Busca roles activos que contengan ese identificador en su JSON
        $sql = "SELECT COUNT(*) FROM roles
                WHERE activo = 1
                  AND deleted_at IS NULL
                  AND JSON_CONTAINS(permisos, :value)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['value' => '"' . $identificador . '"']);
        return (int) $stmt->fetchColumn() > 0;
    }
}
