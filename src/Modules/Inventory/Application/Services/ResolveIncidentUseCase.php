<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * ResolveIncidentUseCase - CU-010: RESOLVER INCIDENCIA
 *
 * Cierra una incidencia en RESUELTA o NO_RESUELTO, en UNA sola transacción
 * (R5). Idempotencia OPCIONAL (idempotencia por estado: una incidencia ya
 * cerrada devuelve 422).
 *
 * El detalle (`descripcion_resolucion`, `acciones_tomadas`, `compensacion`)
 * se guarda en el JSON `resolucion` junto con el actor y la fecha de
 * resolución.
 *
 * v1.10.0 (E4): para UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO el input
 * exige `resultado`:
 *  - ENCONTRADA: la unidad reaparece. `ubicacion_destino_id` opcional indica
 *    dónde se ha encontrado (si difiere de la actual se recalcula el cupo de
 *    ambas con el criterio de presencia física); estado → DISPONIBLE si la
 *    matriz lo admite (si no, se queda como está).
 *  - NO_ENCONTRADA: estado → PERDIDO y la unidad queda sin ubicación
 *    liberando su cupo (si la matriz lo admite; si no, se queda como está).
 * En ambos casos se registra un StockEvent con hash. Para el resto de tipos
 * `resultado` no se admite (422) y resolver sigue sin tocar unidades.
 *
 * Input:
 *  - estado (RESUELTA | NO_RESUELTO, obligatorio)
 *  - descripcion_resolucion (obligatoria), acciones_tomadas (obligatoria)
 *  - compensacion? (opcional)
 *  - resultado? (ENCONTRADA | NO_ENCONTRADA; obligatorio para
 *    UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO)
 *  - ubicacion_destino_id? (sólo con resultado ENCONTRADA)
 */
final class ResolveIncidentUseCase
{
    public const OPERACION = 'inventory.incident.resolve';

    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly InventoryPresenceService $presence,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (opcional)
     * @param string|null          $actorId Usuario que resuelve (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $incidentId, array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($input);

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $incidentId, $payload, $idempotencyKey, $actorId): array {
                $request = ['incidencia_id' => $incidentId] + $payload;

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

                $incident = $this->load($tenantId, $incidentId);

                // BLOQUE R2 (principio 4): la incidencia de una recepción ciega
                // no la resuelve ni el receptor ni el planificador del documento.
                if ($incident->resolucionRestringidaPara((string) $actorId)) {
                    throw InventoryException::incidentResolutionRestricted();
                }

                $this->assertResultado($incident, $payload);
                $incident->resolver($payload['estado'], $payload['detalle'], (string) $actorId);
                $this->incidents->update($incident);

                $unidad = null;

                if ($payload['resultado'] !== null && $incident->unidadFisicaId() !== null) {
                    $unidad = $this->applyUnitEffect($tenantId, $incident, $payload, (string) $actorId);
                }

                $response = [
                    'status' => 200,
                    'data' => [
                        'mensaje' => 'Incidencia cerrada correctamente.',
                        'incidencia' => $incident->toPublicArray(),
                        'resolucion' => $incident->resolucionDetalle(),
                        'unidad' => $unidad,
                    ],
                ];

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
     * v1.10.0 (E4): `resultado` obligatorio para los tipos que cierran con
     * la unidad (UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO) y prohibido
     * para el resto.
     *
     * @param array<string, mixed> $payload
     */
    private function assertResultado(Incident $incident, array $payload): void
    {
        $soporta = in_array($incident->tipo(), Incident::TIPOS_CON_RESOLUCION_UNIDAD, true);

        if ($soporta && $payload['resultado'] === null) {
            throw InventoryException::requiredField('resultado');
        }

        if (!$soporta && $payload['resultado'] !== null) {
            throw InventoryException::invalidField(
                'resultado',
                'Sólo se admite para incidencias ' . implode(' / ', Incident::TIPOS_CON_RESOLUCION_UNIDAD) . '.'
            );
        }

        if ($payload['resultado'] !== null && $payload['ubicacion_destino_id'] !== null
            && $payload['resultado'] !== Incident::RESULTADO_ENCONTRADA
        ) {
            throw InventoryException::invalidField(
                'ubicacion_destino_id',
                'Sólo se admite con resultado ' . Incident::RESULTADO_ENCONTRADA . '.'
            );
        }
    }

    /**
     * v1.10.0 (E4): efecto de ENCONTRADA / NO_ENCONTRADA sobre la unidad.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function applyUnitEffect(string $tenantId, Incident $incident, array $payload, string $actorId): ?array
    {
        $unit = $this->stockUnits->findById($incident->unidadFisicaId());

        if ($unit === null || !$unit->belongsToTenant($tenantId)) {
            // La incidencia apunta a una unidad ya inexistente o de otro
            // tenant: se cierra igualmente, sin tocar nada.
            return null;
        }

        $estadoAnterior = $unit->estado()->value();
        $ubicacionAnterior = $unit->ubicacionId();
        $estadoPosterior = $estadoAnterior;
        $ubicacionNueva = $ubicacionAnterior;
        $resultado = (string) $payload['resultado'];

        if ($resultado === Incident::RESULTADO_ENCONTRADA) {
            $ubicacionNueva = $ubicacionAnterior;

            if ($payload['ubicacion_destino_id'] !== null) {
                $destino = $this->loadLocation($tenantId, (string) $payload['ubicacion_destino_id']);
                $ubicacionNueva = $destino->id();
            }

            if ($this->states->canTransition($estadoAnterior, StockState::DISPONIBLE)) {
                $estadoPosterior = StockState::DISPONIBLE;
            }
        } else {
            if ($this->states->canTransition($estadoAnterior, StockState::PERDIDO)) {
                $estadoPosterior = StockState::PERDIDO;
                $ubicacionNueva = null;
            }
        }

        if ($estadoPosterior !== $estadoAnterior) {
            $unit->moverA($ubicacionNueva, StockState::fromString($estadoPosterior));
        } elseif ($ubicacionNueva !== $ubicacionAnterior) {
            $unit->moverA($ubicacionNueva, $unit->estado());
        }
        $this->stockUnits->update($unit);

        // Ledger (R1 intacto): sólo cambia estado_actual.
        $ledger = $this->ledgers->findByUnidadFisica($unit->id());

        if ($ledger === null) {
            $ledger = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unit->id(),
                $unit->cantidad(),
                $estadoPosterior
            );
            $this->ledgers->save($ledger);
        } else {
            $ledger->actualizar(['estado_actual' => $estadoPosterior]);
            $this->ledgers->update($ledger);
        }

        // Cupo: recálculo con el criterio de presencia física para las
        // ubicaciones afectadas (antigua y, si cambia, la nueva).
        $this->refreshCupo($tenantId, $ubicacionAnterior);
        $this->refreshCupo($tenantId, $ubicacionNueva);

        $cambioEstado = $estadoPosterior !== $estadoAnterior;
        $tipoEvento = match (true) {
            $resultado === Incident::RESULTADO_ENCONTRADA && $cambioEstado => StockEvent::TIPO_DISPONIBLE,
            $resultado === Incident::RESULTADO_NO_ENCONTRADA && $cambioEstado => StockEvent::TIPO_PERDIDO,
            default => 'INCIDENCIA',
        };

        $event = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: $tipoEvento,
            descripcion: sprintf('Incidencia %s resuelta con resultado %s: %s', $incident->tipo(), $resultado, $incident->id()),
            datosOperacion: [
                'incidencia_id' => $incident->id(),
                'tipo_incidencia' => $incident->tipo(),
                'resultado' => $resultado,
                'ubicacion_anterior_id' => $ubicacionAnterior,
                'ubicacion_id' => $unit->ubicacionId(),
                'estado_unidad' => $estadoPosterior,
                'actor_resuelve' => $actorId,
            ],
            cantidadAnterior: $unit->cantidad(),
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior
        );
        $this->events->save($event);

        return [
            'unidad_fisica_id' => $unit->id(),
            'estado' => $estadoPosterior,
            'ubicacion_id' => $unit->ubicacionId(),
            'cantidad' => $unit->cantidad(),
            'resultado' => $resultado,
            'evento' => [
                'id' => $event->id(),
                'tipo_evento' => $event->tipoEvento(),
                'hash_integridad' => $event->hashIntegridad(),
            ],
        ];
    }

    /**
     * Recalcula el cupo usado de una ubicación con el criterio de presencia
     * física (SUM(CEIL(cantidad)) de las unidades presentes).
     */
    private function refreshCupo(string $tenantId, ?string $ubicacionId): void
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

    private function loadLocation(string $tenantId, string $ubicacionId): \App\Modules\Inventory\Domain\Entities\Location
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
        if ($location->tipo()->isVirtual()) {
            throw InventoryException::locationIsVirtual($location->codigo()->value(), 'destino');
        }

        return $location;
    }

    private function load(string $tenantId, string $incidentId): Incident
    {
        $incident = $this->incidents->findById($incidentId);

        if ($incident === null) {
            throw InventoryException::incidentNotFound($incidentId);
        }
        if (!$incident->belongsToTenant($tenantId)) {
            throw InventoryException::tenantIsolationViolation($incident->tenantId(), $tenantId);
        }

        return $incident;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{estado: string, resultado: ?string, ubicacion_destino_id: ?string, detalle: array<string, mixed>}
     */
    private function normalize(array $input): array
    {
        $estado = mb_strtoupper(trim((string) ($input['estado'] ?? '')));

        if ($estado === '') {
            throw InventoryException::requiredField('estado');
        }

        if (!in_array($estado, [Incident::ESTADO_RESUELTA, Incident::ESTADO_NO_RESUELTO], true)) {
            throw InventoryException::invalidField(
                'estado',
                'Estado final desconocido: \'' . $estado . '\'. Válidos: '
                . Incident::ESTADO_RESUELTA . ', ' . Incident::ESTADO_NO_RESUELTO . '.'
            );
        }

        $descripcion = trim((string) ($input['descripcion_resolucion'] ?? ''));

        if ($descripcion === '') {
            throw InventoryException::requiredField('descripcion_resolucion');
        }

        $acciones = trim((string) ($input['acciones_tomadas'] ?? ''));

        if ($acciones === '') {
            throw InventoryException::requiredField('acciones_tomadas');
        }

        foreach (['descripcion_resolucion' => $descripcion, 'acciones_tomadas' => $acciones] as $campo => $valor) {
            if (mb_strlen($valor) > self::MAX_TEXT) {
                throw InventoryException::fieldMaxLength($campo, self::MAX_TEXT);
            }
        }

        $resultado = mb_strtoupper(trim((string) ($input['resultado'] ?? '')));

        if ($resultado === '') {
            $resultado = null;
        } elseif (!in_array($resultado, Incident::RESULTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'resultado',
                'Resultado desconocido: \'' . $resultado . '\'. Válidos: '
                . implode(', ', Incident::RESULTADOS_VALIDOS) . '.'
            );
        }

        $ubicacionDestino = trim((string) ($input['ubicacion_destino_id'] ?? ''));

        return [
            'estado' => $estado,
            'resultado' => $resultado,
            'ubicacion_destino_id' => $ubicacionDestino === '' ? null : $ubicacionDestino,
            'detalle' => [
                'descripcion_resolucion' => $descripcion,
                'acciones_tomadas' => $acciones,
                'compensacion' => $this->text($input['compensacion'] ?? null),
                'resultado' => $resultado,
                'ubicacion_destino_id' => $ubicacionDestino === '' ? null : $ubicacionDestino,
            ],
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw InventoryException::invalidField('compensacion', 'Debe ser un texto.');
        }

        $texto = trim((string) $value);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > self::MAX_TEXT) {
            throw InventoryException::fieldMaxLength('compensacion', self::MAX_TEXT);
        }

        return $texto;
    }
}
