<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\PickingOperation;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * StartPickingUseCase - CU-004: INICIAR PICKING
 *
 * Inicia la selección de UN lote de picking en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): Idempotency-Key obligatoria en HTTP; misma clave +
 *     mismo payload → replay de la respuesta original; clave + payload
 *     distinto → 409.
 *  2. Valida el lote (único por tenant, 409) y la ubicación de preparación
 *     (existente, activa y de tipo PICKING, 422).
 *  3. Por cada unidad: pertenece al tenant (403/404), está RESERVADO (422)
 *     y la transición RESERVADO → EN_PREPARACION está en la matriz §4.
 *  4. Capacidad: si la unidad no está ya en la zona de preparación, la zona
 *     gana `ceil(cantidad)` slots (422 si se excede) y la ubicación de origen
 *     los pierde; si el origen ES la zona de preparación nada cambia (v1.8.2:
 *     antes sumaba slots y dejaba capacidad fantasma).
 *  5. Escribe una fila `picking_operations` EN_CURSO por unidad, mueve la
 *     unidad a la zona de preparación en estado EN_PREPARACION, refresca el
 *     ledger (R1: cantidades intactas, sólo `estado_actual`) y crea el
 *     evento EN_PREPARACION con hash de integridad.
 *  6. La reserva ACTIVA de la unidad pasa a estado final COMPLETADA al
 *     iniciar el picking (v1.7.1): así el comando E7 (expiración) nunca
 *     intenta la transición prohibida EN_PREPARACION → DISPONIBLE con la
 *     unidad ya en preparación. Si la unidad no tiene reserva activa se
 *     continúa igual (no hay nada que E7 vaya a caducar).
 *  7. Cualquier error → ROLLBACK total (la key de idempotencia se revierte).
 *
 * HUECO documentado (v1.7.0): la spec CU-004 admite `cantidad >=
 * cantidad_a_preparar`, pero una unidad física no se divide; si la cantidad
 * pedida no es EXACTAMENTE la de la unidad se responde 422
 * (`unitQuantityMismatch`, field `cantidad`). Desde v1.8.2 la división sólo
 * ocurre al RESERVAR (CU-003): picking y carga siguen exigiendo cantidad
 * exacta, ahora contra la unidad reservada.
 *
 * Eventos (v1.8.2): `cantidad_anterior`/`cantidad_posterior` son la CANTIDAD
 * DE LA UNIDAD; reservado/disponible/en tránsito se registran en
 * `datos_operacion` (ledger antes y después).
 *
 * Input:
 *  - id_lote_picking (obligatorio, único por tenant)
 *  - ubicacion_preparacion_id (obligatorio, tipo PICKING)
 *  - unidades: [{unidad_fisica_id, cantidad?}] (obligatorio, no vacío)
 *  - referencia_orden (opcional)
 */
final class StartPickingUseCase
{
    public const OPERACION = 'inventory.picking.start';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly PickingOperationRepositoryInterface $pickings,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
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

                $response = $this->start($tenantId, $payload, $actorId);

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
    // PICKING (corazón del CU-004)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     * @param array<int, array<string, mixed>> $payload['unidades']
     */
    private function start(string $tenantId, array $payload, ?string $actorId): array
    {
        // 1. Lote único por tenant (id_lote_picking)
        if ($this->pickings->existsLote($payload['id_lote_picking'])) {
            throw InventoryException::pickingLotAlreadyExists($payload['id_lote_picking']);
        }

        // 2. Ubicación de preparación: existente, activa y tipo PICKING
        $preparacion = $this->locations->findById($payload['ubicacion_preparacion_id']);

        if ($preparacion === null) {
            throw InventoryException::locationNotFound($payload['ubicacion_preparacion_id']);
        }
        if (!$preparacion->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($preparacion->tenantId(), $tenantId);
        }
        if (!$preparacion->isActive() || !$preparacion->tipo()->isPicking()) {
            throw InventoryException::pickingLocationRequired();
        }

        $operaciones = [];
        $unidades = [];
        $eventos = [];
        $origenCache = [];
        $origenesAfectadas = [];

        foreach ($payload['unidades'] as $entrada) {
            // 3. Unidad física (tenant-scoped: de otro tenant → 404)
            $unit = $this->stockUnits->findById($entrada['unidad_fisica_id']);

            if ($unit === null) {
                throw InventoryException::unitNotFound($entrada['unidad_fisica_id']);
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }

            // 4. Sólo unidades RESERVADO admiten picking (spec CU-004)
            $estadoAnterior = $unit->estado()->value();

            if ($estadoAnterior !== StockState::RESERVADO) {
                throw InventoryException::unitNotReserved($unit->id(), $estadoAnterior);
            }

            // 5. Cantidad exacta de la unidad (no se divide: 422, ver hueco)
            if ($entrada['cantidad'] !== null && abs($entrada['cantidad'] - $unit->cantidad()) > 0.0001) {
                throw InventoryException::unitQuantityMismatch($unit->id(), $unit->cantidad(), $entrada['cantidad']);
            }

            // 6. Transición válida en la matriz §4: RESERVADO → EN_PREPARACION
            $this->states->assertCanTransition($estadoAnterior, StockState::EN_PREPARACION);

            // 7. Ubicación de origen (de dónde toma la unidad)
            $origen = null;
            if ($unit->ubicacionId() !== null) {
                if (!array_key_exists($unit->ubicacionId(), $origenCache)) {
                    $origenCache[$unit->ubicacionId()] = $this->locations->findById($unit->ubicacionId());
                }

                $origen = $origenCache[$unit->ubicacionId()];

                if ($origen === null) {
                    throw InventoryException::locationNotFound($unit->ubicacionId());
                }
                if (!$origen->belongsToTenant($tenantId)) {
                    throw InventoryException::tenantIsolationViolation($origen->tenantId(), $tenantId);
                }
            }

            // 8. Capacidad (v1.8.2): 1 slot por unidad (`ceil(cantidad)`),
            //    mismo criterio que recepción/movimiento. Si la unidad ya
            //    está en la zona de preparación no hay cambio: moverla dentro
            //    del mismo sitio ni suma ni resta (antes sumaba slots y
            //    dejaba capacidad fantasma).
            $slots = (int) ceil($unit->cantidad());
            $mismoSitio = $origen !== null && $origen->id() === $preparacion->id();

            if (!$mismoSitio) {
                $preparacion->incrementarCapacidadUsada($slots);

                if ($origen !== null) {
                    $origen->decrementarCapacidadUsada($slots);
                }
            }

            if ($origen !== null) {
                $origenesAfectadas[$origen->id()] = $origen;
            }

            // 9. Operación de picking EN_CURSO
            $operacion = PickingOperation::create(
                id: uuid(),
                tenantId: $tenantId,
                idLotePicking: $payload['id_lote_picking'],
                unidadFisicaId: $unit->id(),
                cantidad: $unit->cantidad(),
                ubicacionOrigenId: $origen?->id(),
                ubicacionPreparacionId: $preparacion->id(),
                referenciaOrden: $payload['referencia_orden'],
                actorIdSelector: $actorId
            );
            $this->pickings->save($operacion);
            $operaciones[] = $operacion;

            // 10. Ledger (R1): cantidades intactas, sólo se refresca el estado
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
            $ledger->actualizar(['estado_actual' => StockState::EN_PREPARACION]);
            $this->ledgers->update($ledger);

            // 11. Unidad en la zona de preparación, estado EN_PREPARACION
            $unit->moverA($preparacion->id(), StockState::enPreparacion());
            $this->stockUnits->update($unit);
            $unidades[] = $unit;

            // 12. Reserva ACTIVA de la unidad → COMPLETADA (estado final de
            //     consumida, v1.7.1): E7 sólo caduca reservas ACTIVA y nunca
            //     verá una unidad EN_PREPARACION (matriz §4 prohíbe devolverla
            //     a DISPONIBLE). Sin reserva activa no hay nada que consumir.
            $reserva = $this->reservations->findActivaByUnidad($unit->id());

            if ($reserva !== null) {
                $reserva->completar();
                $this->reservations->update($reserva);
            }

            // 13. Evento EN_PREPARACION (append-only, con hash de integridad)
            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_EN_PREPARACION,
                descripcion: 'Inicio de picking (CU-004)',
                datosOperacion: [
                    'id_lote_picking' => $operacion->idLotePicking(),
                    'picking_id' => $operacion->id(),
                    'ubicacion_origen_id' => $origen?->id(),
                    'ubicacion_preparacion_id' => $preparacion->id(),
                    'cantidad' => $unit->cantidad(),
                    'referencia_orden' => $payload['referencia_orden'],
                    'slots' => $slots,
                    'capacidad_origen_no_cambia' => $mismoSitio,
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::EN_PREPARACION
            );
            $this->events->save($event);
            $eventos[] = $event;
        }

        // 14. Capacidad persistida (origenes y destino en la misma transacción)
        foreach ($origenesAfectadas as $origenAfectada) {
            if ($origenAfectada->id() === $preparacion->id()) {
                continue;
            }

            $this->locations->update($origenAfectada);
        }
        $this->locations->update($preparacion);

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Picking iniciado correctamente.',
                'id_lote_picking' => $payload['id_lote_picking'],
                'referencia_orden' => $payload['referencia_orden'],
                'operaciones' => array_map(
                    static fn(PickingOperation $op): array => $op->toPublicArray(),
                    $operaciones
                ),
                'unidades' => array_map(
                    static fn($unit): array => [
                        'unidad_fisica_id' => $unit->id(),
                        'codigo_barcode' => $unit->codigoBarcode(),
                        'estado' => $unit->estado()->value(),
                    ],
                    $unidades
                ),
                'ubicacion_preparacion' => [
                    'ubicacion_id' => $preparacion->id(),
                    'codigo' => $preparacion->codigo()->value(),
                    'capacidad' => $preparacion->capacidad(),
                    'capacidad_usada' => $preparacion->capacidadUsada(),
                ],
                'eventos' => array_map(
                    static fn(StockEvent $event): array => [
                        'id' => $event->id(),
                        'tipo_evento' => $event->tipoEvento(),
                        'hash_integridad' => $event->hashIntegridad(),
                    ],
                    $eventos
                ),
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
        $lote = trim((string) ($input['id_lote_picking'] ?? ''));

        if ($lote === '') {
            throw InventoryException::requiredField('id_lote_picking');
        }
        if (mb_strlen($lote) > 100) {
            throw InventoryException::fieldMaxLength('id_lote_picking', 100);
        }

        $preparacion = trim((string) ($input['ubicacion_preparacion_id'] ?? ''));

        if ($preparacion === '') {
            throw InventoryException::requiredField('ubicacion_preparacion_id');
        }

        $unidades = $input['unidades'] ?? null;

        if (!is_array($unidades) || $unidades === []) {
            throw InventoryException::invalidField('unidades', 'Debe incluir al menos una unidad física.');
        }

        $normalizadas = [];
        $vistas = [];

        foreach (array_values($unidades) as $indice => $item) {
            if (!is_array($item)) {
                throw InventoryException::invalidField("unidades[{$indice}]", 'Debe ser un objeto con unidad_fisica_id.');
            }

            $unidadId = trim((string) ($item['unidad_fisica_id'] ?? ''));

            if ($unidadId === '') {
                throw InventoryException::requiredField("unidades[{$indice}].unidad_fisica_id");
            }

            if (isset($vistas[$unidadId])) {
                throw InventoryException::duplicateUnitsInRequest($unidadId);
            }
            $vistas[$unidadId] = true;

            $normalizadas[] = [
                'unidad_fisica_id' => $unidadId,
                'cantidad' => $this->optionalPositiveFloat($item, 'cantidad'),
            ];
        }

        return [
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $preparacion,
            'referencia_orden' => $this->optionalString($input, 'referencia_orden'),
            'unidades' => $normalizadas,
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
