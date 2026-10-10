<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

/**
 * Value Object: Estado de la Unidad Física (stock_units.estado)
 *
 * Máquina de estados del inventario (spec §Estados, transiciones CU-001..):
 *  - DISPONIBLE     recién recibida / libre para operar
 *  - RESERVADO      apartada para una orden
 *  - EN_PREPARACION en picking
 *  - CARGADO        en el vehículo
 *  - EN_TRANSITO    salida autorizada
 *  - ENTREGADO      entregada al destinatario
 *  - DEVUELTO       devolución recepcionada
 *  - DAÑADO         con deterioro
 *  - BLOQUEADO      bajo investigación
 *  - PERDIDO        estado terminal
 *
 * Inmutable. Se normaliza a uppercase + trim. Valores coinciden con el ENUM.
 */
final class StockState
{
    public const DISPONIBLE     = 'DISPONIBLE';
    public const RESERVADO      = 'RESERVADO';
    public const EN_PREPARACION = 'EN_PREPARACION';
    public const CARGADO        = 'CARGADO';
    public const EN_TRANSITO    = 'EN_TRANSITO';
    public const ENTREGADO      = 'ENTREGADO';
    public const DEVUELTO       = 'DEVUELTO';
    public const DANADO         = 'DAÑADO';
    public const BLOQUEADO      = 'BLOQUEADO';
    public const PERDIDO        = 'PERDIDO';

    public const VALID_VALUES = [
        self::DISPONIBLE,
        self::RESERVADO,
        self::EN_PREPARACION,
        self::CARGADO,
        self::EN_TRANSITO,
        self::ENTREGADO,
        self::DEVUELTO,
        self::DANADO,
        self::BLOQUEADO,
        self::PERDIDO,
    ];

    private function __construct(
        private readonly string $value
    ) {
    }

    public static function fromString(string $state): self
    {
        $normalized = strtoupper(trim($state));
        self::validate($normalized);

        return new self($normalized);
    }

    public static function disponible(): self { return new self(self::DISPONIBLE); }
    public static function reservado(): self  { return new self(self::RESERVADO); }
    public static function enPreparacion(): self { return new self(self::EN_PREPARACION); }
    public static function cargado(): self    { return new self(self::CARGADO); }
    public static function enTransito(): self { return new self(self::EN_TRANSITO); }
    public static function entregado(): self  { return new self(self::ENTREGADO); }
    public static function devuelto(): self   { return new self(self::DEVUELTO); }
    public static function danado(): self     { return new self(self::DANADO); }
    public static function bloqueado(): self  { return new self(self::BLOQUEADO); }
    public static function perdido(): self    { return new self(self::PERDIDO); }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isDisponible(): bool { return $this->value === self::DISPONIBLE; }
    public function isReservado(): bool  { return $this->value === self::RESERVADO; }
    public function isPerdido(): bool    { return $this->value === self::PERDIDO; }

    /**
     * Estados terminales: no admiten nuevas transiciones (spec R8).
     */
    public function isTerminal(): bool
    {
        return in_array($this->value, [self::PERDIDO], true);
    }

    /**
     * Estados que ocupan capacidad en su ubicación.
     */
    public function occupiesLocation(): bool
    {
        return in_array($this->value, [self::DISPONIBLE, self::RESERVADO, self::EN_PREPARACION, self::BLOQUEADO], true);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toArray(): string
    {
        return $this->value;
    }

    private static function validate(string $state): void
    {
        if (!in_array($state, self::VALID_VALUES, true)) {
            throw InventoryException::invalidStockState($state);
        }
    }
}
