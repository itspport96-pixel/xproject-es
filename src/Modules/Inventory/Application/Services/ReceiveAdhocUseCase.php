<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\SKU;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;
use PDO;

/**
 * ReceiveAdhocUseCase - BLOQUE R3 (v1.13.30): RECEPCIÓN ADHOC.
 *
 * Registra una recepción sin documento planificado (adhoc), dejando las
 * unidades en BLOQUEADO hasta que un segundo usuario las confirme o rechace
 * (separación de funciones).
 *
 *  - Principio 1: `referencia_documento` es OBLIGATORIA (marco de la
 *    recepción adhoc: referencia al albarán/entrega física que la motiva).
 *  - Principio 2: las unidades se crean en BLOQUEADO (estado de la matriz de
 *    transiciones que ocupa capacidad pero no está disponible hasta
 *    confirmar). Confirmar → DISPONIBLE (BLOQUEADO→DISPONIBLE ya en la
 *    matriz); rechazar → se mantiene BLOQUEADO y se abre una incidencia de
 *    prioridad ALTA.
 *  - Principio 3: el usuario que registra la recepción adhoc NO puede
 *    confirmarla (separación de funciones, 422
 *    `receivingAdhocCreatorCannotConfirm`).
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 *
 * Input:
 *  - referencia_documento (obligatoria, ≤100 chars)
 *  - ubicacion_codigo | ubicacion_id (obligatorio uno; destino de las unidades)
 *  - lineas[] (obligatorio, ≥1): { sku (obligatorio), cantidad (>0),
 *    lote?, numero_serie? }
 */
final class ReceiveAdhocUseCase
{
    public const OPERACION = 'inventory.receiving.adhoc';

    private const MAX_REF = 100;
    private const MAX_TEXT = 255;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly ProductRepositoryInterface $products,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
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
    public function execute(array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($actorId === null || trim($actorId) === '') {
            throw InventoryException::requiredField('actor_id');
        }

        $payload = $this->normalize($input);

        return $this->db->transaction(
            function () use ($tenantId, $payload, $idempotencyKey, $actorId): array {
                $request = $payload;

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

                $response = $this->receive($tenantId, $payload, (string) $actorId);

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
    // RECEPCIÓN ADHOC (corazón del R3)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     * @return array{status: int, data: array<string, mixed>, _auditoria: array<string, mixed>}
     */
    private function receive(string $tenantId, array $payload, string $actorId): array
    {
        $this->assertReferenceUnique($tenantId, $payload['referencia_documento']);

        $location = $this->resolveLocation($tenantId, $payload);

        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');
        $adhocId = $this->generateUuid();

        $this->insertAdhoc($pdo, $adhocId, $tenantId, $payload['referencia_documento'], $actorId, $now);

        $lineResults = [];
        $auditLines = [];
        $slots = 0;

        foreach ($payload['lineas'] as $incoming) {
            $product = $this->products->findBySku($incoming['sku']);

            if ($product === null) {
                throw InventoryException::productNotFound($incoming['sku']);
            }
            if (!$product->isActive()) {
                throw InventoryException::productInactive($incoming['sku']);
            }

            $unit = $this->createUnit(
                $tenantId,
                (string) $product->id(),
                $incoming['cantidad'],
                $location,
                $incoming['lote'],
                $incoming['numero_serie'],
                $payload['referencia_documento'],
                $adhocId
            );
            $slots += (int) ceil($incoming['cantidad']);

            $lineId = $this->generateUuid();
            $this->insertAdhocLine($pdo, $lineId, $adhocId, (string) $product->id(), $incoming['sku'], $incoming['cantidad'], $unit->id(), $now);

            $lineResults[] = [
                'sku' => $incoming['sku'],
                'producto_id' => (string) $product->id(),
                'cantidad' => $incoming['cantidad'],
                'unidad_fisica_id' => $unit->id(),
                'estado_unidad' => StockState::BLOQUEADO,
            ];
            $auditLines[] = [
                'sku' => $incoming['sku'],
                'cantidad' => $incoming['cantidad'],
                'unidad_fisica_id' => $unit->id(),
            ];
        }

        if ($slots > 0) {
            $location->incrementarCapacidadUsada($slots);
            $this->locations->update($location);
        }

        $data = [
            'mensaje' => 'Recepción adhoc registrada correctamente. Unidades en BLOQUEADO hasta confirmar.',
            'adhoc_id' => $adhocId,
            'referencia_documento' => $payload['referencia_documento'],
            'estado' => 'PENDIENTE',
            'ubicacion_id' => $location->id(),
            'ubicacion_codigo' => $location->codigo()->value(),
            'lineas' => $lineResults,
        ];

        $auditoria = [
            'adhoc_id' => $adhocId,
            'referencia_documento' => $payload['referencia_documento'],
            'ubicacion_codigo' => $location->codigo()->value(),
            'lineas' => $auditLines,
        ];

        return [
            'status' => 201,
            'data' => $data,
            '_auditoria' => $auditoria,
        ];
    }

    // ==================================================================
    // UNIDAD FÍSICA + LEDGER + EVENTO (en BLOQUEADO)
    // ==================================================================

    private function createUnit(
        string $tenantId,
        string $productoId,
        float $cantidad,
        Location $location,
        ?string $lote,
        ?string $numeroSerie,
        string $referencia,
        string $adhocId
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
            estado: StockState::BLOQUEADO,
            ubicacionId: $location->id()
        );
        $this->stockUnits->save($unit);

        $ledger = $this->calculation->crearLedger(
            $this->generateUuid(),
            $tenantId,
            $unit->id(),
            $unit->cantidad(),
            StockState::BLOQUEADO
        );
        $this->ledgers->save($ledger);

        $event = StockEvent::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unit->id(),
            tipoEvento: StockEvent::TIPO_RECIBIDO,
            descripcion: sprintf('Recepción adhoc registrada (ref %s)', $referencia),
            datosOperacion: [
                'adhoc_id' => $adhocId,
                'referencia_documento' => $referencia,
                'ubicacion_id' => $location->id(),
                'ubicacion_codigo' => $location->codigo()->value(),
                'cantidad' => $unit->cantidad(),
                'lote' => $lote,
                'numero_serie' => $numeroSerie,
            ] + StockLedger::contextoOperacion(
                ['cantidad_reservada' => 0.0, 'cantidad_disponible' => 0.0, 'cantidad_en_transito' => 0.0],
                $ledger->valoresOperacion()
            ),
            cantidadAnterior: 0.0,
            cantidadPosterior: $unit->cantidad(),
            estadoAnterior: null,
            estadoPosterior: StockState::BLOQUEADO
        );
        $this->events->save($event);

        return $unit;
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    private function assertReferenceUnique(string $tenantId, string $referencia): void
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT COUNT(*) FROM receiving_adhoc
              WHERE tenant_id = :tenant_id AND referencia_documento = :ref'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'ref' => $referencia]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw InventoryException::receivingAdhocReferenceAlreadyExists($referencia);
        }
    }

    private function insertAdhoc(
        PDO $pdo,
        string $id,
        string $tenantId,
        string $referencia,
        string $creadoPor,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO receiving_adhoc
                (id, tenant_id, referencia_documento, estado, creado_por, created_at, updated_at)
             VALUES
                (:id, :tenant_id, :ref, :estado, :creado_por, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'ref' => $referencia,
            'estado' => 'PENDIENTE',
            'creado_por' => $creadoPor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertAdhocLine(
        PDO $pdo,
        string $id,
        string $adhocId,
        string $productoId,
        string $sku,
        float $cantidad,
        string $unidadId,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO receiving_adhoc_lines
                (id, adhoc_id, producto_id, sku, cantidad, unidad_id, created_at, updated_at)
             VALUES
                (:id, :adhoc_id, :producto_id, :sku, :cantidad, :unidad_id, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'adhoc_id' => $adhocId,
            'producto_id' => $productoId,
            'sku' => $sku,
            'cantidad' => $cantidad,
            'unidad_id' => $unidadId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function normalize(array $input): array
    {
        $referencia = trim((string) ($input['referencia_documento'] ?? ''));

        if ($referencia === '') {
            throw InventoryException::requiredField('referencia_documento');
        }
        if (mb_strlen($referencia) > self::MAX_REF) {
            throw InventoryException::fieldMaxLength('referencia_documento', self::MAX_REF);
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

            $cantidad = $item['cantidad'] ?? null;
            if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
                throw InventoryException::invalidQuantity(
                    'cantidad',
                    "La línea del SKU '{$sku}' debe tener cantidad > 0."
                );
            }

            $lines[] = [
                'sku' => SKU::fromString($sku)->value(),
                'cantidad' => round((float) $cantidad, 4),
                'lote' => $this->optionalText($item['lote'] ?? null),
                'numero_serie' => $this->optionalText($item['numero_serie'] ?? null),
            ];
        }

        return [
            'referencia_documento' => $referencia,
            'ubicacion_id' => $ubicacionId,
            'ubicacion_codigo' => $ubicacionCodigo,
            'lineas' => $lines,
        ];
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
