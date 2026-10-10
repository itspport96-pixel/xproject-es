<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Reservation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Shared\Services\DatabaseService;
use DateTimeImmutable;
use PDO;

final class ReservationRepository implements ReservationRepositoryInterface
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

    public function findById(string $id): ?Reservation
    {
        $sql = 'SELECT * FROM reservations
                WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Reservation::fromArray($row) : null;
    }

    public function findActivaByUnidad(string $unidadFisicaId): ?Reservation
    {
        $sql = 'SELECT * FROM reservations
                WHERE unidad_fisica_id = :unidad_fisica_id
                  AND tenant_id = :tenant_id
                  AND estado = :estado
                ORDER BY fecha_reserva DESC
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'unidad_fisica_id' => $unidadFisicaId,
            'tenant_id' => $this->getCurrentTenantId(),
            'estado' => Reservation::ESTADO_ACTIVA,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Reservation::fromArray($row) : null;
    }

    public function findExpiradas(DateTimeImmutable $now, int $limit = 500): array
    {
        $sql = 'SELECT * FROM reservations
                WHERE tenant_id = :tenant_id
                  AND estado = :estado
                  AND fecha_expiracion IS NOT NULL
                  AND fecha_expiracion <= :ahora
                ORDER BY fecha_expiracion ASC
                LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getCurrentTenantId(),
            'estado' => Reservation::ESTADO_ACTIVA,
            'ahora' => $now->format('Y-m-d H:i:s'),
        ]);

        $reservations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reservations[] = Reservation::fromArray($row);
        }

        return $reservations;
    }

    public function save(Reservation $reservation): void
    {
        $data = $reservation->toArray();

        $sql = 'INSERT INTO reservations
                (id, tenant_id, unidad_fisica_id, cantidad, referencia_orden, actor_id,
                 fecha_reserva, fecha_expiracion, estado, motivo_cancelacion,
                 created_at, updated_at)
                VALUES
                (:id, :tenant_id, :unidad_fisica_id, :cantidad, :referencia_orden, :actor_id,
                 :fecha_reserva, :fecha_expiracion, :estado, :motivo_cancelacion,
                 :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(Reservation $reservation): void
    {
        $data = $reservation->toArray();

        // Sólo los parámetros con placeholder en el UPDATE: el resto de
        // columnas no cambian y provocan "column index out of range" en PDO.
        $data = array_intersect_key($data, array_flip([
            'estado', 'motivo_cancelacion', 'updated_at', 'id', 'tenant_id',
        ]));

        $sql = 'UPDATE reservations
                SET estado = :estado,
                    motivo_cancelacion = :motivo_cancelacion,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM reservations WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $reservation->id(), 'tenant_id' => $reservation->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw InventoryException::reservationNotFound($reservation->id());
            }
        }
    }

    public function count(array $filters = []): int
    {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getCurrentTenantId()];

        foreach (['estado', 'unidad_fisica_id'] as $column) {
            if (!empty($filters[$column])) {
                $where[] = "{$column} = :{$column}";
                $params[$column] = $filters[$column];
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM reservations WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
