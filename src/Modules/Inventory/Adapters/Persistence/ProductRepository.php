<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class ProductRepository implements ProductRepositoryInterface
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

    public function findById(string $id): ?Product
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE sku = :sku AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['sku' => strtoupper(trim($sku)), 'tenant_id' => $tenantId]);
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

        if (isset($filters['activo'])) {
            $where[] = 'activo = :activo';
            $params['activo'] = $filters['activo'] ? 1 : 0;
        }
        if (!empty($filters['unidad_medida'])) {
            $where[] = 'unidad_medida = :um';
            $params['um'] = strtoupper(trim($filters['unidad_medida']));
        }
        if (!empty($filters['categoria'])) {
            $where[] = 'categoria = :categoria';
            $params['categoria'] = trim($filters['categoria']);
        }
        if (isset($filters['es_serializable'])) {
            $where[] = 'es_serializable = :es';
            $params['es'] = $filters['es_serializable'] ? 1 : 0;
        }
        if (isset($filters['es_loteable'])) {
            $where[] = 'es_loteable = :el';
            $params['el'] = $filters['es_loteable'] ? 1 : 0;
        }
        if (!empty($filters['search'])) {
            // PDO con emulación desactivada no permite repetir el mismo placeholder nombrado
            $where[] = '(sku LIKE :search1 OR nombre LIKE :search2 OR descripcion LIKE :search3 OR categoria LIKE :search4)';
            $term = '%' . trim($filters['search']) . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
            $params['search3'] = $term;
            $params['search4'] = $term;
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM products WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM products WHERE {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
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

    public function findActivos(): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE tenant_id = :tenant_id AND activo = 1 AND deleted_at IS NULL ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findByCategoria(string $categoria): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE tenant_id = :tenant_id AND categoria = :categoria AND activo = 1 AND deleted_at IS NULL ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'categoria' => trim($categoria)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function findByUnidadMedida(string $unidadMedida): array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE tenant_id = :tenant_id AND unidad_medida = :um AND activo = 1 AND deleted_at IS NULL ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'um' => strtoupper(trim($unidadMedida))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function save(Product $product): void
    {
        if ($this->existsBySku($product->sku()->value())) {
            throw InventoryException::skuAlreadyExists($product->sku()->value());
        }

        $data = $this->toRow($product);
        $sql = "INSERT INTO products
                (id, tenant_id, sku, nombre, descripcion, categoria, unidad_medida,
                 peso, volumen, dimensiones, es_serializable, es_loteable, propiedades, activo, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :sku, :nombre, :descripcion, :categoria, :unidad_medida,
                 :peso, :volumen, :dimensiones, :es_serializable, :es_loteable, :propiedades, :activo, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($data);
        } catch (PDOException $e) {
            throw $this->translateDuplicateKey($e, $product->sku()->value());
        }
    }

    public function update(Product $product): void
    {
        $tenantId = $this->getCurrentTenantId();
        $existing = $this->findById($product->id());
        if ($existing === null) {
            throw InventoryException::productNotFound($product->id());
        }

        $data = $this->toRow($product);
        unset($data['id'], $data['tenant_id'], $data['created_at']);

        $sets = array_map(fn($col) => "{$col} = :{$col}", array_keys($data));
        $sql = "UPDATE products SET " . implode(', ', $sets) . " WHERE id = :id AND tenant_id = :tenant_id";

        $data['id'] = $product->id();
        $data['tenant_id'] = $tenantId;

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($data);
        } catch (PDOException $e) {
            throw $this->translateDuplicateKey($e, $product->sku()->value());
        }
    }

    public function softDelete(string $id): void
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "UPDATE products SET deleted_at = NOW(), activo = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    }

    public function findRawIncludingDeleted(string $id): ?array
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT * FROM products WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function existsBySku(string $sku): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM products WHERE sku = :sku AND tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['sku' => strtoupper(trim($sku)), 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM products WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasStockUnits(string $productId): bool
    {
        $tenantId = $this->getCurrentTenantId();
        $sql = "SELECT COUNT(*) FROM stock_units WHERE producto_id = :pid AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pid' => $productId, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(array $filters = []): int
    {
        $tenantId = $this->getCurrentTenantId();
        $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
        $params = ['tenant_id' => $tenantId];

        if (isset($filters['activo'])) {
            $where[] = 'activo = :activo';
            $params['activo'] = $filters['activo'] ? 1 : 0;
        }
        if (!empty($filters['unidad_medida'])) {
            $where[] = 'unidad_medida = :um';
            $params['um'] = strtoupper(trim($filters['unidad_medida']));
        }
        if (!empty($filters['categoria'])) {
            $where[] = 'categoria = :categoria';
            $params['categoria'] = trim($filters['categoria']);
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM products WHERE {$whereClause}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function translateDuplicateKey(PDOException $e, string $sku): InventoryException
    {
        if ($e->getCode() === '23000' && stripos($e->getMessage(), 'sku') !== false) {
            return InventoryException::skuAlreadyExists($sku);
        }

        throw $e;
    }

    private function hydrate(array $row): Product
    {
        return Product::fromArray([
            'id'            => $row['id'],
            'tenant_id'     => $row['tenant_id'],
            'sku'           => $row['sku'],
            'nombre'        => $row['nombre'],
            'descripcion'   => $row['descripcion'] ?? null,
            'categoria'     => $row['categoria'] ?? null,
            'unidad_medida' => $row['unidad_medida'],
            'peso'          => isset($row['peso']) ? (float) $row['peso'] : null,
            'volumen'       => isset($row['volumen']) ? (float) $row['volumen'] : null,
            'dimensiones'   => $row['dimensiones'] ?? null,
            'activo'        => (int) ($row['activo'] ?? 1),
            'metadatos'     => $this->decodeMetadatos($row['propiedades'] ?? null),
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
            'deleted_at'    => $row['deleted_at'] ?? null,
        ]);
    }

    private function decodeMetadatos(mixed $raw): ?array
    {
        if (empty($raw) || !is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!array_key_exists('metadatos', $decoded)) {
            // Formato legacy: propiedades solo contenía duplicados de columnas
            return null;
        }

        $value = $decoded['metadatos'];
        $guard = 0;
        // Formato legacy corrupto: cada guardado re-envolvía metadatos en sí mismo
        while ($guard++ < 8 && is_array($value) && array_key_exists('metadatos', $value)) {
            $value = $value['metadatos'];
        }

        if (!is_array($value)) {
            return null;
        }

        // Solo quedan duplicados de columnas: no había metadatos reales
        $known = ['categoria' => true, 'peso' => true, 'volumen' => true, 'dimensiones' => true];
        if (array_diff_key($value, $known) === []) {
            return null;
        }

        return $value;
    }

    private function toRow(Product $p): array
    {
        return [
            'id'              => $p->id(),
            'tenant_id'       => $p->tenantId(),
            'sku'             => $p->sku()->value(),
            'nombre'          => $p->nombre(),
            'descripcion'     => $p->descripcion(),
            'categoria'       => $p->categoria(),
            'unidad_medida'   => $p->unidadMedida()->value(),
            'peso'            => $p->peso(),
            'volumen'         => $p->volumen(),
            'dimensiones'     => $p->dimensiones(),
            'es_serializable' => 0,
            'es_loteable'     => 0,
            'propiedades'     => $p->metadatos() !== null ? json_encode(['metadatos' => $p->metadatos()], JSON_UNESCAPED_UNICODE) : null,
            'activo'          => $p->activo() ? 1 : 0,
            'created_at'      => $p->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'      => $p->updatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
