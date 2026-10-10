<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Operación de Picking (picking_operations) - spec §3.12
 *
 * Una fila por unidad física incluida en un lote de picking. El lote
 * (`id_lote_picking`) agrupa las unidades del mismo picking y es único por
 * tenant. Ciclo de vida (spec CU-004):
 *
 *  PENDIENTE  → (reservado)  EN_CURSO   al iniciar la selección
 *  EN_CURSO   → COMPLETADA              al terminar la selección
 *  COMPLETADA → VERIFICADA              al supervisar (supervisor ≠ selector)
 *
 * @property-read string $estado PENDIENTE | EN_CURSO | COMPLETADA | VERIFICADA
 */
final class PickingOperation
{
    public const ESTADO_PENDIENTE  = 'PENDIENTE';
    public const ESTADO_EN_CURSO   = 'EN_CURSO';
    public const ESTADO_COMPLETADA = 'COMPLETADA';
    public const ESTADO_VERIFICADA = 'VERIFICADA';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_EN_CURSO,
        self::ESTADO_COMPLETADA,
        self::ESTADO_VERIFICADA,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $idLotePicking,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private readonly ?string $ubicacionOrigenId,
        private readonly ?string $ubicacionPreparacionId,
        private readonly ?string $referenciaOrden,
        private readonly ?string $actorIdSelector,
        private ?string $actorIdSupervisor,
        private string $estado,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        $estado = strtoupper(trim((string) ($data['estado'] ?? self::ESTADO_PENDIENTE)));

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de picking desconocido: '{$estado}'.");
        }

        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            idLotePicking: (string) $data['id_lote_picking'],
            unidadFisicaId: $data['unidad_fisica_id'],
            cantidad: (float) ($data['cantidad'] ?? 0),
            ubicacionOrigenId: $data['ubicacion_origen_id'] ?? null,
            ubicacionPreparacionId: $data['ubicacion_preparacion_id'] ?? null,
            referenciaOrden: $data['referencia_orden'] ?? null,
            actorIdSelector: $data['actor_id_selector'] ?? null,
            actorIdSupervisor: $data['actor_id_supervisor'] ?? null,
            estado: $estado,
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            updatedAt: new DateTimeImmutable($data['updated_at'] ?? 'now')
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $idLotePicking,
        string $unidadFisicaId,
        float $cantidad,
        ?string $ubicacionOrigenId = null,
        ?string $ubicacionPreparacionId = null,
        ?string $referenciaOrden = null,
        ?string $actorIdSelector = null,
        string $estado = self::ESTADO_EN_CURSO,
        ?DateTimeImmutable $now = null
    ): self {
        if (trim($id) === '') {
            throw InventoryException::requiredField('id');
        }
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (trim($idLotePicking) === '') {
            throw InventoryException::requiredField('id_lote_picking');
        }
        if (mb_strlen($idLotePicking) > 100) {
            throw InventoryException::fieldMaxLength('id_lote_picking', 100);
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
        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de picking desconocido: '{$estado}'.");
        }

        $fecha = $now ?? new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            idLotePicking: trim($idLotePicking),
            unidadFisicaId: $unidadFisicaId,
            cantidad: $cantidad,
            ubicacionOrigenId: self::trimOrNull($ubicacionOrigenId),
            ubicacionPreparacionId: self::trimOrNull($ubicacionPreparacionId),
            referenciaOrden: self::trimOrNull($referenciaOrden),
            actorIdSelector: self::trimOrNull($actorIdSelector),
            actorIdSupervisor: null,
            estado: $estado,
            createdAt: $fecha,
            updatedAt: $fecha
        );
    }

    /**
     * Transición de estado de la operación. El mapa refleja el ciclo de vida
     * del picking (spec CU-004); cualquier salto fuera de él se rechaza.
     * `$esperado` en el error es el estado actual que permitiría el salto.
     */
    public function cambiarEstado(string $nuevoEstado, ?string $actorIdSupervisor = null): void
    {
        $estadoRequerido = [
            self::ESTADO_EN_CURSO => self::ESTADO_PENDIENTE,
            self::ESTADO_COMPLETADA => self::ESTADO_EN_CURSO,
            self::ESTADO_VERIFICADA => self::ESTADO_COMPLETADA,
        ];

        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de picking desconocido: '{$nuevoEstado}'.");
        }

        if (!isset($estadoRequerido[$nuevoEstado]) || $this->estado !== $estadoRequerido[$nuevoEstado]) {
            throw InventoryException::pickingLotInvalidState(
                $this->idLotePicking,
                $this->estado,
                $estadoRequerido[$nuevoEstado] ?? $nuevoEstado
            );
        }

        if ($nuevoEstado === self::ESTADO_VERIFICADA) {
            $supervisor = self::trimOrNull($actorIdSupervisor);

            if ($supervisor === null) {
                throw InventoryException::requiredField('actor_id_supervisor');
            }
            if ($supervisor === $this->actorIdSelector) {
                throw InventoryException::pickingSupervisorMustDiffer($this->idLotePicking);
            }

            $this->actorIdSupervisor = $supervisor;
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
    public function idLotePicking(): string { return $this->idLotePicking; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidad(): float { return $this->cantidad; }
    public function ubicacionOrigenId(): ?string { return $this->ubicacionOrigenId; }
    public function ubicacionPreparacionId(): ?string { return $this->ubicacionPreparacionId; }
    public function referenciaOrden(): ?string { return $this->referenciaOrden; }
    public function actorIdSelector(): ?string { return $this->actorIdSelector; }
    public function actorIdSupervisor(): ?string { return $this->actorIdSupervisor; }
    public function estado(): string { return $this->estado; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'id_lote_picking' => $this->idLotePicking,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'ubicacion_origen_id' => $this->ubicacionOrigenId,
            'ubicacion_preparacion_id' => $this->ubicacionPreparacionId,
            'referencia_orden' => $this->referenciaOrden,
            'actor_id_selector' => $this->actorIdSelector,
            'actor_id_supervisor' => $this->actorIdSupervisor,
            'estado' => $this->estado,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'picking_id' => $this->id,
            'id_lote_picking' => $this->idLotePicking,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'ubicacion_origen_id' => $this->ubicacionOrigenId,
            'ubicacion_preparacion_id' => $this->ubicacionPreparacionId,
            'referencia_orden' => $this->referenciaOrden,
            'actor_id_selector' => $this->actorIdSelector,
            'actor_id_supervisor' => $this->actorIdSupervisor,
            'estado' => $this->estado,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
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
