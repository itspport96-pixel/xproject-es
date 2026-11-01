<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\DashboardService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MySqlSession;

/**
 * Métrica "productos disponibles" del dashboard (producto con alguna unidad
 * cuyo stock_ledger.cantidad_disponible > 0) contra MySQL REAL (xproject_e2e,
 * regla 16 y 19: SQLite queda prohibido como mecanismo de verificación; la
 * subquery hace JOIN products x stock_units x stock_ledger, tablas que no
 * existen todas en el fixture SQLite, así que su cobertura vive aquí).
 *
 * Fixture (aislada por tenant, se borra en tearDown):
 *  - P1 DISPONIBLE  (física 5, disponible 5)  -> CUENTA
 *  - P2 RESERVADO   (física 3, disponible 0)  -> NO CUENTA
 *  - P3 EN_TRANSITO (física 2, disponible 0)  -> NO CUENTA
 *  - P4 sin unidades                          -> NO CUENTA
 */
final class DashboardServiceLiveTest extends TestCase
{
    private PDO $pdo;
    private DashboardService $service;
    private string $tenantId;
    /** @var array<int, string> SKUs únicos del fixture (borde del índice único) */
    private string $suffix;

    protected function setUp(): void
    {
        $this->pdo = $this->connectE2e();
        $this->service = new DashboardService($this->pdo);
        $this->suffix = substr(bin2hex(random_bytes(8)), 0, 12);
        $this->tenantId = $this->uuid();

        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        // Orden inverso a las FK: ledger -> units -> products -> tenant.
        $stmt = $this->pdo->prepare('DELETE FROM stock_ledger WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);

        $stmt = $this->pdo->prepare('DELETE FROM stock_units WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);

        $stmt = $this->pdo->prepare('DELETE FROM products WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);

        $stmt = $this->pdo->prepare('DELETE FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId]);
    }

    public function testAvailableProductsCountsOnlyUnitsWithPositiveDisponible(): void
    {
        $counts = $this->service->critical($this->tenantId)['counts'];

        $this->assertSame(4, $counts['productos_activos']);
        // Sólo P1 tiene cantidad_disponible > 0; RESERVADO, EN_TRANSITO y el
        // producto sin unidades quedan fuera (ya informados en sus filas).
        $this->assertSame(1, $counts['productos_con_disponible']);
    }

    // ------------------------------------------------------------------
    // FIXTURE
    // ------------------------------------------------------------------

    private function seedFixture(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $this->tenantId,
            'Dashboard Live ' . $this->suffix,
            'dashboard-live-' . $this->suffix,
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO products (id, tenant_id, sku, nombre, activo) VALUES (?, ?, ?, ?, 1)'
        );
        $productos = [
            'p1' => ['DL-P1-' . $this->suffix, 'Con disponible'],
            'p2' => ['DL-P2-' . $this->suffix, 'Solo reservado'],
            'p3' => ['DL-P3-' . $this->suffix, 'Solo transito'],
            'p4' => ['DL-P4-' . $this->suffix, 'Sin unidades'],
        ];
        foreach ($productos as $key => [$sku, $nombre]) {
            $stmt->execute([$this->uid($key), $this->tenantId, $sku, $nombre]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_units (id, tenant_id, producto_id, codigo_barcode, cantidad, estado)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $unidades = [
            ['u1', 'p1', 5, 'DISPONIBLE'],
            ['u2', 'p2', 3, 'RESERVADO'],
            ['u3', 'p3', 2, 'EN_TRANSITO'],
        ];
        foreach ($unidades as [$key, $prod, $cantidad, $estado]) {
            $stmt->execute([
                $this->uid($key),
                $this->tenantId,
                $this->uid($prod),
                'DL-' . strtoupper($key) . '-' . $this->suffix,
                $cantidad,
                $estado,
            ]);
        }

        // Ledger coherente con el estado de cada unidad (v1.8.3: cantidad_disponible
        // sólo es > 0 en DISPONIBLE).
        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_ledger (id, tenant_id, unidad_fisica_id, cantidad_fisica, cantidad_disponible)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ledger = [
            ['g1', 'u1', 5, 5],
            ['g2', 'u2', 3, 0],
            ['g3', 'u3', 2, 0],
        ];
        foreach ($ledger as [$key, $unidad, $fisica, $disponible]) {
            $stmt->execute([
                $this->uid($key),
                $this->tenantId,
                $this->uid($unidad),
                $fisica,
                $disponible,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // UTILIDADES
    // ------------------------------------------------------------------

    private function uid(string $key): string
    {
        return md5($this->tenantId . '|' . $key);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function connectE2e(): PDO
    {
        $base = dirname(__DIR__, 2);
        (Dotenv::createImmutable($base))->safeLoad();

        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? '');

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname=xproject_e2e;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL xproject_e2e no disponible: ' . $e->getMessage());
        }

        MySqlSession::applyTimeZone($pdo);

        return $pdo;
    }
}
