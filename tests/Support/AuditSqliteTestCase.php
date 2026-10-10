<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Audit\AuditLogger;
use App\Core\Audit\PdoAuditPort;
use App\Core\Audit\Service\AuditLogQueryService;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Services\DatabaseService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base de tests del Sprint Auditoría (v1.5.3).
 *
 * Monta `audit_logs` en SQLite en memoria con el esquema REAL de
 * schema.sql (columnas nuevas incluidas: entidad_id, request_id,
 * idx_resultado) y usa el PdoAuditPort + AuditLogger REALES para que
 * las transacciones y la cadena de hash se comporten como en MySQL.
 */
abstract class AuditSqliteTestCase extends TestCase
{
    protected const T1 = 'cb22db62-5379-4b83-8c51-a3c695c6de51';
    protected const T2 = '4f1d7e0a-9b23-4c56-8d7e-1a2b3c4d5e6f';

    protected PDO $pdo;
    protected DatabaseService $db;
    protected TenantContext $tenantContext;
    protected PdoAuditPort $port;
    protected AuditLogger $logger;
    protected AuditLogQueryService $query;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec(
            'CREATE TABLE audit_logs (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                usuario_id TEXT,
                operacion TEXT,
                modulo TEXT,
                recurso TEXT,
                entidad_id TEXT,
                tipo_cambio TEXT DEFAULT \'EXECUTE\',
                valores_anteriores TEXT,
                valores_nuevos TEXT,
                resultado TEXT DEFAULT \'SUCCESS\',
                razon_fallo TEXT,
                ip_address TEXT,
                user_agent TEXT,
                request_id TEXT,
                timestamp TEXT NOT NULL,
                duracion_operacion INTEGER,
                detalles_adicionales TEXT,
                hash_integridad TEXT
            )'
        );
        $this->pdo->exec('CREATE INDEX idx_tenant_timestamp ON audit_logs (tenant_id, timestamp)');
        $this->pdo->exec('CREATE INDEX idx_resultado ON audit_logs (tenant_id, resultado)');

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->tenantContext = new TenantContext(
            $this->createStub(TenantRepositoryInterface::class)
        );
        $this->tenantContext->setTenantId(self::T1);

        $this->port = new PdoAuditPort($this->db);
        $this->logger = new AuditLogger($this->port, $this->tenantContext);
        $this->query = new AuditLogQueryService($this->db);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $tenantId = self::T1): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM audit_logs WHERE tenant_id = ? ORDER BY timestamp ASC, id ASC'
        );
        $stmt->execute([$tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function rowCount(string $tenantId = self::T1): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Verifica la cadena de integridad de un tenant:
     *  - details.prev_hash de cada fila = hash de la anterior (null en la primera)
     *  - SHA256(prev || payload_canónico) recalculado = hash almacenado
     *
     * @return string|null null si la cadena es válida, o la descripción del fallo
     */
    protected function verifyChain(string $tenantId = self::T1): ?string
    {
        $prev = null;

        foreach ($this->rows($tenantId) as $index => $row) {
            $details = json_decode((string) ($row['detalles_adicionales'] ?? ''), true);
            $storedPrev = is_array($details) ? ($details['prev_hash'] ?? null) : null;

            if ($storedPrev !== $prev) {
                return "Fila {$index}: prev_hash en detalles = " .
                    var_export($storedPrev, true) . ', esperado ' . var_export($prev, true);
            }

            $recomputed = AuditLogger::computeHash($prev, $row);
            if ($recomputed !== $row['hash_integridad']) {
                return "Fila {$index}: hash recalculado {$recomputed} != almacenado {$row['hash_integridad']}";
            }

            $prev = (string) $row['hash_integridad'];
        }

        return null;
    }

    protected function assertChainValid(string $tenantId = self::T1): void
    {
        $this->assertNull($this->verifyChain($tenantId), 'Cadena de integridad rota');
    }
}
