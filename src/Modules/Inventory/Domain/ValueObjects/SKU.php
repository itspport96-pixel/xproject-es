<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;

/**
 * Value Object: SKU (Stock Keeping Unit)
 *
 * Representa el código único de identificación de un producto dentro de un tenant.
 *
 * Reglas de negocio:
 *  - Único por tenant (garantizado por BD: UNIQUE(tenant_id, sku))
 *  - Longitud: 1-100 caracteres
 *  - Caracteres permitidos: alfanuméricos, guiones, puntos, guiones bajos
 *  - Se normaliza a uppercase + trim
 *  - Inmutable tras construcción
 *
 * Invariante: dos SKU con el mismo valor normalizado son equivalentes.
 */
final class SKU
{
    private const MAX_LENGTH = 100;
    private const PATTERN = '/^[A-Z0-9][A-Z0-9._-]*[A-Z0-9]$|^[A-Z0-9]$/';

    private function __construct(
        private readonly string $value
    ) {
    }

    /**
     * Factory: construye un SKU desde un string crudo.
     *
     * @throws InventoryException Si el SKU es inválido
     */
    public static function fromString(string $sku): self
    {
        $normalized = self::normalize($sku);
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

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Serializa para persistencia/JSON.
     */
    public function toArray(): string
    {
        return $this->value;
    }

    // ====================================================================
    // VALIDACIÓN Y NORMALIZACIÓN (privadas)
    // ====================================================================

    private static function normalize(string $sku): string
    {
        // Trim + colapsar espacios + uppercase
        $sku = trim($sku);
        $sku = preg_replace('/\s+/', '', $sku) ?? $sku;
        return strtoupper($sku);
    }

    private static function validate(string $sku): void
    {
        if ($sku === '') {
            throw InventoryException::invalidSku($sku, 'El SKU no puede estar vacío.');
        }

        $length = strlen($sku);
        if ($length > self::MAX_LENGTH) {
            throw InventoryException::invalidSku(
                $sku,
                "Excede la longitud máxima (" . self::MAX_LENGTH . " caracteres)."
            );
        }

        if (!preg_match(self::PATTERN, $sku)) {
            throw InventoryException::invalidSku(
                $sku,
                'Solo se permiten letras, números, guiones (-), puntos (.) y guiones bajos (_). '
                . 'No puede iniciar ni terminar con caracteres especiales.'
            );
        }
    }
}
