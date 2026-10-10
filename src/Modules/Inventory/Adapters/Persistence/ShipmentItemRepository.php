<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\ShipmentItem;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ShipmentItemRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class ShipmentItemRepository implements ShipmentItemRepositoryInterface
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

    public function findByShipment(string $shipmentId): array
    {
        $sql = 'SELECT * FROM shipment_items
                WHERE shipment_id = :shipment_id
                  AND tenant_id = :tenant_id
                ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'shipment_id' => $shipmentId,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ShipmentItem::fromArray($row);
        }

        return $items;
    }

    public function save(ShipmentItem $item): void
    {
        $data = $item->toArray();

        $sql = 'INSERT INTO shipment_items
                (id, tenant_id, shipment_id, unidad_fisica_id, cantidad, estado,
                 created_at, updated_at)
                VALUES
                (:id, :tenant_id, :shipment_id, :unidad_fisica_id, :cantidad, :estado,
                 :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(ShipmentItem $item): void
    {
        $sql = 'UPDATE shipment_items
                SET estado = :estado,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'estado' => $item->estado(),
            'updated_at' => $item->updatedAt()->format('Y-m-d H:i:s'),
            'id' => $item->id(),
            'tenant_id' => $item->tenantId(),
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM shipment_items WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $item->id(), 'tenant_id' => $item->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw InventoryException::shipmentNotFound($item->shipmentId());
            }
        }
    }
}
