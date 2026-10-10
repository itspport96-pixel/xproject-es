<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditLogger;
use App\Core\Audit\AuditPort;
use App\Core\Audit\SecretRedactor;
use App\Shared\Container\Container;
use Tests\Support\AuditSqliteTestCase;

/**
 * AuditLogger: cadena de hash por tenant (spec §7.1), redacción de secretos,
 * append-only (sólo INSERT vía AuditPort) y degradación ante fallos.
 */
final class AuditLoggerTest extends AuditSqliteTestCase
{
    public function testRegistraFilasCompletasYSinTenantNoEscribe(): void
    {
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-1',
            'operacion' => 'product.create',
            'modulo' => 'inventory',
            'recurso' => 'products',
            'entidad_id' => 'prod-1',
            'tipo_cambio' => 'CREATE',
            'valores_anteriores' => null,
            'valores_nuevos' => ['sku' => 'SKU-1', 'nombre' => 'Producto'],
            'resultado' => 'SUCCESS',
            'ip_address' => '127.0.0.1',
            'request_id' => 'req-1',
            'duracion_operacion' => 12,
        ]);

        $this->assertSame(1, $this->rowCount());

        $row = $this->rows()[0];
        $this->assertSame('product.create', $row['operacion']);
        $this->assertSame('prod-1', $row['entidad_id']);
        $this->assertSame('req-1', $row['request_id']);
        $this->assertSame('SUCCESS', $row['resultado']);
        $this->assertSame('CREATE', $row['tipo_cambio']);
        $this->assertSame(12, (int) $row['duracion_operacion']);

        $valoresNuevos = json_decode((string) $row['valores_nuevos'], true);
        $this->assertSame('SKU-1', $valoresNuevos['sku']);

        // Sin tenant (y sin contexto): no hay fila posible (tenant_id NOT NULL + FK).
        Container::resetInstance();
        $sinContexto = new AuditLogger($this->port, null);
        $sinContexto->record(['operacion' => 'auth.login', 'usuario_id' => 'user-1']);

        $this->assertSame(1, $this->rowCount());
    }

    public function testEncadenaElHashPorTenant(): void
    {
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'product.create',
            'timestamp' => '2026-10-01 10:00:00.000001',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'product.update',
            'timestamp' => '2026-10-01 10:00:00.000002',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'product.delete',
            'timestamp' => '2026-10-01 10:00:00.000003',
        ]);

        $this->assertSame(3, $this->rowCount());
        $this->assertChainValid();

        $rows = $this->rows();

        $details0 = json_decode((string) $rows[0]['detalles_adicionales'], true);
        $details1 = json_decode((string) $rows[1]['detalles_adicionales'], true);
        $details2 = json_decode((string) $rows[2]['detalles_adicionales'], true);

        $this->assertNull($details0['prev_hash'], 'La primera fila no tiene anterior');
        $this->assertSame($rows[0]['hash_integridad'], $details1['prev_hash']);
        $this->assertSame($rows[1]['hash_integridad'], $details2['prev_hash']);
    }

    public function testCadaTenantTieneSuPropiaCadena(): void
    {
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 't1.first',
            'timestamp' => '2026-10-01 10:00:00.000001',
        ]);
        $this->logger->record([
            'tenant_id' => self::T2,
            'operacion' => 't2.first',
            'timestamp' => '2026-10-01 10:00:00.000002',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 't1.second',
            'timestamp' => '2026-10-01 10:00:00.000003',
        ]);
        $this->logger->record([
            'tenant_id' => self::T2,
            'operacion' => 't2.second',
            'timestamp' => '2026-10-01 10:00:00.000004',
        ]);

        $this->assertSame(2, $this->rowCount(self::T1));
        $this->assertSame(2, $this->rowCount(self::T2));

        $this->assertChainValid(self::T1);
        $this->assertChainValid(self::T2);

        // La segunda cadena de T2 arranca de null: no hereda el hash de T1.
        $detailsT2 = json_decode((string) $this->rows(self::T2)[0]['detalles_adicionales'], true);
        $this->assertNull($detailsT2['prev_hash']);
    }

    public function testRedactaSecretosEnValoresYDetalles(): void
    {
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'auth.login',
            'valores_nuevos' => [
                'email' => 'demo@acme.local',
                'password' => 'Admin123!',
                'token' => 'abc123',
                'nested' => ['api_key' => 'sk-live-1', 'nombre' => 'ok'],
            ],
            'detalles_adicionales' => [
                'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.aaa.bbb',
                'cabecera' => 'Basic dXNlcjpwYXNz',
                'sin_seguridad' => 'visible',
            ],
        ]);

        $row = $this->rows()[0];

        $this->assertStringNotContainsString('Admin123!', (string) $row['valores_nuevos']);
        $this->assertStringNotContainsString('sk-live-1', (string) $row['valores_nuevos']);
        $this->assertStringNotContainsString('abc123', (string) $row['valores_nuevos']);
        $this->assertStringContainsString(SecretRedactor::MASK, (string) $row['valores_nuevos']);
        $this->assertStringContainsString('demo@acme.local', (string) $row['valores_nuevos']);

        $detalles = (string) $row['detalles_adicionales'];
        $this->assertStringNotContainsString('Basic dXNlcjpwYXNz', $detalles);
        $this->assertStringContainsString(SecretRedactor::MASK, $detalles);
        $this->assertStringContainsString('visible', $detalles);
    }

    public function testElFalloDePersistenciaNoRompeLaPeticion(): void
    {
        $logger = new AuditLogger(new ExplodingAuditPort(), $this->tenantContext);

        $logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'product.create',
        ]);

        $this->assertSame(0, $this->rowCount(), 'Nada debe quedar escrito tras un fallo');
    }

    public function testElPuertoEsAppendOnly(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AuditPort::class))->getMethods()
        );

        sort($methods);
        $this->assertSame(['insert', 'previousHash', 'transaction'], $methods);
        $this->assertNotContains('update', $methods);
        $this->assertNotContains('delete', $methods);

        // Ni en el código del adaptador hay UPDATE/DELETE sobre audit_logs.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Audit/PdoAuditPort.php');
        $this->assertDoesNotMatchRegularExpression('/\bUPDATE\s+audit_logs\b/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\s+audit_logs\b/i', $source);
    }

    public function testTimestampsNaturalesIncluyenMicrosegundos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->logger->record([
                'tenant_id' => self::T1,
                'operacion' => 'natural.' . $i,
            ]);
        }

        $rows = $this->rows();
        $this->assertCount(5, $rows);

        $conMicros = 0;
        $prev = null;

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
                (string) $row['timestamp']
            );

            if (substr((string) $row['timestamp'], -6) !== '000000') {
                $conMicros++;
            }

            if ($prev !== null) {
                $this->assertGreaterThan(
                    $prev,
                    $row['timestamp'],
                    'los timestamps deben ser estrictamente crecientes para poder ordenar la cadena'
                );
            }

            $prev = $row['timestamp'];
        }

        // Regresión: en Windows date('u') devuelve 000000 y colapsa la
        // resolución de la columna a 1s (cadena de integridad inverificable).
        $this->assertGreaterThan(0, $conMicros, 'faltan microsegundos en timestamp');

        $this->assertChainValid();
    }

    public function testElHashEsDeterministaEInmutable(): void
    {
        $row = ['id' => 'x', 'tenant_id' => self::T1, 'operacion' => 'a', 'resultado' => 'SUCCESS'];

        $h1 = AuditLogger::computeHash(null, $row);
        $h2 = AuditLogger::computeHash(null, $row);
        $this->assertSame($h1, $h2);

        // El orden de las claves no altera el payload canónico.
        $this->assertSame($h1, AuditLogger::computeHash(null, array_reverse($row, true)));

        $this->assertNotSame($h1, AuditLogger::computeHash('prev', $row), 'El prev participa');
        $this->assertNotSame($h1, AuditLogger::computeHash(null, ['id' => 'y'] + $row), 'El payload participa');
    }
}

/**
 * Puerto que siempre falla: simula caída de BD.
 */
final class ExplodingAuditPort implements AuditPort
{
    public function transaction(string $tenantId, callable $callback): mixed
    {
        throw new \RuntimeException('bd caida');
    }

    public function previousHash(string $tenantId): ?string
    {
        throw new \RuntimeException('bd caida');
    }

    public function insert(array $row): void
    {
        throw new \RuntimeException('bd caida');
    }
}
