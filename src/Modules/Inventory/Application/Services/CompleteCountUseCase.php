<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\InventoryCountRepositoryInterface;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;

/**
 * CompleteCountUseCase - CU-011: COMPLETAR CONTEO
 *
 * Cierra el conteo (PENDIENTE/EN_CURSO → COMPLETADO) y calcula sus
 * discrepancias en UNA sola transacción (R5):
 *
 *  1. Todas las unidades de la lista teórica deben tener resultado; si
 *     falta alguna → 422 con el detalle de las pendientes.
 *  2. Discrepancia por unidad: cantidad_esperada, cantidad_encontrada y
 *     diferencia (encontrada - esperada). Sólo se guardan las unidades con
 *     diferencia distinta de cero (decisión documentada en el informe).
 *  3. Un conteo ya COMPLETADO o RECONCILIADO devuelve 422.
 */
final class CompleteCountUseCase
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly InventoryCountRepositoryInterface $counts,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param string|null $actorId Usuario que completa (claims)
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $countId, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $countId): array {
                $count = $this->load($tenantId, $countId);

                if (!in_array($count->estado(), [InventoryCount::ESTADO_PENDIENTE, InventoryCount::ESTADO_EN_CURSO], true)) {
                    throw InventoryException::countInvalidState(
                        $countId,
                        $count->estado(),
                        InventoryCount::ESTADO_EN_CURSO
                    );
                }

                $faltantes = $count->unidadesSinResultado();

                if ($faltantes !== []) {
                    throw InventoryException::countMissingResults($faltantes);
                }

                $resultados = [];

                foreach ($count->resultados() as $fila) {
                    $resultados[(string) $fila['unidad_fisica_id']] = $fila;
                }

                $discrepancias = [];

                foreach ($count->listaTeorica() as $fila) {
                    $unidadId = (string) $fila['unidad_fisica_id'];
                    $esperada = (float) $fila['cantidad_esperada'];
                    $encontrada = (float) ($resultados[$unidadId]['cantidad_encontrada'] ?? 0.0);
                    $diferencia = $encontrada - $esperada;

                    if (abs($diferencia) < 0.0000001) {
                        continue;
                    }

                    $discrepancias[] = [
                        'unidad_fisica_id' => $unidadId,
                        'codigo_barcode' => $fila['codigo_barcode'] ?? null,
                        'cantidad_esperada' => $esperada,
                        'cantidad_encontrada' => $encontrada,
                        'diferencia' => $diferencia,
                        'notas' => $resultados[$unidadId]['notas'] ?? null,
                    ];
                }

                $count->completar($discrepancias);
                $this->counts->update($count);

                return [
                    'status' => 200,
                    'data' => [
                        'mensaje' => 'Conteo completado.',
                        'conteo' => $count->toPublicArray(),
                        'discrepancias' => $count->discrepancias(),
                        'total_discrepancias' => count($count->discrepancias()),
                    ],
                ];
            }
        );
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
}
