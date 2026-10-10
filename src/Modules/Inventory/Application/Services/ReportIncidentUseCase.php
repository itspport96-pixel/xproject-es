<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Entities\Location;
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
 * ReportIncidentUseCase - CU-010: REPORTAR INCIDENCIA
 *
 * Crea una incidencia en estado ABIERTA en UNA sola transacción (R5) con
 * idempotencia obligatoria (R7):
 *
 *  1. `tipo` obligatorio (los 7 tipos de la spec + OTRO), `descripcion`
 *     obligatoria; `unidad_fisica_id`, `ubicacion_id` y `observaciones`
 *     opcionales.
 *  2. Si la petición involucra una unidad física se crea además un
 *     StockEvent con hash y se garantiza su ledger (R1 intacto):
 *       - DAÑO → la unidad pasa a DAÑADO con StateTransitionService y su
 *         ledger `estado_actual`; la ubicación NO cambia (sigue presente,
 *         el cupo no se toca).
 *       - PERDIDA → la unidad pasa a PERDIDO SIN ubicación y se libera su
 *         cupo con el mismo criterio de presencia física que
 *         `bin/recalculate-capacity.php` (SUM(CEIL(cantidad))).
 *       - DISCREPANCIA_CANTIDAD → además se crea un InventoryCount
 *         PENDIENTE para la ubicación de la incidencia y se vincula.
 *       - UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO (v1.10.0, E4) →
 *         la unidad pasa a BLOQUEADO si la matriz de transiciones lo admite;
 *         la ubicación NO cambia (el cupo no se toca). Si la unidad ya está
 *         BLOQUEADO/PERDIDO, o su estado no admite BLOQUEADO (ENTREGADO,
 *         DEVUELTO), el reporte NO falla: la unidad queda como estaba y la
 *         incidencia se abre igualmente.
 *       - El resto de tipos sólo dejan la incidencia para investigación
 *         manual (sin cambio de estado de la unidad).
 *  3. El evento usa el nombre de la spec cuando existe (DAÑADO, PERDIDO) y
 *     `INCIDENCIA` para los tipos sin cambio de estado.
 *
 * Input:
 *  - tipo (obligatorio), descripcion (obligatoria)
 *  - unidad_fisica_id?, ubicacion_id?, observaciones?
 *
 * @see ReportIncidentUseCase para el detalle de respuestas 201/404/422
 */
final class ReportIncidentUseCase
{
    public const OPERACION = 'inventory.incident.report';

    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly InventoryCountRepositoryInterface $counts,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly InventoryPresenceService $presence,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario que reporta (claims)
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

                $response = $this->report($tenantId, $payload, (string) $actorId);

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

    // ====================================================================
    // REPORTE DE INCIDENCIA (corazón del CU-010)
    // ====================================================================

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function report(string $tenantId, array $payload, string $actorId): array
    {
        $unit = null;

        if ($payload['unidad_fisica_id'] !== null) {
            $unit = $this->stockUnits->findById($payload['unidad_fisica_id']);

            if ($unit === null) {
                throw InventoryException::unitNotFound($payload['unidad_fisica_id']);
            }
            if (!$unit->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($unit->tenantId(), $tenantId);
            }
        }

        $ubicacionId = $payload['ubicacion_id'] ?? $unit?->ubicacionId();
        $location = $ubicacionId !== null ? $this->loadLocation($tenantId, $ubicacionId) : null;

        // DISCREPANCIA_CANTIDAD genera un conteo físico para su ubicación
        $conteo = null;

        if ($payload['tipo'] === Incident::TIPO_DISCREPANCIA_CANTIDAD) {
            if ($location === null) {
                throw InventoryException::requiredField('ubicacion_id');
            }

            $conteo = InventoryCount::crear(
                id: uuid(),
                tenantId: $tenantId,
                ubicacionId: $location->id(),
                usuarioResponsableId: $actorId,
                fechaConteo: new \DateTimeImmutable(),
                listaTeorica: $this->presence->snapshot($tenantId, $location->id())
            );
            $this->counts->save($conteo);
        }

        $incident = Incident::create(
            id: uuid(),
            tenantId: $tenantId,
            tipo: $payload['tipo'],
            descripcion: $payload['descripcion'],
            unidadFisicaId: $unit?->id(),
            ubicacionId: $location?->id(),
            actorIdReporta: $actorId,
            observaciones: $payload['observaciones']
        );

        if ($conteo !== null) {
            $incident->vincularConteo($conteo->id());
        }

        $this->incidents->save($incident);

        $unidad = null;
        $eventos = [];

        if ($unit !== null) {
            $resultado = $this->applyUnitEffect($tenantId, $incident, $unit, $actorId);
            $unidad = $resultado['unidad'];
            $eventos = $resultado['eventos'];
        }

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Incidencia reportada correctamente.',
                'incidencia' => $incident->toPublicArray(),
                'unidad' => $unidad,
                'conteo' => $conteo?->toPublicArray(),
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
     * Efecto del reporte sobre la unidad física: evento con hash + ledger
     * (siempre) y cambio de estado/capacidad para DAÑO, PERDIDA y bloqueo
     * (E4) en UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO.
     *
     * @return array{unidad: array<string, mixed>, eventos: array<int, StockEvent>}
     */
    private function applyUnitEffect(string $tenantId, Incident $incident, StockUnit $unit, string $actorId): array
    {
        $estadoAnterior = $unit->estado()->value();
        $ledger = $this->ledgerFor($tenantId, $unit->id(), $unit->cantidad(), $estadoAnterior);
        $ledgerAntes = $ledger->valoresOperacion();
        $tipo = $incident->tipo();
        $estadoPosterior = $estadoAnterior;
        $ubicacionId = $unit->ubicacionId();

        if ($tipo === Incident::TIPO_DANO) {
            $this->states->assertCanTransition($estadoAnterior, StockState::DANADO);
            $estadoPosterior = StockState::DANADO;
            $ledger->actualizar(['estado_actual' => StockState::DANADO]);
            $this->ledgers->update($ledger);
            $unit->moverA($unit->ubicacionId(), StockState::fromString(StockState::DANADO));
            $this->stockUnits->update($unit);
        } elseif ($tipo === Incident::TIPO_PERDIDA) {
            // v1.9.1: la transición pasa por la matriz (spec §4 + desviación
            // aprobada DISPONIBLE → PERDIDO): sólo DEVUELTO, DAÑADO,
            // BLOQUEADO y DISPONIBLE pueden marcar PERDIDO; el resto
            // (RESERVADO, EN_PREPARACION, CARGADO, EN_TRANSITO, ENTREGADO y
            // la propia PERDIDO) devuelve 422 con field `estado`.
            $this->states->assertCanTransition($estadoAnterior, StockState::PERDIDO);

            $estadoPosterior = StockState::PERDIDO;
            $ledger->actualizar(['estado_actual' => StockState::PERDIDO]);
            $this->ledgers->update($ledger);
            // La unidad deja de estar presente: sin ubicación, como en CU-009
            $unit->moverA(null, StockState::fromString(StockState::PERDIDO));
            $this->stockUnits->update($unit);
            $this->releasePresenceCupo($tenantId, $ubicacionId);
        } elseif (in_array($tipo, Incident::TIPOS_CON_RESOLUCION_UNIDAD, true)
            && $this->states->canTransition($estadoAnterior, StockState::BLOQUEADO)
        ) {
            // v1.10.0 (E4): bloqueo al reportar UBICACION_DESCONOCIDA /
            // INVENTARIO_DESAPARECIDO. Sólo si la matriz admite el paso; en
            // BLOQUEADO/PERDIDO/ENTREGADO/DEVUELTO se abre la incidencia sin
            // tocar la unidad (ver docblock).
            $estadoPosterior = StockState::BLOQUEADO;
            $ledger->actualizar(['estado_actual' => StockState::BLOQUEADO]);
            $this->ledgers->update($ledger);
            // La ubicación no cambia: la unidad sigue presente y el cupo
            // tampoco (InventoryPresenceService).
            $unit->moverA($unit->ubicacionId(), StockState::fromString(StockState::BLOQUEADO));
            $this->stockUnits->update($unit);
        }

        $tipoEvento = match ($tipo) {
            Incident::TIPO_DANO => StockEvent::TIPO_DANADO,
            Incident::TIPO_PERDIDA => StockEvent::TIPO_PERDIDO,
            default => 'INCIDENCIA',
        };

        if ($estadoPosterior === StockState::BLOQUEADO && $estadoAnterior !== StockState::BLOQUEADO) {
            $tipoEvento = StockEvent::TIPO_BLOQUEADO;
        }

        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: $tipoEvento,
            descripcion: sprintf('Incidencia %s reportada (CU-010): %s', $tipo, $incident->id()),
            datosOperacion: [
                'incidencia_id' => $incident->id(),
                'tipo_incidencia' => $tipo,
                'unidad_fisica_id' => $unit->id(),
                'ubicacion_id' => $ubicacionId,
                'estado_unidad' => $estadoPosterior,
                'actor_id_reporta' => $actorId,
            ] + StockLedger::contextoOperacion($ledgerAntes, $ledger->valoresOperacion()),
            cantidadAnterior: $unit->cantidad(),
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior
        );
        $this->events->save($event);

        return [
            'unidad' => [
                'unidad_fisica_id' => $unit->id(),
                'estado' => $estadoPosterior,
                'ubicacion_id' => $unit->ubicacionId(),
                'cantidad' => $unit->cantidad(),
            ],
            'eventos' => [$event],
        ];
    }

    /**
     * Libera el cupo de la ubicación de origen con el criterio de presencia
     * física de `bin/recalculate-capacity.php` (SUM(CEIL(cantidad)) de las
     * unidades presentes; las virtuales no llevan contador).
     */
    private function releasePresenceCupo(string $tenantId, ?string $ubicacionId): void
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

    private function loadLocation(string $tenantId, string $ubicacionId): Location
    {
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

        return $location;
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

    // ====================================================================
    // NORMALIZACIÓN Y VALIDACIÓN DE ENTRADA
    // ====================================================================

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $tipo = trim((string) ($input['tipo'] ?? ''));

        if ($tipo === '') {
            throw InventoryException::requiredField('tipo');
        }

        if (!in_array($tipo, Incident::TIPOS_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'tipo',
                'Tipo de incidencia desconocido: \'' . $tipo . '\'. Válidos: '
                . implode(', ', Incident::TIPOS_VALIDOS) . '.'
            );
        }

        $descripcion = trim((string) ($input['descripcion'] ?? ''));

        if ($descripcion === '') {
            throw InventoryException::requiredField('descripcion');
        }

        if (mb_strlen($descripcion) > self::MAX_TEXT) {
            throw InventoryException::fieldMaxLength('descripcion', self::MAX_TEXT);
        }

        $unidad = trim((string) ($input['unidad_fisica_id'] ?? ''));
        $ubicacion = trim((string) ($input['ubicacion_id'] ?? ''));

        return [
            'tipo' => $tipo,
            'descripcion' => $descripcion,
            'unidad_fisica_id' => $unidad === '' ? null : $unidad,
            'ubicacion_id' => $ubicacion === '' ? null : $ubicacion,
            'observaciones' => $this->text($input['observaciones'] ?? null),
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
