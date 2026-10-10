<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Reservation;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Services\DatabaseService;
use DateTimeImmutable;

/**
 * Expiración de reservas vencidas (spec E7) - Sprint 6.3.
 *
 * Cancela en lote las reservas ACTIVAS con `fecha_expiracion` pasada y
 * devuelve el stock:
 *
 *  - Sólo se procesa si la unidad sigue en RESERVADO (v1.7.1): cualquier
 *    otra unidad se OMITE y se registra (en el resultado y, si hay
 *    AuditLogger, en `audit_logs`) sin tocar reserva, ledger ni evento,
 *    para que una unidad en EN_PREPARACION/CARGADO/... nunca rompa la
 *    tanda ni reciba una transición prohibida (matriz §4).
 *  - Reserva → CANCELADA (motivo "Expirada automáticamente (E7)")
 *  - Ledger `cantidad_reservada` → descuenta la cantidad (R1)
 *  - Unidad en RESERVADO → DISPONIBLE (matriz §4)
 *  - Evento LIBERADO por cada reserva procesada (append-only, con hash)
 *
 * Toda la tanda corre dentro de UNA transacción: si algo falla a mitad, no
 * se cancela ninguna reserva.
 *
 * Auditoría (v1.6.2): cuando hay AuditLogger disponible, cada reserva
 * expirada genera su fila en `audit_logs` DESPUÉS de confirmar la
 * transacción (nunca dentro: AuditLogger abriría una transacción anidada y
 * rompería la tanda). Las omitidas también dejan constancia, marcadas con
 * `resultado = OMITIDA` en `valores_nuevos`. La expiración corre por CLI,
 * fuera del AuditMiddleware HTTP, de ahí que el registro se haga aquí.
 *
 * Uso CLI: `php bin/expire-reservations.php` (itera los tenants activos).
 */
final class ReservationExpirationService
{
    public const MOTIVO_EXPIRACION = 'Expirada automáticamente (E7)';

    /** Reserva cancelada por E7 (caso normal). */
    public const RESULTADO_CANCELADA = 'CANCELADA';

    /** Reserva omitida: la unidad ya no está en RESERVADO (v1.7.1). */
    public const RESULTADO_OMITIDA = 'OMITIDA';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * @param int                  $limit máximo de reservas por tanda
     * @param DateTimeImmutable|null $now instante de referencia (tests)
     *
     * @return array{
     *     expiradas: int,
     *     omitidas: int,
     *     detalle: array<int, array<string, mixed>>,
     *     omitidas_detalle: array<int, array<string, mixed>>
     * }
     */
    public function expire(int $limit = 500, ?DateTimeImmutable $now = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        $result = $this->db->transaction(
            function () use ($tenantId, $limit, $now): array {
                $momento = $now ?? new DateTimeImmutable();
                $vencidas = $this->reservations->findExpiradas($momento, $limit);

                $procesadas = [];
                $omitidas = [];

                foreach ($vencidas as $reservation) {
                    $detalle = $this->expireOne($tenantId, $reservation);

                    if ($detalle['resultado'] === self::RESULTADO_OMITIDA) {
                        $omitidas[] = $detalle;
                    } else {
                        $procesadas[] = $detalle;
                    }
                }

                return [
                    'expiradas' => count($procesadas),
                    'omitidas' => count($omitidas),
                    'detalle' => $procesadas,
                    'omitidas_detalle' => $omitidas,
                ];
            }
        );

        $this->auditExpirations($tenantId, $result['detalle'], $result['omitidas_detalle']);

        return $result;
    }

    /**
     * Una fila de auditoría por reserva expirada y otra por cada omitida
     * (fuera de la transacción).
     *
     * @param array<int, array<string, mixed>> $procesadas
     * @param array<int, array<string, mixed>> $omitidas
     */
    private function auditExpirations(string $tenantId, array $procesadas, array $omitidas): void
    {
        if ($this->audit === null || ($procesadas === [] && $omitidas === [])) {
            return;
        }

        foreach ($procesadas as $d) {
            $this->audit->record([
                'tenant_id' => $tenantId,
                'usuario_id' => null,
                'operacion' => 'CLI bin/expire-reservations',
                'modulo' => 'inventory',
                'recurso' => 'reservations',
                'entidad_id' => (string) ($d['reserva_id'] ?? null),
                'tipo_cambio' => 'EXECUTE',
                'valores_anteriores' => null,
                'valores_nuevos' => [
                    'reserva_id' => $d['reserva_id'] ?? null,
                    'unidad_fisica_id' => $d['unidad_fisica_id'] ?? null,
                    'cantidad' => $d['cantidad'] ?? null,
                    'estado_unidad_anterior' => $d['estado_unidad_anterior'] ?? null,
                    'estado_unidad_nuevo' => $d['estado_unidad_nuevo'] ?? null,
                    'motivo' => self::MOTIVO_EXPIRACION,
                    'resultado' => self::RESULTADO_CANCELADA,
                ],
                'resultado' => 'SUCCESS',
                'detalles_adicionales' => [
                    'origen' => 'cli',
                    'comando' => 'bin/expire-reservations.php',
                    'regla' => 'E7',
                ],
            ]);
        }

        foreach ($omitidas as $d) {
            $this->audit->record([
                'tenant_id' => $tenantId,
                'usuario_id' => null,
                'operacion' => 'CLI bin/expire-reservations',
                'modulo' => 'inventory',
                'recurso' => 'reservations',
                'entidad_id' => (string) ($d['reserva_id'] ?? null),
                'tipo_cambio' => 'EXECUTE',
                'valores_anteriores' => null,
                'valores_nuevos' => [
                    'reserva_id' => $d['reserva_id'] ?? null,
                    'unidad_fisica_id' => $d['unidad_fisica_id'] ?? null,
                    'cantidad' => $d['cantidad'] ?? null,
                    'estado_unidad_anterior' => $d['estado_unidad_anterior'] ?? null,
                    'estado_unidad_nuevo' => $d['estado_unidad_nuevo'] ?? null,
                    'resultado' => self::RESULTADO_OMITIDA,
                    'motivo_omision' => $d['motivo_omision'] ?? null,
                ],
                'resultado' => 'SUCCESS',
                'detalles_adicionales' => [
                    'origen' => 'cli',
                    'comando' => 'bin/expire-reservations.php',
                    'regla' => 'E7',
                    'omitida' => true,
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function expireOne(string $tenantId, Reservation $reservation): array
    {
        $unit = $this->stockUnits->findById($reservation->unidadFisicaId());

        // v1.7.1: sólo se procesan unidades que siguen RESERVADO. Una unidad
        // en EN_PREPARACION/CARGADO/... (o inexistente) se OMITE y se registra
        // sin tocar la reserva ni el ledger: así la tanda nunca falla en bloque
        // por una unidad inválida y jamás se fuerza una transición prohibida.
        if ($unit === null || $unit->estado()->value() !== StockState::RESERVADO) {
            $estado = $unit?->estado()->value();

            return [
                'resultado' => self::RESULTADO_OMITIDA,
                'reserva_id' => $reservation->id(),
                'unidad_fisica_id' => $reservation->unidadFisicaId(),
                'cantidad' => $reservation->cantidad(),
                'estado_unidad_anterior' => $estado,
                'estado_unidad_nuevo' => $estado,
                'motivo_omision' => $unit === null
                    ? 'La unidad física no existe.'
                    : "La unidad no está en RESERVADO (estado: {$estado}).",
            ];
        }

        $ledger = null;
        $estadoAnterior = null;
        $estadoNuevo = null;
        $ledgerAntes = [];

        if ($unit !== null) {
            $estadoAnterior = $unit->estado()->value();
            $estadoNuevo = $estadoAnterior;

            // Sólo se devuelve la unidad a DISPONIBLE si estaba RESERVADO;
            // en cualquier otro estado no se fuerza una transición inválida.
            if ($estadoAnterior === StockState::RESERVADO) {
                $this->states->assertCanTransition($estadoAnterior, StockState::DISPONIBLE);
                $estadoNuevo = StockState::DISPONIBLE;
            }

            $ledger = $this->ledgers->findByUnidadFisica($unit->id());
            if ($ledger === null) {
                $ledger = $this->calculation->crearLedger(
                    uuid(),
                    $tenantId,
                    $unit->id(),
                    $unit->cantidad(),
                    $estadoAnterior
                );
                $this->ledgers->save($ledger);
            }

            $ledgerAntes = $ledger->valoresOperacion();

            $ledger->actualizar([
                'cantidad_reservada' => max(0.0, $ledger->cantidadReservada() - $reservation->cantidad()),
                'estado_actual' => $estadoNuevo,
            ]);
            $this->ledgers->update($ledger);

            if ($estadoNuevo !== $estadoAnterior) {
                $unit->actualizar(['estado' => $estadoNuevo]);
                $this->stockUnits->update($unit);
            }
        }

        $reservation->cancelar(self::MOTIVO_EXPIRACION);
        $this->reservations->update($reservation);

        if ($unit !== null) {
            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: 'LIBERADO',
                descripcion: 'Reserva expirada (E7)',
                datosOperacion: [
                    'reserva_id' => $reservation->id(),
                    'cantidad' => $reservation->cantidad(),
                    'motivo' => self::MOTIVO_EXPIRACION,
                    'fecha_expiracion' => $reservation->fechaExpiracion()?->format('Y-m-d H:i:s'),
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: $estadoNuevo
            );
            $this->events->save($event);
        }

        return [
            'resultado' => self::RESULTADO_CANCELADA,
            'reserva_id' => $reservation->id(),
            'unidad_fisica_id' => $reservation->unidadFisicaId(),
            'cantidad' => $reservation->cantidad(),
            'estado_unidad_anterior' => $estadoAnterior,
            'estado_unidad_nuevo' => $estadoNuevo,
        ];
    }
}
