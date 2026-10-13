<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\InventoryMysqlTestCase;

/**
 * Humo de la base MySQL de test (BLOQUE R2): valida que la conexi&#243;n a
 * xproject_test, el tenant &#250;nico y el cleanup de tearDown funcionan.
 */
final class InventoryMysqlSmokeTest extends InventoryMysqlTestCase
{
    public function testSetUpCreaTenantProductoYUbicacionEnMysqlReal(): void
    {
        $this->assertSame('xproject_test', $this->scalar('SELECT DATABASE()'));

        $this->assertNotNull($this->row('SELECT id FROM tenants WHERE id = ?', [$this->tenantId]));
        $this->assertNotNull($this->row('SELECT id FROM users WHERE id = ?', [$this->ownerUserId]));
        $this->assertSame(
            'SKU-ALFA',
            $this->scalar('SELECT sku FROM products WHERE tenant_id = ? AND sku = ?', [$this->tenantId, 'SKU-ALFA'])
        );
        $this->assertSame(
            'LOC-01',
            $this->scalar('SELECT codigo FROM locations WHERE tenant_id = ? AND codigo = ?', [$this->tenantId, 'LOC-01'])
        );
    }

    public function testElDocumentoCreadoPorHelperQuedaPlanificadaConLinea(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador, 'PO-SMOKE-001');

        $this->assertSame(
            'PLANIFICADA',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );
    }

    public function testElCleanupDeEsteTenantDejaLaBaseSinSusFilas(): void
    {
        $this->seedProduct($this->tenantId, 'SKU-MIO');
        $doc = $this->createReceivingDocument($this->ownerUserId, 'PO-SMOKE-CLEAN');

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM products WHERE tenant_id = ? AND sku = ?', [$this->tenantId, 'SKU-MIO'])
        );
        $this->assertNotNull($this->row('SELECT id FROM receiving_documents WHERE id = ?', [$doc['id']]));

        // Forzar el cleanup ahora (sin cerrar la conexi&#243;n) y comprobar que no
        // quedan rastros de este tenant en ninguna tabla de negocio.
        $this->forceCleanup();

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM tenants WHERE id = ?', [$this->tenantId])
        );
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM receiving_documents WHERE tenant_id = ?', [$this->tenantId])
        );
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM products WHERE tenant_id = ?', [$this->tenantId])
        );
    }
}
