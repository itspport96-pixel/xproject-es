<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;
use PDO;

/**
 * RejectAdhocUseCase - BLOQUE R3 (v1.13.30): RECHAZO DE RECEPCIÓN ADHOC.
 *
 * Rechaza una recepción adhoc PENDIENTE. Las unidades se quedan BLOQUEADO
 * (no se mueven a DISPONIBLE) y se abre una incidencia de prioridad ALTA.
 *
 *  - Principio 3 (separación de funciones): el usuario que registró la
 *    recepción adhoc NO puede rechazarla (422
 *    `receivingAdhocCreatorCannotConfirm`, misma semántica de separación).
 *  - Sólo se puede rechazar si el adhoc está PENDIENTE (422
 *    `receivingAdhocNotRejectable`).
 *  - La incidencia se abre con prioridad ALTA y `restriccion_resolucion`
 *    = [creador, rechazador] (ni el creador ni el rechazador la resuelven).
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 */
final class RejectAdhocUseCase
{
    public const OPERACION = 'inventory.receiving.adhoc.reject';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly StockEventRepositoryInterface $events,
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

                $response = $this->reject($tenantId, $adhocId, (string) $actorId);

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
    // RECHAZO (corazón del R3 reject)
    // ==================================================================

    /**
     * @return array{status: int, data: array<string, mixed>, _auditoria: array<string, mixed>}
     */
    private function reject(string $tenantId, string $adhocId, string $actorId): array
    {
        $adhoc = $this->loadAdhoc($tenantId, $adhocId);

        // Principio 3: quien registró no rechaza (misma semántica de
        // separación que la confirmación).
        if ($adhoc['creado_por'] === $actorId) {
            throw InventoryException::receivingAdhocCreatorCannotConfirm();
        }

        if ($adhoc['estado'] !== 'PENDIENTE') {
            throw InventoryException::receivingAdhocNotRejectable((string) $adhoc['estado']);
        }

        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');

        $lines = $this->loadLines($adhocId);

        $lineResults = [];
        $auditLines = [];

        foreach ($lines as $line) {
            $unidadId = $line['unidad_id'] !== null ? (string) $line['unidad_id'] : null;

            if ($unidadId !== null) {
                $event = StockEvent::create(
                    id: $this->generateUuid(),
                    tenantId: $tenantId,
                    unidadFisicaId: $unidadId,
                    tipoEvento: StockEvent::TIPO_BLOQUEADO,
                    descripcion: sprintf('Recepción adhoc rechazada (ref %s)', $adhoc['referencia_documento']),
                    datosOperacion: [
                        'adhoc_id' => $adhocId,
                        'referencia_documento' => $adhoc['referencia_documento'],
                        'sku' => $line['sku'],
                        'estado' => StockState::BLOQUEADO,
                        'actor_rechaza' => $actorId,
                        'motivo' => 'Rechazo de recepción adhoc',
                    ],
                    cantidadAnterior: 0.0,
                    cantidadPosterior: 0.0,
                    estadoAnterior: StockState::BLOQUEADO,
                    estadoPosterior: StockState::BLOQUEADO
                );
                $this->events->save($event);
            }

            $lineResults[] = [
                'sku' => $line['sku'],
                'unidad_fisica_id' => $unidadId,
                'estado_unidad' => StockState::BLOQUEADO,
            ];
            $auditLines[] = [
                'sku' => $line['sku'],
                'unidad_fisica_id' => $unidadId,
            ];
        }

        $incident = $this->createRejectIncident(
            $tenantId,
            $adhoc,
            $lines,
            $actorId
        );

        $this->updateAdhocState($pdo, $adhocId, 'RECHAZADA', $now);

        $data = [
            'mensaje' => 'Recepción adhoc rechazada. Unidades permanecen BLOQUEADO.',
            'adhoc_id' => $adhocId,
            'referencia_documento' => $adhoc['referencia_documento'],
            'estado' => 'RECHAZADA',
            'incidencia' => $incident->toPublicArray(),
            'lineas' => $lineResults,
        ];

        $auditoria = [
            'adhoc_id' => $adhocId,
            'referencia_documento' => $adhoc['referencia_documento'],
            'lineas' => $auditLines,
            'incidencia_id' => $incident->id(),
        ];

        return [
            'status' => 200,
            'data' => $data,
            '_auditoria' => $auditoria,
        ];
    }

    // ==================================================================
    // INCIDENCIA DE RECHAZO (ALTA, restringida)
    // ==================================================================

    private function createRejectIncident(
        string $tenantId,
        array $adhoc,
        array $lines,
        string $rechazadorId
    ): Incident {
        $skus = array_map(static fn($l): string => (string) $l['sku'], $lines);
        $descripcion = sprintf(
            'Recepción adhoc rechazada (ref %s). SKUs: %s. Unidades permanecen BLOQUEADO.',
            $adhoc['referencia_documento'],
            implode(', ', $skus)
        );

        $incident = Incident::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            tipo: Incident::TIPO_ENTREGA_RECHAZADA,
            descripcion: $descripcion,
            unidadFisicaId: null,
            ubicacionId: null,
            actorIdReporta: $rechazadorId,
            observaciones: sprintf('Rechazo de recepción adhoc (ref %s).', $adhoc['referencia_documento']),
            prioridad: Incident::PRIORIDAD_ALTA,
            restriccionResolucion: [
                'creador' => (string) $adhoc['creado_por'],
                'rechazador' => $rechazadorId,
            ]
        );
        $this->incidents->save($incident);

        return $incident;
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

    private function updateAdhocState(PDO $pdo, string $adhocId, string $estado, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE receiving_adhoc
                SET estado = :estado, updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute(['estado' => $estado, 'updated_at' => $now, 'id' => $adhocId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
