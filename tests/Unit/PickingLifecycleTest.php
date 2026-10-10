<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-004: COMPLETAR y VERIFICAR el lote de picking (Sprint 7, v1.7.0).
 *
 * Cubre: 404 de lote inexistente/de otro tenant, 409 por estado (la
 * transición exige EN_CURSO → COMPLETADA → VERIFICADA), 422 de supervisor
 * igual al selector, aislamiento entre lotes y replay idempotente (R7).
 */
final class PickingLifecycleTest extends InventorySqliteTestCase
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
    private function receive(
        int $cantidad = 3,
        string $sku = 'SKU-ALFA',
        string $ubicacion = 'LOC-01'
    ): array {
        $result = $this->useCase->execute([
            'sku' => $sku,
            'cantidad_esperada' => $cantidad,
            'ubicacion_codigo' => $ubicacion,
        ]);

        $this->assertSame(201, $result['status']);

        return $result['data'];
    }

    /**
     * Recibe, reserva e inicia un lote EN_CURSO.
     *
     * @return array{unidad: array<string, mixed>, lote: string}
     */
    private function lotInProgress(string $lote, int $cantidad = 3, string $sku = 'SKU-ALFA'): array
    {
        $esT2 = $sku === 'SKU-T2';
        $ubicacion = $esT2 ? 'LOC-T2' : 'LOC-01';
        $preparacion = $esT2 ? $this->loc('LOC-PICK', self::T2) : $this->loc('LOC-PICK');
        $unit = $this->receive($cantidad, $sku, $ubicacion);

        $this->reserveUseCase->execute(
            ['unidad_fisica_id' => $unit['unidad_fisica_id']],
            null,
            'user-9'
        );

        $result = $this->startPickingUseCase->execute([
            'id_lote_picking' => $lote,
            'ubicacion_preparacion_id' => $preparacion,
            'unidades' => [['unidad_fisica_id' => $unit['unidad_fisica_id']]],
        ], null, self::SELECTOR);

        $this->assertSame(201, $result['status']);

        return ['unidad' => $unit, 'lote' => $lote];
    }

    // ====================================================================
    // COMPLETAR (EN_CURSO → COMPLETADA)
    // ====================================================================

    public function testCompletarLoteEnCursoPasaACompletada(): void
    {
        $this->lotInProgress('LOTE-C1');

        $result = $this->completePickingUseCase->execute('LOTE-C1', [], 'cmp-1', self::SELECTOR);

        $this->assertSame(200, $result['status']);
        $this->assertSame('Picking completado correctamente.', $result['data']['mensaje']);
        $this->assertSame('LOTE-C1', $result['data']['id_lote_picking']);
        $this->assertSame('COMPLETADA', $result['data']['estado']);
        $this->assertSame('COMPLETADA', $result['data']['operaciones'][0]['estado']);

        $this->assertSame(
            'COMPLETADA',
            $this->row('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-C1'])['estado']
        );
    }

    public function testCompletarLoteInexistenteDevuelve404(): void
    {
        try {
            $this->completePickingUseCase->execute('LOTE-NO-EXISTE');
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('id_lote_picking', $e->getField());
        }
    }

    public function testCompletarElMismoLoteDosVecesDevuelve409(): void
    {
        $this->lotInProgress('LOTE-C2');
        $this->completePickingUseCase->execute('LOTE-C2');

        try {
            $this->completePickingUseCase->execute('LOTE-C2', [], 'cmp-2');
            $this->fail('Debía rechazar un lote ya COMPLETADA');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('COMPLETADA', $e->getMessage());
            $this->assertStringContainsString('EN_CURSO', $e->getMessage());
        }
    }

    public function testUnLoteNoTocaAlOtro(): void
    {
        $this->lotInProgress('LOTE-A');
        $this->lotInProgress('LOTE-B');

        $this->completePickingUseCase->execute('LOTE-A');

        $this->assertSame(
            'COMPLETADA',
            $this->row('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-A'])['estado']
        );
        $this->assertSame(
            'EN_CURSO',
            $this->row('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-B'])['estado']
        );
    }

    public function testCompletarLoteDeOtroTenantDevuelve404(): void
    {
        $this->seedLocation(self::T2, 'LOC-PICK', capacidad: 10, tipo: 'PICKING');
        $this->useTenant(self::T2);
        $this->lotInProgress('LOTE-T2', 3, 'SKU-T2');
        $this->useTenant(self::T1);

        try {
            $this->completePickingUseCase->execute('LOTE-T2');
            $this->fail('Debía devolver 404: el lote es de otro tenant');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(
            'EN_CURSO',
            $this->row('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-T2'])['estado']
        );
    }

    public function testReplayDeCompletarDevuelveLaMismaRespuesta(): void
    {
        $this->lotInProgress('LOTE-C3');

        $first = $this->completePickingUseCase->execute('LOTE-C3', [], 'cmp-replay');
        $second = $this->completePickingUseCase->execute('LOTE-C3', [], 'cmp-replay');

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(
            $first['data']['operaciones'][0]['picking_id'],
            $second['data']['operaciones'][0]['picking_id']
        );
        $this->assertSame(1, $this->countRows('picking_operations'));
        $this->assertSame(1, $this->countRows('idempotency_keys'));
    }

    // ====================================================================
    // VERIFICAR (COMPLETADA → VERIFICADA, supervisor ≠ selector)
    // ====================================================================

    public function testVerificarLoteCompletadoPasaAVerificada(): void
    {
        $this->lotInProgress('LOTE-V1');
        $this->completePickingUseCase->execute('LOTE-V1');

        $result = $this->verifyPickingUseCase->execute('LOTE-V1', [], 'ver-1', self::SUPERVISOR);

        $this->assertSame(200, $result['status']);
        $this->assertSame('Picking verificado correctamente.', $result['data']['mensaje']);
        $this->assertSame('VERIFICADA', $result['data']['estado']);
        $this->assertSame(self::SUPERVISOR, $result['data']['actor_id_supervisor']);

        $op = $this->row('SELECT * FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-V1']);
        $this->assertSame('VERIFICADA', $op['estado']);
        $this->assertSame(self::SUPERVISOR, $op['actor_id_supervisor']);
        $this->assertSame(self::SELECTOR, $op['actor_id_selector']);
    }

    public function testVerificarLoteEnCursoDevuelve409(): void
    {
        $this->lotInProgress('LOTE-V2');

        try {
            $this->verifyPickingUseCase->execute('LOTE-V2', [], 'ver-2', self::SUPERVISOR);
            $this->fail('Debía exigir que el lote esté COMPLETADA');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('EN_CURSO', $e->getMessage());
            $this->assertStringContainsString('COMPLETADA', $e->getMessage());
        }

        $this->assertSame(
            'EN_CURSO',
            $this->row('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-V2'])['estado']
        );
        $this->assertNull(
            $this->row(
                'SELECT actor_id_supervisor FROM picking_operations WHERE id_lote_picking = ?',
                ['LOTE-V2']
            )['actor_id_supervisor']
        );
    }

    public function testVerificarConSupervisorIgualAlSelectorDevuelve422(): void
    {
        $this->lotInProgress('LOTE-V3');
        $this->completePickingUseCase->execute('LOTE-V3');

        try {
            $this->verifyPickingUseCase->execute('LOTE-V3', [], 'ver-3', self::SELECTOR);
            $this->fail('Debía exigir un supervisor distinto del selector');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('actor_id_supervisor', $e->getField());
        }

        $op = $this->row('SELECT * FROM picking_operations WHERE id_lote_picking = ?', ['LOTE-V3']);
        $this->assertSame('COMPLETADA', $op['estado']);
        $this->assertNull($op['actor_id_supervisor']);
    }

    public function testVerificarSinActorDevuelve400(): void
    {
        $this->lotInProgress('LOTE-V4');
        $this->completePickingUseCase->execute('LOTE-V4');

        try {
            $this->verifyPickingUseCase->execute('LOTE-V4');
            $this->fail('Debía exigir el supervisor autenticado');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('actor_id_supervisor', $e->getField());
        }
    }

    public function testVerificarLoteInexistenteDevuelve404(): void
    {
        try {
            $this->verifyPickingUseCase->execute('LOTE-NADA', [], 'ver-4', self::SUPERVISOR);
            $this->fail('Debía devolver 404');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('id_lote_picking', $e->getField());
        }
    }

    public function testSecuenciaCompletaDelCu004(): void
    {
        $this->lotInProgress('LOTE-CU004');

        $this->assertSame('EN_CURSO', $this->estadoDe('LOTE-CU004'));
        $this->completePickingUseCase->execute('LOTE-CU004');
        $this->assertSame('COMPLETADA', $this->estadoDe('LOTE-CU004'));
        $this->verifyPickingUseCase->execute('LOTE-CU004', [], null, self::SUPERVISOR);
        $this->assertSame('VERIFICADA', $this->estadoDe('LOTE-CU004'));

        // Ninguna llamada de la secuencia usó Idempotency-Key
        $this->assertSame(0, $this->countRows('idempotency_keys'));
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function estadoDe(string $lote): ?string
    {
        return $this->scalar('SELECT estado FROM picking_operations WHERE id_lote_picking = ?', [$lote]);
    }
}
