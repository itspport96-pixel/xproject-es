<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\InventoryCountRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Repositorio MariaDB del conteo físico (CU-011). Tenant-scoped: cada
 * lectura/escritura va limitada por `tenant_id`.
 */
final class InventoryCountRepository implements InventoryCountRepositoryInterface
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

    public function save(InventoryCount $count): void
    {
        $data = $count->toArray();

        $sql = 'INSERT INTO inventory_counts
                (id, tenant_id, ubicacion_id, estado, usuario_responsable_id, fecha_conteo,
                 lista_teorica, resultados, discrepancias, reconciliado, ajustes_aplicados,
                 created_at, updated_at)
                VALUES
                (:id, :tenant_id, :ubicacion_id, :estado, :usuario_responsable_id, :fecha_conteo,
                 :lista_teorica, :resultados, :discrepancias, :reconciliado, :ajustes_aplicados,
                 :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(InventoryCount $count): void
    {
        // Sólo los parámetros con placeholder en el UPDATE: PDO con emulación
        // desactivada falla con "column index out of range" si se le pasan
        // columnas extra (ubicacion_id, usuario_responsable_id, etc.).
        $data = array_intersect_key($count->toArray(), array_flip([
            'estado', 'lista_teorica', 'resultados', 'discrepancias',
            'reconciliado', 'ajustes_aplicados', 'updated_at',
            'id', 'tenant_id',
        ]));

        $sql = 'UPDATE inventory_counts
                   SET estado = :estado,
                       lista_teorica = :lista_teorica,
                       resultados = :resultados,
                       discrepancias = :discrepancias,
                       reconciliado = :reconciliado,
                       ajustes_aplicados = :ajustes_aplicados,
                       updated_at = :updated_at
                 WHERE id = :id
                   AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0 && $this->findById($count->id()) === null) {
            throw InventoryException::countNotFound($count->id());
        }
    }

    public function findById(string $id): ?InventoryCount
    {
        $sql = 'SELECT * FROM inventory_counts WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getCurrentTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? InventoryCount::fromArray($row) : null;
    }
}
