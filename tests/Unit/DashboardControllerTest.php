<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\DashboardService;
use App\Modules\Inventory\Http\DashboardController;
use App\Shared\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Controlador del dashboard: envoltorio de respuesta y guardia de claims.
 */
final class DashboardControllerTest extends TestCase
{
    private DashboardController $controller;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tenants (id TEXT PRIMARY KEY, nombre_publico TEXT, nombre_interno TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE products (id TEXT PRIMARY KEY, tenant_id TEXT, sku TEXT, nombre TEXT, activo INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, tenant_id TEXT, capacidad INTEGER, capacidad_usada INTEGER, activa INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE stock_units (id TEXT PRIMARY KEY, tenant_id TEXT, producto_id TEXT, cantidad NUMERIC, estado TEXT)');
        $pdo->exec('CREATE TABLE reservations (id TEXT PRIMARY KEY, tenant_id TEXT, estado TEXT)');
        $pdo->exec('CREATE TABLE incidents (id TEXT PRIMARY KEY, tenant_id TEXT, estado TEXT)');
        $pdo->exec("INSERT INTO tenants (id, nombre_publico) VALUES ('tenant-1', 'Acme Corp')");

        $this->controller = new DashboardController(new DashboardService($pdo));
    }

    public function testCriticalRejectsRequestWithoutClaims(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->critical([]);
    }

    public function testSecondaryRejectsRequestWithoutTenantClaim(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->secondary(['user' => ['user_id' => 'u-1']]);
    }

    public function testCriticalReturnsStatusAndDataEnvelope(): void
    {
        $result = $this->controller->critical([
            'user' => ['user_id' => 'u-1', 'tenant_id' => 'tenant-1'],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('tenant', $result['data']);
        $this->assertArrayHasKey('counts', $result['data']);
        $this->assertArrayHasKey('stock', $result['data']);
        $this->assertSame('Acme Corp', $result['data']['tenant']['nombre']);
    }

    public function testSecondaryReturnsStatusAndDataEnvelope(): void
    {
        $result = $this->controller->secondary([
            'user' => ['user_id' => 'u-1', 'tenant_id' => 'tenant-1'],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['ubicaciones_disponibles', 'reservas_activas', 'transporte_transito', 'incidencias_abiertas'],
            array_keys($result['data']['counts'])
        );
    }

    /**
     * El tenant sale de los claims del request, nunca de una fuente mutable:
     * el controller no acepta tenant_id por query string.
     */
    public function testControllerIgnoresQueryInputForTenant(): void
    {
        $result = $this->controller->critical([
            'user' => ['user_id' => 'u-1', 'tenant_id' => 'tenant-1'],
            'query' => ['tenant_id' => 'tenant-2'],
        ]);

        $this->assertSame('tenant-1', $result['data']['tenant']['id']);
    }
}
