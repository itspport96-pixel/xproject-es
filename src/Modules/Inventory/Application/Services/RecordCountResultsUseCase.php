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
 * RecordCountResultsUseCase - CU-011: REGISTRAR RESULTADOS DEL CONTEO
 *
 * Guarda los resultados parciales del conteo (cantidad_encontrada y notas
 * por unidad) y pasa el conteo de PENDIENTE a EN_CURSO. Sólo lectura de la
 * lista teórica: cada unidad indicada DEBE estar en ella (422 si no).
 *
 * Las llamadas se fusionan: repetir una unidad sobrescribe su resultado y
 * nunca toca el resto. El conteo sólo acepta resultados en PENDIENTE/EN_CURSO.
 *
 * Input:
 *  - resultados: [{unidad_fisica_id, cantidad_encontrada, notas?}, ...]
 */
final class RecordCountResultsUseCase
{
    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly InventoryCountRepositoryInterface $counts,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $actorId Usuario que registra (claims)
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $countId, array $input, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $resultados = $this->normalize($input);

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $countId, $resultados): array {
                $count = $this->load($tenantId, $countId);

                if (!in_array($count->estado(), [InventoryCount::ESTADO_PENDIENTE, InventoryCount::ESTADO_EN_CURSO], true)) {
                    throw InventoryException::countInvalidState(
                        $countId,
                        $count->estado(),
                        InventoryCount::ESTADO_EN_CURSO
                    );
                }

                $porUnidad = [];

                foreach ($count->listaTeorica() as $fila) {
                    $porUnidad[(string) $fila['unidad_fisica_id']] = true;
                }

                foreach ($resultados as $fila) {
                    if (!isset($porUnidad[(string) $fila['unidad_fisica_id']])) {
                        throw InventoryException::countUnitNotInSheet((string) $fila['unidad_fisica_id']);
                    }
                }

                $count->registrarResultados($resultados);
                $this->counts->update($count);

                $faltantes = $count->unidadesSinResultado();

                return [
                    'status' => 200,
                    'data' => [
                        'mensaje' => 'Resultados registrados.',
                        'conteo' => $count->toPublicArray(),
                        'unidades_contadas' => count($count->resultados()),
                        'unidades_pendientes' => count($faltantes),
                        'pendientes' => $faltantes,
                    ],
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $input): array
    {
        $raw = $input['resultados'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw InventoryException::countResultsRequired();
        }

        $resultados = [];

        foreach (array_values($raw) as $indice => $entrada) {
            if (!is_array($entrada)) {
                throw InventoryException::invalidField("resultados[{$indice}]", 'Debe ser un objeto.');
            }

            $unidadId = trim((string) ($entrada['unidad_fisica_id'] ?? ''));

            if ($unidadId === '') {
                throw InventoryException::requiredField("resultados[{$indice}].unidad_fisica_id");
            }

            if (isset($resultados[$unidadId])) {
                throw InventoryException::duplicateUnitsInRequest($unidadId);
            }

            if (!array_key_exists('cantidad_encontrada', $entrada)) {
                throw InventoryException::requiredField("resultados[{$indice}].cantidad_encontrada");
            }

            $cantidad = $entrada['cantidad_encontrada'];

            if (!is_numeric($cantidad)) {
                throw InventoryException::invalidQuantity('cantidad_encontrada', 'Debe ser un número.');
            }

            $cantidad = (float) $cantidad;

            if (!is_finite($cantidad) || $cantidad < 0) {
                throw InventoryException::invalidQuantity('cantidad_encontrada', 'No puede ser negativa.');
            }

            $notas = null;

            if (array_key_exists('notas', $entrada) && $entrada['notas'] !== null) {
                if (!is_scalar($entrada['notas'])) {
                    throw InventoryException::invalidField("resultados[{$indice}].notas", 'Debe ser un texto.');
                }

                $notas = trim((string) $entrada['notas']);

                if ($notas === '') {
                    $notas = null;
                } elseif (mb_strlen($notas) > self::MAX_TEXT) {
                    throw InventoryException::fieldMaxLength("resultados[{$indice}].notas", self::MAX_TEXT);
                }
            }

            $resultados[$unidadId] = [
                'unidad_fisica_id' => $unidadId,
                'cantidad_encontrada' => $cantidad,
                'notas' => $notas,
            ];
        }

        return array_values($resultados);
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
