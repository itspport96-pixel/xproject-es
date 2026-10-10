<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\InventoryCountRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * StartCountUseCase - CU-011: INICIAR CONTEO FÍSICO
 *
 * Crea UN conteo físico en estado PENDIENTE con su instantánea
 * `lista_teorica`, en UNA sola transacción (R5) con idempotencia
 * obligatoria (R7):
 *
 *  1. `ubicacion_id` opcional: si se indica, sólo se cuentan las unidades
 *     presentes en esa ubicación (física, activa y no virtual); si no, se
 *     cuentan todas las unidades no virtuales del tenant.
 *  2. `fecha` opcional (`fecha_conteo`); por defecto ahora.
 *  3. La lista teórica guarda por unidad: barcode, producto, ubicación y
 *     `cantidad_esperada`. Es inmutable: los resultados se comparan contra ella.
 */
final class StartCountUseCase
{
    public const OPERACION = 'inventory.count.start';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly LocationRepositoryInterface $locations,
        private readonly InventoryCountRepositoryInterface $counts,
        private readonly InventoryPresenceService $presence,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en HTTP)
     * @param string|null          $actorId Usuario responsable (claims)
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
            throw InventoryException::requiredField('usuario_responsable_id');
        }

        // Huella de idempotencia sobre los campos DEL CLIENTE: la fecha por
        // defecto la genera el servidor y cambiaría en cada reintento, lo que
        // rompería el replay (R7) con 409 "payload distinto".
        $request = [
            'ubicacion_id' => trim((string) ($input['ubicacion_id'] ?? '')),
            'fecha' => trim((string) ($input['fecha'] ?? '')),
        ];

        return $this->db->transaction(
            function () use ($tenantId, $payload, $request, $idempotencyKey, $actorId): array {
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

                $ubicacionId = $payload['ubicacion_id'];

                if ($ubicacionId !== null) {
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
                        throw InventoryException::locationIsVirtual($location->codigo()->value(), 'conteo');
                    }
                }

                $count = InventoryCount::crear(
                    id: uuid(),
                    tenantId: $tenantId,
                    ubicacionId: $ubicacionId,
                    usuarioResponsableId: $actorId,
                    fechaConteo: $payload['fecha'],
                    listaTeorica: $this->presence->snapshot($tenantId, $ubicacionId)
                );
                $this->counts->save($count);

                $response = [
                    'status' => 201,
                    'data' => [
                        'mensaje' => 'Conteo físico iniciado.',
                        'conteo' => $count->toPublicArray(),
                        'unidades_en_lista' => count($count->listaTeorica()),
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
     * @param array<string, mixed> $input
     * @return array{ubicacion_id: string|null, fecha: \DateTimeImmutable}
     */
    private function normalize(array $input): array
    {
        $ubicacion = trim((string) ($input['ubicacion_id'] ?? ''));
        $fecha = trim((string) ($input['fecha'] ?? ''));

        if ($fecha === '') {
            $momento = new \DateTimeImmutable();
        } else {
            try {
                $momento = new \DateTimeImmutable($fecha);
            } catch (\Throwable) {
                throw InventoryException::invalidField(
                    'fecha',
                    'Debe ser una fecha válida (ej. 2026-10-04 o 2026-10-04T09:00:00).'
                );
            }
        }

        return [
            'ubicacion_id' => $ubicacion === '' ? null : $ubicacion,
            'fecha' => $momento,
        ];
    }
}
