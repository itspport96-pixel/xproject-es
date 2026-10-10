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
 * CompletePickingUseCase - CU-004: COMPLETAR PICKING
 *
 * Pasa UN lote de EN_CURSO a COMPLETADA en UNA sola transacción (R5):
 *
 *  1. Idempotencia (R7): misma clave + mismo lote → replay; la clave es
 *     OPCIONAL porque la operación está gated por estado (un lote ya
 *     COMPLETADA devuelve 409 sin duplicar nada).
 *  2. El lote debe existir en el tenant (404) y TODAS sus operaciones deben
 *     estar EN_CURSO (409 si no).
 *  3. No toca unidades, ledger ni capacidad: la verificación (y después la
 *     carga) son pasos aparte (spec CU-004/CU-005).
 *
 * Input: id_lote_picking (path), cuerpo ignorado.
 */
final class CompletePickingUseCase
{
    public const OPERACION = 'inventory.picking.complete';

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

                $response = $this->complete($tenantId, $payload);

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
    private function complete(string $tenantId, array $payload): array
    {
        $operations = $this->pickings->findByLote($payload['id_lote_picking']);

        if ($operations === []) {
            throw InventoryException::pickingLotNotFound($payload['id_lote_picking']);
        }

        foreach ($operations as $operation) {
            if (!$operation->belongsToTenant($tenantId)) {
                throw InventoryException::tenantIsolationViolation($operation->tenantId(), $tenantId);
            }

            // EN_CURSO → COMPLETADA (validado por la entidad, 409 si no)
            $operation->cambiarEstado(PickingOperation::ESTADO_COMPLETADA);
            $this->pickings->update($operation);
        }

        return [
            'status' => 200,
            'data' => [
                'mensaje' => 'Picking completado correctamente.',
                'id_lote_picking' => $payload['id_lote_picking'],
                'estado' => PickingOperation::ESTADO_COMPLETADA,
                'operaciones' => array_map(
                    static fn(PickingOperation $op): array => $op->toPublicArray(),
                    $operations
                ),
            ],
        ];
    }
}
