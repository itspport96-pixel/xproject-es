<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\InventoryCountRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * ReconcileCountUseCase - CU-011: RECONCILIAR DISCREPANCIAS
 *
 * Aplica una o varias acciones sobre las discrepancias de un conteo
 * COMPLETADO, en UNA sola transacción (R5) con idempotencia obligatoria
 * (R7). Sólo se puede invocar con `inventory.count.reconcile`.
 *
 * Acciones por discrepancia:
 *  - AJUSTAR: cambia la cantidad de la unidad al valor encontrado, crea un
 *    StockEvent `AJUSTE_CANTIDAD` con hash, actualiza el ledger (R1) y la
 *    capacidad de la ubicación (422 si excede la capacidad). Sólo está
 *    permitida en unidades DISPONIBLE o DAÑADO; en cualquier otro estado la
 *    acción válida es JUSTIFICAR (422 con detalle).
 *    Antes de tocar nada compara la cantidad ACTUAL de la unidad con
 *    `cantidad_esperada` de la instantánea: si difieren la hoja está vencida
 *    y responde 422 con el detalle (unidad, esperada, actual) sin ajustar
 *    (v1.9.1): hay que justificar o iniciar un conteo nuevo.
 *    Si la cantidad encontrada es 0 la unidad pasa a PERDIDO (vía
 *    `StateTransitionService::assertCanTransition`), se libera su cupo y se
 *    crea una incidencia INVENTARIO_DESAPARECIDO.
 *  - JUSTIFICAR: no modifica la unidad; exige `justificacion` (422).
 *
 * Cada acción queda registrada en `ajustes_aplicados` con quién la autoriza
 * y su fecha; cuando todas las discrepancias tienen acción el conteo pasa a
 * RECONCILIADO (antes puede quedar parcialmente reconciliado).
 *
 * Input:
 *  - acciones: [{unidad_fisica_id, accion, justificacion?}, ...]
 */
final class ReconcileCountUseCase
{
    public const OPERACION = 'inventory.count.reconcile';

    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly InventoryCountRepositoryInterface $counts,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly StockCalculationService $calculation,
        private readonly InventoryPresenceService $presence,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario que autoriza (claims)
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
            throw InventoryException::requiredField('actor_autoriza');
        }

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw InventoryException::idempotencyKeyRequired();
        }

        return $this->db->transaction(
            function () use ($tenantId, $payload, $idempotencyKey, $actorId): array {
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

                $response = $this->reconcile($tenantId, $payload, (string) $actorId);

                $this->idempotency->complete(
                    $tenantId,
                    $idempotencyKey,
                    self::OPERACION,
                    $response,
                    $response['status']
                );

                return $response;
            }
        );
    }

    // ====================================================================
    // RECONCILIACIÓN (corazón del CU-011)
    // ====================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function reconcile(string $tenantId, array $payload, string $actorId): array
    {
        $count = $this->load($tenantId, $payload['conteo_id']);

        if ($count->estado() !== InventoryCount::ESTADO_COMPLETADO) {
            throw InventoryException::countInvalidState(
                $count->id(),
                $count->estado(),
                InventoryCount::ESTADO_COMPLETADO
            );
        }

        $registros = [];
        $incidencias = [];
        $eventos = [];

        foreach ($payload['acciones'] as $accion) {
            $unidadId = (string) $accion['unidad_fisica_id'];
            $discrepancia = $this->findDiscrepancy($count, $unidadId);

            if ($discrepancia === null) {
                throw InventoryException::invalidField(
                    'acciones',
                    "La unidad física '{$unidadId}' no tiene discrepancia en este conteo."
                );
            }

            if ($count->discrepanciaPendiente($unidadId) === null) {
                throw InventoryException::countDiscrepancyAlreadyResolved($unidadId);
            }

            $justificacion = $accion['justificacion'];

            if ($accion['accion'] === InventoryCount::ACCION_JUSTIFICAR) {
                if ($justificacion === null) {
                    throw InventoryException::countJustificationRequired();
                }

                $registros[] = [
                    'unidad_fisica_id' => $unidadId,
                    'accion' => InventoryCount::ACCION_JUSTIFICAR,
                    'justificacion' => $justificacion,
                    'cantidad_esperada' => (float) $discrepancia['cantidad_esperada'],
                    'cantidad_encontrada' => (float) $discrepancia['cantidad_encontrada'],
                    'cantidad_ajustada' => null,
                    'incidencia_id' => null,
                    'actor_autoriza' => $actorId,
                    'fecha' => date('Y-m-d H:i:s'),
                ];
            } else {
                $resultado = $this->applyAdjustment(
                    $tenantId,
                    $count->id(),
                    $unidadId,
                    $discrepancia,
                    $justificacion,
                    $actorId
                );

                $registros[] = $resultado['registro'];

                if ($resultado['incidencia'] !== null) {
                    $incidencias[] = $resultado['incidencia'];
                }

                foreach ($resultado['eventos'] as $event) {
                    $eventos[] = $event;
                }
            }

            $count->aplicarAccion(end($registros));
        }

        $this->counts->update($count);

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Discrepancias reconciliadas.',
                'conteo' => $count->toPublicArray(),
                'acciones_aplicadas' => $registros,
                'discrepancias_pendientes' => count($count->discrepanciasPendientes()),
                'incidencias' => array_map(
                    static fn(Incident $incidencia): array => $incidencia->toPublicArray(),
                    $incidencias
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

    /**
     * AJUSTAR: cantidad de la unidad → encontrada, evento AJUSTE_CANTIDAD
     * con hash, ledger (R1) y capacidad recalculada por presencia física.
     * Con cantidad 0 la unidad pasa a PERDIDO y se crea la incidencia
     * INVENTARIO_DESAPARECIDO.
     *
     * @param array<string, mixed> $discrepancia
     * @return array{registro: array<string, mixed>, incidencia: ?Incident, eventos: array<int, StockEvent>}
     */
    private function applyAdjustment(
        string $tenantId,
        string $countId,
        string $unidadId,
        array $discrepancia,
        ?string $justificacion,
        string $actorId
    ): array {
        $unit = $this->stockUnits->findById($unidadId);

        if ($unit === null) {
            throw InventoryException::unitNotFound($unidadId);
        }
        if (!$unit->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
        }

        $estadoAnterior = $unit->estado()->value();

        // v1.9.1: AJUSTAR sólo sobre datos vigentes. La instantánea de la
        // hoja (`cantidad_esperada`) refleja el estado de la unidad al
        // iniciar el conteo; si la cantidad actual ya cambió, la hoja está
        // vencida y NO se ajusta (422 con unidad/esperada/actual).
        $esperada = (float) $discrepancia['cantidad_esperada'];
        $actual = $unit->cantidad();

        if (abs($actual - $esperada) > 0.0001) {
            throw InventoryException::countStaleQuantity($unidadId, $esperada, $actual);
        }

        if (!in_array($estadoAnterior, [StockState::DISPONIBLE, StockState::DANADO], true)) {
            throw InventoryException::countAdjustmentStateNotAllowed($unidadId, $estadoAnterior);
        }

        $encontrada = (float) $discrepancia['cantidad_encontrada'];
        $antes = $unit->cantidad();
        $origenId = $unit->ubicacionId();
        $ledger = $this->ledgerFor($tenantId, $unit->id(), $antes, $estadoAnterior);
        $ledgerAntes = $ledger->valoresOperacion();
        $incidencia = null;
        $estadoPosterior = $estadoAnterior;

        if ($encontrada <= 0.0) {
            // Pérdida total: la unidad deja de estar presente (CU-011).
            // v1.9.1: la transición pasa por la matriz (spec §4 + desviación
            // DISPONIBLE → PERDIDO); el ajuste normal no cambia de estado.
            $this->states->assertCanTransition($estadoAnterior, StockState::PERDIDO);

            $estadoPosterior = StockState::PERDIDO;
            $ledger->actualizar(['estado_actual' => StockState::PERDIDO]);
            $this->ledgers->update($ledger);

            $unit->moverA(null, StockState::fromString(StockState::PERDIDO));
            $this->stockUnits->update($unit);
            $this->recalcularCapacidad($tenantId, $origenId);

            $incidencia = Incident::create(
                id: uuid(),
                tenantId: $tenantId,
                tipo: Incident::TIPO_INVENTARIO_DESAPARECIDO,
                descripcion: sprintf(
                    'Conteo %s: la unidad %s no se encontró (esperada %.4f, encontrada 0).',
                    $countId,
                    $unidadId,
                    $esperada
                ),
                unidadFisicaId: $unit->id(),
                ubicacionId: $origenId,
                actorIdReporta: $actorId,
                observaciones: $justificacion
            );
            $this->incidents->save($incidencia);
        } else {
            $unit->actualizar(['cantidad' => $encontrada]);
            $this->stockUnits->update($unit);

            $ledger->actualizar(['cantidad_fisica' => $encontrada]);
            $this->ledgers->update($ledger);

            // 422 capacityExceeded si el nuevo cupo excede la capacidad
            $this->recalcularCapacidad($tenantId, $origenId);
        }

        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: StockEvent::TIPO_AJUSTE_CANTIDAD,
            descripcion: sprintf('Ajuste por conteo físico (CU-011): incidencia %s', $incidencia?->id() ?? '-'),
            datosOperacion: [
                'unidad_fisica_id' => $unit->id(),
                'conteo_id' => $countId,
                'cantidad_esperada' => $esperada,
                'cantidad_encontrada' => $encontrada,
                'diferencia' => $encontrada - $esperada,
                'ubicacion_id' => $origenId,
                'justificacion' => $justificacion,
                'actor_autoriza' => $actorId,
                'incidencia_id' => $incidencia?->id(),
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            cantidadAnterior: $antes,
            cantidadPosterior: $encontrada,
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior
        );
        $this->events->save($event);

        $registro = [
            'unidad_fisica_id' => $unit->id(),
            'accion' => InventoryCount::ACCION_AJUSTAR,
            'justificacion' => $justificacion,
            'cantidad_esperada' => $esperada,
            'cantidad_encontrada' => $encontrada,
            'cantidad_ajustada' => $encontrada,
            'incidencia_id' => $incidencia?->id(),
            'evento_id' => $event->id(),
            'actor_autoriza' => $actorId,
            'fecha' => date('Y-m-d H:i:s'),
        ];

        return [
            'registro' => $registro,
            'incidencia' => $incidencia,
            'eventos' => [$event],
        ];
    }

    /**
     * @param array<string, mixed> $discrepancia
     */
    private function findDiscrepancy(InventoryCount $count, string $unidadId): ?array
    {
        foreach ($count->discrepancias() as $fila) {
            if ((string) $fila['unidad_fisica_id'] === $unidadId) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Recalcula `capacidad_usada` con el criterio de presencia física de
     * `bin/recalculate-capacity.php` (SUM(CEIL(cantidad))).
     */
    private function recalcularCapacidad(string $tenantId, ?string $ubicacionId): void
    {
        if ($ubicacionId === null) {
            return;
        }

        $location = $this->locations->findById($ubicacionId);

        if ($location === null || !$location->belongsToTenant($tenantId) || $location->tipo()->isVirtual()) {
            return;
        }

        $location->setCapacidadUsada($this->presence->cupoUsado($tenantId, $ubicacionId));
        $this->locations->update($location);
    }

    private function ledgerFor(string $tenantId, string $unitId, float $cantidad, string $estado): StockLedger
    {
        $ledger = $this->ledgers->findByUnidadFisica($unitId);

        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(uuid(), $tenantId, $unitId, $cantidad, $estado);
            $this->ledgers->save($ledger);
        }

        return $ledger;
    }

    private function load(string $tenantId, string $countId): InventoryCount
    {
        $count = $this->counts->findById($countId);

        if ($count === null) {
            throw InventoryException::countNotFound($countId);
        }
        if (!$count->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($count->tenantId(), $tenantId);
        }

        return $count;
    }

    // ====================================================================
    // NORMALIZACIÓN Y VALIDACIÓN DE ENTRADA
    // ====================================================================

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $conteoId = trim((string) ($input['conteo_id'] ?? ''));

        if ($conteoId === '') {
            throw InventoryException::requiredField('conteo_id');
        }

        $raw = $input['acciones'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw InventoryException::requiredField('acciones');
        }

        $acciones = [];
        $vistas = [];

        foreach (array_values($raw) as $indice => $entrada) {
            if (!is_array($entrada)) {
                throw InventoryException::invalidField("acciones[{$indice}]", 'Debe ser un objeto.');
            }

            $unidadId = trim((string) ($entrada['unidad_fisica_id'] ?? ''));

            if ($unidadId === '') {
                throw InventoryException::requiredField("acciones[{$indice}].unidad_fisica_id");
            }

            if (isset($vistas[$unidadId])) {
                throw InventoryException::duplicateUnitsInRequest($unidadId);
            }
            $vistas[$unidadId] = true;

            $accion = mb_strtoupper(trim((string) ($entrada['accion'] ?? '')));

            if ($accion === '') {
                throw InventoryException::requiredField("acciones[{$indice}].accion");
            }

            if (!in_array($accion, InventoryCount::ACCIONES_VALIDAS, true)) {
                throw InventoryException::countInvalidAction($accion);
            }

            $justificacion = null;

            if (array_key_exists('justificacion', $entrada) && $entrada['justificacion'] !== null) {
                if (!is_scalar($entrada['justificacion'])) {
                    throw InventoryException::invalidField("acciones[{$indice}].justificacion", 'Debe ser un texto.');
                }

                $justificacion = trim((string) $entrada['justificacion']);

                if ($justificacion === '') {
                    $justificacion = null;
                } elseif (mb_strlen($justificacion) > self::MAX_TEXT) {
                    throw InventoryException::fieldMaxLength("acciones[{$indice}].justificacion", self::MAX_TEXT);
                }
            }

            $acciones[] = [
                'unidad_fisica_id' => $unidadId,
                'accion' => $accion,
                'justificacion' => $justificacion,
            ];
        }

        return ['conteo_id' => $conteoId, 'acciones' => $acciones];
    }
}
