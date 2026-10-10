<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Exceptions\HttpException;
use Tests\Support\AuditSqliteTestCase;

/**
 * AuditLogQueryService: filtros, paginación, aislamiento multi-tenant
 * y exposición de la cadena (prev_hash) para verificación.
 */
final class AuditLogQueryTest extends AuditSqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 3 SUCCESS + 2 FAILURE en T1, 1 SUCCESS en T2.
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-1',
            'operacion' => 'product.create',
            'modulo' => 'inventory',
            'recurso' => 'products',
            'entidad_id' => 'prod-1',
            'resultado' => 'SUCCESS',
            'request_id' => 'req-1',
            'timestamp' => '2026-09-28 10:00:00.000001',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-2',
            'operacion' => 'auth.login',
            'modulo' => 'auth',
            'recurso' => 'auth',
            'resultado' => 'FAILURE',
            'razon_fallo' => 'Credenciales inválidas',
            'request_id' => 'req-2',
            'timestamp' => '2026-09-29 10:00:00.000002',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-1',
            'operacion' => 'location.create',
            'modulo' => 'inventory',
            'recurso' => 'locations',
            'entidad_id' => 'loc-1',
            'resultado' => 'SUCCESS',
            'request_id' => 'req-3',
            'timestamp' => '2026-09-30 10:00:00.000003',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-3',
            'operacion' => 'user.update',
            'modulo' => 'users',
            'recurso' => 'users',
            'entidad_id' => 'user-3',
            'resultado' => 'FAILURE',
            'request_id' => 'req-4',
            'timestamp' => '2026-10-01 10:00:00.000004',
        ]);
        $this->logger->record([
            'tenant_id' => self::T1,
            'usuario_id' => 'user-1',
            'operacion' => 'product.delete',
            'modulo' => 'inventory',
            'recurso' => 'products',
            'entidad_id' => 'prod-2',
            'resultado' => 'SUCCESS',
            'request_id' => 'req-5',
            'timestamp' => '2026-10-01 11:00:00.000005',
        ]);
        $this->logger->record([
            'tenant_id' => self::T2,
            'usuario_id' => 'user-t2',
            'operacion' => 'product.create',
            'resultado' => 'SUCCESS',
            'timestamp' => '2026-10-01 12:00:00.000006',
        ]);
    }

    public function testSinFiltrosDevuelveTodoElResumenYPaginacion(): void
    {
        $result = $this->query->search([], self::T1);

        $this->assertSame(
            ['total' => 5, 'exitosos' => 3, 'fallidos' => 2],
            $result['summary']
        );
        $this->assertCount(5, $result['data']);
        $this->assertSame(
            ['page' => 1, 'per_page' => 25, 'total' => 5, 'last_page' => 1],
            $result['pagination']
        );

        // Orden: timestamp DESC.
        $this->assertSame('product.delete', $result['data'][0]['operacion']);
        $this->assertSame('product.create', $result['data'][4]['operacion']);
    }

    public function testAislaPorTenant(): void
    {
        $result = $this->query->search([], self::T2);

        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame('user-t2', $result['data'][0]['usuario_id']);

        // El filtro de query string no puede cambiar el tenant.
        $result = $this->query->search(['tenant_id' => self::T2], self::T1);
        $this->assertSame(5, $result['summary']['total']);
        foreach ($result['data'] as $row) {
            $this->assertSame(self::T1, $row['tenant_id']);
        }
    }

    public function testFiltraPorResultado(): void
    {
        $result = $this->query->search(['resultado' => 'FAILURE'], self::T1);

        $this->assertSame(
            ['total' => 2, 'exitosos' => 0, 'fallidos' => 2],
            $result['summary']
        );
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $row) {
            $this->assertSame('FAILURE', $row['resultado']);
        }
    }

    public function testRechazaResultadoInvalido(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->query->search(['resultado' => 'TODO'], self::T1);
    }

    public function testFiltraPorUsuarioAccionYEntidad(): void
    {
        $porUsuario = $this->query->search(['usuario_id' => 'user-1'], self::T1);
        $this->assertSame(3, $porUsuario['summary']['total']);

        $porAccion = $this->query->search(['accion' => 'product.create'], self::T1);
        $this->assertSame(1, $porAccion['summary']['total']);
        $this->assertSame('prod-1', $porAccion['data'][0]['entidad_id']);

        // Alias operacion.
        $porAlias = $this->query->search(['operacion' => 'product.create'], self::T1);
        $this->assertSame(1, $porAlias['summary']['total']);

        $porEntidad = $this->query->search(['entidad' => 'products'], self::T1);
        $this->assertSame(2, $porEntidad['summary']['total']);

        $porEntidadId = $this->query->search(['entidad_id' => 'loc-1'], self::T1);
        $this->assertSame(1, $porEntidadId['summary']['total']);
        $this->assertSame('location.create', $porEntidadId['data'][0]['operacion']);

        $porRequestId = $this->query->search(['request_id' => 'req-3'], self::T1);
        $this->assertSame(1, $porRequestId['summary']['total']);
        $this->assertSame('location.create', $porRequestId['data'][0]['operacion']);
    }

    public function testFiltraPorRangoDeFechas(): void
    {
        $desde = $this->query->search(['fecha_desde' => '2026-09-30'], self::T1);
        $this->assertSame(3, $desde['summary']['total']);

        $hasta = $this->query->search(['fecha_hasta' => '2026-09-29'], self::T1);
        $this->assertSame(2, $hasta['summary']['total']);

        $rango = $this->query->search([
            'fecha_desde' => '2026-09-29',
            'fecha_hasta' => '2026-09-30',
        ], self::T1);
        $this->assertSame(2, $rango['summary']['total']);
    }

    public function testPagina(): void
    {
        $page1 = $this->query->search(['per_page' => 2, 'page' => 1], self::T1);
        $this->assertCount(2, $page1['data']);
        $this->assertSame(
            ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
            $page1['pagination']
        );

        $page3 = $this->query->search(['per_page' => 2, 'page' => 3], self::T1);
        $this->assertCount(1, $page3['data']);

        $this->assertSame('product.create', $page3['data'][0]['operacion']);
    }

    public function testLimitaPerPageACien(): void
    {
        $result = $this->query->search(['per_page' => 500], self::T1);

        $this->assertSame(100, $result['pagination']['per_page']);
    }

    public function testExponePrevHashParaVerificarLaCadena(): void
    {
        $result = $this->query->search(['accion' => 'product.delete'], self::T1);
        $row = $result['data'][0];

        $this->assertArrayHasKey('prev_hash', $row);
        $this->assertArrayHasKey('hash_integridad', $row);

        // La última fila de la cadena apunta a la anterior.
        $prev = $this->query->search(['accion' => 'user.update'], self::T1);
        $this->assertSame($prev['data'][0]['hash_integridad'], $row['prev_hash']);
        $this->assertNull(
            $this->query->search(['accion' => 'product.create'], self::T1)['data'][0]['prev_hash']
        );
    }

    public function testDecodificaLosValoresJson(): void
    {
        $this->logger->record([
            'tenant_id' => self::T1,
            'operacion' => 'product.update',
            'valores_anteriores' => ['nombre' => 'Antes'],
            'valores_nuevos' => ['nombre' => 'Después'],
            'timestamp' => '2026-10-02 10:00:00.000007',
        ]);

        $result = $this->query->search(['accion' => 'product.update'], self::T1);
        $row = $result['data'][0];

        $this->assertSame(['nombre' => 'Antes'], $row['valores_anteriores']);
        $this->assertSame(['nombre' => 'Después'], $row['valores_nuevos']);
        $this->assertSame(6, $this->query->search([], self::T1)['summary']['total']);
    }
}
