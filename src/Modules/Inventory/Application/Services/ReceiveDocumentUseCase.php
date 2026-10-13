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
 * ReceiveDocumentUseCase - BLOQUE R2 (v1.13.29): RECEPCIÓN CIEGA CONTRA
 * DOCUMENTO.
 *
 * Separa la RECEPCIÓN de la PLANIFICACIÓN con recepción a ciegas:
 *  - Principio 1: el `cantidad_esperada` NUNCA lo introduce quien recibe. El
 *    payload NO lo admite (422 `receivingExpectedQuantityNotAccepted`); sólo
 *    se envía lo que se cuenta (`cantidad_recibida`).
 *  - Principio 2: el creador del documento NO puede recibirlo (422
 *    `receivingDocumentCreatorCannotReceive`).
 *  - Principio 3: el receptor cuenta a ciegas. Al primer discrepancia se
 *    responde "recuenta" (estado RECONTEO) SIN revelar la cantidad esperada
 *    ni la diferencia.
 *  - Principio 4 / un solo reconteo: sólo se permite UN reconteo. En el 2º
 *    fallo se registra la cantidad contada (crea unidad física) y se abre una
 *    incidencia DISCREPANCIA_CANTIDAD (tanto en faltante como en
 *    sobre-recepción) con `restriccion_resolucion` = [receptor, planificador]
 *    (ni el receptor ni el planificador la pueden resolver).
 *  - El documento pasa a CERRADA cuando TODAS sus líneas están RECIBIDA; si
 *    sólo algunas, a EN_RECEPCION.
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 *
 * Input:
 *  - ubicacion_codigo | ubicacion_id (obligatorio uno; destino de las unidades)
 *  - lineas[] (obligatorio, ≥1): { sku (obligatorio), cantidad_recibida
 *    (obligatorio, ≥0), lote?, numero_serie? }
 *  - NO se admite cantidad_esperada (ni arriba ni por línea) → 422.
 */
final class ReceiveDocumentUseCase
{
    public const OPERACION = 'inventory.receiving.document.receive';

    /** Tolerancia de comparación de decimales (4 dígitos). */
    private const EPSILON = 0.0001;

    private const MAX_TEXT = 255;

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

    // ==================================================================
    // RECEPCIÓN CIEGA (corazón del R2)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     * @return array{status: int, data: array<string, mixed>, _auditoria: array<string, mixed>}
     */
    private function receive(string $tenantId, string $documentId, array $payload, string $actorId): array
    {
        $doc = $this->loadDocument($tenantId, $documentId);

        // Principio 2: quien planifica/crea no recibe.
        if ($doc['creado_por'] === $actorId) {
            throw InventoryException::receivingDocumentCreatorCannotReceive();
        }

        if (!in_array($doc['estado'], ['PLANIFICADA', 'EN_RECEPCION'], true)) {
            throw InventoryException::receivingDocumentNotReceivable((string) $doc['estado']);
        }

        $location = $this->resolveLocation($tenantId, $payload);

        $linesBySku = $this->loadLinesBySku($documentId);

        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');

        $lineResults = [];
        $auditLines = [];
        $slots = 0;
        $anyReceived = false;
        $anyRecount = false;

        foreach ($payload['lineas'] as $incoming) {
            $sku = $incoming['sku'];

            if (!isset($linesBySku[$sku])) {
                throw InventoryException::receivingLineNotFoundForDocument($sku);
            }

            $line = $linesBySku[$sku];

            if ($line['estado'] === 'RECIBIDA') {
                throw InventoryException::receivingLineAlreadyReceived($sku);
            }

            $esperada = (float) $line['cantidad_esperada'];
            $contada = $incoming['cantidad_recibida'];
            $intentos = (int) $line['intentos'];
            $esReconteo = $intentos >= 1;
            $match = abs($esperada - $contada) < self::EPSILON;

            $nuevosIntentos = $intentos + 1;

            if ($match) {
                $unit = $this->createUnit(
                    $tenantId,
                    (string) $line['producto_id'],
                    $contada,
                    $location,
                    $incoming['lote'],
                    $incoming['numero_serie'],
                    $doc,
                    $esperada,
                    $nuevosIntentos
                );
                $slots += (int) ceil($contada);

                $this->markLineReceived($pdo, (string) $line['id'], $contada, $nuevosIntentos, $unit->id(), $now);

                $lineResults[] = [
                    'sku' => $sku,
                    'estado_linea' => 'RECIBIDA',
                    'cantidad_recibida' => $contada,
                    'unidad_fisica_id' => $unit->id(),
                    'incidencia_id' => null,
                ];
                $auditLines[] = [
                    'sku' => $sku,
                    'cantidad_esperada' => $esperada,
                    'cantidad_recibida' => $contada,
                    'intentos' => $nuevosIntentos,
                    'resultado' => 'RECIBIDA',
                ];
                $anyReceived = true;
                $linesBySku[$sku]['estado'] = 'RECIBIDA';
                continue;
            }

            // Discrepancia.
            if (!$esReconteo) {
                // Primer fallo: sólo se pide reconteo, sin revelar esperado ni
                // diferencia. La línea queda PENDIENTE con intentos=1.
                $this->bumpAttempts($pdo, (string) $line['id'], $nuevosIntentos, $now);

                $lineResults[] = [
                    'sku' => $sku,
                    'estado_linea' => 'RECONTEO',
                    'cantidad_recibida' => null,
                    'unidad_fisica_id' => null,
                    'incidencia_id' => null,
                ];
                $auditLines[] = [
                    'sku' => $sku,
                    'cantidad_esperada' => $esperada,
                    'cantidad_recibida' => $contada,
                    'intentos' => $nuevosIntentos,
                    'resultado' => 'RECONTEO',
                ];
                $anyRecount = true;
                $linesBySku[$sku]['intentos'] = $nuevosIntentos;
                continue;
            }

            // 2º fallo (un solo reconteo agotado): se registra la cantidad
            // contada (unidad física) y se abre incidencia (faltante o
            // sobre-recepción) con restricción de resolución.
            $esSobreRecepcion = $contada > $esperada;
            $unit = $this->createUnit(
                $tenantId,
                (string) $line['producto_id'],
                $contada,
                $location,
                $incoming['lote'],
                $incoming['numero_serie'],
                $doc,
                $esperada,
                $nuevosIntentos
            );
            $slots += (int) ceil($contada);

            $this->markLineReceived($pdo, (string) $line['id'], $contada, $nuevosIntentos, $unit->id(), $now);

            $incident = $this->createDiscrepancyIncident(
                $tenantId,
                $unit->id(),
                $location->id(),
                $doc,
                $sku,
                $esperada,
                $contada,
                $nuevosIntentos,
                $actorId,
                $esSobreRecepcion
            );

            $lineResults[] = [
                'sku' => $sku,
                'estado_linea' => 'RECIBIDA_CON_INCIDENCIA',
                'cantidad_recibida' => $contada,
                'unidad_fisica_id' => $unit->id(),
                'incidencia_id' => $incident->id(),
            ];
            $auditLines[] = [
                'sku' => $sku,
                'cantidad_esperada' => $esperada,
                'cantidad_recibida' => $contada,
                'intentos' => $nuevosIntentos,
                'resultado' => 'INCIDENCIA',
                'incidencia_id' => $incident->id(),
                'tipo_discrepancia' => $esSobreRecepcion ? 'SOBRE_RECEPCION' : 'FALTANTE',
            ];
            $anyReceived = true;
            $linesBySku[$sku]['estado'] = 'RECIBIDA';
        }

        // Capacidad usada de la destino.
        if ($slots > 0) {
            $location->incrementarCapacidadUsada($slots);
            $this->locations->update($location);
        }

        // Transición del documento: CERRADA si todas RECIBIDA, si no
        // EN_RECEPCION.
        $allReceived = $this->allLinesReceived($documentId);
        $newState = $allReceived ? 'CERRADA' : 'EN_RECEPCION';
        $this->updateDocumentState($tenantId, $documentId, $newState, $now);

        $resultado = match (true) {
            $anyRecount => 'RECONTEO_REQUERIDO',
            $allReceived => 'RECIBIDO',
            default => 'RECIBIDO',
        };

        $data = [
            'mensaje' => $anyRecount
                ? 'Recepción procesada. Algunas líneas requieren reconteo.'
                : 'Recepción procesada correctamente.',
            'documento_id' => $documentId,
            'numero_documento' => $doc['numero_documento'],
            'estado_documento' => $newState,
            'resultado' => $resultado,
            'requiere_reconteo' => $anyRecount,
            'lineas' => $lineResults,
        ];

        $auditoria = [
            'documento_id' => $documentId,
            'numero_documento' => $doc['numero_documento'],
            'ubicacion_codigo' => $location->codigo()->value(),
            'lineas' => $auditLines,
        ];

        return [
            'status' => 200,
            'data' => $data,
            '_auditoria' => $auditoria,
        ];
    }

    // ==================================================================
    // UNIDAD FÍSICA + LEDGER + EVENTO
    // ==================================================================

    /**
     * Crea la unidad física recibida (DISPONIBLE en la destino) con su ledger
     * y evento RECIBIDO, igual que el CU-001.
     */
    private function createUnit(
        string $tenantId,
        string $productoId,
        float $cantidad,
        Location $location,
        ?string $lote,
        ?string $numeroSerie,
        array $doc,
        float $esperada,
        int $intentos
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
                'Recepción ciega contra documento %s (intento %d)',
                $doc['numero_documento'],
                $intentos
            ),
            datosOperacion: [
                'documento_id' => $doc['id'],
                'numero_documento' => $doc['numero_documento'],
                'ubicacion_id' => $location->id(),
                'ubicacion_codigo' => $location->codigo()->value(),
                'cantidad_esperada' => $esperada,
                'cantidad_recibida' => $cantidad,
                'intentos' => $intentos,
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
     * Incidencia DISCREPANCIA_CANTIDAD del 2º fallo, con restricción de
     * resolución (ni el receptor ni el planificador pueden resolverla).
     */
    private function createDiscrepancyIncident(
        string $tenantId,
        string $unitId,
        string $locationId,
        array $doc,
        string $sku,
        float $esperada,
        float $contada,
        int $intentos,
        string $receptorId,
        bool $esSobreRecepcion
    ): Incident {
        $diferencia = round($contada - $esperada, 4);
        $prioridad = $esSobreRecepcion
            ? Incident::PRIORIDAD_ALTA
            : $this->prioridadPorFaltante($esperada, $contada);

        $descripcion = $esSobreRecepcion
            ? sprintf(
                'Sobre-recepción en el documento %s, SKU %s: esperadas %.4f, contadas %.4f (+%.4f) tras %d intentos.',
                $doc['numero_documento'],
                $sku,
                $esperada,
                $contada,
                $diferencia,
                $intentos
            )
            : sprintf(
                'Faltante en el documento %s, SKU %s: esperadas %.4f, contadas %.4f (%.4f) tras %d intentos.',
                $doc['numero_documento'],
                $sku,
                $esperada,
                $contada,
                $diferencia,
                $intentos
            );

        $incident = Incident::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            tipo: Incident::TIPO_DISCREPANCIA_CANTIDAD,
            descripcion: $descripcion,
            unidadFisicaId: $unitId,
            ubicacionId: $locationId,
            actorIdReporta: $receptorId,
            observaciones: sprintf('Recepción ciega del documento %s (SKU %s).', $doc['numero_documento'], $sku),
            prioridad: $prioridad,
            restriccionResolucion: [
                'receptor' => $receptorId,
                'planificador' => (string) $doc['creado_por'],
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
    private function loadDocument(string $tenantId, string $documentId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, tenant_id, numero_documento, estado, creado_por
               FROM receiving_documents
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $documentId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw InventoryException::receivingDocumentNotFound($documentId);
        }

        return $row;
    }

    /**
     * @return array<string, array<string, mixed>> líneas del documento indexadas por SKU
     */
    private function loadLinesBySku(string $documentId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, producto_id, sku, cantidad_esperada, estado, intentos
               FROM receiving_document_lines
              WHERE document_id = :document_id'
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
        int $intentos,
        string $unidadId,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE receiving_document_lines
                SET estado = :estado,
                    cantidad_recibida = :cantidad_recibida,
                    intentos = :intentos,
                    unidad_recepcion_id = :unidad_recepcion_id,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            'estado' => 'RECIBIDA',
            'cantidad_recibida' => $cantidadRecibida,
            'intentos' => $intentos,
            'unidad_recepcion_id' => $unidadId,
            'updated_at' => $now,
            'id' => $lineId,
        ]);
    }

    private function bumpAttempts(PDO $pdo, string $lineId, int $intentos, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE receiving_document_lines
                SET intentos = :intentos, updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute(['intentos' => $intentos, 'updated_at' => $now, 'id' => $lineId]);
    }

    private function allLinesReceived(string $documentId): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT COUNT(*) FROM receiving_document_lines
              WHERE document_id = :document_id AND estado <> 'RECIBIDA'"
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
     * Normaliza y valida la entrada. Rechaza `cantidad_esperada` (recepción
     * ciega) y devuelve el payload canónico.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        // Principio 1: el esperado no se admite en ningún nivel del payload.
        if ($this->containsExpectedQuantity($input)) {
            throw InventoryException::receivingExpectedQuantityNotAccepted();
        }

        $ubicacionId = trim((string) ($input['ubicacion_id'] ?? ''));
        $ubicacionCodigo = trim((string) ($input['ubicacion_codigo'] ?? ''));

        if ($ubicacionId === '' && $ubicacionCodigo === '') {
            throw InventoryException::requiredField('ubicacion_codigo');
        }

        $rawLines = $input['lineas'] ?? [];
        if (!is_array($rawLines) || $rawLines === []) {
            throw InventoryException::receivingDocumentLinesRequired();
        }

        $lines = [];
        $seen = [];

        foreach (array_values($rawLines) as $index => $item) {
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
