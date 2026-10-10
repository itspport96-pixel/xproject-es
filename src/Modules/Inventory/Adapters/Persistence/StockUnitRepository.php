<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class StockUnitRepository implements StockUnitRepositoryInterface
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

    public function findById(string $id): ?StockUnit
    {
        $sql = 'SELECT * FROM stock_units WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getCurrentTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? StockUnit::fromArray($row) : null;
    }

    public function findByBarcode(string $barcode): ?StockUnit
    {
        $sql = 'SELECT * FROM stock_units WHERE codigo_barcode = :codigo_barcode AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'codigo_barcode' => $barcode,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? StockUnit::fromArray($row) : null;
    }

    public function existsByBarcode(string $barcode): bool
    {
        $sql = 'SELECT COUNT(*) FROM stock_units WHERE codigo_barcode = :codigo_barcode AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'codigo_barcode' => $barcode,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->getCurrentTenantId();
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['producto_id'])) {
            $where[] = 'producto_id = :producto_id';
            $params['producto_id'] = $filters['producto_id'];
        }
        if (!empty($filters['ubicacion_id'])) {
            $where[] = 'ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = $filters['ubicacion_id'];
        }
        if (!empty($filters['estado'])) {
            $where[] = 'estado = :estado';
            $params['estado'] = strtoupper(trim($filters['estado']));
        }
        if (!empty($filters['search'])) {
            $where[] = '(codigo_barcode LIKE :search1 OR lote LIKE :search2 OR numero_serie LIKE :search3)';
            $term = '%' . trim($filters['search']) . '%';
            $params['search1'] = $term;
            $params['search2'] = $term;
            $params['search3'] = $term;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM stock_units WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM stock_units WHERE {$whereClause} ORDER BY fecha_ingreso DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map(static fn(array $row): StockUnit => StockUnit::fromArray($row), $rows),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    public function save(StockUnit $unit): void
    {
        $data = $unit->toArray();

        $sql = 'INSERT INTO stock_units
                (id, tenant_id, producto_id, propietario_id, custodio_id, numero_serie, lote,
                 codigo_barcode, cantidad, peso, volumen, estado, ubicacion_id, fecha_ingreso,
                 created_at, updated_at)
                VALUES
                (:id, :tenant_id, :producto_id, :propietario_id, :custodio_id, :numero_serie, :lote,
                 :codigo_barcode, :cantidad, :peso, :volumen, :estado, :ubicacion_id, :fecha_ingreso,
                 :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(StockUnit $unit): void
    {
        $data = $unit->toArray();

        // Sólo los parámetros con placeholder en el UPDATE: PDO con emulación
        // desactivada falla con "column index out of range" si se le pasan
        // columnas extra (codigo_barcode, fecha_ingreso, created_at).
        $data = array_intersect_key($data, array_flip([
            'producto_id', 'propietario_id', 'custodio_id', 'numero_serie', 'lote',
            'cantidad', 'peso', 'volumen', 'estado', 'ubicacion_id', 'updated_at',
            'id', 'tenant_id',
        ]));

        $sql = 'UPDATE stock_units
                SET producto_id = :producto_id,
                    propietario_id = :propietario_id,
                    custodio_id = :custodio_id,
                    numero_serie = :numero_serie,
                    lote = :lote,
                    cantidad = :cantidad,
                    peso = :peso,
                    volumen = :volumen,
                    estado = :estado,
                    ubicacion_id = :ubicacion_id,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0) {
            // rowCount() devuelve 0 cuando ningún valor cambió (p.ej. mismo
            // segundo): se confirma la existencia antes de fallar.
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM stock_units WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $unit->id(), 'tenant_id' => $unit->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw InventoryException::invalidField('id', 'La unidad física no existe o no pertenece al tenant actual.');
            }
        }
    }

    public function count(array $filters = []): int
    {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getCurrentTenantId()];

        if (!empty($filters['producto_id'])) {
            $where[] = 'producto_id = :producto_id';
            $params['producto_id'] = $filters['producto_id'];
        }
        if (!empty($filters['ubicacion_id'])) {
            $where[] = 'ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = $filters['ubicacion_id'];
        }
        if (!empty($filters['estado'])) {
            $where[] = 'estado = :estado';
            $params['estado'] = strtoupper(trim($filters['estado']));
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_units WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
