<?php

declare(strict_types=1);

namespace App\Shared\Persistence;

use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Services\DatabaseService;
use PDO;
use PDOStatement;

/**
 * BaseRepository - Clase abstracta para repositorios con aislamiento multi-tenant.
 *
 * Garantiza que TODAS las queries incluyan filtrado por tenant_id.
 * Previene fugas de datos entre tenants mediante validación automática.
 *
 * Uso:
 *   class ProductRepository extends BaseRepository {
 *       protected function getTableName(): string { return 'products'; }
 *       protected function getEntityClass(): string { return Product::class; }
 *   }
 *
 * Métodos proporcionados:
 *   - findById(string $id): ?array
 *   - findAll(array $filters = [], int $page = 1, int $perPage = 20): array
 *   - save(array $data): string (retorna ID)
 *   - update(string $id, array $data): bool
 *   - delete(string $id): bool (soft delete si la tabla tiene deleted_at)
 *   - count(array $filters = []): int
 *
 * Métodos protegidos para queries personalizadas:
 *   - query(string $sql, array $params = []): PDOStatement
 *   - queryOne(string $sql, array $params = []): ?array
 *   - queryAll(string $sql, array $params = []): array
 *   - execute(string $sql, array $params = []): int (rowCount)
 */
abstract class BaseRepository
{
    protected PDO $pdo;
    protected TenantContext $tenantContext;
    protected ?string $cachedTenantId = null;

    public function __construct(DatabaseService $db, TenantContext $tenantContext)
    {
        $this->pdo = $db->getConnection();
        $this->tenantContext = $tenantContext;
    }

    // ========================================================================
    // MÉTODOS ABSTRACTOS (deben implementarse en clases hijas)
    // ========================================================================

    /**
     * Retorna el nombre de la tabla asociada al repositorio.
     */
    abstract protected function getTableName(): string;

    /**
     * Retorna la clase de la entidad (para hidratación si es necesario).
     * Puede ser null si solo se trabaja con arrays.
     */
    abstract protected function getEntityClass(): ?string;

    /**
     * Retorna la lista de columnas permitidas para filtrado en findAll() y count().
     * Previene SQL injection validando los nombres de columna antes de interpolarse en la query.
     *
     * @return array Lista de nombres de columna permitidos (ej: ['email', 'estado', 'nombre'])
     */
    abstract protected function getAllowedFilters(): array;

    // ========================================================================
    // MÉTODOS CRUD GENÉRICOS
    // ========================================================================

    /**
     * Busca un registro por ID dentro del tenant actual.
     *
     * @param string $id ID del registro
     * @return array|null Datos del registro o null si no existe
     */
    public function findById(string $id): ?array
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();

        $sql = "SELECT * FROM {$table}
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Lista registros del tenant actual con filtros opcionales.
     *
     * @param array $filters Filtros WHERE (ej: ['estado' => 'ACTIVO', 'categoria' => 'A'])
     * @param int $page Página actual (default: 1)
     * @param int $perPage Registros por página (default: 20, max: 100)
     * @return array{data: array, pagination: array}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // Construir WHERE
        $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        $allowedFilters = $this->getAllowedFilters();

        foreach ($filters as $field => $value) {
            if ($value === null || !in_array($field, $allowedFilters, true)) {
                continue;
            }
            $where[] = "{$field} = :filter_{$field}";
            $params["filter_{$field}"] = $value;
        }

        $whereClause = implode(' AND ', $where);

        // Contar total
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Obtener datos
        $sql = "SELECT * FROM {$table}
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    /**
     * Inserta un nuevo registro en el tenant actual.
     *
     * @param array $data Datos del registro (NO incluir id ni tenant_id)
     * @return string ID del registro creado
     */
    public function save(array $data): string
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();

        // Generar ID si no se proporciona
        if (!isset($data['id'])) {
            $data['id'] = $this->generateUuid();
        }

        // Forzar tenant_id
        $data['tenant_id'] = $tenantId;

        // Timestamps
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        // Construir INSERT
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return $data['id'];
    }

    /**
     * Actualiza un registro existente en el tenant actual.
     *
     * @param string $id ID del registro
     * @param array $data Datos a actualizar
     * @return bool True si se actualizó, false si no existe
     * @throws TenantException Si el registro no pertenece al tenant actual
     */
    public function update(string $id, array $data): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();

        // Verificar que el registro existe y pertenece al tenant
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        // Validar que el tenant_id no cambie
        if (isset($data['tenant_id']) && $data['tenant_id'] !== $tenantId) {
            throw new TenantException(
                "No se puede cambiar el tenant_id de un recurso existente.",
                403
            );
        }

        // Remover campos inmutables
        unset($data['id'], $data['tenant_id'], $data['created_at']);

        if (empty($data)) {
            return true;
        }

        // Timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Construir UPDATE
        $sets = array_map(fn($col) => "{$col} = :{$col}", array_keys($data));
        $sql = "UPDATE {$table}
                SET " . implode(', ', $sets) . "
                WHERE id = :id AND tenant_id = :tenant_id";

        $data['id'] = $id;
        $data['tenant_id'] = $tenantId;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    /**
     * Elimina un registro (soft delete si la tabla tiene deleted_at, hard delete si no).
     *
     * @param string $id ID del registro
     * @return bool True si se eliminó, false si no existe
     */
    public function delete(string $id): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();

        // Verificar que el registro existe
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        // Verificar si la tabla tiene deleted_at (soft delete)
        $hasSoftDelete = $this->tableHasColumn($table, 'deleted_at');

        if ($hasSoftDelete) {
            $sql = "UPDATE {$table}
                    SET deleted_at = NOW(), updated_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";
        } else {
            $sql = "DELETE FROM {$table}
                    WHERE id = :id AND tenant_id = :tenant_id";
        }

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    }

    /**
     * Cuenta registros del tenant actual con filtros opcionales.
     *
     * @param array $filters Filtros WHERE
     * @return int Número de registros
     */
    public function count(array $filters = []): int
    {
        $tenantId = $this->getCurrentTenantId();
        $table = $this->getTableName();

        $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        $allowedFilters = $this->getAllowedFilters();

        foreach ($filters as $field => $value) {
            if ($value === null || !in_array($field, $allowedFilters, true)) {
                continue;
            }
            $where[] = "{$field} = :filter_{$field}";
            $params["filter_{$field}"] = $value;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ========================================================================
    // MÉTODOS PROTEGIDOS PARA QUERIES PERSONALIZADAS
    // ========================================================================

    /**
     * Ejecuta una query personalizada con tenant_id automático.
     *
     * @param string $sql Query SQL (debe incluir :tenant_id si es necesario)
     * @param array $params Parámetros de la query
     * @return PDOStatement
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        // Inyectar tenant_id si no está presente
        if (!isset($params['tenant_id'])) {
            $params['tenant_id'] = $this->getCurrentTenantId();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Ejecuta una query y retorna un solo registro.
     *
     * @param string $sql Query SQL
     * @param array $params Parámetros
     * @return array|null Registro o null
     */
    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Ejecuta una query y retorna todos los registros.
     *
     * @param string $sql Query SQL
     * @param array $params Parámetros
     * @return array Lista de registros
     */
    protected function queryAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ejecuta una query de escritura (INSERT/UPDATE/DELETE) y retorna el número de filas afectadas.
     *
     * @param string $sql Query SQL
     * @param array $params Parámetros
     * @return int Número de filas afectadas
     */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Inicia una transacción.
     */
    protected function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Confirma la transacción actual.
     */
    protected function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Revierte la transacción actual.
     */
    protected function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    // ========================================================================
    // MÉTODOS AUXILIARES
    // ========================================================================

    /**
     * Obtiene el tenant_id actual (con cache para evitar múltiples llamadas).
     *
     * @return string tenant_id
     * @throws TenantException Si no hay contexto de tenant
     */
    protected function getCurrentTenantId(): string
    {
        if ($this->cachedTenantId === null) {
            $this->cachedTenantId = $this->tenantContext->getTenantId();
        }
        return $this->cachedTenantId;
    }

    /**
     * Valida que un recurso pertenezca al tenant actual.
     *
     * @param string $resourceTenantId tenant_id del recurso
     * @throws TenantException Si no coincide
     */
    protected function validateTenantOwnership(string $resourceTenantId): void
    {
        $currentTenantId = $this->getCurrentTenantId();
        if ($resourceTenantId !== $currentTenantId) {
            throw new TenantException(
                "Violación de aislamiento: el recurso pertenece al tenant '{$resourceTenantId}' " .
                "pero el contexto actual es '{$currentTenantId}'.",
                403
            );
        }
    }

    /**
     * Genera un UUID v4.
     *
     * @return string UUID
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Verifica si una tabla tiene una columna específica.
     *
     * @param string $table Nombre de la tabla
     * @param string $column Nombre de la columna
     * @return bool True si existe la columna
     */
    protected function tableHasColumn(string $table, string $column): bool
    {
        $sql = "SHOW COLUMNS FROM {$table} LIKE :column";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['column' => $column]);
        return $stmt->fetch() !== false;
    }

    /**
     * Limpia el cache del tenant_id (útil en tests o cambios de contexto).
     */
    protected function clearTenantCache(): void
    {
        $this->cachedTenantId = null;
    }
}
