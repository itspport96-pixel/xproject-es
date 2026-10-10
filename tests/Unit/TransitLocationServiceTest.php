<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\TransitLocationService;
use InvalidArgumentException;
use Tests\Support\InventorySqliteTestCase;

/**
 * Semilla de las ubicaciones fijas por tenant: virtual TRANSITO (v1.7.1, A1)
 * y zona DAÑADOS (spec CU-009, v1.8.0).
 *
 * Sin esta ubicación, `POST /api/inventory/shipments/authorize` devolvía 404
 * `transitLocationMissing` para cualquier tenant que no la tuviera: el alta de
 * tenant ahora la crea y `bin/seed-transit-locations.php` la crea para los
 * ya existentes.
 *
 * Cubre: creación, idempotencia, reactivación, unicidad global del código,
 * rechazo de tenant vacío y la integración con `authorize` (en
 * AuthorizeShipmentUseCaseTest).
 */
final class TransitLocationServiceTest extends InventorySqliteTestCase
{
    private TransitLocationService $service;

    private const T3 = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransitLocationService($this->db);
    }

    private function countTransit(string $tenantId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM locations WHERE tenant_id = ? AND tipo = 'TRANSITO'",
            [$tenantId]
        );
    }

    private function countDanados(string $tenantId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM locations WHERE tenant_id = ? AND tipo = ?",
            [$tenantId, TransitLocationService::TIPO_DANADOS]
        );
    }

    public function testCreaLaUbicacionTransitoParaElTenantQueNoLaTiene(): void
    {
        $result = $this->service->ensureForTenant(self::T2);

        $this->assertTrue($result['creada']);
        $this->assertSame('TRANSITO', $result['tipo']);
        $this->assertNull($result['capacidad'], 'Las ubicaciones virtuales no tienen capacidad');
        $this->assertTrue($result['activa']);
        $this->assertSame(0, $result['capacidad_usada']);
        $this->assertSame(TransitLocationService::CODIGO_BASE, $result['codigo']);

        $row = $this->row('SELECT * FROM locations WHERE id = ?', [$result['ubicacion_id']]);
        $this->assertNotNull($row);
        $this->assertSame(self::T2, $row['tenant_id']);
        $this->assertSame('TRANSITO', $row['tipo']);
        $this->assertSame('Transito virtual', $row['nombre']);
        $this->assertSame(1, (int) $row['activa']);
        $this->assertNull($row['capacidad']);
        $this->assertSame(0, (int) $row['capacidad_usada']);
        $this->assertNull($row['deleted_at']);
    }

    public function testSegundaLlamadaEsIdempotenteYNoDuplicaLaFila(): void
    {
        $first = $this->service->ensureForTenant(self::T2);
        $second = $this->service->ensureForTenant(self::T2);

        $this->assertFalse($second['creada']);
        $this->assertSame($first['ubicacion_id'], $second['ubicacion_id']);
        $this->assertSame($first['codigo'], $second['codigo']);
        $this->assertTrue($second['activa']);
        $this->assertSame(1, $this->countTransit(self::T2));
    }

    public function testReactivaUnaUbicacionTransitoDesactivada(): void
    {
        $created = $this->service->ensureForTenant(self::T2);
        $this->pdo
            ->prepare('UPDATE locations SET activa = 0 WHERE id = ?')
            ->execute([$created['ubicacion_id']]);

        $again = $this->service->ensureForTenant(self::T2);

        $this->assertFalse($again['creada'], 'Ya existía: sólo se reactiva');
        $this->assertTrue($again['activa']);
        $this->assertSame($created['ubicacion_id'], $again['ubicacion_id']);
        $this->assertSame(
            1,
            (int) $this->row('SELECT activa FROM locations WHERE id = ?', [$created['ubicacion_id']])['activa']
        );
        $this->assertSame(1, $this->countTransit(self::T2));
    }

    public function testElCodigoNoSeRepiteEntreTenants(): void
    {
        // T1 ya tiene LOC-TRN; T2 se queda con el código base libre y T3 con
        // el prefijo, porque `locations.codigo` es UNIQUE en toda la tabla.
        $t2 = $this->service->ensureForTenant(self::T2);
        $t3 = $this->service->ensureForTenant(self::T3);

        $this->assertSame(TransitLocationService::CODIGO_BASE, $t2['codigo']);
        $this->assertNotSame($t2['codigo'], $t3['codigo']);
        $this->assertStringStartsWith(TransitLocationService::CODIGO_BASE . '-', $t3['codigo']);

        $codigos = (int) $this->scalar(
            "SELECT COUNT(DISTINCT codigo) FROM locations WHERE tipo = 'TRANSITO'"
        );
        $total = (int) $this->scalar("SELECT COUNT(*) FROM locations WHERE tipo = 'TRANSITO'");
        $this->assertSame($total, $codigos, 'No puede haber dos TRANSITO con el mismo código');

        // El primer tenant no se ve afectado por el segundo
        $this->assertSame($t2['codigo'], $this->service->ensureForTenant(self::T2)['codigo']);
        $this->assertSame($t3['codigo'], $this->service->ensureForTenant(self::T3)['codigo']);
    }

    public function testSinUbicacionTransitoPreviaNoAfectaALaDeT1(): void
    {
        $t1 = $this->service->ensureForTenant(self::T1);

        $this->assertFalse($t1['creada'], 'T1 ya tiene LOC-TRN (semilla de tests)');
        $this->assertSame(1, $this->countTransit(self::T1));
        $this->assertSame(
            'LOC-TRN',
            $t1['codigo'],
            'Debe devolver la ubicación TRANSITO existente del tenant, no crear otra'
        );
    }

    public function testRechazaUnTenantIdVacio(): void
    {
        try {
            $this->service->ensureForTenant('   ');
            $this->fail('Debía rechazar un tenant_id vacío');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tenant', $e->getMessage());
        }
    }

    // ====================================================================
    // Zona DAÑADOS (spec CU-009, v1.8.0)
    // ====================================================================

    public function testCreaLaZonaDanadosParaElTenantQueNoLaTiene(): void
    {
        $result = $this->service->ensureDamagedZoneForTenant(self::T2);

        $this->assertTrue($result['creada']);
        $this->assertSame('DAÑADOS', $result['tipo']);
        $this->assertSame(TransitLocationService::CODIGO_BASE_DANADOS, $result['codigo']);
        $this->assertTrue($result['activa']);
        $this->assertNull($result['capacidad'], 'La zona dañados se crea con capacidad ilimitada');

        $row = $this->row('SELECT * FROM locations WHERE id = ?', [$result['ubicacion_id']]);
        $this->assertSame(self::T2, $row['tenant_id']);
        $this->assertSame('DAÑADOS', $row['tipo']);
        $this->assertSame('Zona de danados', $row['nombre']);
        $this->assertSame(1, (int) $row['activa']);
        $this->assertSame(0, (int) $row['capacidad_usada']);
    }

    public function testLaZonaDanadosEsIdempotenteYNoSeMezclaConTransito(): void
    {
        $transit = $this->service->ensureForTenant(self::T2);
        $damaged = $this->service->ensureDamagedZoneForTenant(self::T2);
        $again = $this->service->ensureDamagedZoneForTenant(self::T2);

        $this->assertTrue($damaged['creada']);
        $this->assertFalse($again['creada'], 'Segunda llamada no duplica');
        $this->assertSame($damaged['ubicacion_id'], $again['ubicacion_id']);
        $this->assertNotSame($transit['ubicacion_id'], $damaged['ubicacion_id']);

        $this->assertSame(1, $this->countTransit(self::T2));
        $this->assertSame(1, $this->countDanados(self::T2));
    }

    public function testLasDosUbicacionesFijasPuedenCoexistirEnElMismoTenant(): void
    {
        $this->service->ensureForTenant(self::T2);
        $this->service->ensureDamagedZoneForTenant(self::T2);

        $rows = (int) $this->scalar(
            "SELECT COUNT(*) FROM locations WHERE tenant_id = ? AND tipo IN ('TRANSITO', 'DAÑADOS')",
            [self::T2]
        );
        $this->assertSame(2, $rows);

        // `locations.codigo` es UNIQUE global: si el código base ya lo tiene
        // otro tenant, la zona siguiente usa el prefijo (igual que TRANSITO).
        $this->pdo
            ->prepare("INSERT INTO locations (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada, activa)
                       VALUES (?, ?, ?, ?, 'ALMACENAMIENTO', NULL, 0, 1)")
            ->execute(['loc-race-dan', self::T1, TransitLocationService::CODIGO_BASE_DANADOS, 'Ocupado']);

        $t3 = $this->service->ensureDamagedZoneForTenant(self::T3);

        $this->assertTrue($t3['creada']);
        $this->assertSame('DAÑADOS', $t3['tipo']);
        $this->assertSame(TransitLocationService::CODIGO_BASE_DANADOS . '-', substr($t3['codigo'], 0, 8));
        $this->assertSame(1, $this->countDanados(self::T3));
    }

    /**
     * MySQL con prepares NATIVOS rechaza un placeholder repetido en la misma
     * sentencia (SQLSTATE[HY093]) mientras que SQLite lo tolera: por eso la
     * regresión no la pillan los tests de SQLite, la pilló el E2E en vivo.
     * Este test impide que vuelva a aparecer.
     */
    public function testNingunaSentenciaRepiteUnPlaceholder(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(TransitLocationService::class))->getFileName()
        );

        preg_match_all('/\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'/s', $source, $matches);

        $duplicados = [];

        foreach ($matches[1] as $literal) {
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $literal) !== 1) {
                continue;
            }

            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $literal, $found);

            if (count($found[0]) !== count(array_unique($found[0]))) {
                $duplicados[] = substr((string) preg_replace('/\s+/', ' ', $literal), 0, 120);
            }
        }

        $this->assertSame([], $duplicados, 'Placeholder repetido en una sentencia SQL');
    }

    /**
     * `locations.codigo` es UNIQUE GLOBAL: si otro tenant (u otra fila) ya
     * ocupó el código base, el alta sigue adelante con el prefijo del tenant
     * en lugar de reventar con 23000.
     */
    public function testUnCodigoOcupadoGlobalmenteNoImpideElAlta(): void
    {
        $this->pdo
            ->prepare("INSERT INTO locations (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada, activa)
                       VALUES (?, ?, ?, ?, 'ALMACEN', NULL, 0, 1)")
            ->execute(['loc-race', self::T3, TransitLocationService::CODIGO_BASE, 'Ocupado']);

        $result = $this->service->ensureForTenant(self::T3);

        $this->assertTrue($result['creada']);
        $this->assertNotSame(
            TransitLocationService::CODIGO_BASE,
            $result['codigo'],
            'El código base ya estaba ocupado globalmente: usa el prefijo del tenant'
        );
        $this->assertSame(1, $this->countTransit(self::T3));
    }
}
