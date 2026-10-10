<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Entities\ShipmentItem;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentItemRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\LocationType;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * AuthorizeShipmentUseCase - CU-006: AUTORIZAR SALIDA
 *
 * Autoriza la salida de UN envío en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): Idempotency-Key obligatoria en HTTP.
 *  2. El envío existe en el tenant (404/403) y está PREPARACION (409); tiene
 *     ítems y TODOS están CARGADAS (422 si falta alguno); el destinatario es
 *     válido (422).
 *  3. Ubicación virtual TRANSITO del tenant (404 si no existe: hay que
 *     crearla con el endpoint existente de ubicaciones).
 *  4. Por cada ítem: la unidad está CARGAD (422), la transición CARGADO →
 *     EN_TRANSITO está en la matriz §4 y se mueve a la ubicación TRANSITO.
 *  5. Ledger: `cantidad_en_transito` YA incluye la cantidad desde la carga
 *     (CU-005), así que aquí sólo se refresca `estado_actual` (R1 intacto).
 *  6. Capacidad (v1.8.2): al salir de la ubicación física (zona de picking)
 *     hacia la virtual de tránsito se descuentan `ceil(cantidad)` slots del
 *     origen. Las ubicaciones virtuales no llevan contador y la carga ya no
 *     libera nada (antes o no se liberaba aquí o se liberaba dos veces).
 *  7. Envío → EN_RUTA con `hora_salida` (UTC) y `actor_id_autoriza_salida`;
 *     ítems → EN_TRANSITO; evento EN_TRANSITO con hash por unidad.
 *  8. Cualquier error → ROLLBACK total (la key de idempotencia se revierte).
 *
 * Eventos (v1.8.2): `cantidad_anterior`/`cantidad_posterior` son la CANTIDAD
 * DE LA UNIDAD; reservado/disponible/en tránsito van en `datos_operacion`.
 *
 * Input:
 *  - shipment_id (obligatorio)
 */
final class AuthorizeShipmentUseCase
{
    public const OPERACION = 'inventory.shipment.authorize';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly ShipmentItemRepositoryInterface $shipmentItems,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario que autoriza (claims)
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($input);

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id_autoriza_salida');
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

                $response = $this->authorize($tenantId, $payload, (string) $actorId);

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
    // AUTORIZACIÓN (corazón del CU-006)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function authorize(string $tenantId, array $payload, string $actorId): array
    {
        // 1. Envío existente en PREPARACION (tenant-scoped)
        $shipment = $this->shipments->findById($payload['shipment_id']);

        if ($shipment === null) {
            throw InventoryException::shipmentNotFound($payload['shipment_id']);
        }
        if (!$shipment->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($shipment->tenantId(), $tenantId);
        }
        if ($shipment->estado() !== Shipment::ESTADO_PREPARACION) {
            throw InventoryException::shipmentNotInPreparation($shipment->id(), $shipment->estado());
        }

        // 2. Cargado por completo: ítems presentes y todos CARGADAS
        $items = $this->shipmentItems->findByShipment($shipment->id());

        if ($items === []) {
            throw InventoryException::shipmentNotFullyLoaded($shipment->id());
        }

        foreach ($items as $item) {
            if ($item->estado() !== ShipmentItem::ESTADO_CARGADA) {
                throw InventoryException::shipmentNotFullyLoaded($shipment->id());
            }
        }

        // 3. Destinatario válido (spec CU-006)
        if (!$shipment->tieneDestinatarioValido()) {
            throw InventoryException::recipientInvalid();
        }

        // 4. Ubicación virtual TRANSITO del tenant (404 si no existe)
        $transito = $this->resolveTransitLocation($tenantId);

        $unidades = [];
        $eventos = [];

        foreach ($items as $item) {
            // 5. Unidad física (tenant-scoped: de otro tenant → 404)
            $unit = $this->stockUnits->findById($item->unidadFisicaId());

            if ($unit === null) {
                throw InventoryException::unitNotFound($item->unidadFisicaId());
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }

            // 6. Debe estar CARGAD y admitir la transición CARGADO → EN_TRANSITO
            $estadoAnterior = $unit->estado()->value();

            if ($estadoAnterior !== StockState::CARGADO) {
                throw InventoryException::unitNotLoaded($unit->id(), $estadoAnterior);
            }

            $this->states->assertCanTransition($estadoAnterior, StockState::EN_TRANSITO);

            // 7. Ledger (R1): la cantidad ya está en tránsito desde la carga;
            //    sólo se refresca el estado actual
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
            $ledger->actualizar(['estado_actual' => StockState::EN_TRANSITO]);
            $this->ledgers->update($ledger);

            // 8. Unidad en la ubicación virtual de tránsito; antes de moverla
            //    se anota su ubicación física para descontar sus slots (v1.8.2)
            $slots = (int) ceil($unit->cantidad());
            $origenId = $unit->ubicacionId();

            $unit->moverA($transito->id(), StockState::enTransito());
            $this->stockUnits->update($unit);
            $unidades[] = $unit;

            $this->releaseOriginSlot($tenantId, $origenId, $slots, $transito->id());

            // 9. Ítem → EN_TRANSITO
            $item->cambiarEstado(ShipmentItem::ESTADO_EN_TRANSITO);
            $this->shipmentItems->update($item);

            // 10. Evento EN_TRANSITO (append-only, con hash de integridad)
            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_EN_TRANSITO,
                descripcion: 'Salida autorizada (CU-006)',
                datosOperacion: [
                    'shipment_id' => $shipment->id(),
                    'shipment_item_id' => $item->id(),
                    'ubicacion_transito_id' => $transito->id(),
                    'ubicacion_transito_codigo' => $transito->codigo()->value(),
                    'ubicacion_origen_id' => $origenId,
                    'cantidad' => $unit->cantidad(),
                    'slots_liberados' => $slots,
                    'actor_id_autoriza_salida' => $actorId,
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::EN_TRANSITO
            );
            $this->events->save($event);
            $eventos[] = $event;
        }

        // 11. Envío → EN_RUTA con hora de salida (UTC) y supervisor
        $shipment->autorizarSalida($actorId);
        $this->shipments->update($shipment);

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Salida autorizada correctamente.',
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
                'ubicacion_transito' => [
                    'ubicacion_id' => $transito->id(),
                    'codigo' => $transito->codigo()->value(),
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

    /**
     * Primera ubicación virtual TRANSITO activa del tenant (spec CU-006:
     * "mueve las unidades a la ubicación virtual de tránsito").
     *
     * @throws InventoryException 404 si el tenant no tiene ninguna
     */
    private function resolveTransitLocation(string $tenantId): \App\Modules\Inventory\Domain\Entities\Location
    {
        foreach ($this->locations->findByTipo(LocationType::TRANSITO) as $location) {
            if ($location->belongsToTenant($tenantId) && $location->isActive()) {
                return $location;
            }
        }

        throw InventoryException::transitLocationMissing();
    }

    /**
     * Descuenta los slots de la ubicación FÍSICA que ocupaba la unidad al
     * pasar a la virtual de tránsito (v1.8.2).
     *
     * No toca nada si: no había origen, el origen ES la ubicación de
     * tránsito, no pertenece al tenant o es VIRTUAL (las ubicaciones
     * virtuales no llevan contador de capacidad).
     */
    private function releaseOriginSlot(string $tenantId, ?string $ubicacionId, int $slots, string $transitoId): void
    {
        if ($ubicacionId === null || $ubicacionId === $transitoId) {
            return;
        }

        $location = $this->locations->findById($ubicacionId);

        if ($location === null || !$location->belongsToTenant($tenantId) || $location->tipo()->isVirtual()) {
            return;
        }

        $location->decrementarCapacidadUsada($slots);
        $this->locations->update($location);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $shipmentId = trim((string) ($input['shipment_id'] ?? ''));

        if ($shipmentId === '') {
            throw InventoryException::requiredField('shipment_id');
        }

        return [
            'shipment_id' => $shipmentId,
        ];
    }
}
