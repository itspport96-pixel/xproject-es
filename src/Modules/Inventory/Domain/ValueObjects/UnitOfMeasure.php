<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

/**
 * Value Object: Unidad de Medida
 *
 * Representa la unidad de medida de un producto.
 * Valores permitidos (coinciden con ENUM en BD):
 *  - UNIDAD (conteo discreto)
 *  - KG     (peso)
 *  - LT     (volumen líquido)
 *  - M      (longitud)
 *  - M2     (área)
 *  - M3     (volumen)
 *
 * Inmutable. Se normaliza a uppercase + trim.
 */
final class UnitOfMeasure
{
    public const UNIDAD = 'UNIDAD';
    public const KG     = 'KG';
    public const LT     = 'LT';
    public const M      = 'M';
    public const M2     = 'M2';
    public const M3     = 'M3';

    public const VALID_VALUES = [
        self::UNIDAD,
        self::KG,
        self::LT,
        self::M,
        self::M2,
        self::M3,
    ];

    private function __construct(
        private readonly string $value
    ) {
    }

    public static function fromString(string $unit): self
    {
        $normalized = strtoupper(trim($unit));
        self::validate($normalized);

        return new self($normalized);
    }

    public static function unidad(): self   { return new self(self::UNIDAD); }
    public static function kg(): self       { return new self(self::KG); }
    public static function lt(): self       { return new self(self::LT); }
    public static function m(): self        { return new self(self::M); }
    public static function m2(): self       { return new self(self::M2); }
    public static function m3(): self       { return new self(self::M3); }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isCountable(): bool { return $this->value === self::UNIDAD; }
    public function isWeight(): bool    { return $this->value === self::KG; }
    public function isVolume(): bool    { return $this->value === self::LT || $this->value === self::M3; }
    public function isLength(): bool    { return $this->value === self::M; }
    public function isArea(): bool      { return $this->value === self::M2; }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toArray(): string
    {
        return $this->value;
    }

    private static function validate(string $unit): void
    {
        if (!in_array($unit, self::VALID_VALUES, true)) {
            throw InventoryException::invalidUnitOfMeasure($unit);
        }
    }
}
