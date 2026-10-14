<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Delivery;
use App\Modules\Inventory\Domain\Repositories\DeliveryRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class DeliveryRepository implements DeliveryRepositoryInterface
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

    public function save(Delivery $delivery): void
    {
        $data = $delivery->toArray();

        $sql = 'INSERT INTO deliveries
                (id, tenant_id, shipment_id, unidad_fisica_id, cantidad, estado,
                 destinatario_real, firma_comprobante, observaciones, actor_id_entrega,
                 timestamp_entrega, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :shipment_id, :unidad_fisica_id, :cantidad, :estado,
                 :destinatario_real, :firma_comprobante, :observaciones, :actor_id_entrega,
                 :timestamp_entrega, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function findById(string $id): ?Delivery
    {
        $sql = 'SELECT * FROM deliveries
                WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getCurrentTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Delivery::fromArray($row) : null;
    }

    public function findByShipment(string $shipmentId): array
    {
        $sql = 'SELECT * FROM deliveries
                WHERE shipment_id = :shipment_id
                  AND tenant_id = :tenant_id
                ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'shipment_id' => $shipmentId,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        $deliveries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $deliveries[] = Delivery::fromArray($row);
        }

        return $deliveries;
    }

    public function findLatestByUnidad(string $unidadFisicaId): ?Delivery
    {
        $sql = 'SELECT * FROM deliveries
                WHERE unidad_fisica_id = :unidad_fisica_id
                  AND tenant_id = :tenant_id
                ORDER BY created_at DESC, id ASC
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'unidad_fisica_id' => $unidadFisicaId,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Delivery::fromArray($row) : null;
    }
}
