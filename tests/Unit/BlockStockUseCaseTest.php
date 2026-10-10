<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\BlockStockUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * v1.10.0 (E4): BLOQUEAR / DESBLOQUEAR UNIDAD a mano.
 *
 * Cubre: bloqueo sin tocar ubicación ni cupo, desbloqueo a DISPONIBLE,
 * matriz de transiciones (422 en X → X y en orígenes no permitidos),
 * validación de `accion` (400), unidad inexistente (404), ledger
 * `estado_actual`, eventos BLOQUEADO/DESBLOQUEADO con hash y replay
 * idempotente (R7).
 */
final class BlockStockUseCaseTest extends InventorySqliteTestCase
{
    private const ACTOR = 'usuario-30';

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    private function receive(string $ubicacionCodigo = 'LOC-01', int $cantidad = 3): array
    {
        return $this->useCase->execute([
            'sku' => 'SKU-ALFA',
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacionCodigo,
        ])['data'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function block(string $unitId, array $input = [], ?string $key = 'blk-key-1'): array
    {
        return $this->blockUseCase->execute(
            $unitId,
            $input + ['accion' => BlockStockUseCase::ACCION_BLOQUEAR],
            $key,
            self::ACTOR
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function unblock(string $unitId, array $input = [], ?string $key = 'unblk-key-1'): array
    {
        return $this->blockUseCase->execute(
            $unitId,
            $input + ['accion' => BlockStockUseCase::ACCION_DESBLOQUEAR],
            $key,
            self::ACTOR
        );
    }

    private function unitRow(string $id): array
    {
        return $this->row('SELECT * FROM stock_units WHERE id = ?', [$id]);
    }

    // ====================================================================
    // BLOQUEO
    // ====================================================================

    public function testBloquearCambiaEstadoSinTocarUbicacionNiCupo(): void
    {
        $data = $this->receive('LOC-01', 3);
        $unitId = $data['unidad_fisica_id'];
        $loc = $this->loc('LOC-01');

        $capacidadAntes = (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc]);
        $eventosAntes = $this->countRows('stock_events');

        $result = $this->block($unitId);

        $this->assertSame(200, $result['status']);
        $this->assertSame('Unidad bloqueada correctamente.', $result['data']['mensaje']);
        $this->assertSame('DISPONIBLE', $result['data']['estado_anterior']);
        $this->assertSame('BLOQUEADO', $result['data']['estado']);
        $this->assertSame('BLOQUEADO', $result['data']['unidad']['estado']);

        // La ubicación y el cupo NO cambian (presencia intacta)
        $fila = $this->unitRow($unitId);
        $this->assertSame('BLOQUEADO', $fila['estado']);
        $this->assertSame($loc, $fila['ubicacion_id']);
        $this->assertSame(
            $capacidadAntes,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );

        // Ledger (R1): sólo cambia estado_actual
        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unitId]);
        $this->assertSame('BLOQUEADO', $ledger['estado_actual']);
        $this->assertEqualsWithDelta(3.0, (float) $ledger['cantidad_fisica'], 0.0001);

        // Evento BLOQUEADO con hash
        $this->assertSame($eventosAntes + 1, $this->countRows('stock_events'));
        $evento = $this->row(
            "SELECT * FROM stock_events WHERE unidad_fisica_id = ? AND tipo_evento = 'BLOQUEADO'",
            [$unitId]
        );
        $this->assertNotNull($evento);
        $this->assertNotEmpty($evento['hash_integridad']);
        $this->assertSame('DISPONIBLE', $evento['estado_anterior']);
        $this->assertSame('BLOQUEADO', $evento['estado_posterior']);
    }

    public function testDesbloquearVuelveADisponible(): void
    {
        $data = $this->receive('LOC-01', 2);
        $unitId = $data['unidad_fisica_id'];

        $this->block($unitId, [], 'blk-key-2');
        $result = $this->unblock($unitId, [], 'unblk-key-2');

        $this->assertSame(200, $result['status']);
        $this->assertSame('BLOQUEADO', $result['data']['estado_anterior']);
        $this->assertSame('DISPONIBLE', $result['data']['estado']);

        $this->assertSame('DISPONIBLE', $this->unitRow($unitId)['estado']);
        $evento = $this->row(
            "SELECT * FROM stock_events WHERE unidad_fisica_id = ? AND tipo_evento = 'DESBLOQUEADO'",
            [$unitId]
        );
        $this->assertNotNull($evento);
        $this->assertNotEmpty($evento['hash_integridad']);
    }

    public function testBloquearUnidadYaBloqueadaDevuelve422(): void
    {
        $data = $this->receive('LOC-01', 1);
        $unitId = $data['unidad_fisica_id'];

        $this->block($unitId, [], 'blk-key-3');

        try {
            $this->block($unitId, [], 'blk-key-4');
            $this->fail('Segundo bloqueo debe devolver 422 (matriz X → X inválida)');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        // Efecto único: un solo evento BLOQUEADO
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id = ? AND tipo_evento = 'BLOQUEADO'",
                [$unitId]
            )
        );
    }

    public function testDesbloquearUnidadNoBloqueadaDevuelve422(): void
    {
        $data = $this->receive('LOC-01', 1);

        try {
            $this->unblock($data['unidad_fisica_id'], [], 'unblk-key-3');
            $this->fail('Desbloquear una DISPONIBLE debe devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    public function testUnidadPerdidaNoAdmiteBloqueoPeroTampocoLaRompe(): void
    {
        // PERDIDO → BLOQUEADO no está en la matriz: 422 con field estado
        $data = $this->receive('LOC-01', 1);
        $unitId = $data['unidad_fisica_id'];
        $this->pdo->prepare("UPDATE stock_units SET estado = 'PERDIDO' WHERE id = ?")
            ->execute([$unitId]);

        try {
            $this->block($unitId, [], 'blk-key-5');
            $this->fail('PERDIDO → BLOQUEADO debe devolver 422');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $this->assertSame('PERDIDO', $this->unitRow($unitId)['estado']);
    }

    // ====================================================================
    // VALIDACIONES
    // ====================================================================

    public function testAccionDesconocidaDevuelve400(): void
    {
        $data = $this->receive('LOC-01', 1);

        try {
            $this->block($unitId = $data['unidad_fisica_id'], ['accion' => 'EXPLOTAR'], 'blk-key-6');
            $this->fail('Acción desconocida debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('accion', $e->getField());
        }
    }

    public function testAccionVaciaDevuelve400(): void
    {
        try {
            $this->blockUseCase->execute('unit-cualquiera', ['accion' => ''], null, self::ACTOR);
            $this->fail('accion vacía debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('accion', $e->getField());
        }
    }

    public function testUnidadInexistenteDevuelve404(): void
    {
        try {
            $this->block('unit-que-no-existe', [], 'blk-key-7');
            $this->fail('Unidad inexistente debe devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ====================================================================
    // IDEMPOTENCIA (R7)
    // ====================================================================

    public function testReplayConLaMismaClaveDevuelveElMismoResultado(): void
    {
        $data = $this->receive('LOC-01', 2);
        $unitId = $data['unidad_fisica_id'];

        $first = $this->block($unitId, [], 'blk-replay-1');
        $second = $this->block($unitId, [], 'blk-replay-1');

        $this->assertTrue((bool) ($second['_replay'] ?? false));
        $this->assertSame($first['status'], $second['status']);
        $this->assertSame($first['data'], $second['data']);

        // Sólo UN evento BLOQUEADO
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id = ? AND tipo_evento = 'BLOQUEADO'",
                [$unitId]
            )
        );
    }
}
