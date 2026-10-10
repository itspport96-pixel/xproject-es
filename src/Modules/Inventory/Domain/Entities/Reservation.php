<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Reserva (reservations) - spec §3.10
 *
 * Aparta una unidad física para una orden (CU-003). Mientras está ACTIVA la
 * unidad queda en estado RESERVADO y su `cantidad_disponible` del ledger
 * disminuye; al cancelarse (manualmente o por caducidad, spec E7) vuelve a
 * DISPONIBLE.
 *
 * @property-read string $estado ACTIVA | CANCELADA | COMPLETADA
 */
final class Reservation
{
    public const ESTADO_ACTIVA = 'ACTIVA';
    public const ESTADO_CANCELADA = 'CANCELADA';
    public const ESTADO_COMPLETADA = 'COMPLETADA';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_ACTIVA,
        self::ESTADO_CANCELADA,
        self::ESTADO_COMPLETADA,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private readonly ?string $referenciaOrden,
        private readonly ?string $actorId,
        private readonly DateTimeImmutable $fechaReserva,
        private readonly ?DateTimeImmutable $fechaExpiracion,
        private string $estado,
        private ?string $motivoCancelacion,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        $estado = strtoupper(trim((string) ($data['estado'] ?? self::ESTADO_ACTIVA)));

        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            unidadFisicaId: $data['unidad_fisica_id'],
            cantidad: (float) $data['cantidad'],
            referenciaOrden: $data['referencia_orden'] ?? null,
            actorId: $data['actor_id'] ?? null,
            fechaReserva: new DateTimeImmutable($data['fecha_reserva'] ?? 'now'),
            fechaExpiracion: !empty($data['fecha_expiracion'])
                ? new DateTimeImmutable($data['fecha_expiracion'])
                : null,
            estado: $estado,
            motivoCancelacion: $data['motivo_cancelacion'] ?? null,
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            updatedAt: new DateTimeImmutable($data['updated_at'] ?? 'now')
        );
    }

    /**
     * @param string|null $fechaExpiracion Fecha ISO parseable; debe ser futura
     *                                     respecto a la reserva (400 si no)
     */
    public static function create(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        float $cantidad,
        ?string $referenciaOrden = null,
        ?string $actorId = null,
        ?string $fechaExpiracion = null,
        ?DateTimeImmutable $now = null
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
        if ($referenciaOrden !== null && mb_strlen($referenciaOrden) > 255) {
            throw InventoryException::fieldMaxLength('referencia_orden', 255);
        }

        $fechaReserva = $now ?? new DateTimeImmutable();

        $fechaExpiracionObj = null;
        if ($fechaExpiracion !== null && trim($fechaExpiracion) !== '') {
            try {
                $fechaExpiracionObj = new DateTimeImmutable($fechaExpiracion);
            } catch (\Throwable) {
                throw InventoryException::invalidField(
                    'fecha_expiracion',
                    'Debe ser una fecha y hora válidas (ej. 2026-10-01T12:00:00).'
                );
            }

            if ($fechaExpiracionObj <= $fechaReserva) {
                throw InventoryException::invalidField(
                    'fecha_expiracion',
                    'Debe ser posterior a la fecha de reserva.'
                );
            }
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            unidadFisicaId: $unidadFisicaId,
            cantidad: $cantidad,
            referenciaOrden: self::trimOrNull($referenciaOrden),
            actorId: self::trimOrNull($actorId),
            fechaReserva: $fechaReserva,
            fechaExpiracion: $fechaExpiracionObj,
            estado: self::ESTADO_ACTIVA,
            motivoCancelacion: null,
            createdAt: $fechaReserva,
            updatedAt: $fechaReserva
        );
    }

    public function cancelar(?string $motivo = null): void
    {
        if ($this->estado !== self::ESTADO_ACTIVA) {
            throw InventoryException::reservationNotActive($this->id, $this->estado);
        }

        $this->estado = self::ESTADO_CANCELADA;
        $this->motivoCancelacion = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function completar(): void
    {
        if ($this->estado !== self::ESTADO_ACTIVA) {
            throw InventoryException::reservationNotActive($this->id, $this->estado);
        }

        $this->estado = self::ESTADO_COMPLETADA;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function esActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA;
    }

    /**
     * ¿La reserva tiene plazo y éste ya pasó? (spec E7)
     */
    public function estaExpirada(?DateTimeImmutable $now = null): bool
    {
        if ($this->fechaExpiracion === null) {
            return false;
        }

        return $this->fechaExpiracion <= ($now ?? new DateTimeImmutable());
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidad(): float { return $this->cantidad; }
    public function referenciaOrden(): ?string { return $this->referenciaOrden; }
    public function actorId(): ?string { return $this->actorId; }
    public function fechaReserva(): DateTimeImmutable { return $this->fechaReserva; }
    public function fechaExpiracion(): ?DateTimeImmutable { return $this->fechaExpiracion; }
    public function estado(): string { return $this->estado; }
    public function motivoCancelacion(): ?string { return $this->motivoCancelacion; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'referencia_orden' => $this->referenciaOrden,
            'actor_id' => $this->actorId,
            'fecha_reserva' => $this->fechaReserva->format('Y-m-d H:i:s'),
            'fecha_expiracion' => $this->fechaExpiracion?->format('Y-m-d H:i:s'),
            'estado' => $this->estado,
            'motivo_cancelacion' => $this->motivoCancelacion,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'reserva_id' => $this->id,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'referencia_orden' => $this->referenciaOrden,
            'actor_id' => $this->actorId,
            'fecha_reserva' => $this->fechaReserva->format('Y-m-d H:i:s'),
            'fecha_expiracion' => $this->fechaExpiracion?->format('Y-m-d H:i:s'),
            'estado' => $this->estado,
            'motivo_cancelacion' => $this->motivoCancelacion,
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
