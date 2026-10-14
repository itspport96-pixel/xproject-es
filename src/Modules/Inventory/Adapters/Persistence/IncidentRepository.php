<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Security\Service\EncryptionService;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;

final class IncidentRepository implements IncidentRepositoryInterface
{
    private const PII_FIELDS = ['descripcion', 'observaciones'];
    private const PII_JSON_FIELDS = ['resolucion', 'restriccion_resolucion'];

    private PDO $pdo;
    private TenantContext $tenantContext;
    private ?EncryptionService $encryption;

    public function __construct(
        DatabaseService $db,
        TenantContext $tenantContext,
        ?EncryptionService $encryption = null
    ) {
        $this->pdo = $db->getConnection();
        $this->tenantContext = $tenantContext;
        if ($encryption !== null) {
            $this->encryption = $encryption;
        } else {
            try {
                $container = Container::getInstance();
                $this->encryption = $container->has(EncryptionService::class) ? $container->get(EncryptionService::class) : null;
            } catch (\Throwable) {
                $this->encryption = null;
            }
        }
    }

    private function getCurrentTenantId(): string
    {
        return $this->tenantContext->getTenantId();
    }

    public function save(Incident $incident): void
    {
        $data = $this->encryptPii($incident->toArray());

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
        $data = $this->encryptPii($data);

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

        return $row ? Incident::fromArray($this->decryptRow($row)) : null;
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

        $incidents = [];
        foreach ($rows as $row) {
            $incidents[] = Incident::fromArray($this->decryptRow($row));
        }

        return $incidents;
    }

    private function encryptPii(array $data): array
    {
        if ($this->encryption === null) {
            return $data;
        }
        foreach (self::PII_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->encryption->encrypt($data[$field]);
            }
        }
        // JSON con CHECK json_valid: envolver ciphertext en wrapper
        foreach (self::PII_JSON_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = json_encode(
                    ['__encrypted' => $this->encryption->encrypt($data[$field])],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }
        return $data;
    }

    private function decryptRow(array $row): array
    {
        if ($this->encryption === null) {
            return $row;
        }
        foreach (self::PII_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                try {
                    $row[$field] = $this->encryption->decrypt($row[$field]);
                } catch (\Throwable) {
                }
            }
        }
        foreach (self::PII_JSON_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = $this->decryptJsonField($row[$field]);
            }
        }
        return $row;
    }

    private function decryptJsonField(string $value): string
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['__encrypted']) && is_string($decoded['__encrypted'])) {
            try {
                return $this->encryption->decrypt($decoded['__encrypted']);
            } catch (\Throwable) {
                return $value;
            }
        }
        return $value;
    }
}
