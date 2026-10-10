<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * ReleaseReservationUseCase - CU-003 (cancelación) + spec E7/E8
 *
 * Cancela una reserva ACTIVA en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): con Idempotency-Key el replay devuelve la respuesta
 *     original; sin clave, la operación se ejecuta normalmente (la clave es
 *     obligatoria sólo en crear/mover, Sprint 6.4).
 *  2. Valida que la reserva exista (404) y esté ACTIVA (409).
 *  3. Transición de la unidad hacia DISPONIBLE (matriz §4 vía
 *     StateTransitionService): la unidad debe poder volver a DISPONIBLE
 *     (RESERVADO → DISPONIBLE sí; EN_TRANSITO/ENTREGADO/EN_PREPARACION no).
 *  4. Devuelve `cantidad_reservada` al ledger (R1), cancela la reserva y
 *     escribe el evento LIBERADO.
 *
 * Input:
 *  - motivo (opcional, texto de cancelación)
 */
final class ReleaseReservationUseCase
{
    public const OPERACION = 'inventory.release';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (opcional en HTTP)
     * @param string|null          $actorId
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(
        string $reservaId,
        array $input = [],
        ?string $idempotencyKey = null,
        ?string $actorId = null
    ): array {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = [
            'reserva_id' => trim($reservaId),
            'motivo' => $this->optionalString($input, 'motivo'),
        ];

        if ($payload['reserva_id'] === '') {
            throw InventoryException::requiredField('reserva_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $payload, $idempotencyKey, $actorId): array {
                if ($idempotencyKey !== null) {
                    $replay = $this->idempotency->tryBegin(
                        $tenantId,
                        $idempotencyKey,
                        self::OPERACION,
                        IdempotencyService::requestHash($payload),
                        $actorId
                    );

                    if ($replay !== null) {
                        // v1.7.1: marcador de replay para que la auditoría (A3)
                        // no escriba una fila nueva. Router lo retira del body.
                        return $replay + ['_replay' => true];
                    }
                }

                $response = $this->release($tenantId, $payload, $actorId);

                if ($idempotencyKey !== null) {
                    $this->idempotency->complete(
                        $tenantId,
                        $idempotencyKey,
                        self::OPERACION,
                        $response,
                        $response['status']
                    );
                }

                return $response;
            }
        );
    }

    // ==================================================================
    // LIBERACIÓN (cancelación de reserva, CU-003 / E7)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload
     */
    private function release(string $tenantId, array $payload, ?string $actorId): array
    {
        // 1. Reserva (tenant-scoped: de otro tenant → 404)
        $reservation = $this->reservations->findById($payload['reserva_id']);

        if ($reservation === null) {
            throw InventoryException::reservationNotFound($payload['reserva_id']);
        }
        if (!$reservation->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($reservation->tenantId(), $tenantId);
        }
        if (!$reservation->esActiva()) {
            throw InventoryException::reservationNotActive($reservation->id(), $reservation->estado());
        }

        // 2. Unidad física asociada
        $unit = $this->stockUnits->findById($reservation->unidadFisicaId());

        if ($unit === null) {
            throw InventoryException::unitNotFound($reservation->unidadFisicaId());
        }

        // 3. La unidad debe poder volver a DISPONIBLE (matriz §4)
        $estadoAnterior = $unit->estado()->value();
        if ($estadoAnterior !== StockState::DISPONIBLE) {
            $this->states->assertCanTransition($estadoAnterior, StockState::DISPONIBLE);
        }

        // 4. Ledger: devuelve la cantidad reservada (R1)
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
        $reservadaDespues = max(0.0, $ledger->cantidadReservada() - $reservation->cantidad());

        $ledger->actualizar([
            'cantidad_reservada' => $reservadaDespues,
            'estado_actual' => StockState::DISPONIBLE,
        ]);
        $this->ledgers->update($ledger);

        // 5. Unidad de vuelta a DISPONIBLE
        if ($estadoAnterior !== StockState::DISPONIBLE) {
            $unit->actualizar(['estado' => StockState::DISPONIBLE]);
            $this->stockUnits->update($unit);
        }

        // 6. Reserva cancelada
        $reservation->cancelar($payload['motivo']);
        $this->reservations->update($reservation);

        // 7. Evento LIBERADO (append-only, con hash de integridad)
        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: 'LIBERADO',
            descripcion: 'Reserva cancelada (CU-003)',
            datosOperacion: [
                'reserva_id' => $reservation->id(),
                'cantidad' => $reservation->cantidad(),
                'motivo' => $payload['motivo'],
                'actor_id' => $actorId,
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de la
            // unidad (el disponible va en datos_operacion).
            cantidadAnterior: $unit->cantidad(),
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: $estadoAnterior,
            estadoPosterior: StockState::DISPONIBLE
        );
        $this->events->save($event);

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Reserva cancelada correctamente.',
                'reserva' => $reservation->toPublicArray(),
                'unidad_fisica_id' => $unit->id(),
                'estado' => $unit->estado()->value(),
                'ledger' => $ledger->toPublicArray(),
                'evento' => [
                    'id' => $event->id(),
                    'tipo_evento' => $event->tipoEvento(),
                    'hash_integridad' => $event->hashIntegridad(),
                ],
            ],
        ];
    }

    private function optionalString(array $input, string $field): ?string
    {
        $raw = $input[$field] ?? null;

        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 1000) {
            throw InventoryException::fieldMaxLength($field, 1000);
        }

        return $value;
    }
}
