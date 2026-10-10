<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Reservation;
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
 * ReserveStockUseCase - CU-003: RESERVAR INVENTARIO
 *
 * Reserva una unidad física en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): Idempotency-Key en la misma transacción; replay con
 *     la misma clave devuelve la reserva original, payload distinto → 409.
 *  2. Valida unidad del tenant, transición de estado hacia RESERVADO (matriz
 *     §4 vía StateTransitionService), que NO exista reserva activa (409,
 *     doble reserva) y `cantidad_disponible >= cantidad` (422, spec E7/R8).
 *  3. Crea la reserva ACTIVA, descuenta `cantidad_reservada` del ledger (R1),
 *     pasa la unidad a RESERVADO y escribe el evento RESERVADO.
 *
 * División parcial (v1.8.2): si la cantidad pedida es MENOR que la de la
 * unidad, la reserva no recae sobre la unidad original sino sobre una HIJA
 * NUEVA (barcode propio, misma ubicación y mismo estado) creada por
 * `StockUnitSplitService`; la MADRE queda con el resto en DISPONIBLE. La
 * capacidad usada de la ubicación no cambia (sigue habiendo dos filas en el
 * mismo sitio). Si la cantidad es igual a la de la unidad no se divide.
 *
 * Eventos (v1.8.2): `cantidad_anterior`/`cantidad_posterior` son siempre la
 * CANTIDAD DE LA UNIDAD (aquí no cambia); reservado/disponible/en tránsito se
 * registran en `datos_operacion` (valores del ledger antes y después).
 *
 * Nota (desviación documentada de la spec): la matriz §4 no permite la
 * transición DEVUELTO → RESERVADO, así que sólo se puede reservar desde
 * DISPONIBLE (una unidad DEVUELTO debe pasar antes a DISPONIBLE).
 *
 * Input:
 *  - unidad_fisica_id | codigo_barcode (obligatorio uno)
 *  - cantidad (opcional: por defecto la cantidad total de la unidad; > 0)
 *  - referencia_orden (opcional)
 *  - fecha_expiracion (opcional, futura)
 */
final class ReserveStockUseCase
{
    public const OPERACION = 'inventory.reserve';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext,
        private readonly StockUnitSplitService $splitService
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($input);

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

                $response = $this->reserve($tenantId, $payload, $actorId);

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
    // RESERVA (corazón del CU-003)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     */
    private function reserve(string $tenantId, array $payload, ?string $actorId): array
    {
        // 1. Unidad física (tenant-scoped: de otro tenant → 404)
        $unit = $payload['unidad_fisica_id'] !== ''
            ? $this->stockUnits->findById($payload['unidad_fisica_id'])
            : $this->stockUnits->findByBarcode($payload['codigo_barcode']);

        if ($unit === null) {
            throw InventoryException::unitNotFound(
                $payload['unidad_fisica_id'] !== '' ? $payload['unidad_fisica_id'] : $payload['codigo_barcode']
            );
        }
        if (!$unit->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
        }

        // 2. Una unidad sólo admite UNA reserva activa a la vez (spec CU-003)
        if ($this->reservations->findActivaByUnidad($unit->id()) !== null) {
            throw InventoryException::reservationAlreadyActive($unit->id());
        }

        // 3. Transición de estado válida (matriz §4): DISPONIBLE → RESERVADO
        $this->states->assertCanTransition($unit->estado()->value(), StockState::RESERVADO);

        // 4. Ledger: cantidad_disponible >= cantidad solicitada (R1)
        $ledger = $this->ledgers->findByUnidadFisica($unit->id());
        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unit->id(),
                $unit->cantidad(),
                $unit->estado()->value()
            );
            $this->ledgers->save($ledger);
        }

        $cantidad = $payload['cantidad'] ?? $unit->cantidad();
        $disponibleAntes = $ledger->cantidadDisponible();
        $estadoAnterior = $unit->estado()->value();

        if ($cantidad > $disponibleAntes) {
            throw InventoryException::insufficientStock($disponibleAntes, $cantidad);
        }

        // 5. División parcial (v1.8.2): si la cantidad pedida es menor que la
        //    de la unidad, la reserva recae sobre una HIJA nueva y la madre
        //    queda con el resto en DISPONIBLE (misma ubicación: la capacidad
        //    usada no cambia). Si es igual, no se divide.
        $division = null;
        $unidadReservada = $unit;
        $ledgerReserva = $ledger;

        if ($cantidad < $unit->cantidad()) {
            $division = $this->splitService->dividir(
                unidad: $unit,
                cantidadHija: $cantidad,
                estadoHija: StockState::DISPONIBLE,
                estadoMadre: StockState::DISPONIBLE,
                descripcion: 'Division por reserva parcial (CU-003)',
                datosOperacion: [
                    'motivo' => 'reserva_parcial',
                    'cantidad_solicitada' => $cantidad,
                ]
            );

            $unidadReservada = $division['hija'];
            $ledgerReserva = $division['ledger_hija'];
        }

        $ledgerAntes = $ledgerReserva->valoresOperacion();

        // 6. Reserva ACTIVA (siempre sobre la unidad que quedará RESERVADO)
        $reservation = Reservation::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unidadReservada->id(),
            cantidad: $cantidad,
            referenciaOrden: $payload['referencia_orden'],
            actorId: $actorId,
            fechaExpiracion: $payload['fecha_expiracion']
        );
        $this->reservations->save($reservation);

        // 7. Ledger: cantidad_reservada + cantidad → disponible - cantidad (R1)
        $ledgerReserva->actualizar([
            'cantidad_reservada' => $ledgerReserva->cantidadReservada() + $cantidad,
            'estado_actual' => StockState::RESERVADO,
        ]);
        $this->ledgers->update($ledgerReserva);

        // 8. Unidad en estado RESERVADO
        $unidadReservada->actualizar(['estado' => StockState::RESERVADO]);
        $this->stockUnits->update($unidadReservada);

        // 9. Evento RESERVADO (append-only, con hash de integridad).
        //    v1.8.2: `cantidad_anterior`/`cantidad_posterior` = cantidad de la
        //    unidad (aquí no cambia); reservado/disponible/en tránsito van en
        //    `datos_operacion` con los valores del ledger antes y después.
        $datosOperacion = [
            'reserva_id' => $reservation->id(),
            'cantidad' => $cantidad,
            'referencia_orden' => $reservation->referenciaOrden(),
            'fecha_expiracion' => $reservation->fechaExpiracion()?->format('Y-m-d H:i:s'),
        ] + StockLedger::contextoOperacion($ledgerAntes, $ledgerReserva->valoresOperacion());

        if ($division !== null) {
            $datosOperacion['unidad_madre_id'] = $division['madre']->id();
            $datosOperacion['unidad_madre_cantidad'] = $division['madre']->cantidad();
            $datosOperacion['unidad_madre_barcode'] = $division['madre']->codigoBarcode();
        }

        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unidadReservada->id(),
            tipoEvento: 'RESERVADO',
            descripcion: 'Reserva de inventario (CU-003)',
            datosOperacion: $datosOperacion,
            cantidadAnterior: $unidadReservada->cantidad(),
            cantidadPosterior: $unidadReservada->cantidad(),
            estadoAnterior: $estadoAnterior,
            estadoPosterior: StockState::RESERVADO
        );
        $this->events->save($event);

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Reserva registrada correctamente.',
                'reserva' => $reservation->toPublicArray(),
                'unidad_fisica_id' => $unidadReservada->id(),
                'codigo_barcode' => $unidadReservada->codigoBarcode(),
                'estado' => $unidadReservada->estado()->value(),
                'ledger' => $ledgerReserva->toPublicArray(),
                'division' => $division === null ? null : [
                    'unidad_madre_id' => $division['madre']->id(),
                    'unidad_madre_barcode' => $division['madre']->codigoBarcode(),
                    'unidad_madre_cantidad' => $division['madre']->cantidad(),
                    'unidad_hija_id' => $division['hija']->id(),
                    'unidad_hija_barcode' => $division['hija']->codigoBarcode(),
                    'unidad_hija_cantidad' => $division['hija']->cantidad(),
                    'evento_madre' => [
                        'id' => $division['evento_madre']->id(),
                        'tipo_evento' => $division['evento_madre']->tipoEvento(),
                        'hash_integridad' => $division['evento_madre']->hashIntegridad(),
                    ],
                    'evento_hija' => [
                        'id' => $division['evento_hija']->id(),
                        'tipo_evento' => $division['evento_hija']->tipoEvento(),
                        'hash_integridad' => $division['evento_hija']->hashIntegridad(),
                    ],
                ],
                'evento' => [
                    'id' => $event->id(),
                    'tipo_evento' => $event->tipoEvento(),
                    'hash_integridad' => $event->hashIntegridad(),
                ],
            ],
        ];
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $unidadId = trim((string) ($input['unidad_fisica_id'] ?? ''));
        $barcode = trim((string) ($input['codigo_barcode'] ?? ''));

        if ($unidadId === '' && $barcode === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }

        $cantidad = $this->optionalPositiveFloat($input, 'cantidad');

        return [
            'unidad_fisica_id' => $unidadId,
            'codigo_barcode' => $barcode,
            'cantidad' => $cantidad,
            'referencia_orden' => $this->optionalString($input, 'referencia_orden'),
            'fecha_expiracion' => $this->optionalString($input, 'fecha_expiracion'),
        ];
    }

    private function optionalPositiveFloat(array $input, string $field): ?float
    {
        $raw = $input[$field] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw InventoryException::invalidQuantity($field, 'Debe ser un número.');
        }

        $value = (float) $raw;

        if (!is_finite($value) || $value <= 0) {
            throw InventoryException::invalidQuantity($field, 'Debe ser mayor que cero.');
        }

        return $value;
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

        if (mb_strlen($value) > 255) {
            throw InventoryException::fieldMaxLength($field, 255);
        }

        return $value;
    }
}
