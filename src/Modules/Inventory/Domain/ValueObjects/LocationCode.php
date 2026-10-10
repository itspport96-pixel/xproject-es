<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

/**
 * Value Object: Código de Ubicación
 *
 * Código único que identifica una ubicación física dentro del tenant.
 * Soporta nomenclatura jerárquica: "A-01-02-03-04-05" (centro-nave-zona-torre-nivel-posición).
 *
 * Reglas:
 *  - Único por tenant (UNIQUE(tenant_id, codigo))
 *  - Longitud: 1-100 caracteres
 *  - Caracteres: alfanuméricos, guiones, puntos, guiones bajos, espacios internos
 *  - Se normaliza a uppercase + trim + colapso de espacios
 *  - Inmutable
 */
final class LocationCode
{
    private const MAX_LENGTH = 100;
    private const PATTERN = '/^[A-Z0-9][A-Z0-9._\- ]*[A-Z0-9]$|^[A-Z0-9]$/';

    private function __construct(
        private readonly string $value
    ) {
    }

    public static function fromString(string $code): self
    {
        $normalized = self::normalize($code);
        self::validate($normalized);

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function levels(): array
    {
        return explode('-', $this->value);
    }

    public function levelCount(): int
    {
        return count($this->levels());
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toArray(): string
    {
        return $this->value;
    }

    private static function normalize(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/\s+/', ' ', $code) ?? $code;
        return strtoupper($code);
    }

    private static function validate(string $code): void
    {
        if ($code === '') {
            throw InventoryException::invalidLocationCode($code, 'El código no puede estar vacío.');
        }

        $length = strlen($code);
        if ($length > self::MAX_LENGTH) {
            throw InventoryException::invalidLocationCode(
                $code,
                "Excede la longitud máxima (" . self::MAX_LENGTH . " caracteres)."
            );
        }

        if (!preg_match(self::PATTERN, $code)) {
            throw InventoryException::invalidLocationCode(
                $code,
                'Solo se permiten letras, números, guiones (-), puntos (.), guiones bajos (_) y espacios. '
                . 'No puede iniciar ni terminar con caracteres especiales.'
            );
        }
    }
}
