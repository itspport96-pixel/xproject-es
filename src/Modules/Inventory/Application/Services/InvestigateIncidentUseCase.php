<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * InvestigateIncidentUseCase - CU-010: ABRIR INVESTIGACIÓN
 *
 * Transición ABIERTA → INVESTIGACION de una incidencia, en UNA sola
 * transacción (R5). Idempotencia OPCIONAL: como en los flujos con
 * idempotencia por estado, una segunda llamada sobre una incidencia ya en
 * INVESTIGACION devuelve 422, por eso no se exige Idempotency-Key.
 */
final class InvestigateIncidentUseCase
{
    public const OPERACION = 'inventory.incident.investigate';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param string|null $idempotencyKey Header Idempotency-Key (opcional)
     * @param string|null $actorId Usuario que inicia la investigación (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $incidentId, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $incidentId, $idempotencyKey, $actorId): array {
                $payload = ['incidencia_id' => $incidentId, 'accion' => 'investigate'];

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

                $incident = $this->load($tenantId, $incidentId);
                $incident->iniciarInvestigacion();
                $this->incidents->update($incident);

                $response = [
                    'status' => 200,
                    'data' => [
                        'mensaje' => 'Incidencia en investigación.',
                        'incidencia' => $incident->toPublicArray(),
                        'actor_id' => $actorId,
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
}
