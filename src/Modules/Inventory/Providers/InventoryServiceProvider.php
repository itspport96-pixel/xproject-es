<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Core\Audit\AuditLogger;
use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\InventoryCountRepository;
use App\Modules\Inventory\Adapters\Persistence\DeliveryRepository;
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
use App\Modules\Inventory\Application\Services\BlockStockUseCase;
use App\Modules\Inventory\Application\Services\ConfirmDeliveryUseCase;
use App\Modules\Inventory\Application\Services\DeliveryQueryService;
use App\Modules\Inventory\Application\Services\RegisterDeliveryFailureUseCase;
use App\Modules\Inventory\Application\Services\RegisterReturnUseCase;
use App\Modules\Inventory\Application\Services\ReturnQueryService;
use App\Modules\Inventory\Application\Services\CompleteCountUseCase;
use App\Modules\Inventory\Application\Services\CompletePickingUseCase;
use App\Modules\Inventory\Application\Services\CountQueryService;
use App\Modules\Inventory\Application\Services\DashboardService;
use App\Modules\Inventory\Application\Services\IncidentQueryService;
use App\Modules\Inventory\Application\Services\InventoryPresenceService;
use App\Modules\Inventory\Application\Services\InvestigateIncidentUseCase;
use App\Modules\Inventory\Application\Services\LoadShipmentUseCase;
use App\Modules\Inventory\Application\Services\LocationService;
use App\Modules\Inventory\Application\Services\MoveStockUseCase;
use App\Modules\Inventory\Application\Services\MovementQueryService;
use App\Modules\Inventory\Application\Services\PickingQueryService;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Modules\Inventory\Application\Services\ReceiveInventoryUseCase;
use App\Modules\Inventory\Application\Services\ReceivingDocumentService;
use App\Modules\Inventory\Application\Services\ReconcileCountUseCase;
use App\Modules\Inventory\Application\Services\RecordCountResultsUseCase;
use App\Modules\Inventory\Application\Services\ReleaseReservationUseCase;
use App\Modules\Inventory\Application\Services\ReportIncidentUseCase;
use App\Modules\Inventory\Application\Services\ResolveIncidentUseCase;
use App\Modules\Inventory\Application\Services\ReservationExpirationService;
use App\Modules\Inventory\Application\Services\ReservationQueryService;
use App\Modules\Inventory\Application\Services\ReserveStockUseCase;
use App\Modules\Inventory\Application\Services\ShipmentQueryService;
use App\Modules\Inventory\Application\Services\StartCountUseCase;
use App\Modules\Inventory\Application\Services\StartPickingUseCase;
use App\Modules\Inventory\Application\Services\StateTransitionService;
use App\Modules\Inventory\Application\Services\StockCalculationService;
use App\Modules\Inventory\Application\Services\StockHistoryQueryService;
use App\Modules\Inventory\Application\Services\StockQueryService;
use App\Modules\Inventory\Application\Services\StockUnitSplitService;
use App\Modules\Inventory\Application\Services\TransitLocationService;
use App\Modules\Inventory\Application\Services\VerifyPickingUseCase;
use App\Modules\Inventory\Domain\Repositories\IncidentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\InventoryCountRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\DeliveryRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\MovementRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ReturnOperationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentItemRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\ShipmentRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Services\IdempotencyService;

/**
 * InventoryServiceProvider - Bootstrap del módulo Inventory.
 *
 * Registra en el DI Container:
 *  - Interfaces de repositorio → implementaciones MariaDB
 *    (products, locations, stock_units, stock_ledger, stock_events, incidents)
 *  - Services de aplicación (ProductService, LocationService, DashboardService,
 *    BarcodeService, StockCalculationService, StockQueryService,
 *    ReservationQueryService, ReceiveInventoryUseCase)
 *  - IdempotencyService (Shared, reutilizable fuera de Inventory)
 *
 * Se invoca desde bootstrap.php tras cargar los servicios Core.
 *
 * Orden de registro (de lo más interno a lo más externo):
 *  1. Repositorios (dependen de DatabaseService + TenantContext)
 *  2. IdempotencyService (mismo PDO que la operación: vive en su transacción)
 *  3. Services (dependen de repositorios + TenantContext + InputSanitizer)
 *  4. DashboardService / StockQueryService (sólo PDO: el tenant_id viaja en
 *     los claims)
 */
final class InventoryServiceProvider
{
    public function register(Container $container): void
    {
        // ============================================================
        // REPOSITORIOS
        // ============================================================

        $container->singleton(
            ProductRepositoryInterface::class,
            fn($c) => new ProductRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            LocationRepositoryInterface::class,
            fn($c) => new LocationRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // STOCK / RECEPCIÓN (Sprint 5)
        // ============================================================

        $container->singleton(
            StockUnitRepositoryInterface::class,
            fn($c) => new StockUnitRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            StockLedgerRepositoryInterface::class,
            fn($c) => new StockLedgerRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            StockEventRepositoryInterface::class,
            fn($c) => new StockEventRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            IncidentRepositoryInterface::class,
            fn($c) => new IncidentRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // Conteos físicos (CU-011, v1.9.0): inventory_counts (lista teórica,
        // resultados y ajustes en JSON)
        $container->singleton(
            InventoryCountRepositoryInterface::class,
            fn($c) => new InventoryCountRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // MOVIMIENTOS Y RESERVAS (Sprint 6)
        // ============================================================

        $container->singleton(
            MovementRepositoryInterface::class,
            fn($c) => new MovementRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            ReservationRepositoryInterface::class,
            fn($c) => new ReservationRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // PICKING Y TRANSPORTE (Sprint 7, v1.7.0)
        // ============================================================

        $container->singleton(
            PickingOperationRepositoryInterface::class,
            fn($c) => new PickingOperationRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            ShipmentRepositoryInterface::class,
            fn($c) => new ShipmentRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            ShipmentItemRepositoryInterface::class,
            fn($c) => new ShipmentItemRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // Entregas y devoluciones (Sprint 8, v1.8.0)
        $container->singleton(
            DeliveryRepositoryInterface::class,
            fn($c) => new DeliveryRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            ReturnOperationRepositoryInterface::class,
            fn($c) => new ReturnOperationRepository(
                $c->get('database'),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // SERVICIOS SHARED REUTILIZABLES
        // ============================================================

        // Idempotency (R7): reutilizable fuera de Inventory (movimientos,
        // reservas, envíos). Corre sobre el mismo PDO que la operación para
        // que la key viva dentro de su transacción.
        $container->singleton(
            IdempotencyService::class,
            fn($c) => new IdempotencyService($c->get('database')->getConnection())
        );

        // ============================================================
        // SERVICES DE APLICACIÓN
        // ============================================================

        $container->singleton(
            ProductService::class,
            fn($c) => new ProductService(
                $c->get(ProductRepositoryInterface::class),
                $c->get(TenantContext::class),
                $c->get(InputSanitizer::class)
            )
        );

        $container->singleton(
            LocationService::class,
            fn($c) => new LocationService(
                $c->get(LocationRepositoryInterface::class),
                $c->get(TenantContext::class),
                $c->get(InputSanitizer::class)
            )
        );

        // Ubicación virtual TRANSITO por tenant (v1.7.1): la crea TenantService
        // al dar de alta un tenant y `bin/seed-transit-locations.php` para los
        // existentes. Sólo necesita el PDO (el tenant viaja explícito).
        $container->singleton(
            TransitLocationService::class,
            fn($c) => new TransitLocationService($c->get('database'))
        );

        // ============================================================
        // STOCK / RECEPCIÓN (Sprint 5)
        // ============================================================

        $container->singleton(
            BarcodeService::class,
            fn($c) => new BarcodeService()
        );

        $container->singleton(
            StockCalculationService::class,
            fn($c) => new StockCalculationService()
        );

        // Stock actual (R1): lectura directa, como DashboardService
        $container->singleton(
            StockQueryService::class,
            fn($c) => new StockQueryService($c->get('database')->getConnection())
        );

        // Reservas (GET /api/inventory/reservations, v1.6.2): misma lectura
        // directa; el tenant_id llega por los claims en cada llamada.
        $container->singleton(
            ReservationQueryService::class,
            fn($c) => new ReservationQueryService($c->get('database')->getConnection())
        );

        // CU-001: recepción transaccional (unidad + ledger + evento + capacidad)
        $container->singleton(
            ReceiveInventoryUseCase::class,
            fn($c) => new ReceiveInventoryUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(ProductRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(BarcodeService::class),
                $c->get(TenantContext::class)
            )
        );

        // BLOQUE R1 (v1.13.28): documentos de recepción planificada. El
        // chequeo de "otro receptor activo" usa AuthorizationService (registrado
        // en bootstrap.php).
        $container->singleton(
            ReceivingDocumentService::class,
            fn($c) => new ReceivingDocumentService(
                $c->get('database'),
                $c->get(TenantContext::class),
                $c->get(\App\Core\Authorization\Service\AuthorizationService::class)
            )
        );

        // Dashboard (spec §9, capas crítica/secundaria): recibe el PDO
        // directamente; el tenant_id llega por los claims en cada llamada.
        $container->singleton(
            DashboardService::class,
            fn($c) => new DashboardService(
                $c->get('database')->getConnection()
            )
        );

        // ============================================================
        // MOVIMIENTOS Y RESERVAS (Sprint 6)
        // ============================================================

        // Máquina de estados de la unidad (spec §4): fuente única de verdad
        // sobre transiciones válidas (reserva, liberación, expiración, move).
        $container->singleton(
            StateTransitionService::class,
            fn($c) => new StateTransitionService()
        );

        // CU-002: movimiento interno transaccional (unidad + capacidades +
        // fila movements inmutable + ledger + evento MOVIDO)
        $container->singleton(
            MoveStockUseCase::class,
            fn($c) => new MoveStockUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(MovementRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // v1.10.0 (E4): bloquear/desbloquear unidad a mano (permiso
        // `inventory.stock.block` en la ruta); no toca ubicación ni cupo.
        $container->singleton(
            BlockStockUseCase::class,
            fn($c) => new BlockStockUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-003: reserva transaccional (reserva ACTIVA + ledger + unidad
        // RESERVADO + evento RESERVADO)
        // CU-003: reserva transaccional (reserva ACTIVA + ledger + unidad
        // RESERVADO + evento RESERVADO). v1.8.2: si la cantidad pedida es
        // menor que la de la unidad, la reserva recae sobre una hija creada
        // por StockUnitSplitService (la madre queda con el resto).
        $container->singleton(
            StockUnitSplitService::class,
            fn($c) => new StockUnitSplitService(
                $c->get('database'),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(BarcodeService::class)
            )
        );

        $container->singleton(
            ReserveStockUseCase::class,
            fn($c) => new ReserveStockUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class),
                $c->get(StockUnitSplitService::class)
            )
        );

        // CU-003: cancelación de reserva (vuelve la unidad a DISPONIBLE)
        $container->singleton(
            ReleaseReservationUseCase::class,
            fn($c) => new ReleaseReservationUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // E7: expiración de reservas vencidas (comando simple, lote por tenant).
        // El AuditLogger es opcional: cuando está disponible (CLI real), el
        // servicio escribe en `audit_logs` cada reserva que expira (v1.6.2).
        $container->singleton(
            ReservationExpirationService::class,
            fn($c) => new ReservationExpirationService(
                $c->get('database'),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class),
                $c->has(AuditLogger::class) ? $c->get(AuditLogger::class) : null
            )
        );

        // ============================================================
        // PICKING Y TRANSPORTE (Sprint 7, v1.7.0)
        // ============================================================

        // Consultas paginadas (GET /picking, /shipments, /shipments/{id}):
        // lectura directa por PDO, el tenant_id llega por los claims.
        $container->singleton(
            PickingQueryService::class,
            fn($c) => new PickingQueryService($c->get('database')->getConnection())
        );

        $container->singleton(
            ShipmentQueryService::class,
            fn($c) => new ShipmentQueryService($c->get('database')->getConnection())
        );

        // CU-004: inicio de picking (lote EN_CURSO + unidad EN_PREPARACION +
        // reserva de la unidad cumplida, v1.7.1)
        $container->singleton(
            StartPickingUseCase::class,
            fn($c) => new StartPickingUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(PickingOperationRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-004: lote COMPLETADA / VERIFICADA (supervisor ≠ selector)
        $container->singleton(
            CompletePickingUseCase::class,
            fn($c) => new CompletePickingUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(PickingOperationRepositoryInterface::class),
                $c->get(TenantContext::class)
            )
        );

        $container->singleton(
            VerifyPickingUseCase::class,
            fn($c) => new VerifyPickingUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(PickingOperationRepositoryInterface::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-005: carga de unidades (ítem CARGADA + unidad CARGADO + ledger)
        $container->singleton(
            LoadShipmentUseCase::class,
            fn($c) => new LoadShipmentUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ShipmentRepositoryInterface::class),
                $c->get(ShipmentItemRepositoryInterface::class),
                $c->get(PickingOperationRepositoryInterface::class),
                $c->get(ReservationRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-006: autorización de salida (unidad EN_TRANSITO + envío EN_RUTA)
        $container->singleton(
            AuthorizeShipmentUseCase::class,
            fn($c) => new AuthorizeShipmentUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ShipmentRepositoryInterface::class),
                $c->get(ShipmentItemRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // ENTREGAS Y DEVOLUCIONES (Sprint 8, v1.8.0)
        // ============================================================

        // CU-008: fallo de entrega (delivery RECHAZADA + unidad DEVUELTO +
        // incidencia ENTREGA_RECHAZADA + reubicación al origen)
        $container->singleton(
            RegisterDeliveryFailureUseCase::class,
            fn($c) => new RegisterDeliveryFailureUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ShipmentRepositoryInterface::class),
                $c->get(ShipmentItemRepositoryInterface::class),
                $c->get(DeliveryRepositoryInterface::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-007: confirmación de entrega (entrega total o mixta; en la mixta
        // aplica CU-008 a las unidades faltantes en la misma transacción)
        $container->singleton(
            ConfirmDeliveryUseCase::class,
            fn($c) => new ConfirmDeliveryUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(ShipmentRepositoryInterface::class),
                $c->get(ShipmentItemRepositoryInterface::class),
                $c->get(DeliveryRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(RegisterDeliveryFailureUseCase::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-009: devolución de unidad (DISPONIBLE / DAÑADO / PERDIDO)
        $container->singleton(
            RegisterReturnUseCase::class,
            fn($c) => new RegisterReturnUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(DeliveryRepositoryInterface::class),
                $c->get(ReturnOperationRepositoryInterface::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(ShipmentRepositoryInterface::class),
                $c->get(ShipmentItemRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // Consultas paginadas de entregas y devoluciones (GET /deliveries,
        // GET /returns): lectura directa por PDO, tenant_id en los claims.
        $container->singleton(
            DeliveryQueryService::class,
            fn($c) => new DeliveryQueryService($c->get('database')->getConnection())
        );

        $container->singleton(
            ReturnQueryService::class,
            fn($c) => new ReturnQueryService($c->get('database')->getConnection())
        );

        // ============================================================
        // INCIDENCIAS Y CONTEOS FÍSICOS (Sprint 9, v1.9.0)
        // ============================================================

        // Presencia física en una ubicación (criterio de
        // bin/recalculate-capacity.php): instantánea de la lista teórica y
        // cálculo de cupo usado. Sólo PDO; tenant_id llega explícito.
        $container->singleton(
            InventoryPresenceService::class,
            fn($c) => new InventoryPresenceService($c->get('database')->getConnection())
        );

        // CU-010: consultas de incidencias (listado con filtros + detalle)
        $container->singleton(
            IncidentQueryService::class,
            fn($c) => new IncidentQueryService($c->get('database')->getConnection())
        );

        // CU-011: consultas de conteos (listado, detalle y hoja / CSV)
        $container->singleton(
            CountQueryService::class,
            fn($c) => new CountQueryService($c->get('database')->getConnection())
        );

        // CU-010: reportar incidencia (efecto DAÑO/PERDIDA/DISCREPANCIA +
        // evento con hash + incidencia; idempotencia obligatoria)
        $container->singleton(
            ReportIncidentUseCase::class,
            fn($c) => new ReportIncidentUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(InventoryCountRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(InventoryPresenceService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-010: ABIERTA → INVESTIGACION (idempotencia por estado)
        $container->singleton(
            InvestigateIncidentUseCase::class,
            fn($c) => new InvestigateIncidentUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-010: ABIERTA/INVESTIGACION → RESUELTA o NO_RESUELTO (con
        // descripción, acciones y compensación). v1.10.0 (E4): con `resultado`
        // ENCONTRADA / NO_ENCONTRADA sí toca la unidad y recalcula el cupo.
        $container->singleton(
            ResolveIncidentUseCase::class,
            fn($c) => new ResolveIncidentUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(StateTransitionService::class),
                $c->get(InventoryPresenceService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-011: inicio de conteo (instantánea de la lista teórica)
        $container->singleton(
            StartCountUseCase::class,
            fn($c) => new StartCountUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(InventoryCountRepositoryInterface::class),
                $c->get(InventoryPresenceService::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-011: resultados parciales por unidad (fusión, fuera de lista 422)
        $container->singleton(
            RecordCountResultsUseCase::class,
            fn($c) => new RecordCountResultsUseCase(
                $c->get('database'),
                $c->get(InventoryCountRepositoryInterface::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-011: completar conteo (faltan resultados 422; discrepancias en JSON)
        $container->singleton(
            CompleteCountUseCase::class,
            fn($c) => new CompleteCountUseCase(
                $c->get('database'),
                $c->get(InventoryCountRepositoryInterface::class),
                $c->get(TenantContext::class)
            )
        );

        // CU-011: reconciliar discrepancias (AJUSTE_CANTIDAD + ledger +
        // capacidad; cantidad 0 → PERDIDO e INVENTARIO_DESAPARECIDO)
        $container->singleton(
            ReconcileCountUseCase::class,
            fn($c) => new ReconcileCountUseCase(
                $c->get('database'),
                $c->get(IdempotencyService::class),
                $c->get(InventoryCountRepositoryInterface::class),
                $c->get(StockUnitRepositoryInterface::class),
                $c->get(LocationRepositoryInterface::class),
                $c->get(StockLedgerRepositoryInterface::class),
                $c->get(StockEventRepositoryInterface::class),
                $c->get(IncidentRepositoryInterface::class),
                $c->get(StockCalculationService::class),
                $c->get(InventoryPresenceService::class),
                $c->get(StateTransitionService::class),
                $c->get(TenantContext::class)
            )
        );

        // ============================================================
        // v1.10.0 (R3 / E4): historial de unidad y listado de movimientos
        // ============================================================

        // GET /api/inventory/movements: consulta paginada de `movements`
        // (mismo patrón PDO que IncidentQueryService; tenant por claims)
        $container->singleton(
            MovementQueryService::class,
            fn($c) => new MovementQueryService($c->get('database')->getConnection())
        );

        // GET /api/inventory/stock/{id}/history: fusión de stock_events y
        // movements en una sola línea de tiempo (tenant por claims)
        $container->singleton(
            StockHistoryQueryService::class,
            fn($c) => new StockHistoryQueryService($c->get('database')->getConnection())
        );
    }
}
