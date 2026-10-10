<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Entities\ReturnOperation;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\DeliveryRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReturnOperationRepositoryInterface;
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
 * RegisterReturnUseCase - CU-009: REGISTRAR DEVOLUCIÓN
 *
 * Devuelve UNA unidad física al inventario (tras un fallo de entrega o una
 * entrega confirmada que el cliente devuelve), en UNA sola transacción (R5)
 * con idempotencia obligatoria (R7):
 *
 *  1. La unidad existe en el tenant (404/403) y está DEVUELTO o ENTREGADO
 *     (422 `unitNotReturnable`): cualquier otro estado no admite devolución
 *     (matriz §4).
 *  2. `motivo` (CAMBIO_CLIENTE | DAÑO | FALLO_ENTREGA | NO_APLICA | OTRO) y
 *     `estado_al_devolver` (OK | DAÑADO | DETERIORADO | PERDIDO) obligatorios.
 *  3. Si la unidad está ENTREGADO primero pasa a DEVUELTO con su evento en la
 *     MISMA transición (matriz §4: ENTREGADO → DEVUELTO) y el ítem del envío
 *     asociado pasa de ENTREGADA a DEVUELTA.
 *  4. Destino según `estado_al_devolver`:
 *       - OK → DISPONIBLE en `ubicacion_destino_id`; si no se indica, la
 *         ubicación actual de la unidad o la de origen de su último envío;
 *         si no hay ninguna → 422.
 *       - DAÑADO / DETERIORADO → DAÑADO en la zona DAÑADOS del tenant
 *         (404 `damagedZoneMissing` si no existe) + incidencia DAÑO.
 *         `ubicacion_destino_id` se ignora en este caso (decisión).
 *       - PERDIDO → PERDIDO sin ubicación + incidencia PERDIDA.
 *  5. Capacidad validada (422 `field: capacidad`): el destino gana slots y el
 *     origen físico los pierde; las ubicaciones virtuales no llevan contador.
 *  6. ReturnOperation + StockEvent (DISPONIBLE | DAÑADO | PERDIDO) con hash +
 *     ledger `estado_actual` (cantidades intactas, R1 intacto).
 *
 * Input:
 *  - unidad_fisica_id (obligatorio)
 *  - motivo (obligatorio), estado_al_devolver (obligatorio)
 *  - ubicacion_destino_id? , observaciones? , delivery_id?
 */
final class RegisterReturnUseCase
{
    public const OPERACION = 'inventory.return.register';

    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly ReturnOperationRepositoryInterface $returns,
        private readonly IncidentRepositoryInterface $incidents,
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
     * @param string|null          $actorId Usuario que recibe la devolución (claims)
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
            throw InventoryException::requiredField('actor_id_recibe_devolucion');
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

                $response = $this->registerReturn($tenantId, $payload, (string) $actorId);

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
    // DEVOLUCIÓN (corazón del CU-009)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function registerReturn(string $tenantId, array $payload, string $actorId): array
    {
        $unit = $this->stockUnits->findById($payload['unidad_fisica_id']);

        if ($unit === null) {
            throw InventoryException::unitNotFound($payload['unidad_fisica_id']);
        }
        if (!$unit->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
        }

        $estadoActual = $unit->estado()->value();

        if (!in_array($estadoActual, [StockState::DEVUELTO, StockState::ENTREGADO], true)) {
            throw InventoryException::unitNotReturnable($unit->id(), $estadoActual);
        }

        $estadoFinal = $this->estadoUnidadResultante($payload['estado_al_devolver']);
        $destino = $this->resolveDestination($tenantId, $payload, $unit->ubicacionId());
        $origenId = $unit->ubicacionId();
        $eventos = [];

        // 1. ENTREGADO → DEVUELTO (paso previo en la MISMA transacción)
        if ($estadoActual === StockState::ENTREGADO) {
            $this->states->assertCanTransition($estadoActual, StockState::DEVUELTO);

            $ledger = $this->ledgerFor($tenantId, $unit->id(), $unit->cantidad(), $estadoActual);
            $ledgerAntes = $ledger->valoresOperacion();
            $ledger->actualizar(['estado_actual' => StockState::DEVUELTO]);
            $this->ledgers->update($ledger);

            $unit->moverA($origenId, StockState::fromString(StockState::DEVUELTO));
            $this->stockUnits->update($unit);

            $eventos[] = $this->storeEvent(
                $tenantId,
                $unit->id(),
                StockEvent::TIPO_DEVUELTO,
                'Devolución: unidad entregada vuelve a DEVUELTO (CU-009)',
                [
                    'unidad_fisica_id' => $unit->id(),
                    'estado_al_devolver' => $payload['estado_al_devolver'],
                    'actor_id_recibe_devolucion' => $actorId,
                    'paso' => 'intermedio',
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                $unit->cantidad(),
                $unit->cantidad(),
                $estadoActual,
                StockState::DEVUELTO
            );

            $this->revertShipmentItem($tenantId, $unit->id());
            $estadoActual = StockState::DEVUELTO;
        }

        // 2. DEVUELTO → estado final con la capacidad del destino validada
        $this->states->assertCanTransition($estadoActual, $estadoFinal);

        $slots = (int) ceil($unit->cantidad());

        if ($destino !== null && $destino->id() !== $origenId) {
            $destino->incrementarCapacidadUsada($slots);
            $this->locations->update($destino);
        }

        if ($origenId !== null && ($destino === null || $destino->id() !== $origenId)) {
            $this->releaseOriginSlot($tenantId, $origenId, $slots);
        }

        $unit->moverA($destino?->id(), StockState::fromString($estadoFinal));
        $this->stockUnits->update($unit);

        // 3. Ledger (R1): sólo cambia el estado actual
        $ledger = $this->ledgerFor($tenantId, $unit->id(), $unit->cantidad(), $estadoFinal);
        $ledgerAntes = $ledger->valoresOperacion();
        $ledger->actualizar(['estado_actual' => $estadoFinal]);
        $this->ledgers->update($ledger);

        // 4. Fila de devolución (spec §3.16)
        $devolucion = ReturnOperation::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            cantidad: $unit->cantidad(),
            motivo: $payload['motivo'],
            estadoAlDevolver: $payload['estado_al_devolver'],
            deliveryId: $payload['delivery_id'] ?? $this->latestDeliveryId($unit->id()),
            descripcionMotivo: $payload['observaciones'],
            ubicacionDestinoId: $destino?->id(),
            actorIdRecibeDevolucion: $actorId,
            timestampDevolucion: new \DateTimeImmutable()
        );
        $this->returns->save($devolucion);

        // 5. Incidencia para DAÑO / PÉRDIDA (spec 3.17)
        $incidencia = $this->createIncident(
            $tenantId,
            $payload,
            $unit->id(),
            $origenId,
            $destino,
            $actorId
        );

        // 6. Evento final con hash de integridad
        $tipoEvento = match ($payload['estado_al_devolver']) {
            ReturnOperation::ESTADO_OK => StockEvent::TIPO_DISPONIBLE,
            ReturnOperation::ESTADO_PERDIDO => StockEvent::TIPO_PERDIDO,
            default => StockEvent::TIPO_DANADO,
        };

        $eventos[] = $this->storeEvent(
            $tenantId,
            $unit->id(),
            $tipoEvento,
            'Devolución registrada (CU-009)',
            [
                'return_id' => $devolucion->id(),
                'unidad_fisica_id' => $unit->id(),
                'motivo' => $payload['motivo'],
                'estado_al_devolver' => $payload['estado_al_devolver'],
                'estado_unidad' => $estadoFinal,
                'ubicacion_destino_id' => $destino?->id(),
                'incidencia_id' => $incidencia?->id(),
                'actor_id_recibe_devolucion' => $actorId,
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de la
            // unidad (el disponible va en datos_operacion).
            $unit->cantidad(),
            $unit->cantidad(),
            $estadoActual,
            $estadoFinal
        );

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Devolución registrada correctamente.',
                'devolucion' => $devolucion->toPublicArray(),
                'unidad' => [
                    'unidad_fisica_id' => $unit->id(),
                    'estado' => $estadoFinal,
                    'ubicacion_id' => $destino?->id(),
                    'cantidad' => $unit->cantidad(),
                ],
                'ubicacion_destino' => $destino !== null ? [
                    'ubicacion_id' => $destino->id(),
                    'codigo' => $destino->codigo()->value(),
                    'tipo' => $destino->tipo()->value(),
                ] : null,
                'incidencia' => $incidencia?->toPublicArray(),
                'ledger' => $ledger->toPublicArray(),
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

    // ====================================================================
    // UTILIDADES DE DOMINIO
    // ====================================================================

    private function estadoUnidadResultante(string $estadoAlDevolver): string
    {
        return match ($estadoAlDevolver) {
            ReturnOperation::ESTADO_OK => StockState::DISPONIBLE,
            ReturnOperation::ESTADO_PERDIDO => StockState::PERDIDO,
            default => StockState::DANADO,
        };
    }

    /**
     * Destino final de la unidad según `estado_al_devolver`.
     */
    private function resolveDestination(string $tenantId, array $payload, ?string $ubicacionActual): ?Location
    {
        if ($payload['estado_al_devolver'] === ReturnOperation::ESTADO_PERDIDO) {
            return null;
        }

        if ($payload['estado_al_devolver'] !== ReturnOperation::ESTADO_OK) {
            return $this->resolveDamagedZone($tenantId);
        }

        $ubicacionId = $payload['ubicacion_destino_id'];

        if ($ubicacionId === null) {
            $ubicacionId = $this->defaultDestination($tenantId, $payload['unidad_fisica_id'], $ubicacionActual);
        }

        if ($ubicacionId === null) {
            throw InventoryException::requiredField('ubicacion_destino_id');
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
     * Por defecto "origen": la ubicación actual de la unidad (si es física);
     * si la unidad está fuera del almacén (ENTREGADO), la de origen del
     * último envío que la entregó.
     */
    private function defaultDestination(string $tenantId, string $unitId, ?string $ubicacionActual): ?string
    {
        if ($ubicacionActual !== null) {
            $actual = $this->locations->findById($ubicacionActual);

            if ($actual !== null && $actual->belongsToTenant($tenantId) && !$actual->tipo()->isVirtual()) {
                return $actual->id();
            }
        }

        $delivery = $this->deliveries->findLatestByUnidad($unitId);

        if ($delivery === null) {
            return null;
        }

        $shipment = $this->shipments->findById($delivery->shipmentId());

        if ($shipment === null || !$shipment->belongsToTenant($tenantId)) {
            return null;
        }

        return $shipment->origenUbicacionId();
    }

    private function resolveDamagedZone(string $tenantId): Location
    {
        foreach ($this->locations->findByTipo(LocationType::DANADOS) as $location) {
            if ($location->belongsToTenant($tenantId) && $location->isActive()) {
                return $location;
            }
        }

        throw InventoryException::damagedZoneMissing();
    }

    private function releaseOriginSlot(string $tenantId, string $ubicacionId, int $slots): void
    {
        $location = $this->locations->findById($ubicacionId);

        if ($location === null || !$location->belongsToTenant($tenantId) || $location->tipo()->isVirtual()) {
            return;
        }

        $location->decrementarCapacidadUsada($slots);
        $this->locations->update($location);
    }

    private function ledgerFor(string $tenantId, string $unitId, float $cantidad, string $estado): \App\Modules\Inventory\Domain\Entities\StockLedger
    {
        $ledger = $this->ledgers->findByUnidadFisica($unitId);

        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(uuid(), $tenantId, $unitId, $cantidad, $estado);
            $this->ledgers->save($ledger);
        }

        return $ledger;
    }

    private function storeEvent(
        string $tenantId,
        string $unitId,
        string $tipoEvento,
        string $descripcion,
        array $datos,
        float $cantidadAnterior,
        float $cantidadPosterior,
        string $estadoAnterior,
        string $estadoPosterior
    ): StockEvent {
        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unitId,
            tipoEvento: $tipoEvento,
            descripcion: $descripcion,
            datosOperacion: $datos,
            cantidadAnterior: $cantidadAnterior,
            cantidadPosterior: $cantidadPosterior,
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior
        );
        $this->events->save($event);

        return $event;
    }

    private function createIncident(
        string $tenantId,
        array $payload,
        string $unitId,
        ?string $origenId,
        ?Location $destino,
        string $actorId
    ): ?Incident {
        $tipo = match ($payload['estado_al_devolver']) {
            ReturnOperation::ESTADO_DANADO, ReturnOperation::ESTADO_DETERIORADO => Incident::TIPO_DANO,
            ReturnOperation::ESTADO_PERDIDO => Incident::TIPO_PERDIDA,
            default => null,
        };

        if ($tipo === null) {
            return null;
        }

        $descripcion = sprintf(
            'Devolución %s (motivo %s) de la unidad %s.',
            $payload['estado_al_devolver'],
            $payload['motivo'],
            $unitId
        );

        if ($payload['observaciones'] !== null) {
            $descripcion .= ' ' . $payload['observaciones'];
        }

        $incidencia = Incident::create(
            id: uuid(),
            tenantId: $tenantId,
            tipo: $tipo,
            descripcion: $descripcion,
            unidadFisicaId: $unitId,
            ubicacionId: $destino?->id() ?? $origenId,
            actorIdReporta: $actorId
        );
        $this->incidents->save($incidencia);

        return $incidencia;
    }

    private function latestDeliveryId(string $unitId): ?string
    {
        $delivery = $this->deliveries->findLatestByUnidad($unitId);

        return $delivery?->id();
    }

    /**
     * Ítem del envío asociado: ENTREGADA → DEVUELTA al recibir la devolución
     * del cliente (matriz §3.14). Si no hay ítem o ya estaba devuelto, no
     * hace nada.
     */
    private function revertShipmentItem(string $tenantId, string $unitId): void
    {
        $delivery = $this->deliveries->findLatestByUnidad($unitId);

        if ($delivery === null) {
            return;
        }

        foreach ($this->shipmentItems->findByShipment($delivery->shipmentId()) as $item) {
            if ($item->unidadFisicaId() !== $unitId || $item->estado() !== 'ENTREGADA') {
                continue;
            }

            $item->cambiarEstado('DEVUELTA');
            $this->shipmentItems->update($item);

            return;
        }
    }

    // ====================================================================
    // NORMALIZACIÓN Y VALIDACIÓN DE ENTRADA
    // ====================================================================

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $unitId = trim((string) ($input['unidad_fisica_id'] ?? ''));

        if ($unitId === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));

        if ($motivo === '') {
            throw InventoryException::requiredField('motivo');
        }

        if (!in_array($motivo, ReturnOperation::MOTIVOS_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'motivo',
                'Motivo desconocido: \'' . $motivo . '\'. Válidos: ' . implode(', ', ReturnOperation::MOTIVOS_VALIDOS) . '.'
            );
        }

        $estado = trim((string) ($input['estado_al_devolver'] ?? ''));

        if ($estado === '') {
            throw InventoryException::requiredField('estado_al_devolver');
        }

        if (!in_array($estado, ReturnOperation::ESTADOS_AL_DEVOLVER_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'estado_al_devolver',
                'Estado desconocido: \'' . $estado . '\'. Válidos: '
                . implode(', ', ReturnOperation::ESTADOS_AL_DEVOLVER_VALIDOS) . '.'
            );
        }

        $ubicacion = trim((string) ($input['ubicacion_destino_id'] ?? ''));
        $deliveryId = trim((string) ($input['delivery_id'] ?? ''));

        return [
            'unidad_fisica_id' => $unitId,
            'motivo' => $motivo,
            'estado_al_devolver' => $estado,
            'ubicacion_destino_id' => $ubicacion === '' ? null : $ubicacion,
            'delivery_id' => $deliveryId === '' ? null : $deliveryId,
            'observaciones' => $this->text($input['observaciones'] ?? $input['descripcion_motivo'] ?? null),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw InventoryException::invalidField('observaciones', 'Debe ser un texto.');
        }

        $texto = trim((string) $value);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > self::MAX_TEXT) {
            throw InventoryException::fieldMaxLength('observaciones', self::MAX_TEXT);
        }

        return $texto;
    }
}
