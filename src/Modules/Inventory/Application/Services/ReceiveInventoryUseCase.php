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
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\SKU;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;

/**
 * ReceiveInventoryUseCase - CU-001: RECEPCIÓN DE INVENTARIO
 *
 * Flujo (todo dentro de UNA transacción):
 *
 *  1. Idempotencia (R7): si llega Idempotency-Key se INSERTA la key en la
 *     misma transacción; si ya existe con el mismo payload se devuelve la
 *     respuesta original sin ejecutar nada, y con otro payload → 409.
 *  2. Valida producto activo, ubicación activa y cantidades (> 0).
 *  3. Aplica capacidad de la ubicación (slots = ceil(cantidad recibida)).
 *  4. Genera código de barras único por tenant (con reintentos).
 *  5. Crea StockUnit (DISPONIBLE) + StockLedger (R1) + StockEvent (RECIBIDO).
 *  6. Recepción parcial (recibida < esperada): crea incidencia
 *     DISCREPANCIA_CANTIDAD en la misma transacción. v1.10.0 (E1):
 *       - Si la discrepancia (esperada - recibida) supera el umbral
 *         RECEPTION_DISCREPANCY_THRESHOLD (%, por defecto 5) la incidencia
 *         se crea con prioridad ALTA; si no, NORMAL.
 *       - `rechazo_total: true` (100 % de discrepancia): NO se crea unidad,
 *         ledger, evento ni se toca la capacidad; se crea una sola
 *         incidencia ENTREGA_RECHAZADA con prioridad ALTA.
 *  7. Persiste capacidad usada y guarda la respuesta de idempotencia antes
 *     del COMMIT. Cualquier error → ROLLBACK total (y la key se libera).
 *
 * Input:
 *  - sku (obligatorio)
 *  - cantidad_esperada (obligatorio, > 0)
 *  - cantidad_recibida (opcional, por defecto = esperada; > 0 y <= esperada)
 *  - ubicacion_codigo | ubicacion_id (obligatorio uno)
 *  - lote, numero_serie, propietario_id, custodio_id (opcionales)
 *  - rechazo_total (opcional, bool; sólo con cantidad_recibida 0)
 */
final class ReceiveInventoryUseCase
{
    public const OPERACION = 'inventory.receive';

    /** Umbral de discrepancia significativa (%, sobre cantidad_esperada). */
    public const THRESHOLD_ENV = 'RECEPTION_DISCREPANCY_THRESHOLD';
    public const THRESHOLD_DEFAULT = 5.0;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly IdempotencyService $idempotency,
        private readonly ProductRepositoryInterface $products,
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
     * @param array<string, mixed> $input  Cuerpo de la petición
     * @param string|null          $idempotencyKey Header Idempotency-Key (null = sin idempotencia)
     * @param string|null          $actorId  Usuario autenticado (claims)
     *
     * @return array{status: int, data: array<string, mixed>}
     *
     * @throws HttpException|InventoryException
     */
    public function execute(array $input, ?string $idempotencyKey = null, ?string $actorId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $payload = $this->normalize($input);

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

                $response = $this->receive($tenantId, $payload, $actorId);

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
    // RECEPCIÓN (corazón del CU-001)
    // ==================================================================

    /**
     * @param array<string, mixed> $payload ya normalizado/validado
     */
    private function receive(string $tenantId, array $payload, ?string $actorId): array
    {
        // 1. Producto
        $product = $this->products->findBySku($payload['sku']);
        if ($product === null) {
            throw InventoryException::productNotFound($payload['sku']);
        }
        if (!$product->isActive()) {
            throw InventoryException::productInactive($payload['sku']);
        }

        // 2. Ubicación de destino
        $location = $this->resolveLocation($payload);
        if ($location === null) {
            throw InventoryException::locationNotFound($payload['ubicacion_codigo'] ?? $payload['ubicacion_id']);
        }
        if (!$location->isActive()) {
            throw InventoryException::locationInactive($location->codigo()->value());
        }

        // 3. Rechazo total (E1): sin unidad, sin ledger, sin evento y sin
        //    tocar la capacidad; sólo se abre la incidencia.
        $rechazoTotal = (bool) $payload['rechazo_total'];
        $parcial = !$rechazoTotal && $payload['cantidad_recibida'] < $payload['cantidad_esperada'];
        $prioridad = $this->prioridadPorDiscrepancia(
            $payload['cantidad_esperada'],
            $rechazoTotal ? 0.0 : $payload['cantidad_recibida']
        );

        $unit = null;
        $ledger = null;
        $event = null;
        $slots = 0;

        if (!$rechazoTotal) {
            // 3. Capacidad: 1 slot por unidad, redondeado hacia arriba
            $slots = (int) ceil($payload['cantidad_recibida']);
            $location->incrementarCapacidadUsada($slots);

            // 4. Código de barras (prefijo fijo SU + aleatorio, con reintentos)
            $barcode = $this->generateUniqueBarcode();

            // 5. Unidad física (DISPONIBLE en la ubicación de destino)
            $unit = StockUnit::create(
                id: uuid(),
                tenantId: $tenantId,
                productoId: $product->id(),
                codigoBarcode: $barcode,
                cantidad: $payload['cantidad_recibida'],
                propietarioId: $payload['propietario_id'],
                custodioId: $payload['custodio_id'],
                numeroSerie: $payload['numero_serie'],
                lote: $payload['lote'],
                estado: StockState::DISPONIBLE,
                ubicacionId: $location->id()
            );
            $this->stockUnits->save($unit);

            // 6. Ledger inicial (R1: disponible = física - reservada - en tránsito)
            $ledger = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unit->id(),
                $unit->cantidad(),
                StockState::DISPONIBLE
            );
            $this->ledgers->save($ledger);

            // 7. Evento RECIBIDO (append-only, con hash de integridad)
            $event = StockEvent::create(
                id: uuid(),
                tenantId: $tenantId,
                unidadFisicaId: $unit->id(),
                tipoEvento: StockEvent::TIPO_RECIBIDO,
                descripcion: $parcial
                    ? 'Recepción parcial (CU-001)'
                    : 'Recepción de inventario (CU-001)',
                datosOperacion: [
                    'sku' => $payload['sku'],
                    'ubicacion_id' => $location->id(),
                    'ubicacion_codigo' => $location->codigo()->value(),
                    'cantidad_esperada' => $payload['cantidad_esperada'],
                    'cantidad_recibida' => $payload['cantidad_recibida'],
                    'lote' => $payload['lote'],
                    'numero_serie' => $payload['numero_serie'],
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
        }

        // 8. Rechazo total o recepción parcial → incidencia en la misma
        //    transacción (E1: prioridad según umbral de discrepancia)
        $incident = null;
        if ($rechazoTotal || $parcial) {
            $tipoIncidente = $rechazoTotal
                ? Incident::TIPO_ENTREGA_RECHAZADA
                : Incident::TIPO_DISCREPANCIA_CANTIDAD;

            $incident = Incident::create(
                id: uuid(),
                tenantId: $tenantId,
                tipo: $tipoIncidente,
                descripcion: $rechazoTotal
                    ? sprintf(
                        'Rechazo total de la recepción del SKU %s: esperadas %.4f, recibidas 0.',
                        $payload['sku'],
                        $payload['cantidad_esperada']
                    )
                    : sprintf(
                        'Recepción parcial del SKU %s: esperadas %.4f, recibidas %.4f.',
                        $payload['sku'],
                        $payload['cantidad_esperada'],
                        $payload['cantidad_recibida']
                    ),
                unidadFisicaId: $unit?->id(),
                ubicacionId: $location->id(),
                actorIdReporta: $actorId,
                prioridad: $prioridad
            );
            $this->incidents->save($incident);
        }

        // 9. Capacidad usada definitiva
        if (!$rechazoTotal) {
            $this->locations->update($location);
        }

        return [
            'status' => 201,
            'data' => [
                'mensaje' => 'Recepción registrada correctamente.',
                'unidad_fisica_id' => $unit?->id(),
                'codigo_barcode' => $unit?->codigoBarcode(),
                'producto_id' => $product->id(),
                'sku' => $product->sku()->value(),
                'ubicacion_id' => $location->id(),
                'ubicacion_codigo' => $location->codigo()->value(),
                'cantidad_esperada' => $payload['cantidad_esperada'],
                'cantidad_recibida' => $payload['cantidad_recibida'],
                'estado' => $unit?->estado()->value(),
                'ledger' => $ledger?->toPublicArray(),
                'evento' => $event === null ? null : [
                    'id' => $event->id(),
                    'tipo_evento' => $event->tipoEvento(),
                    'hash_integridad' => $event->hashIntegridad(),
                ],
                'incidencia' => $incident?->toPublicArray(),
                'recepcion_parcial' => $parcial,
                'rechazo_total' => $rechazoTotal,
                'discrepancia_porcentaje' => $this->discrepanciaPorcentaje(
                    $payload['cantidad_esperada'],
                    $rechazoTotal ? 0.0 : $payload['cantidad_recibida']
                ),
            ],
        ];
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * v1.10.0 (E1): prioridad ALTA si la discrepancia en % alcanza o supera
     * el umbral configurable RECEPTION_DISCREPANCY_THRESHOLD (5 % por defecto).
     */
    public function prioridadPorDiscrepancia(float $esperada, float $recibida): string
    {
        $pct = $this->discrepanciaPorcentaje($esperada, $recibida);

        return $pct >= self::thresholdPercent()
            ? Incident::PRIORIDAD_ALTA
            : Incident::PRIORIDAD_NORMAL;
    }

    public function discrepanciaPorcentaje(float $esperada, float $recibida): float
    {
        if ($esperada <= 0) {
            return 0.0;
        }

        return round((($esperada - $recibida) / $esperada) * 100, 2);
    }

    public static function thresholdPercent(): float
    {
        $raw = $_ENV[ReceiveInventoryUseCase::THRESHOLD_ENV] ?? null;

        if ($raw === null || !is_numeric($raw)) {
            return self::THRESHOLD_DEFAULT;
        }

        $value = (float) $raw;

        if (!is_finite($value) || $value <= 0 || $value > 100) {
            return self::THRESHOLD_DEFAULT;
        }

        return $value;
    }

    private function resolveLocation(array $payload): ?Location
    {
        if (!empty($payload['ubicacion_id'])) {
            return $this->locations->findById($payload['ubicacion_id']);
        }

        return $this->locations->findByCodigo($payload['ubicacion_codigo']);
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

    /**
     * Normaliza y valida la entrada. Devuelve el payload canónico que se usa
     * también como base del hash de idempotencia.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $skuRaw = trim((string) ($input['sku'] ?? ''));
        if ($skuRaw === '') {
            throw InventoryException::requiredField('sku');
        }
        $sku = SKU::fromString($skuRaw)->value();

        $esperada = $this->positiveFloat($input, 'cantidad_esperada', true);

        $rechazoTotal = $this->bool($input, 'rechazo_total');

        if ($rechazoTotal) {
            $recibidaRaw = $input['cantidad_recibida'] ?? null;

            if ($recibidaRaw !== null && $recibidaRaw !== '' && (float) $recibidaRaw > 0) {
                throw InventoryException::invalidQuantity(
                    'cantidad_recibida',
                    'Con rechazo_total la cantidad recibida debe ser 0.'
                );
            }

            $recibida = 0.0;
        } else {
            $recibida = array_key_exists('cantidad_recibida', $input) && $input['cantidad_recibida'] !== null && $input['cantidad_recibida'] !== ''
                ? $this->positiveFloat($input, 'cantidad_recibida', false)
                : $esperada;

            if ($recibida > $esperada) {
                throw InventoryException::invalidQuantity(
                    'cantidad_recibida',
                    'No puede ser mayor que la cantidad esperada.'
                );
            }
        }

        $ubicacionId = trim((string) ($input['ubicacion_id'] ?? ''));
        $ubicacionCodigo = trim((string) ($input['ubicacion_codigo'] ?? ''));

        if ($ubicacionId === '' && $ubicacionCodigo === '') {
            throw InventoryException::requiredField('ubicacion_codigo');
        }

        $lote = $this->optionalString($input, 'lote');
        $numeroSerie = $this->optionalString($input, 'numero_serie');
        $propietarioId = $this->optionalString($input, 'propietario_id');
        $custodioId = $this->optionalString($input, 'custodio_id');

        return [
            'sku' => $sku,
            'cantidad_esperada' => $esperada,
            'cantidad_recibida' => $recibida,
            'ubicacion_id' => $ubicacionId,
            'ubicacion_codigo' => $ubicacionCodigo,
            'lote' => $lote,
            'numero_serie' => $numeroSerie,
            'propietario_id' => $propietarioId,
            'custodio_id' => $custodioId,
            'rechazo_total' => $rechazoTotal,
        ];
    }

    /**
     * Booleano tolerante de entrada (true/1/'true'/'1'/'si'/'sí').
     */
    private function bool(array $input, string $field): bool
    {
        $raw = $input[$field] ?? null;

        if ($raw === null || $raw === '' || $raw === false) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }

        if (is_string($raw)) {
            $value = mb_strtolower(trim($raw));

            if (in_array($value, ['1', 'true', 'si', 'sí', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', ''], true)) {
                return false;
            }
        }

        throw InventoryException::invalidField($field, 'Debe ser un booleano.');
    }

    private function positiveFloat(array $input, string $field, bool $required): float
    {
        $raw = $input[$field] ?? null;

        if ($raw === null || $raw === '') {
            if ($required) {
                throw InventoryException::requiredField($field);
            }
            throw InventoryException::invalidQuantity($field, 'Debe ser mayor que cero.');
        }

        if (!is_numeric($raw)) {
            throw InventoryException::invalidQuantity($field, 'Debe ser un número.');
        }

        $value = (float) $raw;

        if (!is_finite($value) || $value <= 0) {
            throw InventoryException::invalidQuantity($field, 'Debe ser mayor que cero.');
        }

        return $value;
    }

    private function optionalString(array $input, string $field): ?string
    {
        $raw = $input[$field] ?? null;

        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 255) {
            throw InventoryException::fieldMaxLength($field, 255);
        }

        return $value;
    }
}
