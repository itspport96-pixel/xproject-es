<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;

/**
 * Máquina de estados de la unidad física (spec §4 "Matriz de Transiciones
 * Válidas", Sprint 6.1).
 *
 * Es la única fuente de verdad sobre qué transiciones se permiten: los use
 * cases (reserva, liberación, expiración, movimiento) consultan este servicio
 * antes de mutar la unidad; cualquier transición fuera de la matriz se rechaza
 * con 422 (`field: estado`, spec R8).
 *
 * Matriz (fila = estado actual, columna = destinos válidos):
 *
 *   DISPONIBLE      → RESERVADO, EN_PREPARACION, DAÑADO, BLOQUEADO, PERDIDO*
 *   RESERVADO       → DISPONIBLE (cancelación), EN_PREPARACION, BLOQUEADO
 *   EN_PREPARACION  → CARGADO, DAÑADO, BLOQUEADO
 *   CARGADO         → EN_TRANSITO, DAÑADO, BLOQUEADO
 *   EN_TRANSITO     → ENTREGADO, DEVUELTO, BLOQUEADO
 *   ENTREGADO       → DEVUELTO
 *   DEVUELTO        → DISPONIBLE, DAÑADO, PERDIDO
 *   DAÑADO          → BLOQUEADO, PERDIDO
 *   BLOQUEADO       → DISPONIBLE, DAÑADO, PERDIDO
 *   PERDIDO         → (ninguno: estado terminal)
 *
 *  * DESVIACIÓN v1.9.1 (aprobada): la matriz §4 de la spec dice
 *    DISPONIBLE → PERDIDO = NO, pero CU-010 (reportar PERDIDA, caso típico
 *    de una pérdida en almacén) y CU-011 (reconciliar con cantidad 0) lo
 *    necesitan desde DISPONIBLE, y `accion_marcar_perdido() → PERDIDO` de la
 *    spec no limita el estado de origen. Cualquier otro origen no contemplado
 *    (RESERVADO, EN_PREPARACION, CARGADO, EN_TRANSITO, ENTREGADO) sigue
 *    rechazándose con 422.
 *
 * Nota: la transición X → X (sin cambio) tampoco es válida (las casillas
 * diagonales de la matriz están vacías).
 */
final class StateTransitionService
{
    /**
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        StockState::DISPONIBLE => [
            StockState::RESERVADO,
            StockState::EN_PREPARACION,
            StockState::DANADO,
            StockState::BLOQUEADO,
            // DESVIACIÓN v1.9.1 (ver docblock): spec §4 dice NO, pero
            // CU-010/CU-011 marcan PERDIDO desde DISPONIBLE.
            StockState::PERDIDO,
        ],
        StockState::RESERVADO => [
            StockState::DISPONIBLE,
            StockState::EN_PREPARACION,
            StockState::BLOQUEADO,
        ],
        StockState::EN_PREPARACION => [
            StockState::CARGADO,
            StockState::DANADO,
            StockState::BLOQUEADO,
        ],
        StockState::CARGADO => [
            StockState::EN_TRANSITO,
            StockState::DANADO,
            StockState::BLOQUEADO,
        ],
        StockState::EN_TRANSITO => [
            StockState::ENTREGADO,
            StockState::DEVUELTO,
            StockState::BLOQUEADO,
        ],
        StockState::ENTREGADO => [
            StockState::DEVUELTO,
        ],
        StockState::DEVUELTO => [
            StockState::DISPONIBLE,
            StockState::DANADO,
            StockState::PERDIDO,
        ],
        StockState::DANADO => [
            StockState::BLOQUEADO,
            StockState::PERDIDO,
        ],
        StockState::BLOQUEADO => [
            StockState::DISPONIBLE,
            StockState::DANADO,
            StockState::PERDIDO,
        ],
        StockState::PERDIDO => [],
    ];

    /**
     * Estados que NO admiten movimiento interno (CU-002: "Estado permite
     * movimiento (NO EN_TRANSITO, ENTREGADO, PERDIDO)").
     *
     * @var array<int, string>
     */
    public const NOT_MOVABLE_STATES = [
        StockState::EN_TRANSITO,
        StockState::ENTREGADO,
        StockState::PERDIDO,
    ];

    /**
     * ¿La transición $from → $to está en la matriz?
     *
     * @throws InventoryException 400 si alguno de los estados es desconocido
     */
    public function canTransition(string $from, string $to): bool
    {
        $fromState = StockState::fromString($from)->value();
        $toState = StockState::fromString($to)->value();

        if ($fromState === $toState) {
            return false;
        }

        return in_array($toState, self::TRANSITIONS[$fromState] ?? [], true);
    }

    /**
     * Lanza 422 si la transición no está permitida (spec R8).
     *
     * @throws InventoryException 422 con field `estado`
     */
    public function assertCanTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw InventoryException::invalidStateTransition(
                StockState::fromString($from)->value(),
                StockState::fromString($to)->value()
            );
        }
    }

    /**
     * Destinos válidos desde un estado (para documentación/errores).
     *
     * @return array<int, string>
     */
    public function allowedTargets(string $from): array
    {
        return self::TRANSITIONS[StockState::fromString($from)->value()] ?? [];
    }

    /**
     * ¿La unidad en este estado puede moverse internamente (CU-002)?
     */
    public function canMove(string $state): bool
    {
        $value = StockState::fromString($state)->value();

        return !in_array($value, self::NOT_MOVABLE_STATES, true);
    }

    /**
     * Lanza 422 si la unidad no admite movimiento interno.
     *
     * @throws InventoryException 422 con field `estado`
     */
    public function assertCanMove(string $unitId, string $state): void
    {
        if (!$this->canMove($state)) {
            throw InventoryException::unitNotMovable(
                $unitId,
                StockState::fromString($state)->value()
            );
        }
    }

    /**
     * ¿El estado actual admite reservar la unidad (CU-003)?
     * Equivale a preguntar si existe la transición X → RESERVADO.
     */
    public function canReserve(string $state): bool
    {
        return $this->canTransition($state, StockState::RESERVADO);
    }
}
