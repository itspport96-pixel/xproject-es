<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use DateTimeImmutable;

/**
 * Entidad: Ledger de Stock (stock_ledger) - spec §3.8
 *
 * Proyección de los totales de una unidad física (R1, v1.8.3):
 *
 *   estado = DISPONIBLE  →  cantidad_disponible = cantidad_fisica
 *                                            - cantidad_reservada
 *                                            - cantidad_en_transito
 *   cualquier otro estado → cantidad_disponible = 0
 *
 * Siempre >= 0. Se actualiza en la misma transacción que cada movimiento.
 */
final class StockLedger
{
    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $unidadFisicaId,
        private float $cantidadFisica,
        private float $cantidadReservada,
        private float $cantidadEnTransito,
        private float $cantidadDisponible,
        private string $estadoActual,
        private DateTimeImmutable $ultimaActualizacion
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            unidadFisicaId: $data['unidad_fisica_id'],
            cantidadFisica: (float) ($data['cantidad_fisica'] ?? 0),
            cantidadReservada: (float) ($data['cantidad_reservada'] ?? 0),
            cantidadEnTransito: (float) ($data['cantidad_en_transito'] ?? 0),
            cantidadDisponible: (float) ($data['cantidad_disponible'] ?? 0),
            estadoActual: (string) ($data['estado_actual'] ?? StockState::DISPONIBLE),
            ultimaActualizacion: isset($data['ultima_actualizacion'])
                ? new DateTimeImmutable($data['ultima_actualizacion'])
                : new DateTimeImmutable()
        );
    }

    /**
     * @param array<string, mixed> $totales cantidades ya calculadas por StockCalculationService
     */
    public static function create(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        float $cantidadFisica,
        float $cantidadReservada,
        float $cantidadEnTransito,
        float $cantidadDisponible,
        string $estadoActual
    ): self {
        self::validate($cantidadFisica, $cantidadReservada, $cantidadEnTransito, $cantidadDisponible);

        return new self(
            id: $id,
            tenantId: $tenantId,
            unidadFisicaId: $unidadFisicaId,
            cantidadFisica: $cantidadFisica,
            cantidadReservada: $cantidadReservada,
            cantidadEnTransito: $cantidadEnTransito,
            cantidadDisponible: $cantidadDisponible,
            estadoActual: $estadoActual,
            ultimaActualizacion: new DateTimeImmutable()
        );
    }

    /**
     * Actualiza los totales y recalcula el disponible (R1, v1.8.3).
     *
     * Sólo suma disponible si el estado resultante es `DISPONIBLE`; con
     * `ENTREGADO`, `DAÑADO`, `PERDIDO`, `DEVUELTO`, `EN_TRANSITO` y el resto
     * de estados el disponible es 0, aunque `cantidad_fisica` siga siendo
     * mayor que cero.
     *
     * @param array{cantidad_fisica?: float, cantidad_reservada?: float, cantidad_en_transito?: float, estado_actual?: string} $cambios
     */
    public function actualizar(array $cambios): void
    {
        if (isset($cambios['cantidad_fisica'])) {
            $this->cantidadFisica = (float) $cambios['cantidad_fisica'];
        }
        if (isset($cambios['cantidad_reservada'])) {
            $this->cantidadReservada = (float) $cambios['cantidad_reservada'];
        }
        if (isset($cambios['cantidad_en_transito'])) {
            $this->cantidadEnTransito = (float) $cambios['cantidad_en_transito'];
        }
        if (isset($cambios['estado_actual'])) {
            $this->estadoActual = StockState::fromString((string) $cambios['estado_actual'])->value();
        }

        $this->cantidadDisponible = StockState::fromString($this->estadoActual)->isDisponible()
            ? $this->cantidadFisica - $this->cantidadReservada - $this->cantidadEnTransito
            : 0.0;

        self::validate(
            $this->cantidadFisica,
            $this->cantidadReservada,
            $this->cantidadEnTransito,
            $this->cantidadDisponible
        );

        $this->ultimaActualizacion = new DateTimeImmutable();
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function cantidadFisica(): float { return $this->cantidadFisica; }
    public function cantidadReservada(): float { return $this->cantidadReservada; }
    public function cantidadEnTransito(): float { return $this->cantidadEnTransito; }
    public function cantidadDisponible(): float { return $this->cantidadDisponible; }
    public function estadoActual(): string { return $this->estadoActual; }
    public function ultimaActualizacion(): DateTimeImmutable { return $this->ultimaActualizacion; }

    /**
     * Valores de negocio que acompañan a cada evento de stock (v1.8.2):
     * reservado, disponible y en tránsito se registran en `datos_operacion`,
     * nunca en `cantidad_anterior`/`cantidad_posterior` (esas columnas
     * reflejan la CANTIDAD DE LA UNIDAD).
     *
     * @return array{cantidad_reservada: float, cantidad_disponible: float, cantidad_en_transito: float}
     */
    public function valoresOperacion(): array
    {
        return [
            'cantidad_reservada'   => $this->cantidadReservada,
            'cantidad_disponible'  => $this->cantidadDisponible,
            'cantidad_en_transito' => $this->cantidadEnTransito,
        ];
    }

    /**
     * Fusiona el antes y el después del ledger para `datos_operacion`:
     * las claves del después quedan como están y las del antes con el
     * sufijo `_anterior`.
     *
     * @param array<string, float> $antes
     * @param array<string, float> $despues
     * @return array<string, float>
     */
    public static function contextoOperacion(array $antes, array $despues): array
    {
        $contexto = $despues;

        foreach ($antes as $clave => $valor) {
            $contexto[$clave . '_anterior'] = $valor;
        }

        return $contexto;
    }

    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'tenant_id'             => $this->tenantId,
            'unidad_fisica_id'      => $this->unidadFisicaId,
            'cantidad_fisica'       => $this->cantidadFisica,
            'cantidad_reservada'    => $this->cantidadReservada,
            'cantidad_en_transito'  => $this->cantidadEnTransito,
            'cantidad_disponible'   => $this->cantidadDisponible,
            'estado_actual'         => $this->estadoActual,
            'ultima_actualizacion'  => $this->ultimaActualizacion->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'unidad_fisica_id'     => $this->unidadFisicaId,
            'cantidad_fisica'      => $this->cantidadFisica,
            'cantidad_reservada'   => $this->cantidadReservada,
            'cantidad_en_transito' => $this->cantidadEnTransito,
            'cantidad_disponible'  => $this->cantidadDisponible,
            'estado_actual'        => $this->estadoActual,
        ];
    }

    private static function validate(
        float $cantidadFisica,
        float $cantidadReservada,
        float $cantidadEnTransito,
        float $cantidadDisponible
    ): void {
        if (!is_finite($cantidadFisica) || $cantidadFisica < 0) {
            throw InventoryException::invalidQuantity('cantidad_fisica', 'No puede ser negativa.');
        }
        if (!is_finite($cantidadReservada) || $cantidadReservada < 0) {
            throw InventoryException::invalidQuantity('cantidad_reservada', 'No puede ser negativa.');
        }
        if (!is_finite($cantidadEnTransito) || $cantidadEnTransito < 0) {
            throw InventoryException::invalidQuantity('cantidad_en_transito', 'No puede ser negativa.');
        }
        if (!is_finite($cantidadDisponible) || $cantidadDisponible < 0) {
            throw InventoryException::availableQuantityCannotBeNegative(
                $cantidadFisica,
                $cantidadReservada,
                $cantidadEnTransito
            );
        }
    }
}
