<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

/**
 * Value Object: Tipo de Ubicación
 *
 * Representa la clasificación funcional de una ubicación dentro del almacén.
 * Valores permitidos (coinciden con ENUM en BD):
 *  - ALMACENAMIENTO (regular, stock disponible)
 *  - PICKING        (zona de preparación de pedidos)
 *  - DAÑADOS        (artículos defectuosos)
 *  - BLOQUEADOS     (bajo investigación)
 *  - TRANSITO       (virtual, unidades en transporte)
 *  - DEVOLUCION     (recepción de devoluciones)
 *
 * Inmutable. Se normaliza a uppercase + trim.
 */
final class LocationType
{
    public const ALMACENAMIENTO = 'ALMACENAMIENTO';
    public const PICKING        = 'PICKING';
    public const DANADOS        = 'DAÑADOS';
    public const BLOQUEADOS     = 'BLOQUEADOS';
    public const TRANSITO       = 'TRANSITO';
    public const DEVOLUCION     = 'DEVOLUCION';

    public const VALID_VALUES = [
        self::ALMACENAMIENTO,
        self::PICKING,
        self::DANADOS,
        self::BLOQUEADOS,
        self::TRANSITO,
        self::DEVOLUCION,
    ];

    private function __construct(
        private readonly string $value
    ) {
    }

    public static function fromString(string $type): self
    {
        $normalized = strtoupper(trim($type));
        self::validate($normalized);

        return new self($normalized);
    }

    public static function almacenamiento(): self { return new self(self::ALMACENAMIENTO); }
    public static function picking(): self        { return new self(self::PICKING); }
    public static function danados(): self        { return new self(self::DANADOS); }
    public static function bloqueados(): self     { return new self(self::BLOQUEADOS); }
    public static function transito(): self       { return new self(self::TRANSITO); }
    public static function devolucion(): self     { return new self(self::DEVOLUCION); }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isStorage(): bool   { return $this->value === self::ALMACENAMIENTO; }
    public function isPicking(): bool   { return $this->value === self::PICKING; }
    public function isDamaged(): bool   { return $this->value === self::DANADOS; }
    public function isBlocked(): bool   { return $this->value === self::BLOQUEADOS; }
    public function isTransit(): bool   { return $this->value === self::TRANSITO; }
    public function isReturn(): bool    { return $this->value === self::DEVOLUCION; }

    /**
     * Ubicaciones virtuales (no tienen capacidad física).
     */
    public function isVirtual(): bool
    {
        return in_array($this->value, [self::TRANSITO], true);
    }

    /**
     * Ubicaciones operativas (pueden recibir stock disponible).
     */
    public function isOperative(): bool
    {
        return in_array($this->value, [
            self::ALMACENAMIENTO,
            self::PICKING,
            self::DEVOLUCION,
        ], true);
    }

    /**
     * Ubicaciones de cuarentena (stock no disponible para operaciones normales).
     */
    public function isQuarantine(): bool
    {
        return in_array($this->value, [self::DANADOS, self::BLOQUEADOS], true);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toArray(): string
    {
        return $this->value;
    }

    private static function validate(string $type): void
    {
        if (!in_array($type, self::VALID_VALUES, true)) {
            throw InventoryException::invalidLocationType($type);
        }
    }
}
