<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Movement;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\MovementRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * MoveStockUseCase - CU-002: MOVER INVENTARIO INTERNAMENTE
 *
 * Mueve una unidad física entre ubicaciones en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): Idempotency-Key insertada en la misma transacción;
 *     misma clave + mismo payload → respuesta original (replay); misma clave
 *     + payload distinto → 409.
 *  2. Valida unidad del tenant, ubicación destino existente y activa, tipo
 *     TRANSITO (virtual, no admite movimientos internos), mismo tenant,
 *     origen ≠ destino y estado que admita movimiento (CU-002: no EN_TRANSITO,
 *     ENTREGADO ni PERDIDO).
 *  3. Aplica capacidad: destino +slots (422 `field: capacidad` si se excede)
 *     y origen -slots.
 *  4. Escribe la fila inmutable `movements` con `hash_integridad`, actualiza
 *     `stock_units.ubicacion_id`, refresca el ledger y crea el evento
 *     MOVIDO (spec §3.9; append-only, con hash).
 *  5. Cualquier error → ROLLBACK total (la key de idempotencia se revierte).
 *
 * Input:
 *  - unidad_fisica_id | codigo_barcode (obligatorio uno)
 *  - ubicacion_destino_id | ubicacion_destino_codigo (obligatorio uno)
 *  - motivo (opcional, por defecto REUBICACION)
 *  - referencia_externa (opcional)
 *
 * v1.10.0:
 *  - CU-002: `data.warnings` (lista de avisos) cuando la unidad movida está
 *    RESERVADO (moverla puede afectar al pedido asociado).
 *  - E8: si el último movimiento de la unidad es exactamente el inverso del
 *    que se va a registrar, el nuevo se marca `es_correccion` y referencia
 *    ese movimiento (`movimiento_referencia_id`); el histórico no se toca.
 */
final class MoveStockUseCase
{
    public const OPERACION = 'inventory.move';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly MovementRepositoryInterface $movements,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input         Cuerpo de la petición
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId        Usuario autenticado (claims)
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

                $response = $this->move($tenantId, $payload, $actorId);

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
    // MOVIMIENTO (corazón del CU-002)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     */
    private function move(string $tenantId, array $payload, ?string $actorId): array
    {
        // 1. Unidad física (tenant-scoped: de otro tenant → 404)
        $unit = $this->resolveUnit($payload);
        if ($unit === null) {
            throw InventoryException::unitNotFound(
                $payload['unidad_fisica_id'] !== '' ? $payload['unidad_fisica_id'] : $payload['codigo_barcode']
            );
        }
        if (!$unit->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
        }

        // 2. Estado que admita movimiento interno (CU-002)
        $this->states->assertCanMove($unit->id(), $unit->estado()->value());

        // 3. Origen (la unidad puede no tener ubicación asignada)
        $origin = null;
        if ($unit->ubicacionId() !== null) {
            $origin = $this->locations->findById($unit->ubicacionId());

            if ($origin === null) {
                throw InventoryException::locationNotFound($unit->ubicacionId());
            }
            if (!$origin->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($origin->tenantId(), $tenantId);
            }
            if ($origin->tipo()->isVirtual()) {
                throw InventoryException::locationIsVirtual($origin->codigo()->value(), 'origen');
            }
        }

        // 4. Destino (existente, activo, mismo tenant, no virtual)
        $destination = $this->resolveDestination($payload);
        if ($destination === null) {
            throw InventoryException::locationNotFound(
                $payload['ubicacion_destino_id'] !== ''
                    ? $payload['ubicacion_destino_id']
                    : $payload['ubicacion_destino_codigo']
            );
        }
        if (!$destination->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($destination->tenantId(), $tenantId);
        }
        if (!$destination->isActive()) {
            throw InventoryException::locationInactive($destination->codigo()->value());
        }
        if ($destination->tipo()->isVirtual()) {
            throw InventoryException::locationIsVirtual($destination->codigo()->value(), 'destino');
        }

        if ($origin !== null && $origin->id() === $destination->id()) {
            throw InventoryException::destinationEqualsOrigin();
        }

        // 5. Capacidad: 1 slot por unidad (mismo criterio que la recepción)
        $slots = (int) ceil($unit->cantidad());
        $destination->incrementarCapacidadUsada($slots);
        $origin?->decrementarCapacidadUsada($slots);

        // 6. Movimiento inmutable (histórico; las correcciones son movimientos
        //    inversos nuevos, spec E8). Si el último movimiento de la unidad es
        //    exactamente el inverso (destino → origen), éste se registra como
        //    corrección con referencia a aquél.
        $previous = $this->movements->findLatestByUnidad($unit->id());
        $esCorreccion = false;
        $movimientoReferenciaId = null;

        if (
            $previous !== null
            && $previous->ubicacionOrigenId() === $destination->id()
            && $previous->ubicacionDestinoId() === ($origin?->id())
        ) {
            $esCorreccion = true;
            $movimientoReferenciaId = $previous->id();
        }

        $movement = Movement::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            cantidad: $unit->cantidad(),
            ubicacionOrigenId: $origin?->id(),
            ubicacionDestinoId: $destination->id(),
            motivo: $payload['motivo'],
            actorId: $actorId,
            referenciaExterna: $payload['referencia_externa'],
            esCorreccion: $esCorreccion,
            movimientoReferenciaId: $movimientoReferenciaId
        );
        $this->movements->save($movement);

        // 7. Unidad en la nueva ubicación (estado sin cambios)
        $unit->moverA($destination->id(), $unit->estado());
        $this->stockUnits->update($unit);

        // 8. Ledger (R1): cantidades intactas, se refresca estado/actualización
        $ledger = $this->ledgers->findByUnidadFisica($unit->id());
        $ledgerAntes = [];

        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unit->id(),
                $unit->cantidad(),
                $unit->estado()->value()
            );
            $this->ledgers->save($ledger);
            $ledgerAntes = $ledger->valoresOperacion();
        } else {
            $ledgerAntes = $ledger->valoresOperacion();
            $ledger->actualizar(['estado_actual' => $unit->estado()->value()]);
            $this->ledgers->update($ledger);
        }

        // 9. Capacidad persistida (origen y destino en la misma transacción)
        if ($origin !== null) {
            $this->locations->update($origin);
        }
        $this->locations->update($destination);

        // 10. Evento MOVIDO (spec §3.9; append-only, con hash de integridad)
        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: 'MOVIDO',
            descripcion: 'Movimiento interno (CU-002)',
            datosOperacion: [
                'movimiento_id' => $movement->id(),
                'ubicacion_origen_id' => $origin?->id(),
                'ubicacion_origen_codigo' => $origin?->codigo()->value(),
                'ubicacion_destino_id' => $destination->id(),
                'ubicacion_destino_codigo' => $destination->codigo()->value(),
                'motivo' => $movement->motivo(),
                'referencia_externa' => $movement->referenciaExterna(),
                'slots' => $slots,
                'es_correccion' => $esCorreccion,
                'movimiento_referencia_id' => $movimientoReferenciaId,
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            // v1.8.2: cantidad_anterior/cantidad_posterior = cantidad de la
            // unidad (el disponible va en datos_operacion).
            cantidadAnterior: $unit->cantidad(),
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: $unit->estado()->value(),
            estadoPosterior: $unit->estado()->value()
        );
        $this->events->save($event);

        // v1.10.0 (CU-002): avisos para el operador. No bloquean la operación.
        $warnings = $this->buildWarnings($unit, $actorId);

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Movimiento registrado correctamente.',
                'warnings' => $warnings,
                'movimiento' => $movement->toPublicArray(),
                'unidad_fisica_id' => $unit->id(),
                'codigo_barcode' => $unit->codigoBarcode(),
                'estado' => $unit->estado()->value(),
                'ubicacion_origen' => $origin === null ? null : [
                    'ubicacion_id' => $origin->id(),
                    'codigo' => $origin->codigo()->value(),
                    'capacidad' => $origin->capacidad(),
                    'capacidad_usada' => $origin->capacidadUsada(),
                ],
                'ubicacion_destino' => [
                    'ubicacion_id' => $destination->id(),
                    'codigo' => $destination->codigo()->value(),
                    'capacidad' => $destination->capacidad(),
                    'capacidad_usada' => $destination->capacidadUsada(),
                ],
                'ledger' => $ledger->toPublicArray(),
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
     * v1.10.0 (CU-002): avisos informativos sobre la unidad movida.
     *
     * @return list<array{codigo: string, mensaje: string}>
     */
    private function buildWarnings(\App\Modules\Inventory\Domain\Entities\StockUnit $unit, ?string $actorId): array
    {
        $warnings = [];

        if ($unit->estado()->value() === \App\Modules\Inventory\Domain\ValueObjects\StockState::RESERVADO) {
            $reserva = $this->reservations->findActivaByUnidad($unit->id());
            $mensaje = 'La unidad estaba en estado RESERVADO al moverla; '
                . 'el movimiento puede afectar al pedido asociado a su reserva activa.';

            if ($reserva !== null) {
                $orden = $reserva->referenciaOrden();
                $mensaje = 'La unidad está RESERVADA (reserva ' . $reserva->id() . ')'
                    . ($orden !== null ? " de la orden {$orden}" : '')
                    . '; moverla puede afectar a la preparación de esa orden.';
            }

            $warnings[] = [
                'codigo' => 'UNIDAD_RESERVADA',
                'mensaje' => $mensaje,
            ];
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUnit(array $payload): ?\App\Modules\Inventory\Domain\Entities\StockUnit
    {
        if ($payload['unidad_fisica_id'] !== '') {
            return $this->stockUnits->findById($payload['unidad_fisica_id']);
        }

        return $this->stockUnits->findByBarcode($payload['codigo_barcode']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveDestination(array $payload): ?\App\Modules\Inventory\Domain\Entities\Location
    {
        if ($payload['ubicacion_destino_id'] !== '') {
            return $this->locations->findById($payload['ubicacion_destino_id']);
        }

        return $this->locations->findByCodigo($payload['ubicacion_destino_codigo']);
    }

    /**
     * Normaliza y valida la entrada. Devuelve el payload canónico que se usa
     * también como base del hash de idempotencia.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $unidadId = trim((string) ($input['unidad_fisica_id'] ?? ''));
        $barcode = trim((string) ($input['codigo_barcode'] ?? ''));

        if ($unidadId === '' && $barcode === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }

        $destinoId = trim((string) ($input['ubicacion_destino_id'] ?? ''));
        $destinoCodigo = trim((string) ($input['ubicacion_destino_codigo'] ?? ''));

        if ($destinoId === '' && $destinoCodigo === '') {
            throw InventoryException::requiredField('ubicacion_destino_id');
        }

        $motivo = trim((string) ($input['motivo'] ?? ''));
        if ($motivo === '') {
            $motivo = Movement::MOTIVO_REUBICACION;
        } else {
            $motivo = strtoupper($motivo);
        }

        $referencia = $this->optionalString($input, 'referencia_externa');

        return [
            'unidad_fisica_id' => $unidadId,
            'codigo_barcode' => $barcode,
            'ubicacion_destino_id' => $destinoId,
            'ubicacion_destino_codigo' => $destinoCodigo,
            'motivo' => $motivo,
            'referencia_externa' => $referencia,
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

        if (mb_strlen($value) > 255) {
            throw InventoryException::fieldMaxLength($field, 255);
        }

        return $value;
    }
}
