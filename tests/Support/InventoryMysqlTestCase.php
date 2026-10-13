<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\LocationRepository;
use App\Modules\Inventory\Adapters\Persistence\ProductRepository;
use App\Modules\Inventory\Adapters\Persistence\StockEventRepository;
use App\Modules\Inventory\Adapters\Persistence\StockLedgerRepository;
use App\Modules\Inventory\Adapters\Persistence\StockUnitRepository;
use App\Modules\Inventory\Application\Services\BarcodeService;
use App\Modules\Inventory\Application\Services\ReceiveAdhocUseCase;
use App\Modules\Inventory\Application\Services\ConfirmAdhocUseCase;
use App\Modules\Inventory\Application\Services\RejectAdhocUseCase;
use App\Modules\Inventory\Application\Services\ReceiveDocumentUseCase;
use App\Modules\Inventory\Application\Services\InventoryPresenceService;
use App\Modules\Inventory\Application\Services\ResolveIncidentUseCase;
use App\Modules\Inventory\Application\Services\StateTransitionService;
use App\Modules\Inventory\Application\Services\StockCalculationService;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\IdempotencyService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base de tests de recepci&#243;n contra MySQL REAL (BLOQUE R2, v1.13.29).
 *
 * Motivo: los tests sobre SQLite en memoria pueden dar falsos verdes frente a
 * MySQL real (ENUMs, sql_mode estricto, decimal(15,4), FKs, CHECK json_valid).
 * Esta base conecta a una BD MySQL dedicada (xproject_test por defecto) que se
 * recrea desde database/schema/schema.sql con bin/prepare-test-db.php, y que
 * NO contiene ning&#250;n dato de la BD de trabajo.
 *
 * Aislamiento por test:
 *  - Cada test crea su propio tenant (+ owner) dentro del m&#233;todo, con IDs
 *    &#250;nicos.
 *  - tearDown() borra TODO lo que el test toc&#243; (tablas de negocio por tenant,
 *    luego users, tenants y las filas de productos/ubicaciones creadas por el
 *    test) y fija FOREIGN_KEY_CHECKS=0 durante el borrado.
 *  - NO se usa una transacci&#243;n externa con rollback: los use cases abren su
 *    propia transacci&#243;n (DatabaseService::transaction) y una anidada fallar&#237;a
 *    en MySQL. El aislamiento es por limpieza expl&#237;cita + BD dedicada.
 *
 * Semilla por defecto (creada en setUp, dentro del tenant del test):
 *  - Producto SKU-ALFA (activo).
 *  - Ubicaci&#243;n LOC-01 (ALMACENAMIENTO, sin capacidad).
 */
abstract class InventoryMysqlTestCase extends TestCase
{
    protected DatabaseService $db;
    protected TenantContext $tenantContext;

    protected ProductRepository $products;
    protected LocationRepository $locations;
    protected StockUnitRepository $stockUnits;
    protected StockLedgerRepository $ledgers;
    protected StockEventRepository $events;
    protected IncidentRepository $incidents;

    // Expuesto para tests que necesiten ejecutar SQL de control o forzar el
    // cleanup a mano (p. ej. InventoryMysqlSmokeTest).
    protected ?PDO $control;

    protected BarcodeService $barcodeService;
    protected StockCalculationService $calculation;
    protected StateTransitionService $states;

    protected ReceiveDocumentUseCase $receiveDocumentUseCase;
    protected ReceiveAdhocUseCase $receiveAdhocUseCase;
    protected ConfirmAdhocUseCase $confirmAdhocUseCase;
    protected RejectAdhocUseCase $rejectAdhocUseCase;
    protected ResolveIncidentUseCase $resolveIncidentUseCase;

    protected string $tenantId;
    protected string $ownerUserId;

    // ====================================================================
    // CICLO DE VIDA
    // ====================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $config = self::config();

        $this->control = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->control->exec("SET FOREIGN_KEY_CHECKS = 0");

        $this->db = new DatabaseService($config);

        $this->tenantContext = new TenantContext(
            $this->createStub(TenantRepositoryInterface::class)
        );

        $this->products = new ProductRepository($this->db, $this->tenantContext);
        $this->locations = new LocationRepository($this->db, $this->tenantContext);
        $this->stockUnits = new StockUnitRepository($this->db, $this->tenantContext);
        $this->ledgers = new StockLedgerRepository($this->db, $this->tenantContext);
        $this->events = new StockEventRepository($this->db, $this->tenantContext);
        $this->incidents = new IncidentRepository($this->db, $this->tenantContext);

        $this->barcodeService = new BarcodeService();
        $this->calculation = new StockCalculationService();
        $this->states = new StateTransitionService();

        $this->receiveAdhocUseCase = new ReceiveAdhocUseCase(
            $this->db,
            new IdempotencyService($this->control),
            new ProductRepository($this->db, $this->tenantContext),
            $this->locations,
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->barcodeService,
            $this->tenantContext
        );

        $this->confirmAdhocUseCase = new ConfirmAdhocUseCase(
            $this->db,
            new IdempotencyService($this->control),
            $this->locations,
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->states,
            $this->tenantContext
        );

        $this->rejectAdhocUseCase = new RejectAdhocUseCase(
            $this->db,
            new IdempotencyService($this->control),
            $this->incidents,
            $this->events,
            $this->tenantContext
        );

        $this->receiveDocumentUseCase = new ReceiveDocumentUseCase(
            $this->db,
            new IdempotencyService($this->control),
            $this->locations,
            $this->stockUnits,
            $this->ledgers,
            $this->events,
            $this->incidents,
            $this->calculation,
            $this->barcodeService,
            $this->tenantContext
        );

        $this->seedTenant();
        $this->seedProduct($this->tenantId, 'SKU-ALFA');
        $this->seedLocation($this->tenantId, 'LOC-01');

        $this->resolveIncidentUseCase = new ResolveIncidentUseCase(
            $this->db,
            new IdempotencyService($this->control),
            $this->incidents,
            $this->stockUnits,
            $this->locations,
            $this->ledgers,
            $this->events,
            $this->calculation,
            $this->states,
            new InventoryPresenceService(
                $this->control
            ),
            $this->tenantContext
        );
    }

    protected function tearDown(): void
    {
        if ($this->control instanceof PDO) {
            $this->forceCleanup();
            $this->control->exec("SET FOREIGN_KEY_CHECKS = 1");
        }

        $this->control = null;

        parent::tearDown();
    }

    /**
     * Limpia todas las filas del tenant actual y lo elimina, pero deja la
     * conexi&#243;n $control viva para que un test pueda seguir consultando despu&#233;s
     * (p. ej. comprobar que no quedan rastros). Ll&#225;manlo a mano si necesitas
     * verificar el aislamiento antes del tearDown autom&#225;tico.
     */
    protected function forceCleanup(): void
    {
        $this->cleanup();
    }

    // ====================================================================
    // CONEXI&#211;N / CONFIG
    // ====================================================================

    private static function config(): array
    {
        $baseDir = dirname(__DIR__, 2);
        Dotenv::createImmutable($baseDir)->safeLoad();

        $database = getenv('TEST_DB_NAME') ?: 'xproject_test';

        return [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => $database,
            'charset' => 'utf8mb4',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];
    }

    // ====================================================================
    // SEMILLA (tenant + producto + ubicaci&#243;n por test)
    // ====================================================================

    private function seedTenant(): void
    {
        $this->tenantId = self::uuid('tenant');
        $this->ownerUserId = self::uuid('user');
        $now = date('Y-m-d H:i:s');

        // El usuario se crea PRIMERO: la columna generada STORED de users no
        // admite INSERT (por eso no se copia en la BD de test), y el tenant lo
        // referencia v&#237;a owner_user_id. Cada placeholder es &#250;nico porque
        // EMULATE_PREPARES=false no permite repetir el mismo nombre.
        $this->control->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, password, estado, created_at, updated_at)
             VALUES (:id, :tenant, :email, :nombre, :pass, :estado, :created, :updated)'
        )->execute([
            'id' => $this->ownerUserId,
            'tenant' => $this->tenantId,
            'email' => 'owner-' . $this->tenantId . '@test.local',
            'nombre' => 'Owner',
            'pass' => password_hash('x', PASSWORD_BCRYPT),
            'estado' => 'ACTIVO',
            'created' => $now,
            'updated' => $now,
        ]);

        $this->control->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado, plan, owner_user_id, created_at, updated_at)
             VALUES (:id, :np, :ni, :estado, :plan, :owner, :created, :updated)'
        )->execute([
            'id' => $this->tenantId,
            'np' => 'Tenant ' . $this->tenantId,
            'ni' => 'tenant-' . $this->tenantId,
            'estado' => 'ACTIVO',
            'plan' => 'FREE',
            'owner' => $this->ownerUserId,
            'created' => $now,
            'updated' => $now,
        ]);

        $this->tenantContext->setTenantId($this->tenantId);
    }

    protected function seedUser(string $tenantId, ?string $id = null): string
    {
        $id ??= self::uuid('user');

        $this->control->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, password, estado, created_at, updated_at)
             VALUES (:id, :tenant, :email, :nombre, :pass, :estado, :created, :updated)'
        )->execute([
            'id' => $id,
            'tenant' => $tenantId,
            'email' => $id . '@test.local',
            'nombre' => 'Usuario ' . $id,
            'pass' => password_hash('x', PASSWORD_BCRYPT),
            'estado' => 'ACTIVO',
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function seedProduct(string $tenantId, string $sku, bool $activo = true, ?string $id = null): string
    {
        $id ??= self::uuid('prod');

        $this->control->prepare(
            'INSERT INTO products (id, tenant_id, sku, nombre, unidad_medida, activo, created_at, updated_at)
             VALUES (:id, :tenant, :sku, :nombre, :um, :activo, :created, :updated)'
        )->execute([
            'id' => $id,
            'tenant' => $tenantId,
            'sku' => $sku,
            'nombre' => 'Producto ' . $sku,
            'um' => 'UNIDAD',
            'activo' => $activo ? 1 : 0,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
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
        $id ??= self::uuid('loc');

        $this->control->prepare(
            'INSERT INTO locations (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada, activa, created_at, updated_at)
             VALUES (:id, :tenant, :codigo, :nombre, :tipo, :cap, :capu, :activa, :created, :updated)'
        )->execute([
            'id' => $id,
            'tenant' => $tenantId,
            'codigo' => $codigo,
            'nombre' => 'Ubicacion ' . $codigo,
            'tipo' => $tipo,
            'cap' => $capacidad,
            'capu' => $capacidadUsada,
            'activa' => $activa ? 1 : 0,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * Crea un documento de recepci&#243;n PLANIFICADA y sus l&#237;neas por SQL
     * directo (BLOQUE R2).
     *
     * Motivo: ReceivingDocumentService::create exige assertAnotherReceiverExists
     * (permisos v&#237;a AuthorizationService), que a&#241;ade acoplamiento innecesario
     * para los tests de ReceiveDocumentUseCase. Este helper inserta el documento
     * y sus l&#237;neas replicando las columnas exactas de insertLines() del servicio.
     *
     * @param array<int, array{sku: string, cantidad_esperada: float}> $lineas
     * @return array{id: string, creado_por: string, numero_documento: string}
     */
    protected function createReceivingDocument(
        string $creadoPor,
        string $numero = 'PO-TEST-001',
        array $lineas = [],
        string $estado = 'PLANIFICADA'
    ): array {
        $lineas = $lineas === []
            ? [['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10.0]]
            : $lineas;

        $docId = self::uuid('rdoc');
        $now = date('Y-m-d H:i:s');

        $this->control->prepare(
            'INSERT INTO receiving_documents
                (id, tenant_id, numero_documento, proveedor, referencia, estado, creado_por, created_at, updated_at)
             VALUES
                (:id, :tenant, :numero, NULL, NULL, :estado, :creado_por, :created, :updated)'
        )->execute([
            'id' => $docId,
            'tenant' => $this->tenantId,
            'numero' => $numero,
            'estado' => $estado,
            'creado_por' => $creadoPor,
            'created' => $now,
            'updated' => $now,
        ]);

        foreach ($lineas as $linea) {
            $this->control->prepare(
                'INSERT INTO receiving_document_lines
                    (id, document_id, producto_id, sku, cantidad_esperada, estado, unidad_recepcion_id, created_at, updated_at)
                 VALUES
                    (:id, :doc, :producto, :sku, :esperada, :estado, NULL, :created, :updated)'
            )->execute([
                'id' => self::uuid('rline'),
                'doc' => $docId,
                'producto' => $this->scalar(
                    'SELECT id FROM products WHERE tenant_id = ? AND sku = ?',
                    [$this->tenantId, $linea['sku']]
                ),
                'sku' => $linea['sku'],
                'esperada' => $linea['cantidad_esperada'],
                'estado' => 'PENDIENTE',
                'created' => $now,
                'updated' => $now,
            ]);
        }

        return [
            'id' => $docId,
            'creado_por' => $creadoPor,
            'numero_documento' => $numero,
        ];
    }

    /**
     * Crea una recepci&#243;n adhoc PENDIENTE y sus l&#237;neas por SQL directo
     * (BLOQUE R3), sin pasar por ReceiveAdhocUseCase (&#250;til para tests que
     * necesitan un adhoc ya existente en un estado concreto).
     *
     * @param array<int, array{sku: string, cantidad: float, unidad_id: string|null}> $lineas
     * @return array{id: string, creado_por: string, referencia: string}
     */
    protected function createReceivingAdhoc(
        string $creadoPor,
        string $referencia = 'ADHOC-REF-001',
        array $lineas = [],
        string $estado = 'PENDIENTE'
    ): array {
        $lineas = $lineas === []
            ? [['sku' => 'SKU-ALFA', 'cantidad' => 10.0, 'unidad_id' => null]]
            : $lineas;

        $adhocId = self::uuid('adhoc');
        $now = date('Y-m-d H:i:s');

        $this->control->prepare(
            'INSERT INTO receiving_adhoc
                (id, tenant_id, referencia_documento, estado, creado_por, creado_por_confirma, created_at, updated_at)
             VALUES
                (:id, :tenant, :ref, :estado, :creado_por, NULL, :created, :updated)'
        )->execute([
            'id' => $adhocId,
            'tenant' => $this->tenantId,
            'ref' => $referencia,
            'estado' => $estado,
            'creado_por' => $creadoPor,
            'created' => $now,
            'updated' => $now,
        ]);

        foreach ($lineas as $linea) {
            $this->control->prepare(
                'INSERT INTO receiving_adhoc_lines
                    (id, adhoc_id, producto_id, sku, cantidad, unidad_id, created_at, updated_at)
                 VALUES
                    (:id, :adhoc, :producto, :sku, :cantidad, :unidad_id, :created, :updated)'
            )->execute([
                'id' => self::uuid('aline'),
                'adhoc' => $adhocId,
                'producto' => $this->scalar(
                    'SELECT id FROM products WHERE tenant_id = ? AND sku = ?',
                    [$this->tenantId, $linea['sku']]
                ),
                'sku' => $linea['sku'],
                'cantidad' => $linea['cantidad'],
                'unidad_id' => $linea['unidad_id'],
                'created' => $now,
                'updated' => $now,
            ]);
        }

        return [
            'id' => $adhocId,
            'creado_por' => $creadoPor,
            'referencia' => $referencia,
        ];
    }

    // ====================================================================
    // AYUDAS DE CONSULTA / ASSERTION
    // ====================================================================

    protected function useTenant(string $tenantId): void
    {
        $this->tenantContext->setTenantId($tenantId);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->control->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->control->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    // ====================================================================
    // LIMPIEZA (aislamiento por test)
    // ====================================================================

    private function cleanup(): void
    {
        $t = $this->tenantId ?? null;
        if ($t === null || !($this->control instanceof PDO)) {
            return;
        }

        $this->control->exec("SET FOREIGN_KEY_CHECKS = 0");

        $tables = [
            'stock_events', 'stock_ledger', 'incidents', 'receiving_adhoc_lines',
            'receiving_adhoc', 'receiving_documents',
            'stock_units', 'movements', 'reservations', 'picking_operations',
            'shipment_items', 'shipments', 'deliveries', 'return_operations',
            'inventory_counts', 'idempotency_keys', 'audit_logs',
        ];

        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            // S&#243;lo filtra por tenant_id si la tabla realmente lo tiene: as&#237;
            // el cleanup no rompe con tablas cuyo esquema cambie (p. ej.
            // receiving_document_lines, que no tiene tenant_id y se borra por
            // la FK del documento m&#225;s abajo).
            if ($this->columnExists($table, 'tenant_id')) {
                $this->control->prepare("DELETE FROM `{$table}` WHERE tenant_id = ?")->execute([$t]);
            } else {
                $this->control->exec("DELETE FROM `{$table}`");
            }
        }

        // receiving_document_lines no tiene tenant_id: se borra por la FK del
        // documento.
        if ($this->tableExists('receiving_document_lines')) {
            $this->control->prepare(
                'DELETE l FROM receiving_document_lines l
                  JOIN receiving_documents d ON d.id = l.document_id
                 WHERE d.tenant_id = ?'
            )->execute([$t]);
        }

        foreach (['users', 'products', 'locations'] as $table) {
            $this->control->prepare("DELETE FROM `{$table}` WHERE tenant_id = ?")->execute([$t]);
        }

        $this->control->prepare('DELETE FROM tenants WHERE id = ?')->execute([$t]);

        $this->control->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->control->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->control->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ====================================================================
    // UUIDS DETERMINISTAS POR TEST
    // ====================================================================

    protected static function uuid(string $prefix): string
    {
        $hash = substr(sha1($prefix . uniqid((string) mt_rand(), true)), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }
}
