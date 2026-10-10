<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;

final class StockLedgerRepository implements StockLedgerRepositoryInterface
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

    public function findByUnidadFisica(string $unidadFisicaId): ?StockLedger
    {
        $sql = 'SELECT * FROM stock_ledger
                WHERE unidad_fisica_id = :unidad_fisica_id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'unidad_fisica_id' => $unidadFisicaId,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? StockLedger::fromArray($row) : null;
    }

    public function save(StockLedger $ledger): void
    {
        $data = $ledger->toArray();

        $sql = 'INSERT INTO stock_ledger
                (id, tenant_id, unidad_fisica_id, cantidad_fisica, cantidad_reservada,
                 cantidad_en_transito, cantidad_disponible, estado_actual, ultima_actualizacion)
                VALUES
                (:id, :tenant_id, :unidad_fisica_id, :cantidad_fisica, :cantidad_reservada,
                 :cantidad_en_transito, :cantidad_disponible, :estado_actual, :ultima_actualizacion)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(StockLedger $ledger): void
    {
        $data = $ledger->toArray();

        // Sólo los parámetros con placeholder en el UPDATE (unidad_fisica_id
        // no se modifica y provoca "column index out of range" en PDO).
        $data = array_intersect_key($data, array_flip([
            'cantidad_fisica', 'cantidad_reservada', 'cantidad_en_transito',
            'cantidad_disponible', 'estado_actual', 'ultima_actualizacion',
            'id', 'tenant_id',
        ]));

        $sql = 'UPDATE stock_ledger
                SET cantidad_fisica = :cantidad_fisica,
                    cantidad_reservada = :cantidad_reservada,
                    cantidad_en_transito = :cantidad_en_transito,
                    cantidad_disponible = :cantidad_disponible,
                    estado_actual = :estado_actual,
                    ultima_actualizacion = :ultima_actualizacion
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM stock_ledger WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $ledger->id(), 'tenant_id' => $ledger->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw new InventoryException(
                    "El ledger de la unidad '{$ledger->unidadFisicaId()}' no existe.",
                    404,
                    InventoryException::CATEGORY_NOT_FOUND
                );
            }
        }
    }
}
