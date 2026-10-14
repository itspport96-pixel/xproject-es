<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Shared\Exceptions\HttpException;
use RuntimeException;
use Throwable;

/**
 * Excepción base del dominio Inventory.
 *
 * Centraliza todos los errores de reglas de negocio del módulo.
 * Cada factory method retorna un código HTTP semántico para que
 * el ErrorHandler global lo mapee correctamente.
 *
 * Categorías:
 *  - 400: Validación de datos (formato, longitud, tipo)
 *  - 404: Recurso no encontrado
 *  - 409: Conflicto de unicidad (SKU, código)
 *  - 422: Regla de negocio violada (estado inválido, capacidad, etc.)
 *  - 403: Violación de aislamiento tenant
 */
final class InventoryException extends HttpException
{
    public const CATEGORY_VALIDATION    = 'validation';
    public const CATEGORY_NOT_FOUND     = 'not_found';
    public const CATEGORY_CONFLICT      = 'conflict';
    public const CATEGORY_BUSINESS      = 'business_rule';
    public const CATEGORY_ISOLATION     = 'isolation';

    private string $category;
    private ?string $field;

    public function __construct(
        string $message = 'Inventory error',
        int $code = 422,
        string $category = self::CATEGORY_BUSINESS,
        ?string $field = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->category = $category;
        $this->field = $field;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function toArray(): array
    {
        return [
            'message'  => $this->getMessage(),
            'code'     => $this->getStatusCode(),
            'category' => $this->category,
            'field'    => $this->field,
        ];
    }

    // ====================================================================
    // PRODUCTOS
    // ====================================================================

    public static function productNotFound(string $id): self
    {
        return (new self(
            "El producto con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('inventory.product_not_found', ['id' => $id]);
    }

    public static function skuAlreadyExists(string $sku): self
    {
        return (new self(
            "El SKU '{$sku}' ya está registrado en este tenant.",
            409,
            self::CATEGORY_CONFLICT,
            'sku'
        ))->withMessageKey('inventory.sku_already_exists', ['sku' => $sku]);
    }

    public static function invalidSku(string $sku, string $reason = ''): self
    {
        $msg = "SKU inválido: '{$sku}'.";
        if ($reason !== '') {
            $msg .= " {$reason}";
        }
        return (new self($msg, 400, self::CATEGORY_VALIDATION, 'sku'))
            ->withMessageKey('inventory.invalid_sku', ['sku' => $sku]);
    }

    public static function productInactive(string $id): self
    {
        return (new self(
            "El producto con ID '{$id}' está inactivo.",
            422,
            self::CATEGORY_BUSINESS,
            'id'
        ))->withMessageKey('inventory.product_inactive', ['id' => $id]);
    }

    public static function productHasStockUnits(string $id): self
    {
        return (new self(
            "No se puede eliminar el producto '{$id}' porque tiene unidades físicas asociadas.",
            409,
            self::CATEGORY_BUSINESS
        ))->withMessageKey('inventory.product_has_stock_units', ['id' => $id]);
    }

    public static function invalidUnitOfMeasure(string $unit): self
    {
        return (new self(
            "Unidad de medida inválida: '{$unit}'. Válidas: UNIDAD, KG, LT, M, M2, M3.",
            400,
            self::CATEGORY_VALIDATION,
            'unidad_medida'
        ))->withMessageKey('inventory.invalid_unit_of_measure', ['unit' => $unit]);
    }

    // ====================================================================
    // UBICACIONES
    // ====================================================================

    public static function locationNotFound(string $id): self
    {
        return (new self(
            "La ubicación con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('inventory.location_not_found', ['id' => $id]);
    }

    public static function locationCodeAlreadyExists(string $code): self
    {
        return (new self(
            "El código de ubicación '{$code}' ya está registrado en este tenant.",
            409,
            self::CATEGORY_CONFLICT,
            'codigo'
        ))->withMessageKey('inventory.location_code_already_exists', ['code' => $code]);
    }

    public static function invalidLocationCode(string $code, string $reason = ''): self
    {
        $msg = "Código de ubicación inválido: '{$code}'.";
        if ($reason !== '') {
            $msg .= " {$reason}";
        }
        return (new self($msg, 400, self::CATEGORY_VALIDATION, 'codigo'))
            ->withMessageKey('inventory.invalid_location_code', ['code' => $code]);
    }

    public static function locationInactive(string $id): self
    {
        return (new self(
            "La ubicación con ID '{$id}' está inactiva.",
            422,
            self::CATEGORY_BUSINESS,
            'id'
        ))->withMessageKey('inventory.location_inactive', ['id' => $id]);
    }

    public static function invalidLocationType(string $type): self
    {
        return (new self(
            "Tipo de ubicación inválido: '{$type}'. Válidos: ALMACENAMIENTO, PICKING, DAÑADOS, BLOQUEADOS, TRANSITO, DEVOLUCION.",
            400,
            self::CATEGORY_VALIDATION,
            'tipo'
        ))->withMessageKey('inventory.invalid_location_type', ['type' => $type]);
    }

    public static function capacityExceeded(string $locationId, int $capacidad, int $usada): self
    {
        return (new self(
            "La ubicación '{$locationId}' excede su capacidad ({$usada}/{$capacidad}).",
            422,
            self::CATEGORY_BUSINESS,
            'capacidad'
        ))->withMessageKey('inventory.capacity_exceeded', [
            'locationId' => $locationId,
            'used' => $usada,
            'capacity' => $capacidad,
        ]);
    }

    public static function invalidCapacity(?int $capacidad, ?int $capacidadUsada): self
    {
        return (new self(
            "Capacidad inválida. Debe ser un entero positivo y capacidad_usada no puede exeder capacidad.",
            400,
            self::CATEGORY_VALIDATION,
            'capacidad'
        ))->withMessageKey('inventory.invalid_capacity');
    }

    public static function locationHasStockUnits(string $id): self
    {
        return (new self(
            "No se puede eliminar la ubicación '{$id}' porque tiene unidades físicas asociadas.",
            409,
            self::CATEGORY_BUSINESS
        ))->withMessageKey('inventory.location_has_stock_units', ['id' => $id]);
    }

    public static function locationHasMovements(string $id): self
    {
        return (new self(
            "No se puede eliminar la ubicación '{$id}' porque tiene movimientos registrados.",
            409,
            self::CATEGORY_BUSINESS
        ))->withMessageKey('inventory.location_has_movements', ['id' => $id]);
    }

    // ====================================================================
    // VALIDACIÓN GENÉRICA
    // ====================================================================

    public static function invalidField(string $field, string $reason = ''): self
    {
        $msg = "Campo '{$field}' inválido.";
        if ($reason !== '') {
            $msg .= " {$reason}";
        }
        return (new self($msg, 400, self::CATEGORY_VALIDATION, $field))
            ->withMessageKey('inventory.invalid_field', ['field' => $field]);
    }

    public static function requiredField(string $field): self
    {
        return (new self(
            "El campo '{$field}' es obligatorio.",
            400,
            self::CATEGORY_VALIDATION,
            $field
        ))->withMessageKey('inventory.required_field', ['field' => $field]);
    }

    public static function fieldMaxLength(string $field, int $max): self
    {
        return (new self(
            "El campo '{$field}' excede la longitud máxima ({$max} caracteres).",
            400,
            self::CATEGORY_VALIDATION,
            $field
        ))->withMessageKey('inventory.field_max_length', ['field' => $field, 'max' => $max]);
    }

    // ====================================================================
    // AISLAMIENTO TENANT
    // ====================================================================

    public static function tenantIsolationViolation(string $resourceTenantId, string $currentTenantId): self
    {
        return (new self(
            "Violación de aislamiento: el recurso pertenece al tenant '{$resourceTenantId}' pero el contexto actual es '{$currentTenantId}'.",
            403,
            self::CATEGORY_ISOLATION
        ))->withMessageKey('inventory.tenant_isolation_violation', [
            'resourceTenant' => $resourceTenantId,
            'currentTenant' => $currentTenantId,
        ]);
    }

    // ====================================================================
    // STOCK / RECEPCIÓN (CU-001)
    // ====================================================================

    public static function invalidQuantity(string $field, string $reason = ''): self
    {
        $msg = "La cantidad del campo '{$field}' es inválida.";
        if ($reason !== '') {
            $msg .= " {$reason}";
        }

        return (new self($msg, 400, self::CATEGORY_VALIDATION, $field))
            ->withMessageKey('inventory.invalid_quantity', ['field' => $field]);
    }

    /**
     * R1: cantidad_disponible = cantidad_fisica - cantidad_reservada - cantidad_en_transito (>= 0).
     */
    public static function availableQuantityCannotBeNegative(
        float $fisica,
        float $reservada,
        float $enTransito
    ): self {
        return (new self(
            sprintf(
                'La cantidad disponible no puede ser negativa (%.4f - %.4f - %.4f).',
                $fisica,
                $reservada,
                $enTransito
            ),
            422,
            self::CATEGORY_BUSINESS,
            'cantidad_disponible'
        ))->withMessageKey('inventory.available_quantity_cannot_be_negative', [
            'physical' => $fisica,
            'reserved' => $reservada,
            'inTransit' => $enTransito,
        ]);
    }

    public static function invalidStockState(string $state): self
    {
        return (new self(
            "Estado de unidad física inválido: '{$state}'.",
            400,
            self::CATEGORY_VALIDATION,
            'estado'
        ))->withMessageKey('inventory.invalid_stock_state', ['state' => $state]);
    }

    public static function barcodeGenerationFailed(): self
    {
        return (new self(
            'No se pudo generar un código de barras único para la unidad física.',
            500,
            self::CATEGORY_BUSINESS,
            'codigo_barcode'
        ))->withMessageKey('inventory.barcode_generation_failed');
    }

    public static function idempotencyKeyRequired(): self
    {
        return (new self(
            'El encabezado Idempotency-Key es obligatorio para esta operación.',
            400,
            self::CATEGORY_VALIDATION,
            'idempotency-key'
        ))->withMessageKey('inventory.idempotency_key_required');
    }

    public static function idempotencyKeyInvalid(string $reason = ''): self
    {
        $msg = 'El encabezado Idempotency-Key no es válido.';
        if ($reason !== '') {
            $msg .= " {$reason}";
        }

        return (new self($msg, 400, self::CATEGORY_VALIDATION, 'idempotency-key'))
            ->withMessageKey('inventory.idempotency_key_invalid');
    }

    // ====================================================================
    // MÁQUINA DE ESTADOS (Sprint 6, StateTransitionService)
    // ====================================================================

    public static function invalidStateTransition(string $from, string $to): self
    {
        return (new self(
            "Transición de estado no permitida: '{$from}' → '{$to}'.",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.invalid_state_transition', ['from' => $from, 'to' => $to]);
    }

    // ====================================================================
    // MOVIMIENTOS (CU-002)
    // ====================================================================

    public static function unitNotFound(string $id): self
    {
        return (new self(
            "La unidad física con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND,
            'unidad_fisica_id'
        ))->withMessageKey('inventory.unit_not_found', ['id' => $id]);
    }

    public static function unitNotMovable(string $id, string $estado): self
    {
        return (new self(
            "La unidad física '{$id}' está en estado '{$estado}' y no puede moverse internamente.",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_movable', ['id' => $id, 'estado' => $estado]);
    }

    public static function destinationEqualsOrigin(): self
    {
        return (new self(
            'La ubicación de destino debe ser distinta de la de origen.',
            422,
            self::CATEGORY_BUSINESS,
            'ubicacion_destino_id'
        ))->withMessageKey('inventory.destination_equals_origin');
    }

    /**
     * Una ubicación virtual (tipo TRANSITO) no participa en movimientos
     * internos: no tiene capacidad física y representa transporte, no almacén.
     */
    public static function locationIsVirtual(string $codigo, string $lado): self
    {
        return (new self(
            "La ubicación '{$codigo}' es virtual (TRANSITO) y no puede usarse como {$lado} de un movimiento interno.",
            422,
            self::CATEGORY_BUSINESS,
            "ubicacion_{$lado}_id"
        ))->withMessageKey('inventory.location_is_virtual', ['codigo' => $codigo, 'side' => $lado]);
    }

    // ====================================================================
    // RESERVAS (CU-003)
    // ====================================================================

    public static function insufficientStock(float $disponible, float $requerido): self
    {
        return (new self(
            sprintf('Stock insuficiente: disponible %.4f, solicitado %.4f.', $disponible, $requerido),
            422,
            self::CATEGORY_BUSINESS,
            'cantidad'
        ))->withMessageKey('inventory.insufficient_stock', [
            'available' => $disponible,
            'requested' => $requerido,
        ]);
    }

    public static function reservationAlreadyActive(string $unidadFisicaId): self
    {
        return (new self(
            "La unidad física '{$unidadFisicaId}' ya tiene una reserva activa.",
            409,
            self::CATEGORY_CONFLICT,
            'unidad_fisica_id'
        ))->withMessageKey('inventory.reservation_already_active', ['unitId' => $unidadFisicaId]);
    }

    public static function reservationNotFound(string $id): self
    {
        return (new self(
            "La reserva con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND,
            'reserva_id'
        ))->withMessageKey('inventory.reservation_not_found', ['id' => $id]);
    }

    public static function reservationNotActive(string $id, string $estado): self
    {
        return (new self(
            "La reserva '{$id}' no está activa (estado '{$estado}').",
            409,
            self::CATEGORY_CONFLICT,
            'estado'
        ))->withMessageKey('inventory.reservation_not_active', ['id' => $id, 'estado' => $estado]);
    }

    // ====================================================================
    // PICKING (CU-004)
    // ====================================================================

    public static function pickingLotNotFound(string $lote): self
    {
        return (new self(
            "El lote de picking '{$lote}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND,
            'id_lote_picking'
        ))->withMessageKey('inventory.picking_lot_not_found', ['lote' => $lote]);
    }

    public static function pickingLotAlreadyExists(string $lote): self
    {
        return (new self(
            "El lote de picking '{$lote}' ya existe en este tenant.",
            409,
            self::CATEGORY_CONFLICT,
            'id_lote_picking'
        ))->withMessageKey('inventory.picking_lot_already_exists', ['lote' => $lote]);
    }

    /**
     * Estado del lote incompatible con la operación pedida (complete/verify).
     * $esperado es el estado actual que permitiría el salto (p. ej. "EN_CURSO").
     */
    public static function pickingLotInvalidState(string $lote, string $estado, string $esperado): self
    {
        return (new self(
            "El lote de picking '{$lote}' está en estado '{$estado}'; se requiere '{$esperado}'.",
            409,
            self::CATEGORY_CONFLICT,
            'estado'
        ))->withMessageKey('inventory.picking_lot_invalid_state', [
            'lote' => $lote,
            'estado' => $estado,
            'esperado' => $esperado,
        ]);
    }

    public static function pickingSupervisorMustDiffer(string $lote): self
    {
        return (new self(
            "El supervisor del lote de picking '{$lote}' debe ser distinto del selector.",
            422,
            self::CATEGORY_BUSINESS,
            'actor_id_supervisor'
        ))->withMessageKey('inventory.picking_supervisor_must_differ', ['lote' => $lote]);
    }

    public static function pickingLocationRequired(): self
    {
        return (new self(
            'La ubicación de preparación debe existir, estar activa y ser de tipo PICKING.',
            422,
            self::CATEGORY_BUSINESS,
            'ubicacion_preparacion_id'
        ))->withMessageKey('inventory.picking_location_required');
    }

    /**
     * La unidad no está en estado RESERVADO (CU-004: sólo se recogen
     * unidades reservadas).
     */
    public static function unitNotReserved(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' debe estar RESERVADO para iniciar picking (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_reserved', ['unitId' => $unitId, 'estado' => $estado]);
    }

    /**
     * HUECO documentado (v1.7.0): la spec CU-004 permite preparar una
     * cantidad parcial (`cantidad >= cantidad_a_preparar`), pero la unidad
     * física no se divide, así que la cantidad pedida debe ser EXACTAMENTE la
     * de la unidad. Cualquier otra cantidad → 422.
     */
    public static function unitQuantityMismatch(string $unitId, float $esperada, float $pedida): self
    {
        return (new self(
            sprintf(
                'La unidad física \'%s\' no se puede dividir: cantidad pedida %.4f, cantidad de la unidad %.4f.',
                $unitId,
                $pedida,
                $esperada
            ),
            422,
            self::CATEGORY_BUSINESS,
            'cantidad'
        ))->withMessageKey('inventory.unit_quantity_mismatch', [
            'unitId' => $unitId,
            'requested' => $pedida,
            'unitQuantity' => $esperada,
        ]);
    }

    // ====================================================================
    // SHIPMENTS / TRANSPORTE (CU-005, CU-006)
    // ====================================================================

    public static function shipmentNotFound(string $id): self
    {
        return (new self(
            "El envío con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND,
            'shipment_id'
        ))->withMessageKey('inventory.shipment_not_found', ['id' => $id]);
    }

    public static function shipmentNotInPreparation(string $id, string $estado): self
    {
        return (new self(
            "El envío '{$id}' debe estar en PREPARACION (estado actual: '{$estado}').",
            409,
            self::CATEGORY_CONFLICT,
            'estado'
        ))->withMessageKey('inventory.shipment_not_in_preparation', ['id' => $id, 'estado' => $estado]);
    }

    /**
     * Falta cargar: no hay ítems o alguno no está CARGADA (spec CU-006).
     */
    public static function shipmentNotFullyLoaded(string $id): self
    {
        return (new self(
            "El envío '{$id}' no está completamente cargado: todas las unidades deben estar CARGADAS.",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.shipment_not_fully_loaded', ['id' => $id]);
    }

    public static function recipientInvalid(): self
    {
        return (new self(
            'El envío no tiene un destinatario válido (nombre o ID externo obligatorio).',
            422,
            self::CATEGORY_BUSINESS,
            'destinatario_nombre'
        ))->withMessageKey('inventory.recipient_invalid');
    }

    public static function transitLocationMissing(): self
    {
        return (new self(
            'El tenant no tiene una ubicación virtual TRANSITO activa: créela antes de autorizar la salida.',
            404,
            self::CATEGORY_NOT_FOUND,
            'ubicacion_transito_id'
        ))->withMessageKey('inventory.transit_location_missing');
    }

    /**
     * La unidad no está en la zona de preparación (tipo PICKING).
     */
    public static function unitNotInPickingZone(string $unitId): self
    {
        return (new self(
            "La unidad física '{$unitId}' no está en una zona de preparación (PICKING).",
            422,
            self::CATEGORY_BUSINESS,
            'ubicacion_id'
        ))->withMessageKey('inventory.unit_not_in_picking_zone', ['unitId' => $unitId]);
    }

    /**
     * La unidad no está en EN_PREPARACION (sólo se carga lo preparado).
     */
    public static function unitNotPreparedForLoad(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' debe estar EN_PREPARACION para cargarla (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_prepared_for_load', ['unitId' => $unitId, 'estado' => $estado]);
    }

    /**
     * El picking de la unidad no está COMPLETADA ni VERIFICADA.
     */
    public static function unitPickingNotFinished(string $unitId, string $estado): self
    {
        return (new self(
            "El picking de la unidad física '{$unitId}' debe estar COMPLETADA o VERIFICADA (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_picking_not_finished', ['unitId' => $unitId, 'estado' => $estado]);
    }

    /**
     * La unidad no está CARGADA en el envío que se quiere autorizar.
     */
    public static function unitNotLoaded(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' debe estar CARGADA para autorizar la salida (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_loaded', ['unitId' => $unitId, 'estado' => $estado]);
    }

    // ====================================================================
    // DELIVERIES / DEVOLUCIONES (CU-007, CU-008, CU-009) - Sprint 8
    // ====================================================================

    /**
     * El envío debe estar EN_RUTA para entregar o registrar un fallo.
     */
    public static function shipmentNotOnRoute(string $id, string $estado): self
    {
        return (new self(
            "El envío '{$id}' debe estar en EN_RUTA (estado actual: '{$estado}').",
            409,
            self::CATEGORY_CONFLICT,
            'estado'
        ))->withMessageKey('inventory.shipment_not_on_route', ['id' => $id, 'estado' => $estado]);
    }

    /**
     * Todas las unidades del envío ya están entregadas: no hay fallo que
     * registrar (spec CU-008 paso 2).
     */
    public static function shipmentAllUnitsDelivered(string $id): self
    {
        return (new self(
            "El envío '{$id}' ya tiene todas sus unidades entregadas: no hay unidades por registrar como fallo.",
            409,
            self::CATEGORY_CONFLICT,
            'estado'
        ))->withMessageKey('inventory.shipment_all_units_delivered', ['id' => $id]);
    }

    /**
     * La entrega confirmada no incluye ninguna unidad (spec CU-007: para un
     * fallo total se usa `POST /deliveries/failed`).
     */
    public static function deliveryUnitsRequired(): self
    {
        return (new self(
            'Debe indicar al menos una unidad entregada (para un fallo total use POST /inventory/deliveries/failed).',
            422,
            self::CATEGORY_BUSINESS,
            'unidades'
        ))->withMessageKey('inventory.delivery_units_required');
    }

    /**
     * La unidad no forma parte del envío que se está entregando.
     */
    public static function unitNotInShipment(string $unitId, string $shipmentId): self
    {
        return (new self(
            "La unidad física '{$unitId}' no pertenece al envío '{$shipmentId}'.",
            422,
            self::CATEGORY_BUSINESS,
            'unidad_fisica_id'
        ))->withMessageKey('inventory.unit_not_in_shipment', ['unitId' => $unitId, 'shipmentId' => $shipmentId]);
    }

    /**
     * Para entregar/fallar la unidad debe estar EN_TRANSITO.
     */
    public static function unitNotInTransitForDelivery(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' debe estar EN_TRANSITO (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_in_transit_for_delivery', ['unitId' => $unitId, 'estado' => $estado]);
    }

    /**
     * Cantidad entregada distinta de la cargada: la unidad no se divide.
     */
    public static function deliveryQuantityMismatch(string $unitId, float $cargada, float $entregada): self
    {
        return (new self(
            sprintf(
                'La unidad física \'%s\' no se puede dividir: cantidad entregada %.4f, cantidad cargada %.4f.',
                $unitId,
                $entregada,
                $cargada
            ),
            422,
            self::CATEGORY_BUSINESS,
            'cantidad_entregada'
        ))->withMessageKey('inventory.delivery_quantity_mismatch', [
            'unitId' => $unitId,
            'delivered' => $entregada,
            'loaded' => $cargada,
        ]);
    }

    /**
     * La firma/comprobante debe ser una URL http(s) válida (spec CU-007:
     * no hay subida de archivos en este módulo).
     */
    public static function invalidSignatureUrl(string $reason = ''): self
    {
        $msg = 'El comprobante de firma debe ser una URL http:// o https:// válida.';
        if ($reason !== '') {
            $msg .= ' ' . $reason;
        }

        return (new self($msg, 422, self::CATEGORY_BUSINESS, 'firma_comprobante'))
            ->withMessageKey('inventory.invalid_signature_url');
    }

    /**
     * El tenant no tiene zona DAÑADOS activa (spec CU-009: la devolución
     * dañada se reubica ahí).
     */
    public static function damagedZoneMissing(): self
    {
        return (new self(
            'El tenant no tiene una zona DAÑADOS activa: créela (o ejecute php bin/seed-transit-locations.php) antes de registrar la devolución.',
            404,
            self::CATEGORY_NOT_FOUND,
            'ubicacion_destino_id'
        ))->withMessageKey('inventory.damaged_zone_missing');
    }

    /**
     * CU-009: la unidad debe estar DEVUELTO o ENTREGADO para devolverla.
     */
    public static function unitNotReturnable(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' debe estar DEVUELTO o ENTREGADO para registrar la devolución (estado actual: '{$estado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.unit_not_returnable', ['unitId' => $unitId, 'estado' => $estado]);
    }

    /**
     * El envío no tiene ubicación de origen y no se indicó destino para
     * reubicar las unidades no entregadas (spec CU-008 / E3).
     */
    public static function deliveryReturnLocationMissing(): self
    {
        return (new self(
            'El envío no tiene ubicación de origen: indique ubicacion_destino_id para reubicar las unidades no entregadas.',
            422,
            self::CATEGORY_BUSINESS,
            'ubicacion_destino_id'
        ))->withMessageKey('inventory.delivery_return_location_missing');
    }

    public static function duplicateUnitsInRequest(string $unitId): self
    {
        return (new self(
            "La unidad física '{$unitId}' aparece más de una vez en la petición.",
            409,
            self::CATEGORY_CONFLICT,
            'unidades'
        ))->withMessageKey('inventory.duplicate_units_in_request', ['unitId' => $unitId]);
    }

    /**
     * El envío no indicó `origen_ubicacion_id` y no se pudo derivar de las
     * unidades cargadas (ocupan más de una zona o ninguna tiene ubicación)
     * (v1.8.3, spec CU-005).
     */
    public static function shipmentOriginLocationMissing(): self
    {
        return (new self(
            'No se pudo determinar la ubicación de origen del envío: envíe origen_ubicacion_id (las unidades cargadas no comparten una sola zona de preparación).',
            422,
            self::CATEGORY_BUSINESS,
            'origen_ubicacion_id'
        ))->withMessageKey('inventory.shipment_origin_location_missing');
    }

    // ====================================================================
    // INCIDENCIAS (CU-010, v1.9.0)
    // ====================================================================

    public static function incidentNotFound(string $id): self
    {
        return (new self(
            "La incidencia con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('inventory.incident_not_found', ['id' => $id]);
    }

    public static function incidentInvalidState(string $id, string $estado, string $esperado): self
    {
        return (new self(
            "La incidencia '{$id}' no admite esta operación en el estado '{$estado}' (se esperaba '{$esperado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.incident_invalid_state', [
            'id' => $id,
            'estado' => $estado,
            'esperado' => $esperado,
        ]);
    }

    // ====================================================================
    // CONTEOS FÍSICOS (CU-011, v1.9.0)
    // ====================================================================

    public static function countNotFound(string $id): self
    {
        return (new self(
            "El conteo físico con ID '{$id}' no existe.",
            404,
            self::CATEGORY_NOT_FOUND
        ))->withMessageKey('inventory.count_not_found', ['id' => $id]);
    }

    public static function countInvalidState(string $id, string $estado, string $esperado): self
    {
        return (new self(
            "El conteo '{$id}' no admite esta operación en el estado '{$estado}' (se esperaba '{$esperado}').",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.count_invalid_state', [
            'id' => $id,
            'estado' => $estado,
            'esperado' => $esperado,
        ]);
    }

    /**
     * Completar un conteo sin resultado para todas las unidades de la lista.
     *
     * @param array<int, string> $faltantes
     */
    public static function countMissingResults(array $faltantes): self
    {
        return (new self(
            'Faltan resultados para ' . count($faltantes) . ' unidad(es): ' . implode(', ', $faltantes) . '.',
            422,
            self::CATEGORY_BUSINESS,
            'resultados'
        ))->withMessageKey('inventory.count_missing_results', [
            'count' => count($faltantes),
            'units' => implode(', ', $faltantes),
        ]);
    }

    public static function countUnitNotInSheet(string $unitId): self
    {
        return (new self(
            "La unidad física '{$unitId}' no está en la lista teórica del conteo.",
            422,
            self::CATEGORY_BUSINESS,
            'unidad_fisica_id'
        ))->withMessageKey('inventory.count_unit_not_in_sheet', ['unitId' => $unitId]);
    }

    public static function countResultsRequired(): self
    {
        return (new self(
            'El conteo debe registrar al menos una unidad.',
            422,
            self::CATEGORY_BUSINESS,
            'resultados'
        ))->withMessageKey('inventory.count_results_required');
    }

    public static function countDiscrepancyAlreadyResolved(string $unitId): self
    {
        return (new self(
            "La discrepancia de la unidad física '{$unitId}' ya tiene una acción registrada.",
            409,
            self::CATEGORY_CONFLICT,
            'unidad_fisica_id'
        ))->withMessageKey('inventory.count_discrepancy_already_resolved', ['unitId' => $unitId]);
    }

    public static function countAdjustmentStateNotAllowed(string $unitId, string $estado): self
    {
        return (new self(
            "La unidad física '{$unitId}' está en '{$estado}': el ajuste automático sólo está permitido en DISPONIBLE o DAÑADO. Registre la acción JUSTIFICAR con su justificación.",
            422,
            self::CATEGORY_BUSINESS,
            'estado'
        ))->withMessageKey('inventory.count_adjustment_state_not_allowed', ['unitId' => $unitId, 'estado' => $estado]);
    }

    public static function countInvalidAction(string $accion): self
    {
        return (new self(
            "Acción de reconciliación desconocida: '{$accion}'. Válidas: AJUSTAR, JUSTIFICAR.",
            400,
            self::CATEGORY_VALIDATION,
            'accion'
        ))->withMessageKey('inventory.count_invalid_action', ['accion' => $accion]);
    }

    public static function countJustificationRequired(): self
    {
        return (new self(
            'La acción JUSTIFICAR requiere una `justificacion` obligatoria.',
            422,
            self::CATEGORY_BUSINESS,
            'justificacion'
        ))->withMessageKey('inventory.count_justification_required');
    }

    /**
     * v1.9.1: AJUSTAR no se aplica sobre datos obsoletos. La cantidad actual
     * de la unidad ya no coincide con `cantidad_esperada` de la instantánea
     * (`lista_teorica`) del conteo: la hoja está vencida y hay que justificar
     * la discrepancia o iniciar un conteo nuevo.
     *
     * El detalle (unidad, esperada y actual) viaja en el mensaje: la API sólo
     * expone `message`, `category` y `field`.
     */
    public static function countStaleQuantity(string $unitId, float $esperada, float $actual): self
    {
        return (new self(
            sprintf(
                "La unidad física '%s' ya no coincide con la hoja teórica del conteo: cantidad_esperada %.4f, cantidad actual %.4f. No se ajusta sobre datos obsoletos: registre la acción JUSTIFICAR con justificación o inicie un conteo nuevo.",
                $unitId,
                $esperada,
                $actual
            ),
            422,
            self::CATEGORY_BUSINESS,
            'cantidad'
        ))->withMessageKey('inventory.count_stale_quantity', [
            'unitId' => $unitId,
            'expected' => $esperada,
            'current' => $actual,
        ]);
    }
}
