<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class StockEventRepository implements StockEventRepositoryInterface
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

    public function save(StockEvent $event): void
    {
        $data = $event->toArray();

        $sql = 'INSERT INTO stock_events
                (id, tenant_id, unidad_fisica_id, tipo_evento, descripcion, datos_operacion,
                 cantidad_anterior, cantidad_posterior, estado_anterior, estado_posterior,
                 created_at, hash_integridad)
                VALUES
                (:id, :tenant_id, :unidad_fisica_id, :tipo_evento, :descripcion, :datos_operacion,
                 :cantidad_anterior, :cantidad_posterior, :estado_anterior, :estado_posterior,
                 :created_at, :hash_integridad)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function findByUnidadFisica(string $unidadFisicaId, int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));

        $sql = 'SELECT * FROM stock_events
                WHERE unidad_fisica_id = :unidad_fisica_id AND tenant_id = :tenant_id
                ORDER BY created_at ASC, id ASC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':unidad_fisica_id', $unidadFisicaId);
        $stmt->bindValue(':tenant_id', $this->getCurrentTenantId());
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): StockEvent => StockEvent::fromArray($row), $rows);
    }
}
