<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\ReceivingController;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/inventory/receive — ENDPOINT CERRADO (v1.13.32).
 *
 * No hay recepcion sin documento planificado:
 *  - 401 sin claims.
 *  - 422 receivingWithoutDocumentNotAllowed con claims (independientemente del
 *    header Idempotency-Key): una entrega imprevista se planifica primero y se
 *    recibe contra el documento.
 * El endpoint sigue registrado (dependencias historicas) pero no toca stock,
 * ledger, eventos ni idempotency.
 */
final class ReceivingControllerTest extends TestCase
{
    private ReceivingController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ReceivingController();
    }

    public function testSinClaimsDevuelve401(): void
    {
        try {
            $this->controller->receive([
                'user' => null,
                'headers' => ['idempotency-key' => 'k1'],
                'body' => ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 4, 'ubicacion_codigo' => 'LOC-01'],
                'query' => [],
            ]);
            $this->fail('Sin claims debe devolver 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testConClaimsDevuelve422SinDocumentoPlanificado(): void
    {
        try {
            $this->controller->receive([
                'user' => ['user_id' => 'user-1', 'tenant_id' => 'tenant-1'],
                'headers' => ['idempotency-key' => 'k1'],
                'body' => ['sku' => 'SKU-ALFA', 'cantidad_recibida' => 4, 'ubicacion_codigo' => 'LOC-01'],
                'query' => [],
            ]);
            $this->fail('El endpoint cerrado debe devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('documento planificado', $e->getMessage());
        }
    }

    public function testElEndpointCerradoNoRequiereIdempotencyKey(): void
    {
        // El 422 del cierre llega ANTES de cualquier validacion de cabecera:
        // no hay operacion que idempotentizar.
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(422);

        $this->controller->receive([
            'user' => ['user_id' => 'user-1', 'tenant_id' => 'tenant-1'],
            'headers' => [],
            'body' => [],
            'query' => [],
        ]);
    }
}
