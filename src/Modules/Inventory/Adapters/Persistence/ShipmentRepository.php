<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Adapters\Persistence;

use App\Core\Security\Service\EncryptionService;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ShipmentRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;

final class ShipmentRepository implements ShipmentRepositoryInterface
{
    private const PII_FIELDS = ['destino_direccion', 'destinatario_nombre', 'conductor_nombre', 'destinatario_contacto', 'conductor_contacto'];

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

    public function findById(string $id): ?Shipment
    {
        $sql = 'SELECT * FROM shipments
                WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getCurrentTenantId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Shipment::fromArray($this->decryptRow($row)) : null;
    }

    public function save(Shipment $shipment): void
    {
        $data = $shipment->toArray();
        $data = $this->encryptPii($data);

        $sql = 'INSERT INTO shipments
                (id, tenant_id, referencia_externa, tipo, origen_ubicacion_id,
                 destino_direccion, destinatario_id, destinatario_nombre, destinatario_contacto,
                 conductor_nombre, conductor_contacto, vehiculo_referencia,
                 estado, hora_salida, hora_entrega_esperada, hora_entrega_real,
                 observaciones, actor_id_autoriza_salida, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :referencia_externa, :tipo, :origen_ubicacion_id,
                 :destino_direccion, :destinatario_id, :destinatario_nombre, :destinatario_contacto,
                 :conductor_nombre, :conductor_contacto, :vehiculo_referencia,
                 :estado, :hora_salida, :hora_entrega_esperada, :hora_entrega_real,
                 :observaciones, :actor_id_autoriza_salida, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function update(Shipment $shipment): void
    {
        $data = $this->encryptPii($shipment->toArray());

        // Sólo los parámetros con placeholder en el UPDATE (created_at no
        // cambia y provocaría "column index out of range" en PDO).
        unset($data['created_at']);

        $sql = 'UPDATE shipments
                SET referencia_externa = :referencia_externa,
                    tipo = :tipo,
                    origen_ubicacion_id = :origen_ubicacion_id,
                    destino_direccion = :destino_direccion,
                    destinatario_id = :destinatario_id,
                    destinatario_nombre = :destinatario_nombre,
                    destinatario_contacto = :destinatario_contacto,
                    conductor_nombre = :conductor_nombre,
                    conductor_contacto = :conductor_contacto,
                    vehiculo_referencia = :vehiculo_referencia,
                    estado = :estado,
                    hora_salida = :hora_salida,
                    hora_entrega_esperada = :hora_entrega_esperada,
                    hora_entrega_real = :hora_entrega_real,
                    observaciones = :observaciones,
                    actor_id_autoriza_salida = :actor_id_autoriza_salida,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM shipments WHERE id = :id AND tenant_id = :tenant_id'
            );
            $check->execute(['id' => $shipment->id(), 'tenant_id' => $shipment->tenantId()]);

            if ((int) $check->fetchColumn() === 0) {
                throw InventoryException::shipmentNotFound($shipment->id());
            }
        }
    }

    /**
     * Cifra los campos PII de un array de datos de shipments.
     */
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
        return $data;
    }

    /**
     * Descifra los campos PII de una fila de shipments.
     * Si el valor no está cifrado (pre-migración), lo devuelve tal cual.
     */
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
                    // Pre-migración: valor en claro, dejar tal cual.
                }
            }
        }
        return $row;
    }
}
