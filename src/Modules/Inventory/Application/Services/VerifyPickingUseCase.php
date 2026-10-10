<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\PickingOperation;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * VerifyPickingUseCase - CU-004: VERIFICAR PICKING
 *
 * Supervisa UN lote de COMPLETADA a VERIFICADA en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): clave OPCIONAL (la operación está gated por estado:
 *     un lote ya VERIFICADA devuelve 409).
 *  2. El lote debe existir en el tenant (404) y TODAS sus operaciones deben
 *     estar COMPLETADA (409 si no).
 *  3. El supervisor es el usuario autenticado y debe ser DISTINTO del
 *     selector de cada operación (422, spec CU-004).
 *  4. Registra `actor_id_supervisor` en cada fila del lote.
 *
 * Input: id_lote_picking (path), cuerpo ignorado.
 */
final class VerifyPickingUseCase
{
    public const OPERACION = 'inventory.picking.verify';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly PickingOperationRepositoryInterface $pickings,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(
        string $idLotePicking,
        array $input = [],
        ?string $idempotencyKey = null,
        ?string $actorId = null
    ): array {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = [
            'id_lote_picking' => trim($idLotePicking),
        ];

        if ($payload['id_lote_picking'] === '') {
            throw InventoryException::requiredField('id_lote_picking');
        }

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id_supervisor');
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

                $response = $this->verify($tenantId, $payload, (string) $actorId);

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
    private function verify(string $tenantId, array $payload, string $actorId): array
    {
        $operations = $this->pickings->findByLote($payload['id_lote_picking']);

        if ($operations === []) {
            throw InventoryException::pickingLotNotFound($payload['id_lote_picking']);
        }

        foreach ($operations as $operation) {
            if (!$operation->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($operation->tenantId(), $tenantId);
            }

            // COMPLETADA → VERIFICADA con supervisor ≠ selector (409/422)
            $operation->cambiarEstado(PickingOperation::ESTADO_VERIFICADA, $actorId);
            $this->pickings->update($operation);
        }

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Picking verificado correctamente.',
                'id_lote_picking' => $payload['id_lote_picking'],
                'estado' => PickingOperation::ESTADO_VERIFICADA,
                'actor_id_supervisor' => $actorId,
                'operaciones' => array_map(
                    static fn(PickingOperation $op): array => $op->toPublicArray(),
                    $operations
                ),
            ],
        ];
    }
}
