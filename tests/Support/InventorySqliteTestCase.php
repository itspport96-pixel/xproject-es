<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\DeliveryRepository;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\InventoryCountRepository;
use App\Modules\Inventory\Adapters\Persistence\LocationRepository;
use App\Modules\Inventory\Adapters\Persistence\MovementRepository;
use App\Modules\Inventory\Adapters\Persistence\PickingOperationRepository;
use App\Modules\Inventory\Adapters\Persistence\ProductRepository;
use App\Modules\Inventory\Adapters\Persistence\ReservationRepository;
use App\Modules\Inventory\Adapters\Persistence\ReturnOperationRepository;
use App\Modules\Inventory\Adapters\Persistence\ShipmentItemRepository;
use App\Modules\Inventory\Adapters\Persistence\ShipmentRepository;
use App\Modules\Inventory\Adapters\Persistence\StockEventRepository;
use App\Modules\Inventory\Adapters\Persistence\StockLedgerRepository;
use App\Modules\Inventory\Adapters\Persistence\StockUnitRepository;
use App\Modules\Inventory\Application\Services\AuthorizeShipmentUseCase;
use App\Modules\Inventory\Application\Services\BarcodeService;
use App\Modules\Inventory\Application\Services\CompleteCountUseCase;
use App\Modules\Inventory\Application\Services\CompletePickingUseCase;
use App\Modules\Inventory\Application\Services\ConfirmDeliveryUseCase;
use App\Modules\Inventory\Application\Services\CountQueryService;
use App\Modules\Inventory\Application\Services\DeliveryQueryService;
use App\Modules\Inventory\Application\Services\IncidentQueryService;
use App\Modules\Inventory\Application\Services\InventoryPresenceService;
use App\Modules\Inventory\Application\Services\InvestigateIncidentUseCase;
use App\Modules\Inventory\Application\Services\BlockStockUseCase;
use App\Modules\Inventory\Application\Services\LoadShipmentUseCase;
use App\Modules\Inventory\Application\Services\MoveStockUseCase;
use App\Modules\Inventory\Application\Services\PickingQueryService;
use App\Modules\Inventory\Application\Services\ReconcileCountUseCase;
use App\Modules\Inventory\Application\Services\RecordCountResultsUseCase;
use App\Modules\Inventory\Application\Services\ReceiveInventoryUseCase;
use App\Modules\Inventory\Application\Services\RegisterDeliveryFailureUseCase;
use App\Modules\Inventory\Application\Services\RegisterReturnUseCase;
use App\Modules\Inventory\Application\Services\ReleaseReservationUseCase;
use App\Modules\Inventory\Application\Services\ReportIncidentUseCase;
use App\Modules\Inventory\Application\Services\ReservationExpirationService;
use App\Modules\Inventory\Application\Services\ReserveStockUseCase;
use App\Modules\Inventory\Application\Services\ResolveIncidentUseCase;
use App\Modules\Inventory\Application\Services\ReturnQueryService;
use App\Modules\Inventory\Application\Services\ShipmentQueryService;
use App\Modules\Inventory\Application\Services\StartCountUseCase;
use App\Modules\Inventory\Application\Services\StartPickingUseCase;
use App\Modules\Inventory\Application\Services\StateTransitionService;
use App\Modules\Inventory\Application\Services\StockCalculationService;
use App\Modules\Inventory\Application\Services\StockUnitSplitService;
use App\Modules\Inventory\Application\Services\VerifyPickingUseCase;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base de tests de recepción/stock (Sprint 5).
 *
 * Monta un MySQL-like en SQLite en memoria con los repositorios REALES:
 * así las transacciones, el rollback y los UNIQUE se comportan como en
 * producción (a diferencia de los dobles en memoria usados en el Sprint 4).
 *
 * Semilla base:
 *  - T1: SKU-ALFA (activo), SKU-OFF (inactivo)
 *  - T1: LOC-01 (sin capacidad), LOC-FULL (capacidad 5 llena), LOC-OFF (inactiva)
 *  - T1: LOC-PICK (PICKING, capacidad 10), LOC-PICK-FULL (PICKING llena),
 *    LOC-TRN (TRANSITO) — zonas de los CU-004/CU-005/CU-006 (v1.7.0)
 *  - T2: SKU-T2 (activo), LOC-T2 (sin capacidad)
 */
abstract class InventorySqliteTestCase extends TestCase
{
    protected const T1 = 'cb22db62-5379-4b83-8c51-a3c695c6de51';
    protected const T2 = '4f1d7e0a-9b23-4c56-8d7e-1a2b3c4d5e6f';

    protected PDO $pdo;
    protected DatabaseService $db;
    protected TenantContext $tenantContext;

    protected ProductRepository $products;
    protected LocationRepository $locations;
    protected StockUnitRepository $stockUnits;
    protected StockLedgerRepository $ledgers;
    protected StockEventRepository $events;
    protected IncidentRepository $incidents;
    protected MovementRepository $movements;
    protected ReservationRepository $reservations;
    protected PickingOperationRepository $pickings;
    protected ShipmentRepository $shipments;
    protected ShipmentItemRepository $shipmentItems;
    protected DeliveryRepository $deliveries;
    protected ReturnOperationRepository $returnOperations;
    protected InventoryCountRepository $counts;

    protected BarcodeService $barcodeService;
    protected StockCalculationService $calculation;
    protected StateTransitionService $states;
    protected StockUnitSplitService $splitService;
    protected ReceiveInventoryUseCase $useCase;
    protected MoveStockUseCase $moveUseCase;
    protected BlockStockUseCase $blockUseCase;
    protected ReserveStockUseCase $reserveUseCase;
    protected ReleaseReservationUseCase $releaseUseCase;
    protected ReservationExpirationService $expirationService;
    protected StartPickingUseCase $startPickingUseCase;
    protected CompletePickingUseCase $completePickingUseCase;
    protected VerifyPickingUseCase $verifyPickingUseCase;
    protected LoadShipmentUseCase $loadShipmentUseCase;
    protected AuthorizeShipmentUseCase $authorizeShipmentUseCase;
    protected PickingQueryService $pickingQuery;
    protected ShipmentQueryService $shipmentQuery;

    // Sprint 8 (v1.8.0): entregas y devoluciones (CU-007/CU-008/CU-009)
    protected RegisterDeliveryFailureUseCase $failureUseCase;
    protected ConfirmDeliveryUseCase $confirmDeliveryUseCase;
    protected RegisterReturnUseCase $registerReturnUseCase;
    protected DeliveryQueryService $deliveryQuery;
    protected ReturnQueryService $returnQuery;

    // Sprint 9 (v1.9.0): incidencias y conteos físicos (CU-010 / CU-011)
    protected InventoryPresenceService $presence;
    protected ReportIncidentUseCase $reportIncidentUseCase;
    protected InvestigateIncidentUseCase $investigateIncidentUseCase;
    protected ResolveIncidentUseCase $resolveIncidentUseCase;
    protected StartCountUseCase $startCountUseCase;
    protected RecordCountResultsUseCase $recordCountResultsUseCase;
    protected CompleteCountUseCase $completeCountUseCase;
    protected ReconcileCountUseCase $reconcileCountUseCase;
    protected IncidentQueryService $incidentQuery;
    protected CountQueryService $countQuery;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->tenantContext = new TenantContext(
            $this->createStub(TenantRepositoryInterface::class)
        );
        $this->tenantContext->setTenantId(self::T1);

        $this->products = new ProductRepository($this->db, $this->tenantContext);
        $this->locations = new LocationRepository($this->db, $this->tenantContext);
        $this->stockUnits = new StockUnitRepository($this->db, $this->tenantContext);
        $this->ledgers = new StockLedgerRepository($this->db, $this->tenantContext);
        $this->events = new StockEventRepository($this->db, $this->tenantContext);
        $this->incidents = new IncidentRepository($this->db, $this->tenantContext);
        $this->movements = new MovementRepository($this->db, $this->tenantContext);
        $this->reservations = new ReservationRepository($this->db, $this->tenantContext);

        $this->barcodeService = new BarcodeService();
        $this->calculation = new StockCalculationService();
        $this->states = new StateTransitionService();

        $this->splitService = new StockUnitSplitService(
            $this->db,
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->states,
            $this->barcodeService
        );

        $this->useCase = $this->buildUseCase($this->stockUnits);

        $this->moveUseCase = new MoveStockUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->movements,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->blockUseCase = new BlockStockUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->reserveUseCase = new ReserveStockUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext,
            $this->splitService
        );

        $this->releaseUseCase = new ReleaseReservationUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->expirationService = new ReservationExpirationService(
            $this->db,
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        // ==============================================================
        // PICKING Y TRANSPORTE (Sprint 7, v1.7.0)
        // ==============================================================
        $this->pickings = new PickingOperationRepository($this->db, $this->tenantContext);
        $this->shipments = new ShipmentRepository($this->db, $this->tenantContext);
        $this->shipmentItems = new ShipmentItemRepository($this->db, $this->tenantContext);

        $this->startPickingUseCase = new StartPickingUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->pickings,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->completePickingUseCase = new CompletePickingUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->pickings,
            $this->tenantContext
        );

        $this->verifyPickingUseCase = new VerifyPickingUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->pickings,
            $this->tenantContext
        );

        $this->loadShipmentUseCase = new LoadShipmentUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->shipments,
            $this->shipmentItems,
            $this->pickings,
            $this->reservations,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->authorizeShipmentUseCase = new AuthorizeShipmentUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->shipments,
            $this->shipmentItems,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->pickingQuery = new PickingQueryService($this->db->getConnection());
        $this->shipmentQuery = new ShipmentQueryService($this->db->getConnection());

        // ==============================================================
        // ENTREGAS Y DEVOLUCIONES (Sprint 8, v1.8.0)
        // ==============================================================
        $this->deliveries = new DeliveryRepository($this->db, $this->tenantContext);
        $this->returnOperations = new ReturnOperationRepository($this->db, $this->tenantContext);

        $this->failureUseCase = new RegisterDeliveryFailureUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->shipments,
            $this->shipmentItems,
            $this->deliveries,
            $this->incidents,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->confirmDeliveryUseCase = new ConfirmDeliveryUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->shipments,
            $this->shipmentItems,
            $this->deliveries,
            $this->calculation,
            $this->states,
            $this->failureUseCase,
            $this->tenantContext
        );

        $this->registerReturnUseCase = new RegisterReturnUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->deliveries,
            $this->returnOperations,
            $this->incidents,
            $this->shipments,
            $this->shipmentItems,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->deliveryQuery = new DeliveryQueryService($this->db->getConnection());
        $this->returnQuery = new ReturnQueryService($this->db->getConnection());

        // ==============================================================
        // INCIDENCIAS Y CONTEOS FÍSICOS (Sprint 9, v1.9.0)
        // ==============================================================
        $this->counts = new InventoryCountRepository($this->db, $this->tenantContext);
        $this->presence = new InventoryPresenceService($this->db->getConnection());

        $this->reportIncidentUseCase = new ReportIncidentUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->incidents,
            $this->counts,
            $this->calculation,
            $this->states,
            $this->presence,
            $this->tenantContext
        );

        $this->investigateIncidentUseCase = new InvestigateIncidentUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->incidents,
            $this->tenantContext
        );

        $this->resolveIncidentUseCase = new ResolveIncidentUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->incidents,
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->states,
            $this->presence,
            $this->tenantContext
        );

        $this->startCountUseCase = new StartCountUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->locations,
            $this->counts,
            $this->presence,
            $this->tenantContext
        );

        $this->recordCountResultsUseCase = new RecordCountResultsUseCase(
            $this->db,
            $this->counts,
            $this->tenantContext
        );

        $this->completeCountUseCase = new CompleteCountUseCase(
            $this->db,
            $this->counts,
            $this->tenantContext
        );

        $this->reconcileCountUseCase = new ReconcileCountUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->counts,
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->incidents,
            $this->calculation,
            $this->presence,
            $this->states,
            $this->tenantContext
        );

        $this->incidentQuery = new IncidentQueryService($this->db->getConnection());
        $this->countQuery = new CountQueryService($this->db->getConnection());

        $this->seedBase();
    }

    /**
     * Fábrica del use case: permite inyectar un repositorio doble
     * (p. ej. CollidingBarcodeRepository) para forzar colisiones de barcode.
     */
    protected function buildUseCase(StockUnitRepositoryInterface $stockUnits): ReceiveInventoryUseCase
    {
        return new ReceiveInventoryUseCase(
            $this->db,
            new IdempotencyService($this->pdo),
            $this->products,
            $this->locations,
            $stockUnits,
            $this->ledgers,
            $this->events,
            $this->incidents,
            $this->calculation,
            $this->barcodeService,
            $this->tenantContext
        );
    }

    // ====================================================================
    // SEMILLA
    // ====================================================================

    protected function seedBase(): void
    {
        $this->seedProduct(self::T1, 'SKU-ALFA');
        $this->seedProduct(self::T1, 'SKU-OFF', activo: false);
        $this->seedLocation(self::T1, 'LOC-01');
        $this->seedLocation(self::T1, 'LOC-FULL', capacidad: 5, capacidadUsada: 5);
        $this->seedLocation(self::T1, 'LOC-OFF', activa: false);
        // Zonas del CU-004/CU-005/CU-006 (v1.7.0)
        $this->seedLocation(self::T1, 'LOC-PICK', capacidad: 10, tipo: 'PICKING');
        $this->seedLocation(self::T1, 'LOC-PICK-FULL', capacidad: 1, capacidadUsada: 1, tipo: 'PICKING');
        $this->seedLocation(self::T1, 'LOC-TRN', tipo: 'TRANSITO');
        // Zona DAÑADOS (v1.8.0, CU-009): destino de las devoluciones dañadas
        $this->seedLocation(self::T1, 'LOC-DAM', tipo: 'DAÑADOS');

        $this->seedProduct(self::T2, 'SKU-T2');
        $this->seedLocation(self::T2, 'LOC-T2');
        // T2 queda SIN ubicaciones fijas a propósito: es el tenant "vacío" de
        // TransitLocationServiceTest (sin TRANSITO ni DAÑADOS).
    }

    protected function seedProduct(
        string $tenantId,
        string $sku,
        bool $activo = true,
        ?string $id = null
    ): string {
        $id = $id ?? 'prod-' . strtolower($sku) . '-' . substr($tenantId, 0, 5);

        $stmt = $this->pdo->prepare(
            'INSERT INTO products (id, tenant_id, sku, nombre, unidad_medida, activo, created_at, updated_at)
             VALUES (:id, :tenant_id, :sku, :nombre, :unidad_medida, :activo, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'nombre' => "Producto {$sku}",
            'unidad_medida' => 'UNIDAD',
            'activo' => $activo ? 1 : 0,
            'now' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function seedLocation(
        string $tenantId,
        string $codigo,
        ?int $capacidad = null,
        int $capacidadUsada = 0,
        bool $activa = true,
        ?string $id = null,
        string $tipo = 'ALMACENAMIENTO'
    ): string {
        $id = $id ?? 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);

        $stmt = $this->pdo->prepare(
            'INSERT INTO locations (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada, activa, created_at, updated_at)
             VALUES (:id, :tenant_id, :codigo, :nombre, :tipo, :capacidad, :capacidad_usada, :activa, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'codigo' => $codigo,
            'nombre' => "Ubicacion {$codigo}",
            'tipo' => $tipo,
            'capacidad' => $capacidad,
            'capacidad_usada' => $capacidadUsada,
            'activa' => $activa ? 1 : 0,
            'now' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    // ====================================================================
    // AYUDAS DE ASSERTIÓN / CONSULTA
    // ====================================================================

    protected function useTenant(string $tenantId): void
    {
        $this->tenantContext->setTenantId($tenantId);
    }

    protected function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    // ====================================================================
    // ESQUEMA SQLite (equivalente a las tablas MySQL usadas)
    // ====================================================================

    private function createSchema(): void
    {
        $ddl = [
            'CREATE TABLE products (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                sku TEXT NOT NULL,
                nombre TEXT NOT NULL,
                descripcion TEXT,
                categoria TEXT,
                unidad_medida TEXT NOT NULL,
                peso REAL,
                volumen REAL,
                dimensiones TEXT,
                es_serializable INTEGER DEFAULT 0,
                es_loteable INTEGER DEFAULT 0,
                propiedades TEXT,
                activo INTEGER DEFAULT 1,
                metadatos TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT,
                sku_activo TEXT GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN sku ELSE NULL END) STORED,
                UNIQUE (tenant_id, sku_activo)
            )',
            'CREATE TABLE locations (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                codigo TEXT NOT NULL,
                nombre TEXT,
                tipo TEXT NOT NULL,
                jerarquia TEXT,
                capacidad INTEGER,
                capacidad_usada INTEGER DEFAULT 0,
                activa INTEGER DEFAULT 1,
                metadatos TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT,
                codigo_activo TEXT GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN codigo ELSE NULL END) STORED,
                UNIQUE (tenant_id, codigo_activo)
            )',
            'CREATE TABLE stock_units (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                producto_id TEXT NOT NULL,
                propietario_id TEXT,
                custodio_id TEXT,
                numero_serie TEXT,
                lote TEXT,
                codigo_barcode TEXT NOT NULL,
                cantidad REAL NOT NULL,
                peso REAL,
                volumen REAL,
                estado TEXT DEFAULT \'DISPONIBLE\',
                ubicacion_id TEXT,
                fecha_ingreso TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, codigo_barcode)
            )',
            'CREATE TABLE stock_ledger (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                cantidad_fisica REAL,
                cantidad_reservada REAL DEFAULT 0,
                cantidad_en_transito REAL DEFAULT 0,
                cantidad_disponible REAL,
                estado_actual TEXT,
                ultima_actualizacion TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE stock_events (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                tipo_evento TEXT NOT NULL,
                descripcion TEXT,
                datos_operacion TEXT,
                cantidad_anterior REAL,
                cantidad_posterior REAL,
                estado_anterior TEXT,
                estado_posterior TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                hash_integridad TEXT
            )',
            // Sprint 9 (v1.9.0): incidents replica las columnas de
            // SHOW CREATE TABLE incidents de la BD viva (observaciones,
            // inventory_count_id e índice a inventory_counts; sin FK en
            // SQLite, como el resto de tablas de este esquema).
            'CREATE TABLE incidents (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                tipo TEXT NOT NULL,
                unidad_fisica_id TEXT,
                descripcion TEXT NOT NULL,
                observaciones TEXT,
                ubicacion_id TEXT,
                estado TEXT DEFAULT \'ABIERTA\',
                inventory_count_id TEXT,
                actor_id_reporta TEXT,
                timestamp_deteccion TEXT DEFAULT CURRENT_TIMESTAMP,
                resolucion TEXT,
                prioridad TEXT DEFAULT \'NORMAL\',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE INDEX idx_incidents_inventory_count_id ON incidents (inventory_count_id)',
            // Sprint 9 (v1.9.0): inventory_counts (CU-011), columnas idénticas
            // a SHOW CREATE TABLE inventory_counts de la BD viva. SQLite no
            // admite CHECK(json_valid(...)), así que las columnas JSON son TEXT.
            'CREATE TABLE inventory_counts (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                ubicacion_id TEXT,
                estado TEXT DEFAULT \'PENDIENTE\',
                usuario_responsable_id TEXT,
                fecha_conteo TEXT DEFAULT CURRENT_TIMESTAMP,
                lista_teorica TEXT,
                resultados TEXT,
                discrepancias TEXT,
                reconciliado INTEGER DEFAULT 0,
                ajustes_aplicados TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE INDEX idx_inventory_counts_tenant_estado ON inventory_counts (tenant_id, estado)',
            'CREATE TABLE idempotency_keys (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                operacion TEXT NOT NULL,
                request_hash TEXT NOT NULL,
                response_json TEXT,
                http_status INTEGER,
                actor_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, idempotency_key, operacion)
            )',
            'CREATE TABLE movements (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL NOT NULL,
                ubicacion_origen_id TEXT,
                ubicacion_destino_id TEXT,
                motivo TEXT,
                actor_id TEXT,
                referencia_externa TEXT,
                timestamp_movimiento TEXT DEFAULT CURRENT_TIMESTAMP,
                hash_integridad TEXT,
                es_correccion INTEGER DEFAULT 0,
                movimiento_referencia_id TEXT
            )',
            'CREATE TABLE reservations (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL NOT NULL,
                referencia_orden TEXT,
                actor_id TEXT,
                fecha_reserva TEXT DEFAULT CURRENT_TIMESTAMP,
                fecha_expiracion TEXT,
                estado TEXT DEFAULT \'ACTIVA\',
                motivo_cancelacion TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, id)
            )',
            // Sprint 7 (v1.7.0): tablas de picking y transporte — columnas
            // idénticas a database/schema/schema.sql (spec 3.12-3.14), sin
            // ENUM/FK para que SQLite las toleren.
            'CREATE TABLE picking_operations (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                id_lote_picking TEXT,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL,
                ubicacion_origen_id TEXT,
                ubicacion_preparacion_id TEXT,
                referencia_orden TEXT,
                actor_id_selector TEXT,
                actor_id_supervisor TEXT,
                estado TEXT DEFAULT \'PENDIENTE\',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE shipments (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                referencia_externa TEXT,
                tipo TEXT DEFAULT \'PROPIO\',
                origen_ubicacion_id TEXT,
                destino_direccion TEXT,
                destinatario_id TEXT,
                destinatario_nombre TEXT,
                destinatario_contacto TEXT,
                conductor_nombre TEXT,
                conductor_contacto TEXT,
                vehiculo_referencia TEXT,
                estado TEXT DEFAULT \'PREPARACION\',
                hora_salida TEXT,
                hora_entrega_esperada TEXT,
                hora_entrega_real TEXT,
                observaciones TEXT,
                actor_id_autoriza_salida TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE shipment_items (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                shipment_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL,
                estado TEXT DEFAULT \'PREPARADA\',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            // Sprint 8 (v1.8.0): tablas de entregas y devoluciones — columnas
            // idénticas a database/schema/schema.sql (spec 3.15-3.16), sin
            // ENUM/FK para que SQLite las tolere.
            'CREATE TABLE deliveries (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                shipment_id TEXT NOT NULL,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL,
                estado TEXT DEFAULT \'PENDIENTE\',
                destinatario_real TEXT,
                firma_comprobante TEXT,
                observaciones TEXT,
                actor_id_entrega TEXT,
                timestamp_entrega TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE return_operations (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                delivery_id TEXT,
                unidad_fisica_id TEXT NOT NULL,
                cantidad REAL,
                motivo TEXT DEFAULT \'OTRO\',
                descripcion_motivo TEXT,
                estado_al_devolver TEXT DEFAULT \'OK\',
                ubicacion_destino_id TEXT,
                actor_id_recibe_devolucion TEXT,
                timestamp_devolucion TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
        ];

        foreach ($ddl as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
