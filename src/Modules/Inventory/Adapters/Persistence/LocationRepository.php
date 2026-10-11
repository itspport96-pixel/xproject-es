<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class LocationRepository implements LocationRepositoryInterface
{
    private PDO $pdo;
    private TenantContext $tenantContext;

    public function __construct(DatabaseService $db, TenantContext $tenantContext)
    {
        $this->pdo = $db->getConnection();
        $this->tenantContext = $tenantContext;
    }

    private function getCurrentTenantId(): string
    {
        return $this->tenantContext->getTenantId();
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    public function findById(string $id): ?Location
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByCodigo(string $codigo): ?Location
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE codigo = :codigo AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['codigo' => strtoupper(trim($codigo)), 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->getCurrentTenantId();
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['tipo'])) {
            $where[] = 'tipo = :tipo';
            $params['tipo'] = strtoupper(trim($filters['tipo']));
        }
        if (isset($filters['activa'])) {
            $where[] = 'activa = :activa';
            $params['activa'] = $filters['activa'] ? 1 : 0;
        }
        if (!empty($filters['search'])) {
            // PDO con emulación desactivada no permite repetir el mismo placeholder nombrado
            $where[] = '(codigo LIKE :search1 OR nombre LIKE :search2)';
            $term = '%' . trim($filters['search']) . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
        }
        if (!empty($filters['jerarquia'])) {
            $where[] = 'jerarquia LIKE :jerarquia';
            $params['jerarquia'] = trim($filters['jerarquia']) . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM locations WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM locations WHERE {$whereClause} ORDER BY codigo ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(fn($row) => $this->hydrate($row), $rows);

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

    public function findActivas(): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE tenant_id = :tenant_id AND activa = 1 AND deleted_at IS NULL ORDER BY codigo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findByTipo(string $tipo): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE tenant_id = :tenant_id AND tipo = :tipo AND deleted_at IS NULL ORDER BY codigo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'tipo' => strtoupper(trim($tipo))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findWithCapacityAvailable(): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations
                WHERE tenant_id = :tenant_id AND activa = 1 AND deleted_at IS NULL
                  AND (capacidad IS NULL OR capacidad_usada < capacidad)
                ORDER BY codigo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findChildren(string $jerarquiaPadre): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE tenant_id = :tenant_id AND deleted_at IS NULL AND jerarquia LIKE :jerarquia ORDER BY codigo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'jerarquia' => trim($jerarquiaPadre) . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    public function save(Location $location): void
    {
        if ($this->existsByCodigo($location->codigo()->value())) {
            throw InventoryException::locationCodeAlreadyExists($location->codigo()->value());
        }

        $data = $this->toRow($location);
        $sql = "INSERT INTO locations
                (id, tenant_id, codigo, nombre, tipo, jerarquia, capacidad, capacidad_usada, activa, metadatos, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :codigo, :nombre, :tipo, :jerarquia, :capacidad, :capacidad_usada, :activa, :metadatos, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($data);
        } catch (PDOException $e) {
            throw $this->translateDuplicateKey($e, $location->codigo()->value());
        }
    }

    public function update(Location $location): void
    {
        $tenantId = $this->getCurrentTenantId();
        $existing = $this->findById($location->id());
        if ($existing === null) {
            throw InventoryException::locationNotFound($location->id());
        }

        $data = $this->toRow($location);
        unset($data['id'], $data['tenant_id'], $data['created_at']);

        $sets = array_map(fn($col) => "{$col} = :{$col}", array_keys($data));
        $sql = "UPDATE locations SET " . implode(', ', $sets) . " WHERE id = :id AND tenant_id = :tenant_id";

        $data['id'] = $location->id();
        $data['tenant_id'] = $tenantId;

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($data);
        } catch (PDOException $e) {
            throw $this->translateDuplicateKey($e, $location->codigo()->value());
        }
    }

    public function softDelete(string $id): void
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "UPDATE locations SET deleted_at = NOW(), activa = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    }

    public function findRawIncludingDeleted(string $id): ?array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM locations WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function translateDuplicateKey(PDOException $e, string $codigo): InventoryException
    {
        if ($e->getCode() === '23000' && stripos($e->getMessage(), 'codigo') !== false) {
            return InventoryException::locationCodeAlreadyExists($codigo);
        }

        throw $e;
    }

    // ====================================================================
    // CONSULTAS
    // ====================================================================

    public function existsByCodigo(string $codigo): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM locations WHERE codigo = :codigo AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['codigo' => strtoupper(trim($codigo)), 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM locations WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasStockUnits(string $locationId): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM stock_units WHERE ubicacion_id = :lid AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['lid' => $locationId, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasMovements(string $locationId): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM movements
                WHERE (ubicacion_origen_id = :lid1 OR ubicacion_destino_id = :lid2) AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['lid1' => $locationId, 'lid2' => $locationId, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(array $filters = []): int
    {
        $tenantId = $this->getCurrentTenantId();
        $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['tipo'])) {
            $where[] = 'tipo = :tipo';
            $params['tipo'] = strtoupper(trim($filters['tipo']));
        }
        if (isset($filters['activa'])) {
            $where[] = 'activa = :activa';
            $params['activa'] = $filters['activa'] ? 1 : 0;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM locations WHERE {$whereClause}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ====================================================================
    // HIDRATACIÓN Y MAPEO
    // ====================================================================

    private function hydrate(array $row): Location
    {
        return Location::fromArray([
            'id'              => $row['id'],
            'tenant_id'       => $row['tenant_id'],
            'codigo'          => $row['codigo'],
            'nombre'          => $row['nombre'] ?? null,
            'tipo'            => $row['tipo'],
            'jerarquia'       => $row['jerarquia'] ?? null,
            'capacidad'       => isset($row['capacidad']) ? (int) $row['capacidad'] : null,
            'capacidad_usada' => (int) ($row['capacidad_usada'] ?? 0),
            'activa'          => (int) ($row['activa'] ?? 1),
            'metadatos'       => !empty($row['metadatos']) ? (is_string($row['metadatos']) ? json_decode($row['metadatos'], true) : $row['metadatos']) : null,
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
            'deleted_at'      => $row['deleted_at'] ?? null,
        ]);
    }

    private function toRow(Location $l): array
    {
        return [
            'id'              => $l->id(),
            'tenant_id'       => $l->tenantId(),
            'codigo'          => $l->codigo()->value(),
            'nombre'          => $l->nombre(),
            'tipo'            => $l->tipo()->value(),
            'jerarquia'       => $l->jerarquia(),
            'capacidad'       => $l->capacidad(),
            'capacidad_usada' => $l->capacidadUsada(),
            'activa'          => $l->activa() ? 1 : 0,
            'metadatos'       => $l->metadatos() !== null ? json_encode($l->metadatos(), JSON_UNESCAPED_UNICODE) : null,
            'created_at'      => $l->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'      => $l->updatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
