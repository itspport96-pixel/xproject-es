<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Delivery;
use App\Modules\Inventory\Domain\Entities\Shipment;
use App\Modules\Inventory\Domain\Entities\ShipmentItem;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\DeliveryRepositoryInterface;
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
 * ConfirmDeliveryUseCase - CU-007: CONFIRMAR ENTREGA
 *
 * Confirma la entrega de las unidades de UN envío EN_RUTA en UNA sola
 * transacción (R5), con idempotencia obligatoria (R7) y auditoría de dominio:
 *
 *  1. Idempotencia: Idempotency-Key obligatoria en HTTP; el replay devuelve la
 *     respuesta original SIN repetir escrituras ni filas de auditoría.
 *  2. El envío existe en el tenant (404/403) y está EN_RUTA (409); si no le
 *     queda ninguna unidad EN_TRANSITO se responde 409 (ya entregado/fallido).
 *  3. Cada unidad indicada debe pertenecer al envío (422), estar EN_TRANSITO
 *     (422) y su `cantidad_entregada` debe coincidir con la cargada (422): la
 *     unidad no se divide (misma regla que CU-004/CU-005).
 *  4. `firma_comprobante` es opcional y sólo admite URL http(s): no hay
 *     subida de archivos en este módulo (spec CU-007 / hueco documentado).
 *  5. Por cada unidad: Delivery ENTREGADA + unidad ENTREGADO + ítem ENTREGADA
 *     + evento ENTREGADO con hash + ledger `cantidad_en_transito` -= cantidad
 *     (R1 intacto). La unidad sale de la ubicación virtual TRANSITO.
 *  6. Las unidades NO indicadas siguen CU-008 en la MISMA transacción
 *     (Delivery RECHAZADA + unidad DEVUELTO + incidencia ENTREGA_RECHAZADA +
 *     reubicación a la origen del envío + evento DEVUELTO).
 *  7. Envío → ENTREGADO con `hora_entrega_real` (UTC). Regla de decisión
 *     (documentada en el informe): todas entregadas → ENTREGADO; ninguna →
 *     el cliente debe usar POST /deliveries/failed; mixto → ENTREGADO con las
 *     faltantes procesadas por CU-008.
 *
 * Input:
 *  - shipment_id (obligatorio)
 *  - unidades[] (obligatorio, 1..N): unidad_fisica_id, cantidad_entregada?,
 *    firma_comprobante?, observaciones?
 *  - destinatario_real?, firma_comprobante?, observaciones? (defecto común)
 *  - motivo_fallo? (motivo CU-008 para las unidades no indicadas, defecto OTRO)
 */
final class ConfirmDeliveryUseCase
{
    public const OPERACION = 'inventory.delivery.confirm';

    private const MAX_TEXT = 2000;
    private const MAX_NOMBRE = 255;

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
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly RegisterDeliveryFailureUseCase $failureUseCase,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario que confirma (claims)
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
            throw InventoryException::requiredField('actor_id_entrega');
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

                $response = $this->confirm($tenantId, $payload, (string) $actorId);

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
    // CONFIRMACIÓN (corazón del CU-007)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function confirm(string $tenantId, array $payload, string $actorId): array
    {
        // 1. Envío existente en EN_RUTA (tenant-scoped)
        $shipment = $this->shipments->findById($payload['shipment_id']);

        if ($shipment === null) {
            throw InventoryException::shipmentNotFound($payload['shipment_id']);
        }
        if (!$shipment->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($shipment->tenantId(), $tenantId);
        }
        if ($shipment->estado() !== Shipment::ESTADO_EN_RUTA) {
            throw InventoryException::shipmentNotOnRoute($shipment->id(), $shipment->estado());
        }

        $items = $this->shipmentItems->findByShipment($shipment->id());

        if ($items === []) {
            throw InventoryException::shipmentAllUnitsDelivered($shipment->id());
        }

        // 2. Mapa unidad → ítem y validación de cada unidad entregada
        $itemsPorUnidad = [];

        foreach ($items as $item) {
            $itemsPorUnidad[$item->unidadFisicaId()] = $item;
        }

        $entregadas = [];

        foreach ($payload['unidades'] as $entrada) {
            $unitId = $entrada['unidad_fisica_id'];
            $item = $itemsPorUnidad[$unitId] ?? null;

            if ($item === null) {
                throw InventoryException::unitNotInShipment($unitId, $shipment->id());
            }
            if ($item->estado() !== ShipmentItem::ESTADO_EN_TRANSITO) {
                throw InventoryException::unitNotInTransitForDelivery($unitId, $item->estado());
            }

            $unit = $this->stockUnits->findById($unitId);

            if ($unit === null) {
                throw InventoryException::unitNotFound($unitId);
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }
            if ($unit->estado()->value() !== StockState::EN_TRANSITO) {
                throw InventoryException::unitNotInTransitForDelivery($unitId, $unit->estado()->value());
            }

            $cantidad = $entrada['cantidad_entregada'] ?? $item->cantidad();

            if (abs($cantidad - $item->cantidad()) > 0.000001) {
                throw InventoryException::deliveryQuantityMismatch($unitId, $item->cantidad(), $cantidad);
            }

            $entregadas[$unitId] = ['item' => $item, 'unit' => $unit, 'entrada' => $entrada];
        }

        // 3. Nada entregado todavía: si TODOS los ítems ya no están EN_TRANSITO
        //    el envío ya se procesó (409)
        $pendientes = array_values(array_filter(
            $items,
            static fn(ShipmentItem $item): bool => $item->estado() === ShipmentItem::ESTADO_EN_TRANSITO
        ));

        if ($pendientes === []) {
            throw InventoryException::shipmentAllUnitsDelivered($shipment->id());
        }

        $unidadesPublicas = [];
        $entregasPublicas = [];
        $eventos = [];

        // 4. Unidades entregadas (Delivery ENTREGADA + unidad ENTREGADO + ledger)
        foreach ($entregadas as $registro) {
            $item = $registro['item'];
            $unit = $registro['unit'];
            $entrada = $registro['entrada'];

            $estadoAnterior = $unit->estado()->value();
            $this->states->assertCanTransition($estadoAnterior, StockState::ENTREGADO);

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
                'estado_actual' => StockState::ENTREGADO,
            ]);
            $this->ledgers->update($ledger);

            // La unidad sale del almacén: sale también de la virtual TRANSITO
            $transito = $unit->ubicacionId();
            $unit->moverA(null, StockState::fromString(StockState::ENTREGADO));
            $this->stockUnits->update($unit);
            $this->releaseOriginSlot($tenantId, $transito, (int) ceil($unit->cantidad()));

            $item->cambiarEstado(ShipmentItem::ESTADO_ENTREGADA);
            $this->shipmentItems->update($item);

            $delivery = Delivery::create(
                id: uuid(),
                tenantId: $tenantId,
                shipmentId: $shipment->id(),
                unidadFisicaId: $unit->id(),
                cantidad: $unit->cantidad(),
                estado: Delivery::ESTADO_ENTREGADA,
                destinatarioReal: $entrada['destinatario_real'] ?? $payload['destinatario_real'],
                firmaComprobante: $entrada['firma_comprobante'] ?? $payload['firma_comprobante'],
                observaciones: $entrada['observaciones'] ?? $payload['observaciones'],
                actorIdEntrega: $actorId,
                timestampEntrega: new \DateTimeImmutable()
            );
            $this->deliveries->save($delivery);

            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_ENTREGADO,
                descripcion: 'Entrega confirmada (CU-007)',
                datosOperacion: [
                    'shipment_id' => $shipment->id(),
                    'shipment_item_id' => $item->id(),
                    'delivery_id' => $delivery->id(),
                    'cantidad' => $unit->cantidad(),
                    'actor_id_entrega' => $actorId,
                ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
                // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de
                // la unidad (el disponible va en datos_operacion).
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::ENTREGADO
            );
            $this->events->save($event);

            $unidadesPublicas[] = [
                'unidad_fisica_id' => $unit->id(),
                'estado' => StockState::ENTREGADO,
                'cantidad' => $unit->cantidad(),
            ];
            $entregasPublicas[] = $delivery->toPublicArray();
            $eventos[] = $event;
        }

        // 5. Unidades no indicadas → CU-008 en la MISMA transacción
        $faltantes = array_values(array_filter(
            $pendientes,
            static fn(ShipmentItem $item): bool => !isset($entregadas[$item->unidadFisicaId()])
        ));
        $fallos = null;

        if ($faltantes !== []) {
            $fallos = $this->failureUseCase->applyFailure(
                $shipment,
                $faltantes,
                $payload['motivo_fallo'],
                $payload['observaciones'],
                null,
                $actorId
            );

            foreach ($fallos['entregas'] as $delivery) {
                $entregasPublicas[] = $delivery->toPublicArray();
            }
            foreach ($fallos['unidades'] as $unidad) {
                $unidadesPublicas[] = $unidad;
            }
            foreach ($fallos['eventos'] as $event) {
                $eventos[] = $event;
            }
        }

        // 6. Envío → ENTREGADO con hora real (UTC)
        $shipment->marcarEntregado(new \DateTimeImmutable());
        $this->shipments->update($shipment);

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Entrega confirmada correctamente.',
                'shipment' => $shipment->toPublicArray(),
                'entregas' => $entregasPublicas,
                'unidades' => $unidadesPublicas,
                'no_entregadas' => $fallos !== null ? $fallos['unidades'] : [],
                'incidencia' => $fallos['incidencia'] ?? null,
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
     * Libera slots de la ubicación de origen. Las ubicaciones VIRTUALES
     * (TRANSITO) no llevan contador: la autorización de salida tampoco lo
     * incrementa, así que aquí no se tocan (invariante documentado).
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

        $unidades = $input['unidades'] ?? null;

        if (!is_array($unidades) || $unidades === []) {
            throw InventoryException::deliveryUnitsRequired();
        }

        $normalizadas = [];
        $vistas = [];

        foreach ($unidades as $indice => $entrada) {
            if (!is_array($entrada)) {
                throw InventoryException::invalidField('unidades', "La unidad en la posición {$indice} no es válida.");
            }

            $unitId = trim((string) ($entrada['unidad_fisica_id'] ?? ''));

            if ($unitId === '') {
                throw InventoryException::requiredField('unidades[' . $indice . '].unidad_fisica_id');
            }

            if (isset($vistas[$unitId])) {
                throw InventoryException::duplicateUnitsInRequest($unitId);
            }
            $vistas[$unitId] = true;

            $cantidad = $entrada['cantidad_entregada'] ?? null;

            if ($cantidad !== null && $cantidad !== '') {
                $cantidad = $this->positiveFloat($cantidad, 'cantidad_entregada');
            } else {
                $cantidad = null;
            }

            $normalizadas[] = [
                'unidad_fisica_id' => $unitId,
                'cantidad_entregada' => $cantidad,
                'firma_comprobante' => $this->signatureUrl($entrada['firma_comprobante'] ?? null),
                'observaciones' => $this->text($entrada['observaciones'] ?? null, 'observaciones', self::MAX_TEXT),
                'destinatario_real' => $this->text(
                    $entrada['destinatario_real'] ?? null,
                    'destinatario_real',
                    self::MAX_NOMBRE
                ),
            ];
        }

        $motivoFallo = trim((string) ($input['motivo_fallo'] ?? RegisterDeliveryFailureUseCase::MOTIVO_OTRO));

        if (!in_array($motivoFallo, RegisterDeliveryFailureUseCase::MOTIVOS_FALLO, true)) {
            throw InventoryException::invalidField(
                'motivo_fallo',
                'Motivo de fallo desconocido: \'' . $motivoFallo . '\'.'
            );
        }

        return [
            'shipment_id' => $shipmentId,
            'unidades' => $normalizadas,
            'destinatario_real' => $this->text($input['destinatario_real'] ?? null, 'destinatario_real', self::MAX_NOMBRE),
            'firma_comprobante' => $this->signatureUrl($input['firma_comprobante'] ?? null),
            'observaciones' => $this->text($input['observaciones'] ?? null, 'observaciones', self::MAX_TEXT),
            'motivo_fallo' => $motivoFallo,
        ];
    }

    private function positiveFloat(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw InventoryException::invalidField($field, 'Debe ser un número mayor que cero.');
        }

        $number = (float) $value;

        if (!is_finite($number) || $number <= 0) {
            throw InventoryException::invalidQuantity($field, 'Debe ser mayor que cero.');
        }

        return $number;
    }

    private function text(mixed $value, string $field, int $max): ?string
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

        if (mb_strlen($texto) > $max) {
            throw InventoryException::fieldMaxLength($field, $max);
        }

        return $texto;
    }

    /**
     * Sólo URL http(s): no hay subida de archivos en el módulo (spec CU-007).
     */
    private function signatureUrl(mixed $value): ?string
    {
        $texto = $this->text($value, 'firma_comprobante', 500);

        if ($texto === null) {
            return null;
        }

        if (filter_var($texto, FILTER_VALIDATE_URL) === false) {
            throw InventoryException::invalidSignatureUrl('La URL no es válida.');
        }

        $esquema = strtolower((string) parse_url($texto, PHP_URL_SCHEME));

        if (!in_array($esquema, ['http', 'https'], true)) {
            throw InventoryException::invalidSignatureUrl('Sólo se admiten http:// y https://.');
        }

        return $texto;
    }
}
