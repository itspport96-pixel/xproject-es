<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Entrega (deliveries) - spec §3.15 / CU-007 / CU-008
 *
 * Una fila POR UNIDAD FÍSICA del envío:
 *
 *  - ENTREGADA  → la unidad llegó a destino (CU-007)
 *  - RECHAZADA  → la unidad no pudo entregarse y vuelve al almacén (CU-008)
 *  - PARCIAL    → reserva del spec para entregas de cantidad parcial; en
 *                 v1.8.0 la unidad no se divide (misma regla que CU-004/005),
 *                 por lo que queda sin usar (decisión documentada).
 *  - PENDIENTE  → estado por defecto del spec, tampoco se emite aún.
 *
 * @property-read string $estado PENDIENTE | ENTREGADA | RECHAZADA | PARCIAL
 */
final class Delivery
{
    public const ESTADO_PENDIENTE  = 'PENDIENTE';
    public const ESTADO_ENTREGADA  = 'ENTREGADA';
    public const ESTADO_RECHAZADA  = 'RECHAZADA';
    public const ESTADO_PARCIAL    = 'PARCIAL';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_ENTREGADA,
        self::ESTADO_RECHAZADA,
        self::ESTADO_PARCIAL,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $shipmentId,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private readonly string $estado,
        private readonly ?string $destinatarioReal,
        private readonly ?string $firmaComprobante,
        private readonly ?string $observaciones,
        private readonly ?string $actorIdEntrega,
        private readonly DateTimeImmutable $timestampEntrega,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $data Fila de `deliveries`
     */
    public static function fromArray(array $data): self
    {
        $estado = strtoupper(trim((string) ($data['estado'] ?? self::ESTADO_PENDIENTE)));

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de entrega desconocido: '{$estado}'.");
        }

        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            shipmentId: (string) $data['shipment_id'],
            unidadFisicaId: (string) $data['unidad_fisica_id'],
            cantidad: (float) ($data['cantidad'] ?? 0),
            estado: $estado,
            destinatarioReal: $data['destinatario_real'] ?? null,
            firmaComprobante: $data['firma_comprobante'] ?? null,
            observaciones: $data['observaciones'] ?? null,
            actorIdEntrega: $data['actor_id_entrega'] ?? null,
            timestampEntrega: new DateTimeImmutable(
                (string) ($data['timestamp_entrega'] ?? 'now')
            ),
            createdAt: new DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            updatedAt: new DateTimeImmutable((string) ($data['updated_at'] ?? 'now'))
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $shipmentId,
        string $unidadFisicaId,
        float $cantidad,
        string $estado,
        ?string $destinatarioReal = null,
        ?string $firmaComprobante = null,
        ?string $observaciones = null,
        ?string $actorIdEntrega = null,
        ?DateTimeImmutable $timestampEntrega = null
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
            throw InventoryException::invalidField('estado', "Estado de entrega desconocido: '{$estado}'.");
        }

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            shipmentId: $shipmentId,
            unidadFisicaId: $unidadFisicaId,
            cantidad: $cantidad,
            estado: $estado,
            destinatarioReal: self::trimOrNull($destinatarioReal),
            firmaComprobante: self::trimOrNull($firmaComprobante),
            observaciones: self::trimOrNull($observaciones),
            actorIdEntrega: self::trimOrNull($actorIdEntrega),
            timestampEntrega: $timestampEntrega ?? $now,
            createdAt: $now,
            updatedAt: $now
        );
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
    public function destinatarioReal(): ?string { return $this->destinatarioReal; }
    public function firmaComprobante(): ?string { return $this->firmaComprobante; }
    public function observaciones(): ?string { return $this->observaciones; }
    public function actorIdEntrega(): ?string { return $this->actorIdEntrega; }
    public function timestampEntrega(): DateTimeImmutable { return $this->timestampEntrega; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'shipment_id' => $this->shipmentId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado,
            'destinatario_real' => $this->destinatarioReal,
            'firma_comprobante' => $this->firmaComprobante,
            'observaciones' => $this->observaciones,
            'actor_id_entrega' => $this->actorIdEntrega,
            'timestamp_entrega' => $this->timestampEntrega->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'delivery_id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'estado' => $this->estado,
            'destinatario_real' => $this->destinatarioReal,
            'firma_comprobante' => $this->firmaComprobante,
            'observaciones' => $this->observaciones,
            'actor_id_entrega' => $this->actorIdEntrega,
            'timestamp_entrega' => $this->timestampEntrega->format('Y-m-d H:i:s'),
        ];
    }

    private static function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
