<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Movement;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\MovementRepositoryInterface;
use App\Shared\Services\DatabaseService;
use PDO;
use PDOException;

final class MovementRepository implements MovementRepositoryInterface
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

    public function findById(string $id): ?Movement
    {
        $sql = 'SELECT * FROM movements
                WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Movement::fromArray($row) : null;
    }

    public function save(Movement $movement): void
    {
        $data = $movement->toArray();

        $sql = 'INSERT INTO movements
                (id, tenant_id, unidad_fisica_id, cantidad, ubicacion_origen_id,
                 ubicacion_destino_id, motivo, actor_id, referencia_externa,
                 timestamp_movimiento, hash_integridad, es_correccion,
                 movimiento_referencia_id)
                VALUES
                (:id, :tenant_id, :unidad_fisica_id, :cantidad, :ubicacion_origen_id,
                 :ubicacion_destino_id, :motivo, :actor_id, :referencia_externa,
                 :timestamp_movimiento, :hash_integridad, :es_correccion,
                 :movimiento_referencia_id)';

        $stmt = $this->pdo->prepare($sql);

        try {
            $stmt->execute($data);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InventoryException(
                    "El movimiento '{$movement->id()}' ya existe.",
                    409,
                    InventoryException::CATEGORY_CONFLICT,
                    'id'
                );
            }

            throw $e;
        }
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM movements WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<Movement>
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);

        [$where, $params] = $this->buildWhere($filters);

        $sql = 'SELECT * FROM movements
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY timestamp_movimiento DESC, id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): Movement => Movement::fromArray($row), $rows);
    }

    public function findLatestByUnidad(string $unidadFisicaId): ?Movement
    {
        $sql = 'SELECT * FROM movements
                WHERE tenant_id = :tenant_id AND unidad_fisica_id = :unidad_fisica_id
                ORDER BY timestamp_movimiento DESC, id DESC
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getCurrentTenantId(),
            'unidad_fisica_id' => $unidadFisicaId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Movement::fromArray($row) : null;
    }

    /**
     * @param array $filters unidad_fisica_id, ubicacion_origen_id,
     *        ubicacion_destino_id, motivo, es_correccion, fecha_desde, fecha_hasta
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getCurrentTenantId()];

        foreach (['unidad_fisica_id', 'ubicacion_origen_id', 'ubicacion_destino_id', 'motivo'] as $column) {
            if (!empty($filters[$column])) {
                $where[] = "{$column} = :{$column}";
                $params[$column] = $filters[$column];
            }
        }

        if (isset($filters['es_correccion']) && $filters['es_correccion'] !== null && $filters['es_correccion'] !== '') {
            $where[] = 'es_correccion = :es_correccion';
            $params['es_correccion'] = (int) (bool) $filters['es_correccion'];
        }

        if (!empty($filters['fecha_desde'])) {
            $where[] = 'timestamp_movimiento >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'timestamp_movimiento <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        return [$where, $params];
    }
}
