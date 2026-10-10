<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\PickingOperation;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Entities\ShipmentItem;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentItemRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * LoadShipmentUseCase - CU-005: CARGAR VEHÍCULO
 *
 * Carga unidades preparadas en UN envío en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): Idempotency-Key obligatoria en HTTP.
 *  2. Crea el shipment (PREPARACION) con los datos opcionales del cuerpo, o
 *     reutiliza uno existente del tenant que esté PREPARACION (404/409).
 *  3. Por cada unidad: pertenece al tenant (404/403), está EN_PREPARACION
 *     (422), su picking está COMPLETADA o VERIFICADA (422), está en una zona
 *     PICKING (422) y su cantidad es exacta (422, no se divide).
 *  4. Escribe un `shipment_items` CARGADA por unidad, deja la unidad CARGADO
 *     (en su misma ubicación: sigue ocupando la zona de picking hasta que se
 *     autorice la salida, v1.8.2), actualiza el ledger:
 *     transfiere `cantidad_reservada` → `cantidad_en_transito` (R1: el
 *     disponible NO cambia) y completa la reserva activa de la unidad para
 *     que el comando E7 no la expire con la unidad ya cargada.
 *  5. Evento CARGADO con hash por unidad.
 *  6. Origen del envío (v1.8.3): `shipments.origen_ubicacion_id` es
 *     obligatorio. Si el cliente no lo envía se deriva de la ubicación de
 *     las unidades cargadas; si no hay una única ubicación posible → 422.
 *  7. Cualquier error → ROLLBACK total (la key de idempotencia se revierte).
 *
 * Capacidad (v1.8.2): LA CARGA NO LIBERA SLOTS. La unidad sigue físicamente
 * en la zona de picking hasta que `AuthorizeShipmentUseCase` la mueve a la
 * ubicación virtual de tránsito, que es cuando se descuenta el origen (antes
 * se liberaba aquí y al autorizar, o sólo aquí mientras la unidad seguía
 * ocupando sitio: ambas dejaban `capacidad_usada` incoherente).
 *
 * Eventos (v1.8.2): `cantidad_anterior`/`cantidad_posterior` son la CANTIDAD
 * DE LA UNIDAD; reservado/disponible/en tránsito van en `datos_operacion`.
 *
 * DECISIÓN documentada (v1.7.0): la spec dice "unidades EN_PREPARACION o
 * VERIFICADA", mezclando estado de unidad (EN_PREPARACION) con estado del
 * picking (VERIFICADA). Se exige AMBAS cosas: unidad EN_PREPARACION cuya
 * operación de picking esté COMPLETADA o VERIFICADA.
 *
 * DECISIÓN documentada: `cantidad_reservada → cantidad_en_transito` se
 * transfiere AQUÍ (spec CU-005: "actualiza ledger cantidad_en_transito");
 * al autorizar la salida sólo se refresca `estado_actual`, para no contar
 * dos veces la misma cantidad.
 *
 * Input:
 *  - unidades: [{unidad_fisica_id, cantidad?}] (obligatorio, no vacío)
 *  - shipment_id (opcional: envío PREPARACION existente)
 *  - shipment: {...} (opcional: datos del envío a crear; si falta
 *    `origen_ubicacion_id` se deriva de las unidades, v1.8.3)
 */
final class LoadShipmentUseCase
{
    public const OPERACION = 'inventory.shipment.load';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly ShipmentItemRepositoryInterface $shipmentItems,
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

                $response = $this->load($tenantId, $payload);

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
    // CARGA (corazón del CU-005)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     * @return array{status: int, data: array<string, mixed>}
     */
    private function load(string $tenantId, array $payload): array
    {
        // 1. Shipment existente (PREPARACION) o creación del nuevo (se guarda
        //    ANTES de los ítems: shipment_items tiene FK a shipments)
        $nuevo = $payload['shipment_id'] === '';
        $shipment = $nuevo
            ? Shipment::create(uuid(), $tenantId, $payload['shipment'])
            : $this->resolveExistingShipment($tenantId, $payload['shipment_id']);

        if ($nuevo) {
            $this->shipments->save($shipment);
        }

        $items = [];
        $unidades = [];
        $eventos = [];
        /** @var array<string, true> $origenes zonas de preparación de las unidades */
        $origenes = [];

        foreach ($payload['unidades'] as $entrada) {
            // 2. Unidad física (tenant-scoped: de otro tenant → 404)
            $unit = $this->stockUnits->findById($entrada['unidad_fisica_id']);

            if ($unit === null) {
                throw InventoryException::unitNotFound($entrada['unidad_fisica_id']);
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }

            // 3. Sólo lo preparado se carga (EN_PREPARACION, 422)
            $estadoAnterior = $unit->estado()->value();

            if ($estadoAnterior !== StockState::EN_PREPARACION) {
                throw InventoryException::unitNotPreparedForLoad($unit->id(), $estadoAnterior);
            }

            // 4. Su picking debe estar COMPLETADA o VERIFICADA (422)
            $picking = $this->pickings->findLatestByUnidad($unit->id());

            if ($picking === null) {
                throw InventoryException::unitPickingNotFinished($unit->id(), 'SIN_PICKING');
            }

            if (!in_array($picking->estado(), [
                PickingOperation::ESTADO_COMPLETADA,
                PickingOperation::ESTADO_VERIFICADA,
            ], true)) {
                throw InventoryException::unitPickingNotFinished($unit->id(), $picking->estado());
            }

            // 5. Debe estar en una zona de preparación (tipo PICKING, 422)
            $zona = $unit->ubicacionId() !== null
                ? $this->locations->findById($unit->ubicacionId())
                : null;

            if ($zona === null || !$zona->tipo()->isPicking()) {
                throw InventoryException::unitNotInPickingZone($unit->id());
            }
            if (!$zona->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($zona->tenantId(), $tenantId);
            }
            $origenes[$zona->id()] = true;

            // 6. Cantidad exacta de la unidad (no se divide: 422, ver hueco)
            if ($entrada['cantidad'] !== null && abs($entrada['cantidad'] - $unit->cantidad()) > 0.0001) {
                throw InventoryException::unitQuantityMismatch($unit->id(), $unit->cantidad(), $entrada['cantidad']);
            }

            // 7. Transición válida en la matriz §4: EN_PREPARACION → CARGADO
            $this->states->assertCanTransition($estadoAnterior, StockState::CARGADO);

            // 8. Capacidad (v1.8.2): NO se libera aquí. La unidad sigue en la
            //    zona de picking; el slot se descuenta al autorizar la salida.
            $slots = (int) ceil($unit->cantidad());

            // 9. Ítem del envío CARGADA
            $item = ShipmentItem::create(
                id: uuid(),
                tenantId: $tenantId,
                shipmentId: $shipment->id(),
                unidadFisicaId: $unit->id(),
                cantidad: $unit->cantidad(),
                estado: ShipmentItem::ESTADO_CARGADA
            );
            $this->shipmentItems->save($item);
            $items[] = $item;

            // 10. Ledger (R1): reservada → en_transito (disponible intacto)
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
                'cantidad_reservada' => max(0.0, $ledger->cantidadReservada() - $unit->cantidad()),
                'cantidad_en_transito' => $ledger->cantidadEnTransito() + $unit->cantidad(),
                'estado_actual' => StockState::CARGADO,
            ]);
            $this->ledgers->update($ledger);

            // 11. Reserva activa de la unidad: cumplida al cargarla (evita que
            //     E7 intente devolver a DISPONIBLE una unidad ya CARGADA)
            $reserva = $this->reservations->findActivaByUnidad($unit->id());

            if ($reserva !== null) {
                $reserva->completar();
                $this->reservations->update($reserva);
            }

            // 12. Unidad CARGADO (misma ubicación hasta autorizar la salida)
            $unit->moverA($zona->id(), StockState::cargado());
            $this->stockUnits->update($unit);
            $unidades[] = $unit;

            // 13. Evento CARGADO (append-only, con hash de integridad)
            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_CARGADO,
                descripcion: 'Carga en vehículo (CU-005)',
                datosOperacion: [
                    'shipment_id' => $shipment->id(),
                    'shipment_item_id' => $item->id(),
                    'id_lote_picking' => $picking->idLotePicking(),
                    'ubicacion_id' => $zona->id(),
                    'cantidad' => $unit->cantidad(),
                    'slots' => $slots,
                    'capacidad_liberada' => false,
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::CARGADO
            );
            $this->events->save($event);
            $eventos[] = $event;
        }

        // 6. Origen del envío (v1.8.3): obligatorio. Si el cliente no envió
        //    `origen_ubicacion_id` se deriva de la ubicación de las unidades
        //    cargadas; si no hay una única ubicación posible → 422 (y la
        //    transacción revierte envío, ítems y unidades).
        $origenActual = $shipment->origenUbicacionId();

        if ($origenActual === null || trim($origenActual) === '') {
            $shipment->definirOrigen($this->resolveOrigen($origenes));
            $this->shipments->update($shipment);
        }

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Unidades cargadas correctamente.',
                'shipment' => $shipment->toPublicArray(),
                'items' => array_map(
                    static fn(ShipmentItem $item): array => $item->toPublicArray(),
                    $items
                ),
                'unidades' => array_map(
                    static fn($unit): array => [
                        'unidad_fisica_id' => $unit->id(),
                        'codigo_barcode' => $unit->codigoBarcode(),
                        'estado' => $unit->estado()->value(),
                    ],
                    $unidades
                ),
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
     * Ubicación de origen del envío a partir de las zonas de preparación de
     * las unidades cargadas (v1.8.3): si todas comparten una sola zona se
     * usa esa; con varias (o ninguna) no se puede determinar → 422.
     *
     * @param array<string, true> $origenes
     *
     * @throws InventoryException 422 con field `origen_ubicacion_id`
     */
    private function resolveOrigen(array $origenes): string
    {
        if (count($origenes) !== 1) {
            throw InventoryException::shipmentOriginLocationMissing();
        }

        return (string) array_key_first($origenes);
    }

    private function resolveExistingShipment(string $tenantId, string $shipmentId): Shipment
    {
        $shipment = $this->shipments->findById($shipmentId);

        if ($shipment === null) {
            throw InventoryException::shipmentNotFound($shipmentId);
        }
        if (!$shipment->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($shipment->tenantId(), $tenantId);
        }

        if ($shipment->estado() !== Shipment::ESTADO_PREPARACION) {
            throw InventoryException::shipmentNotInPreparation($shipment->id(), $shipment->estado());
        }

        return $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
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

        $shipmentId = trim((string) ($input['shipment_id'] ?? ''));
        $shipmentData = is_array($input['shipment'] ?? null) ? $input['shipment'] : [];

        if ($shipmentId === '' && $shipmentData === []) {
            // Se permite crear un shipment mínimo: los datos del destinatario
            // se validan al autorizar la salida (spec CU-006).
            $shipmentData = [];
        }

        return [
            'unidades' => $normalizadas,
            'shipment_id' => $shipmentId,
            'shipment' => $this->normalizeShipmentData($shipmentData),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeShipmentData(array $data): array
    {
        $campos = [
            'referencia_externa' => 255,
            'tipo' => 20,
            'origen_ubicacion_id' => 36,
            'destinatario_id' => 255,
            'destinatario_nombre' => 255,
            'destinatario_contacto' => 255,
            'conductor_nombre' => 255,
            'conductor_contacto' => 255,
            'vehiculo_referencia' => 100,
            'hora_entrega_esperada' => 40,
        ];

        $normalizado = [];

        foreach ($campos as $campo => $max) {
            $valor = $data[$campo] ?? null;

            if ($valor === null || $valor === '') {
                continue;
            }

            $texto = trim((string) $valor);

            if ($texto === '') {
                continue;
            }

            if (mb_strlen($texto) > $max) {
                throw InventoryException::fieldMaxLength($campo, $max);
            }

            // v1.8.0 (hueco v1.7.0): la columna es TIMESTAMP y el cliente
            // envía el valor de un input `datetime-local` (`YYYY-MM-DDTHH:mm`),
            // que MySQL sólo acepta en algunos modos. Se valida el formato y
            // se normaliza a `Y-m-d H:i:s` (UTC) antes de persistir.
            $normalizado[$campo] = $campo === 'hora_entrega_esperada'
                ? $this->normalizeExpectedDeliveryTime($texto)
                : $texto;
        }

        foreach (['destino_direccion', 'observaciones'] as $campo) {
            $valor = $data[$campo] ?? null;

            if ($valor === null || trim((string) $valor) === '') {
                continue;
            }

            $texto = trim((string) $valor);

            if (mb_strlen($texto) > 2000) {
                throw InventoryException::fieldMaxLength($campo, 2000);
            }

            $normalizado[$campo] = $texto;
        }

        return $normalizado;
    }

    /**
     * Normaliza `hora_entrega_esperada` a `Y-m-d H:i:s` (UTC).
     *
     * Hueco declarado en v1.7.0: el backend sólo comprobaba la longitud y
     * la columna es TIMESTAMP, de modo que cualquier texto pasaba y MySQL
     * podía rechazarlo (o interpretarlo) al escribir. Ahora se exige un
     * formato de fecha/hora reconocible.
     *
     * @throws InventoryException 400 con field `hora_entrega_esperada`
     */
    private function normalizeExpectedDeliveryTime(string $value): string
    {
        $formats = [
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($parsed === false) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();

            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        throw InventoryException::invalidField(
            'hora_entrega_esperada',
            'Debe ser una fecha válida (ej. 2026-10-05 o 2026-10-05T12:30:00).'
        );
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
}
