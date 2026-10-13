<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;
use PDO;

/**
 * ConfirmAdhocUseCase - BLOQUE R3 (v1.13.30): CONFIRMACIÓN DE RECEPCIÓN ADHOC.
 *
 * Confirma una recepción adhoc PENDIENTE, pasando todas sus unidades de
 * BLOQUEADO a DISPONIBLE (transición BLOQUEADO→DISPONIBLE ya presente en la
 * matriz de transiciones).
 *
 *  - Principio 3 (separación de funciones): el usuario que registró la
 *    recepción adhoc NO puede confirmarla (422
 *    `receivingAdhocCreatorCannotConfirm`); la confirmación la hace otro
 *    usuario con `inventory.receiving.confirm`.
 *  - Sólo se puede confirmar si el adhoc está PENDIENTE (422
 *    `receivingAdhocNotConfirmable`).
 *  - Cada línea debe tener unidad física asociada (422
 *    `receivingAdhocLineHasNoUnit`).
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 */
final class ConfirmAdhocUseCase
{
    public const OPERACION = 'inventory.receiving.adhoc.confirm';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input        Cuerpo de la petición (puede ir vacío)
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en el controller)
     * @param string|null          $actorId      Usuario autenticado (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _auditoria?: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $adhocId, array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        return $this->db->transaction(
            function () use ($tenantId, $adhocId, $idempotencyKey, $actorId): array {
                $request = ['adhoc_id' => $adhocId];

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

                $response = $this->confirm($tenantId, $adhocId, (string) $actorId);

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

    // ==================================================================
    // CONFIRMACIÓN (corazón del R3 confirm)
    // ==================================================================

    /**
     * @return array{status: int, data: array<string, mixed>, _auditoria: array<string, mixed>}
     */
    private function confirm(string $tenantId, string $adhocId, string $actorId): array
    {
        $adhoc = $this->loadAdhoc($tenantId, $adhocId);

        // Principio 3: quien registró no confirma.
        if ($adhoc['creado_por'] === $actorId) {
            throw InventoryException::receivingAdhocCreatorCannotConfirm();
        }

        if ($adhoc['estado'] !== 'PENDIENTE') {
            throw InventoryException::receivingAdhocNotConfirmable((string) $adhoc['estado']);
        }

        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');

        $lines = $this->loadLines($adhocId);

        $lineResults = [];
        $auditLines = [];

        foreach ($lines as $line) {
            $unidadId = $line['unidad_id'] !== null ? (string) $line['unidad_id'] : null;

            if ($unidadId === null) {
                throw InventoryException::receivingAdhocLineHasNoUnit((string) $line['sku']);
            }

            $unit = $this->stockUnits->findById($unidadId);

            if ($unit === null) {
                throw InventoryException::receivingAdhocLineHasNoUnit((string) $line['sku']);
            }

            $estadoAnterior = $unit->estado()->value();
            $this->states->assertCanTransition($estadoAnterior, StockState::DISPONIBLE);

            $unit->actualizar(['estado' => StockState::DISPONIBLE]);
            $this->stockUnits->update($unit);

            $ledger = $this->ledgers->findByUnidadFisica($unidadId);
            if ($ledger !== null) {
                $ledger->actualizar(['estado_actual' => StockState::DISPONIBLE]);
                $this->ledgers->update($ledger);
            }

            $event = StockEvent::create(
                id: $this->generateUuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unidadId,
                tipoEvento: StockEvent::TIPO_DISPONIBLE,
                descripcion: sprintf('Recepción adhoc confirmada (ref %s)', $adhoc['referencia_documento']),
                datosOperacion: [
                    'adhoc_id' => $adhocId,
                    'referencia_documento' => $adhoc['referencia_documento'],
                    'sku' => $line['sku'],
                    'estado_anterior' => $estadoAnterior,
                    'estado_posterior' => StockState::DISPONIBLE,
                    'actor_confirma' => $actorId,
                ] + StockLedger::contextoOperacion(
                    ['cantidad_reservada' => 0.0, 'cantidad_disponible' => 0.0, 'cantidad_en_transito' => 0.0],
                    $ledger !== null ? $ledger->valoresOperacion() : []
                ),
                cantidadAnterior: $unit->cantidad(),
                cantidadPosterior: $unit->cantidad(),
                estadoAnterior: $estadoAnterior,
                estadoPosterior: StockState::DISPONIBLE
            );
            $this->events->save($event);

            $lineResults[] = [
                'sku' => $line['sku'],
                'unidad_fisica_id' => $unidadId,
                'estado_anterior' => $estadoAnterior,
                'estado_posterior' => StockState::DISPONIBLE,
            ];
            $auditLines[] = [
                'sku' => $line['sku'],
                'unidad_fisica_id' => $unidadId,
                'estado_anterior' => $estadoAnterior,
                'estado_posterior' => StockState::DISPONIBLE,
            ];
        }

        $this->updateAdhocState($pdo, $adhocId, 'CONFIRMADA', $actorId, $now);

        $data = [
            'mensaje' => 'Recepción adhoc confirmada. Unidades en DISPONIBLE.',
            'adhoc_id' => $adhocId,
            'referencia_documento' => $adhoc['referencia_documento'],
            'estado' => 'CONFIRMADA',
            'lineas' => $lineResults,
        ];

        $auditoria = [
            'adhoc_id' => $adhocId,
            'referencia_documento' => $adhoc['referencia_documento'],
            'lineas' => $auditLines,
        ];

        return [
            'status' => 200,
            'data' => $data,
            '_auditoria' => $auditoria,
        ];
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function loadAdhoc(string $tenantId, string $adhocId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, tenant_id, referencia_documento, estado, creado_por
               FROM receiving_adhoc
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $adhocId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::receivingAdhocNotFound($adhocId);
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLines(string $adhocId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, producto_id, sku, cantidad, unidad_id
               FROM receiving_adhoc_lines
              WHERE adhoc_id = :adhoc_id'
        );
        $stmt->execute(['adhoc_id' => $adhocId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateAdhocState(PDO $pdo, string $adhocId, string $estado, string $confirmadoPor, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE receiving_adhoc
                SET estado = :estado, creado_por_confirma = :confirmado_por, updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            'estado' => $estado,
            'confirmado_por' => $confirmadoPor,
            'updated_at' => $now,
            'id' => $adhocId,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
