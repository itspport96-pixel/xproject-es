<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\Location;
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
use PDO;

/**
 * ReceiveDocumentUseCase - recepción contra documento planificado (v1.13.32).
 *
 * Flujo definitivo (sin cegueza ni reconteo):
 *  - El receptor ve la cantidad esperada (GET pendientes la devuelve) y la
 *    UI la muestra bloqueada; el payload NO admite `cantidad_esperada`
 *    (422 `receivingExpectedQuantityNotAccepted`).
 *  - Quien creó el documento NO puede recibirlo, tampoco ADMIN (403
 *    `receivingDocumentCreatorCannotReceive`).
 *  - Recibida == esperada → unidad física + ledger + evento + línea RECIBIDA.
 *  - Recibida != esperada → la unidad entra con lo contado y se abre
 *    incidencia (faltante DISCREPANCIA_CANTIDAD con prioridad por umbral;
 *    sobre-recepción DISCREPANCIA_CANTIDAD ALTA). La línea queda CERRADA
 *    (RECIBIDA); no hay reconteo ni segunda recepción del faltante: si el
 *    proveedor entrega el resto, se planifica un documento nuevo.
 *  - `rechazo_total` (opcional): las líneas del payload (o todas las
 *    pendientes si lineas viene vacío) se cierran como RECHAZADA sin crear
 *    stock y se abre una incidencia ENTREGA_RECHAZADA de prioridad ALTA con
 *    restricción de resolución (ni receptor ni planificador).
 *  - Concurrencia: las líneas del documento se cargan con SELECT ... FOR
 *    UPDATE dentro de la transacción; dos receptores simultáneos sobre la
 *    misma línea → uno gana, el otro recibe 409.
 *  - Documento: PLANIFICADA → EN_RECEPCION (primera línea cerrada) → CERRADA
 *    (todas las líneas cerradas). ANULADA/CERRADA no se reciben.
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 *
 * Input:
 *  - ubicacion_codigo | ubicacion_id (obligatorio uno; destino de las unidades)
 *  - lineas[] (obligatorio si no hay rechazo_total, ≥1): { sku (obligatorio),
 *    cantidad_recibida (obligatorio si no hay rechazo_total, ≥0), lote?,
 *    numero_serie? }
 *  - rechazo_total? (bool, opcional)
 *  - NO se admite cantidad_esperada (ni arriba ni por línea) → 422.
 */
final class ReceiveDocumentUseCase
{
    public const OPERACION = 'inventory.receiving.document.receive';

    /** Tolerancia de comparación de decimales (4 dígitos). */
    private const EPSILON = 0.0001;

    private const MAX_TEXT = 255;

    /** Estados de línea que se pueden recibir (PENDIENTE y RECONTEO legado). */
    private const LINEAS_RECIBIBLES = ['PENDIENTE', 'RECONTEO'];

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly IncidentRepositoryInterface $incidents,
        private readonly StockCalculationService $calculation,
        private readonly BarcodeService $barcodeService,
        private readonly TenantContext $tenantContext
    ) {
    }

    /**
     * @param array<string, mixed> $input        Cuerpo de la petición
     * @param string|null          $idempotencyKey Header Idempotency-Key (obligatorio en el controller)
     * @param string|null          $actorId      Usuario autenticado (claims)
     *
     * @return array{status: int, data: array<string, mixed>, _auditoria?: array<string, mixed>, _replay?: bool}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(string $documentId, array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        $payload = $this->normalize($input);

        return $this->db->transaction(
            function () use ($tenantId, $documentId, $payload, $idempotencyKey, $actorId): array {
                $request = ['documento_id' => $documentId] + $payload;

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

                $response = $this->receive($tenantId, $documentId, $payload, (string) $actorId);

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
    // RECEPCIÓN CONTRA DOCUMENTO
    // ====================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     * @return array{status: int, data: array<string, mixed>, _auditoria: array<string, mixed>}
     */
    private function receive(string $tenantId, string $documentId, array $payload, string $actorId): array
    {
        $doc = $this->loadDocumentForUpdate($tenantId, $documentId);

        // Separación de funciones: quien planifica no recibe (403, también ADMIN).
        if ($doc['creado_por'] === $actorId) {
            throw InventoryException::receivingDocumentCreatorCannotReceive();
        }

        if (!in_array($doc['estado'], ['PLANIFICADA', 'EN_RECEPCION'], true)) {
            throw InventoryException::receivingDocumentNotReceivable((string) $doc['estado']);
        }

        $location = $this->resolveLocation($tenantId, $payload);

        // Bloqueo de fila de las líneas del documento: dos receptores
        // simultáneos sobre la misma línea → el segundo espera el lock y
        // luego recibe 409 (línea ya cerrada).
        $linesBySku = $this->loadLinesBySkuForUpdate($documentId);

        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');

        $rechazoTotal = (bool) $payload['rechazo_total'];
        $incomingBySku = [];

        foreach ($payload['lineas'] as $incoming) {
            $incomingBySku[$incoming['sku']] = $incoming;
        }

        if ($rechazoTotal && $incomingBySku === []) {
            // Rechazo total de la entrega sin desglose: se rechazan todas
            // las líneas pendientes del documento.
            foreach ($linesBySku as $sku => $line) {
                if (in_array((string) $line['estado'], self::LINEAS_RECIBIBLES, true)) {
                    $incomingBySku[$sku] = ['sku' => $sku, 'cantidad_recibida' => 0.0, 'lote' => null, 'numero_serie' => null];
                }
            }

            if ($incomingBySku === []) {
                throw InventoryException::receivingDocumentLinesRequired();
            }
        }

        $lineResults = [];
        $auditLines = [];
        $slots = 0;
        $anyClosed = false;
        $rejectedSkus = [];

        foreach ($incomingBySku as $sku => $incoming) {
            if (!isset($linesBySku[$sku])) {
                throw InventoryException::receivingLineNotFoundForDocument($sku);
            }

            $line = $linesBySku[$sku];
            $estadoLinea = (string) $line['estado'];

            if ($estadoLinea === 'RECIBIDA') {
                throw InventoryException::receivingLineAlreadyReceived($sku);
            }

            if ($estadoLinea === 'RECHAZADA') {
                throw InventoryException::receivingLineClosed($sku, $estadoLinea);
            }

            if (!in_array($estadoLinea, self::LINEAS_RECIBIBLES, true)) {
                throw InventoryException::receivingLineClosed($sku, $estadoLinea);
            }

            $esperada = (float) $line['cantidad_esperada'];

            if ($rechazoTotal) {
                // Rechazo total: la línea se cierra RECHAZADA, sin stock.
                $this->markLineRejected($pdo, (string) $line['id'], $now);

                $lineResults[] = [
                    'line_id' => (string) $line['id'],
                    'sku' => $sku,
                    'estado_linea' => 'RECHAZADA',
                    'cantidad_recibida' => 0.0,
                    'unidad_fisica_id' => null,
                    'incidencia_id' => null,
                ];
                $auditLines[] = [
                    'line_id' => (string) $line['id'],
                    'sku' => $sku,
                    'cantidad_esperada' => $esperada,
                    'cantidad_recibida' => 0.0,
                    'resultado' => 'RECHAZADA',
                ];
                $rejectedSkus[] = $sku;
                $anyClosed = true;
                $linesBySku[$sku]['estado'] = 'RECHAZADA';
                continue;
            }

            $contada = $incoming['cantidad_recibida'];
            $match = abs($esperada - $contada) < self::EPSILON;

            if ($match) {
                $unit = $this->createUnit(
                    $tenantId,
                    (string) $line['producto_id'],
                    $contada,
                    $location,
                    $incoming['lote'],
                    $incoming['numero_serie'],
                    $doc,
                    $esperada
                );
                $slots += (int) ceil($contada);

                $this->markLineReceived($pdo, (string) $line['id'], $contada, $unit->id(), $now);

                $lineResults[] = [
                    'line_id' => (string) $line['id'],
                    'sku' => $sku,
                    'estado_linea' => 'RECIBIDA',
                    'cantidad_recibida' => $contada,
                    'unidad_fisica_id' => $unit->id(),
                    'incidencia_id' => null,
                ];
                $auditLines[] = [
                    'line_id' => (string) $line['id'],
                    'sku' => $sku,
                    'cantidad_esperada' => $esperada,
                    'cantidad_recibida' => $contada,
                    'resultado' => 'RECIBIDA',
                ];
                $anyClosed = true;
                $linesBySku[$sku]['estado'] = 'RECIBIDA';
                continue;
            }

            // Discrepancia: el stock refleja SIEMPRE lo contado y la línea
            // queda cerrada con incidencia (sin reconteo ni segunda entrega).
            $esSobreRecepcion = $contada > $esperada;
            $unit = $this->createUnit(
                $tenantId,
                (string) $line['producto_id'],
                $contada,
                $location,
                $incoming['lote'],
                $incoming['numero_serie'],
                $doc,
                $esperada
            );
            $slots += (int) ceil($contada);

            $this->markLineReceived($pdo, (string) $line['id'], $contada, $unit->id(), $now);

            $incident = $this->createDiscrepancyIncident(
                $tenantId,
                $unit->id(),
                $location->id(),
                $doc,
                $sku,
                $esperada,
                $contada,
                $actorId,
                $esSobreRecepcion
            );

            $lineResults[] = [
                'line_id' => (string) $line['id'],
                'sku' => $sku,
                'estado_linea' => 'RECIBIDA_CON_INCIDENCIA',
                'cantidad_recibida' => $contada,
                'unidad_fisica_id' => $unit->id(),
                'incidencia_id' => $incident->id(),
            ];
            $auditLines[] = [
                'line_id' => (string) $line['id'],
                'sku' => $sku,
                'cantidad_esperada' => $esperada,
                'cantidad_recibida' => $contada,
                'resultado' => 'INCIDENCIA',
                'incidencia_id' => $incident->id(),
                'tipo_discrepancia' => $esSobreRecepcion ? 'SOBRE_RECEPCION' : 'FALTANTE',
            ];
            $anyClosed = true;
            $linesBySku[$sku]['estado'] = 'RECIBIDA';
        }

        // Incidencia del rechazo total de la entrega (una sola, ALTA).
        $rechazoIncidentId = null;
        if ($rechazoTotal && $rejectedSkus !== []) {
            $rechazoIncident = $this->createRejectedDeliveryIncident(
                $tenantId,
                $location,
                $doc,
                $rejectedSkus,
                $actorId
            );
            $rechazoIncidentId = $rechazoIncident->id();

            foreach ($lineResults as $index => $result) {
                if ($result['estado_linea'] === 'RECHAZADA') {
                    $lineResults[$index]['incidencia_id'] = $rechazoIncidentId;
                    $auditLines[$index]['incidencia_id'] = $rechazoIncidentId;
                }
            }
        }

        // Capacidad usada de la destino.
        if ($slots > 0) {
            $location->incrementarCapacidadUsada($slots);
            $this->locations->update($location);
        }

        // Transición del documento: la primera línea cerrada pasa a
        // EN_RECEPCION; si todas están cerradas (RECIBIDA o RECHAZADA), CERRADA.
        $allClosed = $this->allLinesClosed($documentId);
        $newState = $allClosed
            ? 'CERRADA'
            : ($anyClosed ? 'EN_RECEPCION' : (string) $doc['estado']);
        $this->updateDocumentState($tenantId, $documentId, $newState, $now);

        $data = [
            'mensaje' => $rechazoTotal
                ? 'Recepción procesada: entrega rechazada con incidencia de prioridad alta.'
                : 'Recepción procesada correctamente.',
            'documento_id' => $documentId,
            'numero_documento' => $doc['numero_documento'],
            'estado_documento' => $newState,
            'resultado' => $rechazoTotal ? 'RECHAZADA' : 'RECIBIDO',
            'rechazo_total' => $rechazoTotal,
            'lineas' => $lineResults,
        ];

        $auditoria = [
            'documento_id' => $documentId,
            'numero_documento' => $doc['numero_documento'],
            'ubicacion_codigo' => $location->codigo()->value(),
            'creado_por' => (string) $doc['creado_por'],
            'recibido_por' => $actorId,
            'rechazo_total' => $rechazoTotal,
            'lineas' => $auditLines,
        ];

        return [
            'status' => 200,
            'data' => $data,
            '_auditoria' => $auditoria,
        ];
    }

    // ====================================================================
    // UNIDAD FÍSICA + LEDGER + EVENTO
    // ====================================================================

    /**
     * Crea la unidad física recibida (DISPONIBLE en la destino) con su ledger
     * y evento RECIBIDO. La cantidad es SIEMPRE la contada.
     */
    private function createUnit(
        string $tenantId,
        string $productoId,
        float $cantidad,
        Location $location,
        ?string $lote,
        ?string $numeroSerie,
        array $doc,
        float $esperada
    ): StockUnit {
        $barcode = $this->generateUniqueBarcode();

        $unit = StockUnit::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            productoId: $productoId,
            codigoBarcode: $barcode,
            cantidad: $cantidad,
            propietarioId: null,
            custodioId: null,
            numeroSerie: $numeroSerie,
            lote: $lote,
            estado: StockState::DISPONIBLE,
            ubicacionId: $location->id()
        );
        $this->stockUnits->save($unit);

        $ledger = $this->calculation->crearLedger(
            $this->generateUuid(),
            $tenantId,
            $unit->id(),
            $unit->cantidad(),
            StockState::DISPONIBLE
        );
        $this->ledgers->save($ledger);

        $event = StockEvent::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: StockEvent::TIPO_RECIBIDO,
            descripcion: sprintf(
                'Recepción contra documento %s',
                $doc['numero_documento']
            ),
            datosOperacion: [
                'documento_id' => $doc['id'],
                'numero_documento' => $doc['numero_documento'],
                'ubicacion_id' => $location->id(),
                'ubicacion_codigo' => $location->codigo()->value(),
                'cantidad_esperada' => $esperada,
                'cantidad_recibida' => $cantidad,
                'lote' => $lote,
                'numero_serie' => $numeroSerie,
            ] + StockLedger::contextoOperacion(
                ['cantidad_reservada' => 0.0, 'cantidad_disponible' => 0.0, 'cantidad_en_transito' => 0.0],
                $ledger->valoresOperacion()
            ),
            cantidadAnterior: 0.0,
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: null,
            estadoPosterior: StockState::DISPONIBLE
        );
        $this->events->save($event);

        return $unit;
    }

    /**
     * Incidencia por discrepancia de cantidad (faltante o sobre-recepción),
     * con restricción de resolución (ni el receptor ni el planificador).
     */
    private function createDiscrepancyIncident(
        string $tenantId,
        string $unitId,
        string $locationId,
        array $doc,
        string $sku,
        float $esperada,
        float $contada,
        string $receptorId,
        bool $esSobreRecepcion
    ): Incident {
        $diferencia = round($contada - $esperada, 4);
        $prioridad = $esSobreRecepcion
            ? Incident::PRIORIDAD_ALTA
            : $this->prioridadPorFaltante($esperada, $contada);

        $descripcion = $esSobreRecepcion
            ? sprintf(
                'Sobre-recepción en el documento %s, SKU %s: esperadas %.4f, contadas %.4f (+%.4f).',
                $doc['numero_documento'],
                $sku,
                $esperada,
                $contada,
                $diferencia
            )
            : sprintf(
                'Faltante en el documento %s, SKU %s: esperadas %.4f, contadas %.4f (%.4f).',
                $doc['numero_documento'],
                $sku,
                $esperada,
                $contada,
                $diferencia
            );

        $incident = Incident::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            tipo: Incident::TIPO_DISCREPANCIA_CANTIDAD,
            descripcion: $descripcion,
            unidadFisicaId: $unitId,
            ubicacionId: $locationId,
            actorIdReporta: $receptorId,
            observaciones: sprintf('Recepción del documento %s (SKU %s).', $doc['numero_documento'], $sku),
            prioridad: $prioridad,
            restriccionResolucion: [
                'receptor' => $receptorId,
                'planificador' => (string) $doc['creado_por'],
            ]
        );
        $this->incidents->save($incident);

        return $incident;
    }

    /**
     * Incidencia ENTREGA_RECHAZADA de prioridad ALTA del rechazo total, con
     * restricción de resolución (ni receptor ni planificador).
     *
     * @param array<int, string> $skus
     */
    private function createRejectedDeliveryIncident(
        string $tenantId,
        Location $location,
        array $doc,
        array $skus,
        string $receptorId
    ): Incident {
        $incident = Incident::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            tipo: Incident::TIPO_ENTREGA_RECHAZADA,
            descripcion: sprintf(
                'Rechazo total de la entrega del documento %s (SKUs: %s).',
                $doc['numero_documento'],
                implode(', ', $skus)
            ),
            unidadFisicaId: null,
            ubicacionId: $location->id(),
            actorIdReporta: $receptorId,
            observaciones: sprintf('Rechazo total de la entrega del documento %s.', $doc['numero_documento']),
            prioridad: Incident::PRIORIDAD_ALTA,
            restriccionResolucion: [
                'receptor' => $receptorId,
                'planificador' => (string) $doc['creado_por'],
            ]
        );
        $this->incidents->save($incident);

        return $incident;
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Carga el documento con bloqueo de fila (FOR UPDATE) para serializar la
     * transición de estado entre receptores concurrentes.
     *
     * @return array<string, mixed>
     */
    private function loadDocumentForUpdate(string $tenantId, string $documentId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, tenant_id, numero_documento, estado, creado_por
               FROM receiving_documents
              WHERE id = :id AND tenant_id = :tenant_id
                FOR UPDATE'
        );
        $stmt->execute(['id' => $documentId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::receivingDocumentNotFound($documentId);
        }

        return $row;
    }

    /**
     * @return array<string, array<string, mixed>> líneas del documento indexadas por SKU, bloqueadas (FOR UPDATE)
     */
    private function loadLinesBySkuForUpdate(string $documentId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, producto_id, sku, cantidad_esperada, estado
               FROM receiving_document_lines
              WHERE document_id = :document_id
                FOR UPDATE'
        );
        $stmt->execute(['document_id' => $documentId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['sku']] = $row;
        }

        return $map;
    }

    private function markLineReceived(
        PDO $pdo,
        string $lineId,
        float $cantidadRecibida,
        string $unidadId,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE receiving_document_lines
                SET estado = :estado,
                    cantidad_recibida = :cantidad_recibida,
                    unidad_recepcion_id = :unidad_recepcion_id,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            'estado' => 'RECIBIDA',
            'cantidad_recibida' => $cantidadRecibida,
            'unidad_recepcion_id' => $unidadId,
            'updated_at' => $now,
            'id' => $lineId,
        ]);
    }

    private function markLineRejected(PDO $pdo, string $lineId, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE receiving_document_lines
                SET estado = :estado,
                    cantidad_recibida = 0,
                    unidad_recepcion_id = NULL,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute(['estado' => 'RECHAZADA', 'updated_at' => $now, 'id' => $lineId]);
    }

    /** true si TODAS las líneas están cerradas (RECIBIDA o RECHAZADA). */
    private function allLinesClosed(string $documentId): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT COUNT(*) FROM receiving_document_lines
              WHERE document_id = :document_id AND estado NOT IN ('RECIBIDA', 'RECHAZADA')"
        );
        $stmt->execute(['document_id' => $documentId]);

        return (int) $stmt->fetchColumn() === 0;
    }

    private function updateDocumentState(string $tenantId, string $documentId, string $estado, string $now): void
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE receiving_documents
                SET estado = :estado, updated_at = :updated_at
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['estado' => $estado, 'updated_at' => $now, 'id' => $documentId, 'tenant_id' => $tenantId]);
    }

    private function resolveLocation(string $tenantId, array $payload): Location
    {
        $location = !empty($payload['ubicacion_id'])
            ? $this->locations->findById($payload['ubicacion_id'])
            : $this->locations->findByCodigo($payload['ubicacion_codigo']);

        if ($location === null) {
            throw InventoryException::locationNotFound($payload['ubicacion_codigo'] ?? $payload['ubicacion_id']);
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

    private function generateUniqueBarcode(): string
    {
        for ($attempt = 1; $attempt <= BarcodeService::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->barcodeService->generate();

            if (!$this->stockUnits->existsByBarcode($candidate)) {
                return $candidate;
            }
        }

        throw InventoryException::barcodeGenerationFailed();
    }

    /** Prioridad ALTA si el faltante alcanza el umbral RECEPTION_DISCREPANCY_THRESHOLD. */
    private function prioridadPorFaltante(float $esperada, float $contada): string
    {
        if ($esperada <= 0) {
            return Incident::PRIORIDAD_ALTA;
        }

        $pct = (($esperada - $contada) / $esperada) * 100;

        return $pct >= ReceiveInventoryUseCase::thresholdPercent()
            ? Incident::PRIORIDAD_ALTA
            : Incident::PRIORIDAD_NORMAL;
    }

    /**
     * Normaliza y valida la entrada. Rechaza `cantidad_esperada` (no se
     * admite en el payload) y devuelve el payload canónico.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        // El esperado no se admite en ningún nivel del payload: la cantidad
        // esperada la fija el documento, no el receptor.
        if ($this->containsExpectedQuantity($input)) {
            throw InventoryException::receivingExpectedQuantityNotAccepted();
        }

        $ubicacionId = trim((string) ($input['ubicacion_id'] ?? ''));
        $ubicacionCodigo = trim((string) ($input['ubicacion_codigo'] ?? ''));

        if ($ubicacionId === '' && $ubicacionCodigo === '') {
            throw InventoryException::requiredField('ubicacion_codigo');
        }

        $rechazoTotal = filter_var($input['rechazo_total'] ?? false, FILTER_VALIDATE_BOOL);

        $rawLines = $input['lineas'] ?? [];
        if (!$rechazoTotal && (!is_array($rawLines) || $rawLines === [])) {
            throw InventoryException::receivingDocumentLinesRequired();
        }

        $lines = [];
        $seen = [];

        foreach (array_values(is_array($rawLines) ? $rawLines : []) as $index => $item) {
            $n = $index + 1;

            if (!is_array($item)) {
                throw InventoryException::invalidField('lineas', "La línea #{$n} no es un objeto válido.");
            }

            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                throw InventoryException::invalidField('lineas', "La línea #{$n} no tiene SKU.");
            }

            if (isset($seen[$sku])) {
                throw InventoryException::receivingDocumentDuplicateSku($sku);
            }
            $seen[$sku] = true;

            if ($rechazoTotal) {
                $lines[] = [
                    'sku' => $sku,
                    'cantidad_recibida' => 0.0,
                    'lote' => $this->optionalText($item['lote'] ?? null),
                    'numero_serie' => $this->optionalText($item['numero_serie'] ?? null),
                ];
                continue;
            }

            $contada = $item['cantidad_recibida'] ?? null;
            if (!is_numeric($contada) || (float) $contada < 0) {
                throw InventoryException::invalidQuantity(
                    'cantidad_recibida',
                    "La línea del SKU '{$sku}' debe tener cantidad_recibida >= 0."
                );
            }

            $lines[] = [
                'sku' => $sku,
                'cantidad_recibida' => round((float) $contada, 4),
                'lote' => $this->optionalText($item['lote'] ?? null),
                'numero_serie' => $this->optionalText($item['numero_serie'] ?? null),
            ];
        }

        return [
            'ubicacion_id' => $ubicacionId,
            'ubicacion_codigo' => $ubicacionCodigo,
            'rechazo_total' => $rechazoTotal,
            'lineas' => $lines,
        ];
    }

    /** true si el payload contiene `cantidad_esperada` en cualquier nivel. */
    private function containsExpectedQuantity(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (array_key_exists('cantidad_esperada', $value)) {
            return true;
        }

        foreach ($value as $item) {
            if (is_array($item) && $this->containsExpectedQuantity($item)) {
                return true;
            }
        }

        return false;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::MAX_TEXT) {
            throw InventoryException::fieldMaxLength('lote', self::MAX_TEXT);
        }

        return $text;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
