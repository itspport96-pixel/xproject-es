<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * BlockStockUseCase - v1.10.0 (E4): BLOQUEAR / DESBLOQUEAR UNIDAD
 *
 * Acciones manuales del operador protegidas por el permiso
 * `inventory.stock.block` (el check es del middleware de la ruta; este caso
 * de uso sólo aplica la matriz de transiciones).
 *
 * Todo en UNA transacción (R5), idempotencia OPCIONAL:
 *
 *  - BLOQUEAR: X → BLOQUEADO si la matriz lo admite (si no, 422 con field
 *    `estado`, igual que el resto de transiciones). La ubicación NO cambia:
 *    la unidad sigue presente y el cupo tampoco.
 *  - DESBLOQUEAR: BLOQUEADO → DISPONIBLE (si no estaba BLOQUEADO, 422).
 *    Tampoco toca ubicación ni cupo.
 *  - En ambos casos se actualiza `stock_ledgers.estado_actual` (R1) y se
 *    crea un StockEvent con hash (BLOQUEADO / DESBLOQUEADO).
 *
 * Input:
 *  - accion (BLOQUEAR | DESBLOQUEAR, obligatoria)
 */
final class BlockStockUseCase
{
    public const OPERACION = 'inventory.stock.block';

    public const ACCION_BLOQUEAR = 'BLOQUEAR';
    public const ACCION_DESBLOQUEAR = 'DESBLOQUEAR';

    public const ACCIONES_VALIDAS = [
        self::ACCION_BLOQUEAR,
        self::ACCION_DESBLOQUEAR,
    ];

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (opcional)
     * @param string|null          $actorId        Usuario autenticado (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $unitId, array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($unitId, $input);

        return $this->db->transaction(
            function () use ($tenantId, $payload, $idempotencyKey, $actorId): array {
                $request = ['unidad_fisica_id' => $payload['unidad_fisica_id'], 'accion' => $payload['accion']];

                if ($idempotencyKey !== null) {
                    $replay = $this->idempotency->tryBegin(
                        $tenantId,
                        $idempotencyKey,
                        self::OPERACION,
                        IdempotencyService::requestHash($request),
                        $actorId
                    );

                    if ($replay !== null) {
                        return $replay + ['_replay' => true];
                    }
                }

                $response = $this->apply($tenantId, $payload, $actorId);

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

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function apply(string $tenantId, array $payload, ?string $actorId): array
    {
        $unit = $this->stockUnits->findById($payload['unidad_fisica_id']);

        if ($unit === null) {
            throw InventoryException::unitNotFound($payload['unidad_fisica_id']);
        }
        if (!$unit->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
        }

        $estadoAnterior = $unit->estado()->value();
        $esBloquear = $payload['accion'] === self::ACCION_BLOQUEAR;
        $estadoPosterior = $esBloquear ? StockState::BLOQUEADO : StockState::DISPONIBLE;

        // Matriz de transiciones: mismas reglas que el resto de cambios de
        // estado (X → X inválido, 422 con field `estado`).
        $this->states->assertCanTransition($estadoAnterior, $estadoPosterior);

        $ledger = $this->ledgers->findByUnidadFisica($unit->id());
        $ledgerAntes = [];

        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unit->id(),
                $unit->cantidad(),
                $estadoPosterior
            );
            $this->ledgers->save($ledger);
            $ledgerAntes = $ledger->valoresOperacion();
        } else {
            $ledgerAntes = $ledger->valoresOperacion();
            $ledger->actualizar(['estado_actual' => $estadoPosterior]);
            $this->ledgers->update($ledger);
        }

        // La ubicación no cambia: la unidad sigue presente (presencia física
        // de InventoryPresenceService) y el cupo tampoco se toca.
        $unit->moverA($unit->ubicacionId(), StockState::fromString($estadoPosterior));
        $this->stockUnits->update($unit);

        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: $esBloquear ? StockEvent::TIPO_BLOQUEADO : StockEvent::TIPO_DESBLOQUEADO,
            descripcion: $esBloquear
                ? 'Unidad bloqueada manualmente (v1.10.0 E4)'
                : 'Unidad desbloqueada manualmente (v1.10.0 E4)',
            datosOperacion: [
                'unidad_fisica_id' => $unit->id(),
                'accion' => $payload['accion'],
                'ubicacion_id' => $unit->ubicacionId(),
                'estado_unidad' => $estadoPosterior,
                'actor_id' => $actorId,
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            cantidadAnterior: $unit->cantidad(),
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior
        );
        $this->events->save($event);

        return [
            'status' => 200,
            'data' => [
                'mensaje' => $esBloquear
                    ? 'Unidad bloqueada correctamente.'
                    : 'Unidad desbloqueada correctamente.',
                'unidad' => [
                    'unidad_fisica_id' => $unit->id(),
                    'estado' => $estadoPosterior,
                    'ubicacion_id' => $unit->ubicacionId(),
                    'cantidad' => $unit->cantidad(),
                ],
                'estado_anterior' => $estadoAnterior,
                'estado' => $estadoPosterior,
                'ledger' => $ledger->toPublicArray(),
                'evento' => [
                    'id' => $event->id(),
                    'tipo_evento' => $event->tipoEvento(),
                    'hash_integridad' => $event->hashIntegridad(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{unidad_fisica_id: string, accion: string}
     */
    private function normalize(string $unitId, array $input): array
    {
        $unidad = trim((string) ($input['unidad_fisica_id'] ?? $unitId));

        if ($unidad === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }

        $accion = mb_strtoupper(trim((string) ($input['accion'] ?? '')));

        if ($accion === '') {
            throw InventoryException::requiredField('accion');
        }

        if (!in_array($accion, self::ACCIONES_VALIDAS, true)) {
            throw InventoryException::invalidField(
                'accion',
                'Acción desconocida: \'' . $accion . '\'. Válidas: '
                . implode(', ', self::ACCIONES_VALIDAS) . '.'
            );
        }

        return [
            'unidad_fisica_id' => $unidad,
            'accion' => $accion,
        ];
    }
}
