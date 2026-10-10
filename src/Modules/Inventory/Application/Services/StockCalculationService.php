<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;

/**
 * Cálculos de stock (spec R1, Sprint 5).
 *
 * Invariante central (v1.8.3):
 *
 *   estado = DISPONIBLE  →  cantidad_disponible = cantidad_fisica
 *                                            - cantidad_reservada
 *                                            - cantidad_en_transito
 *   cualquier otro estado (RESERVADO, EN_PREPARACION, CARGADO, EN_TRANSITO,
 *   ENTREGADO, DEVUELTO, DAÑADO, BLOQUEADO, PERDIDO) → cantidad_disponible = 0
 *
 * El resultado nunca puede ser negativo (422 si un flujo lo provocaría).
 */
final class StockCalculationService
{
    /**
     * Cantidad disponible de una unidad (R1).
     *
     * @param string|null $estado estado actual de la unidad; si no es
     *                            `DISPONIBLE` el disponible es 0 (v1.8.3)
     *
     * @throws InventoryException 400 si hay cantidades negativas o el estado
     *                            no existe, 422 si el disponible resultaría < 0
     */
    public function disponible(
        float $cantidadFisica,
        float $cantidadReservada,
        float $cantidadEnTransito,
        ?string $estado = null
    ): float {
        if (!is_finite($cantidadFisica) || $cantidadFisica < 0) {
            throw InventoryException::invalidQuantity('cantidad_fisica', 'No puede ser negativa.');
        }
        if (!is_finite($cantidadReservada) || $cantidadReservada < 0) {
            throw InventoryException::invalidQuantity('cantidad_reservada', 'No puede ser negativa.');
        }
        if (!is_finite($cantidadEnTransito) || $cantidadEnTransito < 0) {
            throw InventoryException::invalidQuantity('cantidad_en_transito', 'No puede ser negativa.');
        }

        if ($estado !== null && !StockState::fromString($estado)->isDisponible()) {
            return 0.0;
        }

        $disponible = $cantidadFisica - $cantidadReservada - $cantidadEnTransito;

        if ($disponible < 0) {
            throw InventoryException::availableQuantityCannotBeNegative(
                $cantidadFisica,
                $cantidadReservada,
                $cantidadEnTransito
            );
        }

        return $disponible;
    }

    /**
     * Construye el ledger inicial de una unidad recién recibida
     * (física = recibida, reservada = 0, en tránsito = 0 → disponible = recibida).
     */
    public function crearLedger(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        float $cantidadFisica,
        string $estadoActual
    ): StockLedger {
        $disponible = $this->disponible($cantidadFisica, 0.0, 0.0, $estadoActual);

        return StockLedger::create(
            id: $id,
            tenantId: $tenantId,
            unidadFisicaId: $unidadFisicaId,
            cantidadFisica: $cantidadFisica,
            cantidadReservada: 0.0,
            cantidadEnTransito: 0.0,
            cantidadDisponible: $disponible,
            estadoActual: StockState::fromString($estadoActual)->value()
        );
    }
}
