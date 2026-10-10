<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Operación de devolución (return_operations) - spec §3.16 / CU-009
 *
 * Registra la vuelta de una unidad al inventario después de una entrega
 * fallida (FALLO_ENTREGA) o de que el cliente la devuelva (CAMBIO_CLIENTE,
 * DAÑO, ...). Según `estado_al_devolver` la unidad termina en:
 *
 *  - OK         → DISPONIBLE en `ubicacion_destino_id`
 *  - DAÑADO     → DAÑADO en la zona DAÑADOS (+ incidencia DAÑO)
 *  - DETERIORADO→ DAÑADO en la zona DAÑADOS (+ incidencia DAÑO); el spec no
 *                 define estado propio, se trata como DAÑADO (decisión)
 *  - PERDIDO    → PERDIDO, sin ubicación (+ incidencia PERDIDA)
 *
 * @property-read string $motivo CAMBIO_CLIENTE | DAÑO | FALLO_ENTREGA | NO_APLICA | OTRO
 * @property-read string $estadoAlDevolver OK | DAÑADO | DETERIORADO | PERDIDO
 */
final class ReturnOperation
{
    public const MOTIVO_CAMBIO_CLIENTE = 'CAMBIO_CLIENTE';
    public const MOTIVO_DANO = 'DAÑO';
    public const MOTIVO_FALLO_ENTREGA = 'FALLO_ENTREGA';
    public const MOTIVO_NO_APLICA = 'NO_APLICA';
    public const MOTIVO_OTRO = 'OTRO';

    public const MOTIVOS_VALIDOS = [
        self::MOTIVO_CAMBIO_CLIENTE,
        self::MOTIVO_DANO,
        self::MOTIVO_FALLO_ENTREGA,
        self::MOTIVO_NO_APLICA,
        self::MOTIVO_OTRO,
    ];

    public const ESTADO_OK = 'OK';
    public const ESTADO_DANADO = 'DAÑADO';
    public const ESTADO_DETERIORADO = 'DETERIORADO';
    public const ESTADO_PERDIDO = 'PERDIDO';

    public const ESTADOS_AL_DEVOLVER_VALIDOS = [
        self::ESTADO_OK,
        self::ESTADO_DANADO,
        self::ESTADO_DETERIORADO,
        self::ESTADO_PERDIDO,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly ?string $deliveryId,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private readonly string $motivo,
        private readonly ?string $descripcionMotivo,
        private readonly string $estadoAlDevolver,
        private readonly ?string $ubicacionDestinoId,
        private readonly ?string $actorIdRecibeDevolucion,
        private readonly DateTimeImmutable $timestampDevolucion,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $data Fila de `return_operations`
     */
    public static function fromArray(array $data): self
    {
        $motivo = strtoupper(trim((string) ($data['motivo'] ?? self::MOTIVO_OTRO)));
        $estado = strtoupper(trim((string) ($data['estado_al_devolver'] ?? self::ESTADO_OK)));

        if (!in_array($motivo, self::MOTIVOS_VALIDOS, true)) {
            throw InventoryException::invalidField('motivo', "Motivo de devolución desconocido: '{$motivo}'.");
        }
        if (!in_array($estado, self::ESTADOS_AL_DEVOLVER_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'estado_al_devolver',
                "Estado al devolver desconocido: '{$estado}'."
            );
        }

        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            deliveryId: $data['delivery_id'] ?? null,
            unidadFisicaId: (string) $data['unidad_fisica_id'],
            cantidad: (float) ($data['cantidad'] ?? 0),
            motivo: $motivo,
            descripcionMotivo: $data['descripcion_motivo'] ?? null,
            estadoAlDevolver: $estado,
            ubicacionDestinoId: $data['ubicacion_destino_id'] ?? null,
            actorIdRecibeDevolucion: $data['actor_id_recibe_devolucion'] ?? null,
            timestampDevolucion: new DateTimeImmutable(
                (string) ($data['timestamp_devolucion'] ?? 'now')
            ),
            createdAt: new DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            updatedAt: new DateTimeImmutable((string) ($data['updated_at'] ?? 'now'))
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        float $cantidad,
        string $motivo,
        string $estadoAlDevolver,
        ?string $deliveryId = null,
        ?string $descripcionMotivo = null,
        ?string $ubicacionDestinoId = null,
        ?string $actorIdRecibeDevolucion = null,
        ?DateTimeImmutable $timestampDevolucion = null
    ): self {
        if (trim($id) === '') {
            throw InventoryException::requiredField('id');
        }
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (trim($unidadFisicaId) === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }
        if (!is_finite($cantidad) || $cantidad <= 0) {
            throw InventoryException::invalidQuantity('cantidad', 'Debe ser mayor que cero.');
        }
        if (!in_array($motivo, self::MOTIVOS_VALIDOS, true)) {
            throw InventoryException::invalidField('motivo', "Motivo de devolución desconocido: '{$motivo}'.");
        }
        if (!in_array($estadoAlDevolver, self::ESTADOS_AL_DEVOLVER_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'estado_al_devolver',
                "Estado al devolver desconocido: '{$estadoAlDevolver}'."
            );
        }

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            deliveryId: $deliveryId !== null && trim($deliveryId) !== '' ? trim($deliveryId) : null,
            unidadFisicaId: trim($unidadFisicaId),
            cantidad: $cantidad,
            motivo: $motivo,
            descripcionMotivo: self::trimOrNull($descripcionMotivo),
            estadoAlDevolver: $estadoAlDevolver,
            ubicacionDestinoId: $ubicacionDestinoId !== null && trim($ubicacionDestinoId) !== ''
                ? trim($ubicacionDestinoId)
                : null,
            actorIdRecibeDevolucion: self::trimOrNull($actorIdRecibeDevolucion),
            timestampDevolucion: $timestampDevolucion ?? $now,
            createdAt: $now,
            updatedAt: $now
        );
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    /**
     * Estado de unidad resultante (matriz §4) según `estado_al_devolver`.
     */
    public function estadoUnidadResultante(): string
    {
        return match ($this->estadoAlDevolver) {
            self::ESTADO_OK => \App\Modules\Inventory\Domain\ValueObjects\StockState::DISPONIBLE,
            self::ESTADO_PERDIDO => \App\Modules\Inventory\Domain\ValueObjects\StockState::PERDIDO,
            default => \App\Modules\Inventory\Domain\ValueObjects\StockState::DANADO,
        };
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function deliveryId(): ?string { return $this->deliveryId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidad(): float { return $this->cantidad; }
    public function motivo(): string { return $this->motivo; }
    public function descripcionMotivo(): ?string { return $this->descripcionMotivo; }
    public function estadoAlDevolver(): string { return $this->estadoAlDevolver; }
    public function ubicacionDestinoId(): ?string { return $this->ubicacionDestinoId; }
    public function actorIdRecibeDevolucion(): ?string { return $this->actorIdRecibeDevolucion; }
    public function timestampDevolucion(): DateTimeImmutable { return $this->timestampDevolucion; }
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
            'delivery_id' => $this->deliveryId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'motivo' => $this->motivo,
            'descripcion_motivo' => $this->descripcionMotivo,
            'estado_al_devolver' => $this->estadoAlDevolver,
            'ubicacion_destino_id' => $this->ubicacionDestinoId,
            'actor_id_recibe_devolucion' => $this->actorIdRecibeDevolucion,
            'timestamp_devolucion' => $this->timestampDevolucion->format('Y-m-d H:i:s'),
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
            'return_id' => $this->id,
            'delivery_id' => $this->deliveryId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'motivo' => $this->motivo,
            'descripcion_motivo' => $this->descripcionMotivo,
            'estado_al_devolver' => $this->estadoAlDevolver,
            'ubicacion_destino_id' => $this->ubicacionDestinoId,
            'actor_id_recibe_devolucion' => $this->actorIdRecibeDevolucion,
            'timestamp_devolucion' => $this->timestampDevolucion->format('Y-m-d H:i:s'),
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
