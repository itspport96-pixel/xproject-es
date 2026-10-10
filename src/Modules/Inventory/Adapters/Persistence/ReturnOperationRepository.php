<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\ReturnOperation;
use App\Modules\Inventory\Domain\Repositories\ReturnOperationRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class ReturnOperationRepository implements ReturnOperationRepositoryInterface
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

    public function save(ReturnOperation $returnOperation): void
    {
        $data = $returnOperation->toArray();

        $sql = 'INSERT INTO return_operations
                (id, tenant_id, delivery_id, unidad_fisica_id, cantidad, motivo,
                 descripcion_motivo, estado_al_devolver, ubicacion_destino_id,
                 actor_id_recibe_devolucion, timestamp_devolucion, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :delivery_id, :unidad_fisica_id, :cantidad, :motivo,
                 :descripcion_motivo, :estado_al_devolver, :ubicacion_destino_id,
                 :actor_id_recibe_devolucion, :timestamp_devolucion, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function findById(string $id): ?ReturnOperation
    {
        $sql = 'SELECT * FROM return_operations
                WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getCurrentTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ReturnOperation::fromArray($row) : null;
    }

    public function findByUnidad(string $unidadFisicaId): array
    {
        $sql = 'SELECT * FROM return_operations
                WHERE unidad_fisica_id = :unidad_fisica_id
                  AND tenant_id = :tenant_id
                ORDER BY timestamp_devolucion DESC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'unidad_fisica_id' => $unidadFisicaId,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);

        $operations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $operations[] = ReturnOperation::fromArray($row);
        }

        return $operations;
    }
}
