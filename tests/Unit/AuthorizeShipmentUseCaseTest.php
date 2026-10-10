<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\TransitLocationService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-006: AUTORIZAR LA SALIDA de un envío (Sprint 7, v1.7.0).
 *
 * Cubre: 404 de envío inexistente / sin ubicación TRANSITO, 409 de envío ya
 * autorizado, 422 por no estar cargado, sin destinatario, unidad no CARGADA
 * y sin actor, replay idempotente (R7) y atomicidad (R5).
 */
final class AuthorizeShipmentUseCaseTest extends InventorySqliteTestCase
{
    private const SELECTOR = 'selector-1';
    private const SUPERVISOR = 'supervisor-9';

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function receive(int $cantidad = 3): array
    {
        $result = $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => 'LOC-01',
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    /**
     * Unidad RESERVADO → EN_PREPARACION (lote COMPLETADA) → CARGADO.
     *
     * @return array<string, mixed>
     */
    private function loadedUnit(string $lote, int $cantidad = 3, ?string $shipmentId = null): array
    {
        $unit = $this->receive($cantidad);

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $this->loc('LOC-PICK'),
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SELECTOR);

        $this->completePickingUseCase->execute($lote);

        $overrides = [
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
            'shipment' => [
                'referencia_externa' => 'TR-200',
                'destinatario_nombre' => 'ACME SA',
                'conductor_nombre' => 'Chofer Uno',
            ],
        ];

        if ($shipmentId !== null) {
            $overrides['shipment_id'] = $shipmentId;
            $overrides['shipment'] = [];
        }

        $result = $this->loadShipmentUseCase->execute($overrides, null, self::SELECTOR);
        $this->assertSame(201, $result['status']);

        $unit['shipment_id'] = $result['data']['shipment']['shipment_id'];

        return $unit;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function authorize(array $overrides = [], ?string $key = 'az-1', ?string $actor = self::SUPERVISOR): array
    {
        return $this->authorizeShipmentUseCase->execute(
            array_merge(['shipment_id' => ''], $overrides),
            $key,
            $actor
        );
    }

    private function countQuery(string $sql, array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM ({$sql})", $params);
    }

    // ====================================================================
    // CAMINO FELIZ
    // ====================================================================

    public function testAutorizaSalidaMueveLasUnidadesATransitoYElEnvioARuta(): void
    {
        $unit = $this->loadedUnit('LOTE-A1');

        $result = $this->authorize(['shipment_id' => $unit['shipment_id']]);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame('Salida autorizada correctamente.', $data['mensaje']);
        $this->assertSame('EN_RUTA', $data['shipment']['estado']);
        $this->assertSame(self::SUPERVISOR, $data['shipment']['actor_id_autoriza_salida']);
        $this->assertNotNull($data['shipment']['hora_salida']);
        $this->assertSame('EN_TRANSITO', $data['items'][0]['estado']);
        $this->assertSame('EN_TRANSITO', $data['unidades'][0]['estado']);
        $this->assertSame($this->loc('LOC-TRN'), $data['ubicacion_transito']['ubicacion_id']);
        $this->assertSame('EN_TRANSITO', $data['eventos'][0]['tipo_evento']);

        // Persistencia (spec 3.13 / 3.14)
        $shipment = $this->row('SELECT * FROM shipments WHERE id = ?', [$unit['shipment_id']]);
        $this->assertSame('EN_RUTA', $shipment['estado']);
        $this->assertSame(self::SUPERVISOR, $shipment['actor_id_autoriza_salida']);
        $this->assertNotNull($shipment['hora_salida']);

        $item = $this->row('SELECT * FROM shipment_items');
        $this->assertSame('EN_TRANSITO', $item['estado']);

        // Unidad EN_TRANSITO en la ubicación virtual TRANSITO
        $unitRow = $this->row('SELECT * FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_TRANSITO', $unitRow['estado']);
        $this->assertSame($this->loc('LOC-TRN'), $unitRow['ubicacion_id']);

        // Ledger: cantidades intactas, sólo estado_actual (R1)
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('EN_TRANSITO', $ledger['estado_actual']);
        $this->assertSame(3.0, (float) $ledger['cantidad_en_transito']);
        $this->assertSame(0.0, (float) $ledger['cantidad_reservada']);
        $this->assertSame(0.0, (float) $ledger['cantidad_disponible']);

        // TRANSITO es virtual: no ocupa capacidad en la zona de picking
        $zona = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-PICK')]);
        $this->assertSame(0, (int) $zona['capacidad_usada']);
        $transito = $this->row('SELECT * FROM locations WHERE id = ?', [$this->loc('LOC-TRN')]);
        $this->assertSame(0, (int) $transito['capacidad_usada']);
    }

    // ====================================================================
    // VALIDACIONES (spec CU-006)
    // ====================================================================

    public function testAutorizarSinCargarDevuelve422(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo
            ->prepare(
                'INSERT INTO shipments (id, tenant_id, estado, destinatario_nombre, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute(['ship-vacia', self::T1, 'PREPARACION', 'ACME SA', $now, $now]);

        try {
            $this->authorize(['shipment_id' => 'ship-vacia']);
            $this->fail('Debía exigir unidades CARGADAS');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $shipment = $this->row('SELECT estado FROM shipments WHERE id = ?', ['ship-vacia']);
        $this->assertSame('PREPARACION', $shipment['estado']);
    }

    public function testDestinatarioInvalidoDevuelve422(): void
    {
        $unit = $this->loadedUnit('LOTE-A2');

        // Envío creado sin destinatario (carga mínima)
        $this->pdo
            ->prepare("UPDATE shipments SET destinatario_nombre = NULL, destinatario_id = NULL WHERE id = ?")
            ->execute([$unit['shipment_id']]);

        try {
            $this->authorize(['shipment_id' => $unit['shipment_id']]);
            $this->fail('Debía exigir un destinatario válido');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('destinatario_nombre', $e->getField());
        }

        $shipment = $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']]);
        $this->assertSame('PREPARACION', $shipment['estado']);
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $unitRow['estado']);
    }

    public function testSinUbicacionTransitoActivaDevuelve404(): void
    {
        $unit = $this->loadedUnit('LOTE-A3');
        $this->pdo
            ->prepare('UPDATE locations SET activa = 0 WHERE id = ?')
            ->execute([$this->loc('LOC-TRN')]);

        try {
            $this->authorize(['shipment_id' => $unit['shipment_id']]);
            $this->fail('Debía devolver 404 sin zona TRANSITO activa');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('ubicacion_transito_id', $e->getField());
        }

        $shipment = $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']]);
        $this->assertSame('PREPARACION', $shipment['estado']);
        $item = $this->row('SELECT estado FROM shipment_items');
        $this->assertSame('CARGADA', $item['estado']);
        $unitRow = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $unitRow['estado']);
    }

    /**
     * v1.7.1 (A1): la semilla por tenant reactiva la ubicación TRANSITO y,
     * con ella, `authorize` deja de devolver 404 `transitLocationMissing`.
     */
    public function testSemillaTransitoReactivaLaUbicacionYPermiteAutorizar(): void
    {
        $unit = $this->loadedUnit('LOTE-A11');
        $this->pdo
            ->prepare('UPDATE locations SET activa = 0 WHERE id = ?')
            ->execute([$this->loc('LOC-TRN')]);

        $transito = (new TransitLocationService($this->db))->ensureForTenant(self::T1);

        $this->assertFalse($transito['creada'], 'Ya existía: sólo se reactiva');
        $this->assertTrue($transito['activa']);

        $result = $this->authorize(['shipment_id' => $unit['shipment_id']], 'az-semilla');

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            'EN_RUTA',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']])['estado']
        );
        $this->assertSame(
            'EN_TRANSITO',
            $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unit['unidad_fisica_id']])['estado']
        );
    }

    public function testShipmentInexistenteDevuelve404(): void
    {
        try {
            $this->authorize(['shipment_id' => 'no-existe']);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('shipment_id', $e->getField());
        }
    }

    public function testEnvioYaAutorizadoDevuelve409(): void
    {
        $unit = $this->loadedUnit('LOTE-A4');
        $this->authorize(['shipment_id' => $unit['shipment_id']]);

        try {
            $this->authorize(['shipment_id' => $unit['shipment_id']], 'az-2');
            $this->fail('Debía rechazar un envío que ya salió');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('PREPARACION', $e->getMessage());
        }

        // Sólo la primera autorización escribió eventos
        $this->assertSame(1, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'EN_TRANSITO'"
        ));
        $this->assertSame(
            'EN_RUTA',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']])['estado']
        );
    }

    public function testUnidadNoCargadaDevuelve422(): void
    {
        $unit = $this->loadedUnit('LOTE-A5');
        $this->pdo
            ->prepare('UPDATE stock_units SET estado = ? WHERE id = ?')
            ->execute(['DISPONIBLE', $unit['unidad_fisica_id']]);

        try {
            $this->authorize(['shipment_id' => $unit['shipment_id']]);
            $this->fail('Debía exigir unidades CARGADAS');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $shipment = $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']]);
        $this->assertSame('PREPARACION', $shipment['estado']);
    }

    public function testSinActorDevuelve400(): void
    {
        $unit = $this->loadedUnit('LOTE-A6');

        try {
            $this->authorize(['shipment_id' => $unit['shipment_id']], null, null);
            $this->fail('Debía exigir el actor que autoriza la salida');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('actor_id_autoriza_salida', $e->getField());
        }
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayIdempotenteNoVuelveAAutorizar(): void
    {
        $unit = $this->loadedUnit('LOTE-A7');
        $payload = ['shipment_id' => $unit['shipment_id']];

        $first = $this->authorize($payload, 'az-replay');
        $second = $this->authorize($payload, 'az-replay');

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(
            $first['data']['shipment']['hora_salida'],
            $second['data']['shipment']['hora_salida']
        );
        $this->assertSame(
            'EN_RUTA',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$unit['shipment_id']])['estado']
        );
    }

    public function testMismaClaveConPayloadDistintoDevuelve409(): void
    {
        $unit = $this->loadedUnit('LOTE-A8');
        $this->authorize(['shipment_id' => $unit['shipment_id']], 'az-clave');

        try {
            $this->authorize(['shipment_id' => 'otro-envio'], 'az-clave');
            $this->fail('Debía devolver 409 por payload distinto');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ====================================================================
    // ATOMICIDAD (R5)
    // ====================================================================

    public function testFalloEnLaSegundaUnidadReverteLaAutorizacion(): void
    {
        $unitA = $this->loadedUnit('LOTE-A9A');
        $unitB = $this->loadedUnit('LOTE-A9B', 2, $unitA['shipment_id']);
        $this->pdo
            ->prepare('UPDATE stock_units SET estado = ? WHERE id = ?')
            ->execute(['DISPONIBLE', $unitB['unidad_fisica_id']]);

        try {
            $this->authorize(['shipment_id' => $unitA['shipment_id']], 'az-atomic');
            $this->fail('Debía propagar el fallo de la segunda unidad');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Rollback: nada cambió en el envío, los ítems ni las unidades
        $this->assertSame(
            'PREPARACION',
            $this->row('SELECT estado FROM shipments WHERE id = ?', [$unitA['shipment_id']])['estado']
        );
        $this->assertNull(
            $this->row(
                'SELECT actor_id_autoriza_salida FROM shipments WHERE id = ?',
                [$unitA['shipment_id']]
            )['actor_id_autoriza_salida']
        );
        $this->assertSame(
            2,
            $this->countQuery("SELECT id FROM shipment_items WHERE estado = 'CARGADA'")
        );
        $this->assertSame(0, $this->countRows('idempotency_keys'));

        // Ninguna unidad llegó a EN_TRANSITO
        $rowA = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unitA['unidad_fisica_id']]);
        $this->assertSame('CARGADO', $rowA['estado']);
        $rowB = $this->row('SELECT estado FROM stock_units WHERE id = ?', [$unitB['unidad_fisica_id']]);
        $this->assertSame('DISPONIBLE', $rowB['estado']);
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_units WHERE estado = 'EN_TRANSITO'"
        ));
        $this->assertSame(0, $this->countQuery(
            "SELECT id FROM stock_events WHERE tipo_evento = 'EN_TRANSITO'"
        ));
    }
}
