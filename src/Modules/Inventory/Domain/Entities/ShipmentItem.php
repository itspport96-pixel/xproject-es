<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Ítem de Shipment (shipment_items) - spec §3.14
 *
 * Una fila por unidad física cargada en un envío:
 *
 *  (creación al cargar) CARGADA → EN_TRANSITO al autorizar la salida
 *  EN_TRANSITO → ENTREGADA | RECHAZADA | DEVUELTA   (CU-006+)
 *
 * @property-read string $estado PREPARADA | CARGADA | EN_TRANSITO | ENTREGADA | RECHAZADA | DEVUELTA
 */
final class ShipmentItem
{
    public const ESTADO_PREPARADA  = 'PREPARADA';
    public const ESTADO_CARGADA    = 'CARGADA';
    public const ESTADO_EN_TRANSITO = 'EN_TRANSITO';
    public const ESTADO_ENTREGADA  = 'ENTREGADA';
    public const ESTADO_RECHAZADA  = 'RECHAZADA';
    public const ESTADO_DEVUELTA   = 'DEVUELTA';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PREPARADA,
        self::ESTADO_CARGADA,
        self::ESTADO_EN_TRANSITO,
        self::ESTADO_ENTREGADA,
        self::ESTADO_RECHAZADA,
        self::ESTADO_DEVUELTA,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $shipmentId,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private string $estado,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        $estado = strtoupper(trim((string) ($data['estado'] ?? self::ESTADO_PREPARADA)));

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de ítem de envío desconocido: '{$estado}'.");
        }

        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            shipmentId: $data['shipment_id'],
            unidadFisicaId: $data['unidad_fisica_id'],
            cantidad: (float) ($data['cantidad'] ?? 0),
            estado: $estado,
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            updatedAt: new DateTimeImmutable($data['updated_at'] ?? 'now')
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $shipmentId,
        string $unidadFisicaId,
        float $cantidad,
        string $estado = self::ESTADO_CARGADA,
        ?DateTimeImmutable $now = null
    ): self {
        if (trim($id) === '') {
            throw InventoryException::requiredField('id');
        }
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (trim($shipmentId) === '') {
            throw InventoryException::requiredField('shipment_id');
        }
        if (trim($unidadFisicaId) === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }
        if (!is_finite($cantidad) || $cantidad <= 0) {
            throw InventoryException::invalidQuantity('cantidad', 'Debe ser mayor que cero.');
        }
        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de ítem de envío desconocido: '{$estado}'.");
        }

        $fecha = $now ?? new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            shipmentId: $shipmentId,
            unidadFisicaId: $unidadFisicaId,
            cantidad: $cantidad,
            estado: $estado,
            createdAt: $fecha,
            updatedAt: $fecha
        );
    }

    public function cambiarEstado(string $nuevoEstado): void
    {
        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de ítem de envío desconocido: '{$nuevoEstado}'.");
        }

        if ($nuevoEstado === $this->estado) {
            return;
        }

        $permitidos = [
            self::ESTADO_PREPARADA => [self::ESTADO_CARGADA, self::ESTADO_RECHAZADA],
            self::ESTADO_CARGADA => [self::ESTADO_EN_TRANSITO, self::ESTADO_RECHAZADA],
            self::ESTADO_EN_TRANSITO => [self::ESTADO_ENTREGADA, self::ESTADO_DEVUELTA, self::ESTADO_RECHAZADA],
            self::ESTADO_ENTREGADA => [self::ESTADO_DEVUELTA],
            self::ESTADO_RECHAZADA => [],
            self::ESTADO_DEVUELTA => [],
        ];

        if (!in_array($nuevoEstado, $permitidos[$this->estado] ?? [], true)) {
            throw InventoryException::invalidStateTransition($this->estado, $nuevoEstado);
        }

        $this->estado = $nuevoEstado;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function shipmentId(): string { return $this->shipmentId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidad(): float { return $this->cantidad; }
    public function estado(): string { return $this->estado; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'shipment_id' => $this->shipmentId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'shipment_item_id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
