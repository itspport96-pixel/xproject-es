<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\DashboardService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Contrato de las dos capas del dashboard (spec §9) sobre una BD en memoria:
 *
 *  - CAPA 1 (critical): tenant + contadores + stock resumido (LIMIT 20)
 *  - CAPA 2 (secondary): ubicaciones disponibles, reservas, tránsito, incidencias
 *
 * El eje del test es el Aislamiento de tenant (spec R4): los números de T1
 * no cambian aunque T2 tenga datos, y viceversa.
 */
final class DashboardServiceTest extends TestCase
{
    private const T1 = 'tenant-1';
    private const T2 = 'tenant-2';

    private PDO $pdo;
    private DashboardService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seed();

        $this->service = new DashboardService($this->pdo);
    }

    // ====================================================================
    // CAPA 1 - CRITICAL
    // ====================================================================

    public function testCriticalReturnsTenantInfoAndCounts(): void
    {
        $result = $this->service->critical(self::T1);

        $this->assertSame(self::T1, $result['tenant']['id']);
        $this->assertSame('Acme Corp', $result['tenant']['nombre']);

        $counts = $result['counts'];
        $this->assertSame(3, $counts['productos_activos']);
        $this->assertSame(1, $counts['productos_sin_stock']);
        $this->assertSame(3, $counts['ubicaciones_activas']);
        $this->assertSame(1, $counts['ubicaciones_criticas']);
        $this->assertSame(4, $counts['unidades_total']);
        $this->assertSame(2, $counts['unidades_disponibles']);
        $this->assertSame(1, $counts['unidades_reservadas']);
        $this->assertSame(1, $counts['unidades_en_transito']);
        $this->assertEqualsCanonicalizing(
            ['DISPONIBLE', 'RESERVADO', 'EN_TRANSITO'],
            array_keys($counts['por_estado'])
        );
    }

    public function testCriticalStockSummaryIsOrderedAndScopedToTenant(): void
    {
        $result = $this->service->critical(self::T1);

        // Sin unidades primero (accionable), luego por SKU.
        $this->assertSame(['A-SIN', 'B-UNA', 'C-DOS'], array_column($result['stock'], 'sku'));

        foreach ($result['stock'] as $row) {
            $this->assertNotSame('X-OTHER', $row['sku'], 'Se filtró un producto de otro tenant');
        }

        $this->assertSame(0, $result['stock'][0]['unidades']);
        $this->assertSame(2, $result['stock'][1]['unidades']);
        $this->assertSame(2.0, $result['stock'][1]['cantidad']);
    }

    public function testCriticalExcludesInactiveAndDeletedProducts(): void
    {
        $result = $this->service->critical(self::T1);
        $skus = array_column($result['stock'], 'sku');

        $this->assertNotContains('D-OFF', $skus);
        $this->assertNotContains('E-DEL', $skus);
        $this->assertSame(3, $result['counts']['productos_activos']);
    }

    public function testCriticalStockSummaryIsLimitedTo20Rows(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO products (id, tenant_id, sku, nombre, activo) VALUES (:id, :tenant, :sku, :nombre, 1)'
        );
        for ($i = 1; $i <= 25; $i++) {
            $insert->execute([
                'id' => "bulk-$i",
                'tenant' => self::T1,
                'sku' => sprintf('BULK-%02d', $i),
                'nombre' => "Producto bulk $i",
            ]);
        }

        $result = $this->service->critical(self::T1);

        $this->assertCount(DashboardService::STOCK_LIMIT, $result['stock']);
        $this->assertSame(28, $result['counts']['productos_activos']);
    }

    public function testCriticalIsIsolatedFromOtherTenant(): void
    {
        $t1 = $this->service->critical(self::T1);
        $t2 = $this->service->critical(self::T2);

        $this->assertSame(3, $t1['counts']['productos_activos']);
        $this->assertSame(1, $t2['counts']['productos_activos']);
        // px (tenant-2) sí tiene unidades: el conteo no arrastra el sin-stock de T1.
        $this->assertSame(0, $t2['counts']['productos_sin_stock']);
        $this->assertSame(1, $t2['counts']['ubicaciones_criticas']);
        // unidades_total cuenta filas de stock_units (igual que /tenant/stats),
        // no la suma de cantidades: T2 tiene 1 unidad con cantidad 5.
        $this->assertSame(1, $t2['counts']['unidades_total']);
        $this->assertSame('Globex', $t2['tenant']['nombre']);

        $this->assertSame(['X-OTHER'], array_column($t2['stock'], 'sku'));
    }

    public function testCriticalForUnknownTenantReturnsEmptyPayload(): void
    {
        $result = $this->service->critical('tenant-that-does-not-exist');

        $this->assertNull($result['tenant']);
        $this->assertSame([], $result['stock']);
        $this->assertSame(0, $result['counts']['productos_activos']);
        $this->assertSame(0, $result['counts']['unidades_total']);
    }

    public function testCriticalGeneratedAtIsValidIso8601(): void
    {
        $result = $this->service->critical(self::T1);

        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $result['generated_at']));
    }

    // ====================================================================
    // CAPA 2 - SECONDARY
    // ====================================================================

    public function testSecondaryReturnsLayerTwoCounts(): void
    {
        $result = $this->service->secondary(self::T1);

        $this->assertSame(
            [
                'ubicaciones_disponibles' => 2,
                'reservas_activas' => 1,
                'transporte_transito' => 1,
                'incidencias_abiertas' => 2,
            ],
            $result['counts']
        );
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $result['generated_at']));
    }

    public function testSecondaryIsIsolatedFromOtherTenant(): void
    {
        $result = $this->service->secondary(self::T2);

        // T2 sólo tiene su ubicación llena: nada del inventario de T1.
        $this->assertSame(0, $result['counts']['ubicaciones_disponibles']);
        $this->assertSame(1, $result['counts']['reservas_activas']);
        $this->assertSame(0, $result['counts']['transporte_transito']);
        $this->assertSame(1, $result['counts']['incidencias_abiertas']);
    }

    public function testFullLocationsAreNotCountedAsAvailable(): void
    {
        $available = $this->service->secondary(self::T1)['counts']['ubicaciones_disponibles'];

        // l1 está llena (10/10) y l4 está inactiva: no cuentan.
        $this->assertSame(2, $available);
    }

    // ====================================================================
    // FIXTURE
    // ====================================================================

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE tenants (
            id TEXT PRIMARY KEY,
            nombre_publico TEXT,
            nombre_interno TEXT,
            deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE products (
            id TEXT PRIMARY KEY,
            tenant_id TEXT NOT NULL,
            sku TEXT,
            nombre TEXT,
            activo INTEGER DEFAULT 1,
            deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE locations (
            id TEXT PRIMARY KEY,
            tenant_id TEXT NOT NULL,
            capacidad INTEGER,
            capacidad_usada INTEGER,
            activa INTEGER DEFAULT 1,
            deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE stock_units (
            id TEXT PRIMARY KEY,
            tenant_id TEXT NOT NULL,
            producto_id TEXT,
            cantidad NUMERIC,
            estado TEXT
        )');
        $this->pdo->exec('CREATE TABLE reservations (
            id TEXT PRIMARY KEY,
            tenant_id TEXT NOT NULL,
            estado TEXT
        )');
        $this->pdo->exec('CREATE TABLE incidents (
            id TEXT PRIMARY KEY,
            tenant_id TEXT NOT NULL,
            estado TEXT
        )');
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, nombre_publico, nombre_interno) VALUES
            ('tenant-1', 'Acme Corp', 'acme'),
            ('tenant-2', 'Globex', 'globex')");

        $this->pdo->exec("INSERT INTO products (id, tenant_id, sku, nombre, activo, deleted_at) VALUES
            ('p1', 'tenant-1', 'A-SIN',  'Sin stock',     1, NULL),
            ('p2', 'tenant-1', 'B-UNA',  'Con una',       1, NULL),
            ('p3', 'tenant-1', 'C-DOS',  'Con dos',       1, NULL),
            ('p4', 'tenant-1', 'D-OFF',  'Inactivo',      0, NULL),
            ('p5', 'tenant-1', 'E-DEL',  'Borrado',       1, '2026-01-01 00:00:00'),
            ('px', 'tenant-2', 'X-OTHER','De otro tenant',1, NULL)");

        $this->pdo->exec("INSERT INTO stock_units (id, tenant_id, producto_id, cantidad, estado) VALUES
            ('s1', 'tenant-1', 'p2', 1, 'DISPONIBLE'),
            ('s2', 'tenant-1', 'p3', 1, 'RESERVADO'),
            ('s3', 'tenant-1', 'p3', 1, 'EN_TRANSITO'),
            ('s4', 'tenant-1', 'p2', 1, 'DISPONIBLE'),
            ('s9', 'tenant-2', 'px', 5, 'DISPONIBLE')");

        $this->pdo->exec("INSERT INTO locations (id, tenant_id, capacidad, capacidad_usada, activa, deleted_at) VALUES
            ('l1', 'tenant-1', 10, 10, 1, NULL),
            ('l2', 'tenant-1',  5,  0, 1, NULL),
            ('l3', 'tenant-1',  0,  0, 1, NULL),
            ('l4', 'tenant-1',  5,  0, 0, NULL),
            ('lx', 'tenant-2',  1,  1, 1, NULL)");

        $this->pdo->exec("INSERT INTO reservations (id, tenant_id, estado) VALUES
            ('r1', 'tenant-1', 'ACTIVA'),
            ('r2', 'tenant-1', 'CANCELADA'),
            ('r9', 'tenant-2', 'ACTIVA')");

        $this->pdo->exec("INSERT INTO incidents (id, tenant_id, estado) VALUES
            ('i1', 'tenant-1', 'ABIERTA'),
            ('i2', 'tenant-1', 'INVESTIGACION'),
            ('i3', 'tenant-1', 'RESUELTA'),
            ('i9', 'tenant-2', 'ABIERTA')");
    }
}
