<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\PickingOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class PickingOperationRepository implements PickingOperationRepositoryInterface
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

    public function findByLote(string $idLotePicking): array
    {
        $sql = 'SELECT * FROM picking_operations
                WHERE id_lote_picking = :lote
                  AND tenant_id = :tenant_id
                ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'lote' => $idLotePicking,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        $operations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $operations[] = PickingOperation::fromArray($row);
        }

        return $operations;
    }

    public function findLatestByUnidad(string $unidadFisicaId): ?PickingOperation
    {
        $sql = 'SELECT * FROM picking_operations
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

        return $row ? PickingOperation::fromArray($row) : null;
    }

    public function existsLote(string $idLotePicking): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM picking_operations
              WHERE id_lote_picking = :lote AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'lote' => $idLotePicking,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function save(PickingOperation $operation): void
    {
        $data = $operation->toArray();

        $sql = 'INSERT INTO picking_operations
                (id, tenant_id, id_lote_picking, unidad_fisica_id, cantidad,
                 ubicacion_origen_id, ubicacion_preparacion_id, referencia_orden,
                 actor_id_selector, actor_id_supervisor, estado, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :id_lote_picking, :unidad_fisica_id, :cantidad,
                 :ubicacion_origen_id, :ubicacion_preparacion_id, :referencia_orden,
                 :actor_id_selector, :actor_id_supervisor, :estado, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(PickingOperation $operation): void
    {
        $sql = 'UPDATE picking_operations
                SET estado = :estado,
                    actor_id_supervisor = :actor_id_supervisor,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'estado' => $operation->estado(),
            'actor_id_supervisor' => $operation->actorIdSupervisor(),
            'updated_at' => $operation->updatedAt()->format('Y-m-d H:i:s'),
            'id' => $operation->id(),
            'tenant_id' => $operation->tenantId(),
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM picking_operations WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $operation->id(), 'tenant_id' => $operation->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw InventoryException::pickingLotNotFound($operation->idLotePicking());
            }
        }
    }
}
