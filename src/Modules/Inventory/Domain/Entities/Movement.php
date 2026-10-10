<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Movimiento (movements) - spec §3.11
 *
 * Cambio de ubicación de una unidad física. Es INMUTABLE: no tiene
 * `updated_at`, no se edita ni se borra; una corrección es un movimiento
 * inverso nuevo (spec E8) y ambos quedan en el histórico.
 *
 * Cada fila lleva `hash_integridad` = SHA256 de sus campos canónicos (misma
 * estrategia que StockEvent) para detectar alteraciones.
 *
 * No se expone ninguna mutación de negocio: sólo `create()` y getters.
 */
final class Movement
{
    public const MOTIVO_REUBICACION = 'REUBICACION';
    public const MOTIVO_MAX_LENGTH = 50;

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $unidadFisicaId,
        private readonly float $cantidad,
        private readonly ?string $ubicacionOrigenId,
        private readonly ?string $ubicacionDestinoId,
        private readonly string $motivo,
        private readonly ?string $actorId,
        private readonly ?string $referenciaExterna,
        private readonly DateTimeImmutable $timestampMovimiento,
        private readonly string $hashIntegridad,
        private readonly bool $esCorreccion = false,
        private readonly ?string $movimientoReferenciaId = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            unidadFisicaId: (string) $data['unidad_fisica_id'],
            cantidad: (float) $data['cantidad'],
            ubicacionOrigenId: $data['ubicacion_origen_id'] ?? null,
            ubicacionDestinoId: $data['ubicacion_destino_id'] ?? null,
            motivo: (string) ($data['motivo'] ?? self::MOTIVO_REUBICACION),
            actorId: $data['actor_id'] ?? null,
            referenciaExterna: $data['referencia_externa'] ?? null,
            timestampMovimiento: new DateTimeImmutable($data['timestamp_movimiento'] ?? 'now'),
            hashIntegridad: (string) ($data['hash_integridad'] ?? ''),
            esCorreccion: (bool) ($data['es_correccion'] ?? false),
            movimientoReferenciaId: $data['movimiento_referencia_id'] ?? null
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        float $cantidad,
        ?string $ubicacionOrigenId,
        ?string $ubicacionDestinoId,
        string $motivo,
        ?string $actorId = null,
        ?string $referenciaExterna = null,
        ?DateTimeImmutable $timestamp = null,
        bool $esCorreccion = false,
        ?string $movimientoReferenciaId = null
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

        $motivo = self::normalizeMotivo($motivo);

        if ($referenciaExterna !== null && mb_strlen($referenciaExterna) > 255) {
            throw InventoryException::fieldMaxLength('referencia_externa', 255);
        }

        $fecha = $timestamp ?? new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            unidadFisicaId: $unidadFisicaId,
            cantidad: $cantidad,
            ubicacionOrigenId: $ubicacionOrigenId !== null ? trim($ubicacionOrigenId) : null,
            ubicacionDestinoId: $ubicacionDestinoId !== null ? trim($ubicacionDestinoId) : null,
            motivo: $motivo,
            actorId: $actorId !== null && trim($actorId) !== '' ? trim($actorId) : null,
            referenciaExterna: $referenciaExterna !== null && trim($referenciaExterna) !== ''
                ? trim($referenciaExterna)
                : null,
            timestampMovimiento: $fecha,
            hashIntegridad: self::computeHash([
                'id' => $id,
                'tenant_id' => $tenantId,
                'unidad_fisica_id' => $unidadFisicaId,
                'cantidad' => $cantidad,
                'ubicacion_origen_id' => $ubicacionOrigenId,
                'ubicacion_destino_id' => $ubicacionDestinoId,
                'motivo' => $motivo,
                'actor_id' => $actorId,
                'referencia_externa' => $referenciaExterna,
                'timestamp_movimiento' => $fecha->format('Y-m-d H:i:s'),
            ]),
            // v1.10.0 (E8): las columnas de corrección NO entran en el hash:
            // las filas anteriores al ALTER no las llevan y dejarían de ser
            // integros (ver desviación en CHANGELOG).
            esCorreccion: $esCorreccion,
            movimientoReferenciaId: $movimientoReferenciaId !== null && trim($movimientoReferenciaId) !== ''
                ? trim($movimientoReferenciaId)
                : null
        );
    }

    /**
     * Recalcula el hash de una fila leída de BD (auditoría / tests).
     */
    public static function computeHash(array $campos): string
    {
        return hash('sha256', self::canonicalize($campos));
    }

    public function esIntegro(): bool
    {
        return hash_equals(
            $this->hashIntegridad,
            self::computeHash([
                'id' => $this->id,
                'tenant_id' => $this->tenantId,
                'unidad_fisica_id' => $this->unidadFisicaId,
                'cantidad' => $this->cantidad,
                'ubicacion_origen_id' => $this->ubicacionOrigenId,
                'ubicacion_destino_id' => $this->ubicacionDestinoId,
                'motivo' => $this->motivo,
                'actor_id' => $this->actorId,
                'referencia_externa' => $this->referenciaExterna,
                'timestamp_movimiento' => $this->timestampMovimiento->format('Y-m-d H:i:s'),
            ])
        );
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidad(): float { return $this->cantidad; }
    public function ubicacionOrigenId(): ?string { return $this->ubicacionOrigenId; }
    public function ubicacionDestinoId(): ?string { return $this->ubicacionDestinoId; }
    public function motivo(): string { return $this->motivo; }
    public function actorId(): ?string { return $this->actorId; }
    public function referenciaExterna(): ?string { return $this->referenciaExterna; }
    public function timestampMovimiento(): DateTimeImmutable { return $this->timestampMovimiento; }
    public function hashIntegridad(): string { return $this->hashIntegridad; }
    public function esCorreccion(): bool { return $this->esCorreccion; }
    public function movimientoReferenciaId(): ?string { return $this->movimientoReferenciaId; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'ubicacion_origen_id' => $this->ubicacionOrigenId,
            'ubicacion_destino_id' => $this->ubicacionDestinoId,
            'motivo' => $this->motivo,
            'actor_id' => $this->actorId,
            'referencia_externa' => $this->referenciaExterna,
            'timestamp_movimiento' => $this->timestampMovimiento->format('Y-m-d H:i:s'),
            'hash_integridad' => $this->hashIntegridad,
            'es_correccion' => $this->esCorreccion ? 1 : 0,
            'movimiento_referencia_id' => $this->movimientoReferenciaId,
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'movimiento_id' => $this->id,
            'unidad_fisica_id' => $this->unidadFisicaId,
            'cantidad' => $this->cantidad,
            'ubicacion_origen_id' => $this->ubicacionOrigenId,
            'ubicacion_destino_id' => $this->ubicacionDestinoId,
            'motivo' => $this->motivo,
            'actor_id' => $this->actorId,
            'referencia_externa' => $this->referenciaExterna,
            'timestamp_movimiento' => $this->timestampMovimiento->format('Y-m-d H:i:s'),
            'hash_integridad' => $this->hashIntegridad,
            'es_correccion' => $this->esCorreccion,
            'movimiento_referencia_id' => $this->movimientoReferenciaId,
        ];
    }

    private static function normalizeMotivo(string $motivo): string
    {
        $motivo = strtoupper(trim($motivo));

        if ($motivo === '') {
            return self::MOTIVO_REUBICACION;
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAX_LENGTH) {
            throw InventoryException::fieldMaxLength('motivo', self::MOTIVO_MAX_LENGTH);
        }

        if (!preg_match('/^[A-Z0-9_]+$/', $motivo)) {
            throw InventoryException::invalidField(
                'motivo',
                'Sólo letras, números y guiones bajos (ej. REUBICACION, PICKING).'
            );
        }

        return $motivo;
    }

    private static function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(
                    static fn(mixed $item): string => self::canonicalize($item),
                    $value
                )) . ']';
            }

            ksort($value);

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (json_encode((string) $key, JSON_UNESCAPED_UNICODE) ?: '""')
                    . ':' . self::canonicalize($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
        }

        return (string) json_encode($value);
    }
}
