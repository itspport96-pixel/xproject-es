<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Delivery;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Entities\ShipmentItem;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\DeliveryRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
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
 * RegisterDeliveryFailureUseCase - CU-008: REGISTRAR FALLO DE ENTREGA
 *
 * Registra que las unidades de UN envío EN_RUTA no pudieron entregarse y las
 * devuelve al almacén, en UNA sola transacción (R5, idempotencia R7):
 *
 *  1. El envío existe en el tenant (404/403) y está EN_RUTA (409); si ya no
 *     le queda ninguna unidad EN_TRANSITO → 409 (nada que registrar).
 *  2. `motivo` obligatorio: NO_DISPONIBLE | RECHAZO | ACCESO_DENEGADO | OTRO
 *     (spec CU-008). `unidades` opcional: por defecto TODAS las no entregadas.
 *  3. Destino: `ubicacion_destino_id` si se indica; en otro caso la ubicación
 *     de ORIGEN del envío (spec CU-008/E3). Sin origen ni destino → 422.
 *  4. Por cada unidad: Delivery RECHAZADA + ítem DEVUELTA + unidad DEVUELTO
 *     (EN_TRANSITO → DEVUELTO, matriz §4) + reubicación con capacidad
 *     validada + evento DEVUELTO con hash + ledger `cantidad_en_transito` -=
 *     cantidad (R1 intacto).
 *  5. UNA incidencia ENTREGA_RECHAZADA por operación con el motivo y las
 *     unidades afectadas (spec 3.17).
 *  6. Envío → FALLO_ENTREGA cuando ya no queda ninguna unidad EN_TRANSITO
 *     (regla documentada en el informe: entrega parcial previa → ENTREGADO).
 *
 * Este use case expone además `applyFailure()`, que aplica los pasos 4 y 5
 * DENTRO de la transacción de otro use case (CU-007 entrega mixta), sin abrir
 * transacción ni idempotencia propias.
 */
final class RegisterDeliveryFailureUseCase
{
    public const OPERACION = 'inventory.delivery.failure';

    public const MOTIVO_NO_DISPONIBLE = 'NO_DISPONIBLE';
    public const MOTIVO_RECHAZO = 'RECHAZO';
    public const MOTIVO_ACCESO_DENEGADO = 'ACCESO_DENEGADO';
    public const MOTIVO_OTRO = 'OTRO';

    public const MOTIVOS_FALLO = [
        self::MOTIVO_NO_DISPONIBLE,
        self::MOTIVO_RECHAZO,
        self::MOTIVO_ACCESO_DENEGADO,
        self::MOTIVO_OTRO,
    ];

    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly ShipmentItemRepositoryInterface $shipmentItems,
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario que registra el fallo (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($input);

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id_reporta');
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
                        return $replay + ['_replay' => true];
                    }
                }

                $response = $this->registerFailure($tenantId, $payload, (string) $actorId);

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
    // FALLO DE ENTREGA (corazón del CU-008)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function registerFailure(string $tenantId, array $payload, string $actorId): array
    {
        $shipment = $this->loadShipment($tenantId, $payload['shipment_id']);

        if ($shipment->estado() !== Shipment::ESTADO_EN_RUTA) {
            throw InventoryException::shipmentNotOnRoute($shipment->id(), $shipment->estado());
        }

        $items = $this->shipmentItems->findByShipment($shipment->id());
        $pendientes = array_values(array_filter(
            $items,
            static fn(ShipmentItem $item): bool => $item->estado() === ShipmentItem::ESTADO_EN_TRANSITO
        ));

        if ($pendientes === []) {
            throw InventoryException::shipmentAllUnitsDelivered($shipment->id());
        }

        $seleccionados = $this->selectItems($pendientes, $payload['unidades'], $shipment->id());
        $destino = $this->resolveDestination($payload['ubicacion_destino_id'], $shipment);

        $resultado = $this->applyFailure(
            $shipment,
            $seleccionados,
            $payload['motivo'],
            $payload['observaciones'],
            $destino,
            $actorId
        );

        // Regla de decisión (informe): si el envío ya no tiene ninguna
        // unidad EN_TRANSITO → FALLO_ENTREGA; si había entregas previas se
        // mantiene ENTREGADO (el confirm lo fija él mismo).
        $quedanEnTransito = array_filter(
            $this->shipmentItems->findByShipment($shipment->id()),
            static fn(ShipmentItem $item): bool => $item->estado() === ShipmentItem::ESTADO_EN_TRANSITO
        );

        if ($quedanEnTransito === []) {
            $shipment->marcarFalloEntrega();
            $this->shipments->update($shipment);
        }

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Fallo de entrega registrado correctamente.',
                'shipment' => $shipment->toPublicArray(),
                'motivo' => $payload['motivo'],
                'ubicacion_destino' => [
                    'ubicacion_id' => $destino->id(),
                    'codigo' => $destino->codigo()->value(),
                ],
                'entregas' => array_map(
                    static fn(Delivery $delivery): array => $delivery->toPublicArray(),
                    $resultado['entregas']
                ),
                'unidades' => $resultado['unidades'],
                'incidencia' => $resultado['incidencia'],
                'eventos' => array_map(
                    static fn(StockEvent $event): array => [
                        'id' => $event->id(),
                        'tipo_evento' => $event->tipoEvento(),
                        'hash_integridad' => $event->hashIntegridad(),
                    ],
                    $resultado['eventos']
                ),
            ],
        ];
    }

    /**
     * Aplica el fallo (pasos 4 y 5) a los ítems indicados DENTRO de la
     * transacción del llamante. No modifica el estado del envío: eso lo
     * decide cada caller.
     *
     * @param array<int, ShipmentItem>        $items  ítems EN_TRANSITO afectados
     * @param Location                        $destino ubicación de retorno (validada por el caller)
     *
     * @return array{entregas: array<int, Delivery>, unidades: array<int, array<string, mixed>>, incidencia: array<string, mixed>|null, eventos: array<int, StockEvent>}
     */
    public function applyFailure(
        Shipment $shipment,
        array $items,
        string $motivo,
        ?string $observaciones,
        ?Location $destino,
        string $actorId
    ): array {
        if (!in_array($motivo, self::MOTIVOS_FALLO, true)) {
            throw InventoryException::invalidField('motivo', 'Motivo de fallo desconocido: \'' . $motivo . '\'.');
        }

        $tenantId = $shipment->tenantId();

        if ($destino === null) {
            $destino = $this->resolveDestination(null, $shipment);
        }

        $unidades = [];
        $entregas = [];
        $eventos = [];

        foreach ($items as $item) {
            $unitId = $item->unidadFisicaId();
            $unit = $this->stockUnits->findById($unitId);

            if ($unit === null) {
                throw InventoryException::unitNotFound($unitId);
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }

            $estadoAnterior = $unit->estado()->value();

            if ($estadoAnterior !== StockState::EN_TRANSITO) {
                throw InventoryException::unitNotInTransitForDelivery($unitId, $estadoAnterior);
            }

            $this->states->assertCanTransition($estadoAnterior, StockState::DEVUELTO);

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
                'cantidad_en_transito' => max(0.0, $ledger->cantidadEnTransito() - $unit->cantidad()),
                'estado_actual' => StockState::DEVUELTO,
            ]);
            $this->ledgers->update($ledger);

            // Sale de la ubicación virtual TRANSITO y entra en el destino
            // (v1.8.2: el origen sólo descuenta slots si es físico y con
            // `ceil(cantidad)`, igual que el resto de flujos)
            $slots = (int) ceil($unit->cantidad());
            $origenId = $unit->ubicacionId();
            $unit->moverA($destino->id(), StockState::fromString(StockState::DEVUELTO));
            $this->stockUnits->update($unit);
            $this->releaseOriginSlot($tenantId, $origenId, $slots);

            $destino->incrementarCapacidadUsada($slots);
            $this->locations->update($destino);

            $item->cambiarEstado(ShipmentItem::ESTADO_DEVUELTA);
            $this->shipmentItems->update($item);

            $delivery = Delivery::create(
                id: uuid(),
                tenantId: $tenantId,
                shipmentId: $shipment->id(),
                unidadFisicaId: $unit->id(),
                cantidad: $unit->cantidad(),
                estado: Delivery::ESTADO_RECHAZADA,
                destinatarioReal: null,
                firmaComprobante: null,
                observaciones: $observaciones,
                actorIdEntrega: $actorId,
                timestampEntrega: new \DateTimeImmutable()
            );
            $this->deliveries->save($delivery);

            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_DEVUELTO,
                descripcion: 'Fallo de entrega registrado (CU-008)',
                datosOperacion: [
                    'shipment_id' => $shipment->id(),
                    'shipment_item_id' => $item->id(),
                    'delivery_id' => $delivery->id(),
                    'motivo' => $motivo,
                    'ubicacion_origen_id' => $origenId,
                    'ubicacion_destino_id' => $destino->id(),
                    'cantidad' => $unit->cantidad(),
                    'slots' => $slots,
                    'actor_id' => $actorId,
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::DEVUELTO
            );
            $this->events->save($event);

            $unidades[] = [
                'unidad_fisica_id' => $unit->id(),
                'estado' => StockState::DEVUELTO,
                'ubicacion_id' => $destino->id(),
                'cantidad' => $unit->cantidad(),
            ];
            $entregas[] = $delivery;
            $eventos[] = $event;
        }

        // Una incidencia por operación (spec 3.17 / CU-008)
        $incidencia = null;

        if ($entregas !== []) {
            $descripcion = sprintf(
                'Fallo de entrega (%s): %d unidad(es) no entregada(s).',
                $motivo,
                count($entregas)
            );

            if ($observaciones !== null) {
                $descripcion .= ' ' . $observaciones;
            }

            $incidencia = Incident::create(
                id: uuid(),
                tenantId: $tenantId,
                tipo: Incident::TIPO_ENTREGA_RECHAZADA,
                descripcion: $descripcion,
                unidadFisicaId: null,
                ubicacionId: $destino->id(),
                actorIdReporta: $actorId
            );
            $this->incidents->save($incidencia);
        }

        return [
            'entregas' => $entregas,
            'unidades' => $unidades,
            'incidencia' => $incidencia?->toPublicArray(),
            'eventos' => $eventos,
        ];
    }

    // ====================================================================
    // UTILIDADES DE DOMINIO
    // ====================================================================

    private function loadShipment(string $tenantId, string $shipmentId): Shipment
    {
        $shipment = $this->shipments->findById($shipmentId);

        if ($shipment === null) {
            throw InventoryException::shipmentNotFound($shipmentId);
        }
        if (!$shipment->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($shipment->tenantId(), $tenantId);
        }

        return $shipment;
    }

    /**
     * @param array<int, ShipmentItem> $pendientes
     * @param array<int, string>       $seleccionadas unidad_fisica_id indicadas (vacío → todas)
     *
     * @return array<int, ShipmentItem>
     */
    private function selectItems(array $pendientes, array $seleccionadas, string $shipmentId): array
    {
        if ($seleccionadas === []) {
            return $pendientes;
        }

        $porUnidad = [];

        foreach ($pendientes as $item) {
            $porUnidad[$item->unidadFisicaId()] = $item;
        }

        $elegidos = [];

        foreach ($seleccionadas as $unitId) {
            $item = $porUnidad[$unitId] ?? null;

            if ($item === null) {
                throw InventoryException::unitNotInShipment($unitId, $shipmentId);
            }

            $elegidos[] = $item;
        }

        return $elegidos;
    }

    /**
     * Ubicación de retorno: la indicada, o la de origen del envío (spec
     * CU-008/E3). Siempre tenant-scoped, activa y no virtual.
     */
    private function resolveDestination(?string $ubicacionDestinoId, Shipment $shipment): Location
    {
        $tenantId = $shipment->tenantId();
        $ubicacionId = $ubicacionDestinoId;

        if ($ubicacionId === null || $ubicacionId === '') {
            $ubicacionId = $shipment->origenUbicacionId();
        }

        if ($ubicacionId === null || $ubicacionId === '') {
            throw InventoryException::deliveryReturnLocationMissing();
        }

        $location = $this->locations->findById($ubicacionId);

        if ($location === null) {
            throw InventoryException::locationNotFound($ubicacionId);
        }
        if (!$location->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($location->tenantId(), $tenantId);
        }
        if (!$location->isActive()) {
            throw InventoryException::locationInactive($location->codigo()->value());
        }
        if ($location->tipo()->isVirtual()) {
            throw InventoryException::locationIsVirtual($location->codigo()->value(), 'destino');
        }

        return $location;
    }

    /**
     * Libera los slots de la ubicación FÍSICA de origen (v1.8.2: `ceil
     * (cantidad)` en vez de 1 hardcodeado, y sin tocar las virtuales: la
     * ubicación de tránsito no lleva contador).
     */
    private function releaseOriginSlot(string $tenantId, ?string $ubicacionId, int $slots): void
    {
        if ($ubicacionId === null) {
            return;
        }

        $location = $this->locations->findById($ubicacionId);

        if ($location === null || !$location->belongsToTenant($tenantId) || $location->tipo()->isVirtual()) {
            return;
        }

        $location->decrementarCapacidadUsada($slots);
        $this->locations->update($location);
    }

    // ====================================================================
    // NORMALIZACIÓN Y VALIDACIÓN DE ENTRADA
    // ====================================================================

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $shipmentId = trim((string) ($input['shipment_id'] ?? ''));

        if ($shipmentId === '') {
            throw InventoryException::requiredField('shipment_id');
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));

        if ($motivo === '') {
            throw InventoryException::requiredField('motivo');
        }

        if (!in_array($motivo, self::MOTIVOS_FALLO, true)) {
            throw InventoryException::invalidField(
                'motivo',
                'Motivo desconocido: \'' . $motivo . '\'. Válidos: ' . implode(', ', self::MOTIVOS_FALLO) . '.'
            );
        }

        $unidades = $input['unidades'] ?? [];
        $ids = [];

        if ($unidades !== null && $unidades !== []) {
            if (!is_array($unidades)) {
                throw InventoryException::invalidField('unidades', 'Debe ser una lista de unidades.');
            }

            foreach ($unidades as $indice => $entrada) {
                $unitId = is_array($entrada)
                    ? trim((string) ($entrada['unidad_fisica_id'] ?? ''))
                    : trim((string) $entrada);

                if ($unitId === '') {
                    throw InventoryException::requiredField('unidades[' . $indice . '].unidad_fisica_id');
                }

                if (in_array($unitId, $ids, true)) {
                    throw InventoryException::duplicateUnitsInRequest($unitId);
                }

                $ids[] = $unitId;
            }
        }

        $destino = trim((string) ($input['ubicacion_destino_id'] ?? ''));

        return [
            'shipment_id' => $shipmentId,
            'motivo' => $motivo,
            'unidades' => $ids,
            'ubicacion_destino_id' => $destino === '' ? null : $destino,
            'observaciones' => $this->text($input['observaciones'] ?? null, 'observaciones'),
        ];
    }

    private function text(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw InventoryException::invalidField($field, 'Debe ser un texto.');
        }

        $texto = trim((string) $value);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > self::MAX_TEXT) {
            throw InventoryException::fieldMaxLength($field, self::MAX_TEXT);
        }

        return $texto;
    }
}
