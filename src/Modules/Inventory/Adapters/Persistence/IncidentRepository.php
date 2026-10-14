<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class IncidentRepository implements IncidentRepositoryInterface
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

    public function save(Incident $incident): void
    {
        $data = $incident->toArray();

        $sql = 'INSERT INTO incidents
                (id, tenant_id, tipo, unidad_fisica_id, descripcion, observaciones,
                 ubicacion_id, estado, inventory_count_id,
                 actor_id_reporta, timestamp_deteccion, resolucion, prioridad,
                 created_at, updated_at)
                VALUES
                (:id, :tenant_id, :tipo, :unidad_fisica_id, :descripcion, :observaciones,
                 :ubicacion_id, :estado, :inventory_count_id,
                 :actor_id_reporta, :timestamp_deteccion, :resolucion, :prioridad,
                 :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(Incident $incident): void
    {
        // Sólo los parámetros con placeholder en el UPDATE: PDO con emulación
        // desactivada falla con "column index out of range" si se le pasan
        // columnas extra (tipo, unidad_fisica_id, descripcion, etc.).
        $data = array_intersect_key($incident->toArray(), array_flip([
            'estado', 'resolucion', 'inventory_count_id', 'updated_at',
            'id', 'tenant_id',
        ]));

        $sql = 'UPDATE incidents
                   SET estado = :estado,
                       resolucion = :resolucion,
                       inventory_count_id = :inventory_count_id,
                       updated_at = :updated_at
                 WHERE id = :id
                   AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0 && $this->findById($incident->id()) === null) {
            throw InventoryException::incidentNotFound($incident->id());
        }
    }

    public function findById(string $id): ?Incident
    {
        $sql = 'SELECT * FROM incidents WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getCurrentTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Incident::fromArray($row) : null;
    }

    public function findAbiertas(int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));

        $sql = 'SELECT * FROM incidents
                WHERE tenant_id = :tenant_id AND estado IN (:e1, :e2)
                ORDER BY timestamp_deteccion DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':tenant_id', $this->getCurrentTenantId());
        $stmt->bindValue(':e1', Incident::ESTADO_ABIERTA);
        $stmt->bindValue(':e2', Incident::ESTADO_INVESTIGACION);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): Incident => Incident::fromArray($row), $rows);
    }
}
