<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Entities\InventoryCount;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Application\Services\CountQueryService;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-011: CONTEO FÍSICO (Sprint 9, v1.9.0).
 *
 * Cubre: aislamiento entre tenants, inicio con lista teórica, resultados
 * parciales (fusión y unidades fuera de la hoja), unidades faltantes al
 * completar (422), discrepancias (esperada/encontrada/diferencia, sólo las
 * que difieren), reconciliar AJUSTAR con evento AJUSTE_CANTIDAD + hash +
 * ledger + capacidad, estados no permitidos (422), cantidad 0 con incidencia
 * INVENTARIO_DESAPARECIDO, replay idempotente (R7) y atomicidad (R5).
 */
final class PhysicalCountUseCaseTest extends InventorySqliteTestCase
{
    private const ACTOR = 'contador-11';
    private const ACTOR_AUTOR = 'responsable-12';

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    private function seedUnit(
        string $tenantId,
        ?string $ubicacionId,
        float $cantidad = 3,
        string $estado = 'DISPONIBLE',
        ?string $id = null
    ): string {
        $id = $id ?? 'unit-' . substr(md5($tenantId . $ubicacionId . uniqid('', true)), 0, 12);
        $producto = $this->scalar('SELECT id FROM products WHERE sku = ? AND tenant_id = ?', [
            'SKU-ALFA',
            $tenantId,
        ]) ?: $this->scalar('SELECT id FROM products WHERE tenant_id = ? LIMIT 1', [$tenantId]);

        $this->pdo->prepare(
            'INSERT INTO stock_units (id, tenant_id, producto_id, codigo_barcode, cantidad, estado, ubicacion_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $tenantId, $producto, 'BC-' . $id, $cantidad, $estado, $ubicacionId]);

        $this->pdo->prepare(
            'INSERT INTO stock_ledger (id, tenant_id, unidad_fisica_id, cantidad_fisica,
                                       cantidad_reservada, cantidad_en_transito, cantidad_disponible, estado_actual)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?)'
        )->execute(['led-' . $id, $tenantId, $id, $cantidad, $cantidad, $estado]);

        return $id;
    }

    private function setCapacidadUsada(string $locId, int $usada): void
    {
        $this->pdo->prepare('UPDATE locations SET capacidad_usada = ? WHERE id = ?')
            ->execute([$usada, $locId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function start(array $input = [], ?string $key = 'cnt-start-1'): array
    {
        return $this->startCountUseCase->execute($input, $key, self::ACTOR);
    }

    /**
     * Cuenta todas las unidades de `resultados` y completa el conteo.
     *
     * @param array<string, float> $resultados unidad_id => cantidad_encontrada
     * @return string id del conteo COMPLETADO
     */
    private function completedCount(string $locId, array $resultados): string
    {
        $inicio = $this->start(['ubicacion_id' => $locId], 'cnt-' . uniqid('', true));
        $this->assertSame(201, $inicio['status']);
        $countId = $inicio['data']['conteo']['conteo_id'];

        $filas = [];
        foreach ($resultados as $unidadId => $cantidad) {
            $filas[] = ['unidad_fisica_id' => $unidadId, 'cantidad_encontrada' => $cantidad];
        }

        $registrados = $this->recordCountResultsUseCase->execute(
            $countId,
            ['resultados' => $filas],
            self::ACTOR
        );
        $this->assertSame(200, $registrados['status']);

        $completado = $this->completeCountUseCase->execute($countId, self::ACTOR);
        $this->assertSame(200, $completado['status']);

        return $countId;
    }

    /**
     * @param array<string, mixed> $acciones
     * @return array<string, mixed>
     */
    private function reconcile(string $countId, array $acciones, ?string $key = 'cnt-rec-1'): array
    {
        return $this->reconcileCountUseCase->execute(
            ['conteo_id' => $countId, 'acciones' => $acciones],
            $key,
            self::ACTOR_AUTOR
        );
    }

    // ====================================================================
    // INICIO DEL CONTEO
    // ====================================================================

    public function testIniciarConteoCreaLaListaTeoricaDeLaUbicacion(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $b = $this->seedUnit(self::T1, $loc, 2);
        $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 7);

        $result = $this->start(['ubicacion_id' => $loc]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(InventoryCount::ESTADO_PENDIENTE, $result['data']['conteo']['estado']);
        $this->assertSame($loc, $result['data']['conteo']['ubicacion_id']);
        $this->assertSame(2, $result['data']['unidades_en_lista']);
        $this->assertSame(1, $this->countRows('inventory_counts'));

        $fila = $this->row('SELECT * FROM inventory_counts WHERE id = ?', [
            $result['data']['conteo']['conteo_id'],
        ]);
        $this->assertSame(InventoryCount::ESTADO_PENDIENTE, $fila['estado']);
        $this->assertSame(self::ACTOR, $fila['usuario_responsable_id']);

        $lista = json_decode((string) $fila['lista_teorica'], true);
        $this->assertCount(2, $lista);
        $porUnidad = [];
        foreach ($lista as $entrada) {
            $porUnidad[$entrada['unidad_fisica_id']] = $entrada;
        }
        $this->assertEqualsWithDelta(5.0, (float) $porUnidad[$a]['cantidad_esperada'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $porUnidad[$b]['cantidad_esperada'], 0.0001);
        $this->assertSame('LOC-01', $porUnidad[$a]['ubicacion_codigo']);
    }

    public function testIniciarConteoSinUbicacionCuentaTodoElTenant(): void
    {
        $this->seedUnit(self::T1, $this->loc('LOC-01'), 1);
        $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 2);
        // Unidad sin ubicación (p.ej. ENTREGADO): no está presente
        $this->seedUnit(self::T1, null, 3, 'ENTREGADO');
        $this->seedUnit(self::T2, $this->loc('LOC-T2', self::T2), 9);

        $result = $this->start([]);

        $this->assertSame(201, $result['status']);
        // Sólo las 2 unidades presentes del tenant 1 (sin transito, sin
        // unidades sin ubicación y sin las de otro tenant)
        $this->assertSame(2, $result['data']['unidades_en_lista']);
    }

    public function testIniciarConteoValidaLaUbicacion(): void
    {
        try {
            $this->start(['ubicacion_id' => $this->loc('LOC-OFF')]);
            $this->fail('Se esperaba 422 por ubicación inactiva');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $this->start(['ubicacion_id' => $this->loc('LOC-TRN')]);
            $this->fail('Se esperaba 422 por ubicación virtual');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $this->start(['ubicacion_id' => $this->loc('LOC-T2', self::T2)]);
            $this->fail('Se esperaba 404 por ubicación de otro tenant');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('inventory_counts'));
    }

    public function testIniciarConteoRepetidoConLaMismaClaveEsUnReplay(): void
    {
        $this->seedUnit(self::T1, $this->loc('LOC-01'), 4);
        $input = ['ubicacion_id' => $this->loc('LOC-01')];

        $primera = $this->start($input, 'same-start-key');
        $segunda = $this->start($input, 'same-start-key');

        $this->assertSame(201, $primera['status']);
        $this->assertTrue($segunda['_replay']);
        $this->assertSame($primera['data'], $segunda['data']);
        $this->assertSame(1, $this->countRows('inventory_counts'));
    }

    // ====================================================================
    // RESULTADOS Y COMPLETADO
    // ====================================================================

    public function testRegistrarResultadosPasaAEstadoEnCursoYSeFusiona(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $b = $this->seedUnit(self::T1, $loc, 3);

        $inicio = $this->start(['ubicacion_id' => $loc]);
        $countId = $inicio['data']['conteo']['conteo_id'];

        $parcial = $this->recordCountResultsUseCase->execute($countId, [
            'resultados' => [['unidad_fisica_id' => $a, 'cantidad_encontrada' => 5]],
        ], self::ACTOR);

        $this->assertSame(200, $parcial['status']);
        $this->assertSame(InventoryCount::ESTADO_EN_CURSO, $parcial['data']['conteo']['estado']);
        $this->assertSame(1, $parcial['data']['unidades_contadas']);
        $this->assertSame([$b], $parcial['data']['pendientes']);

        // Fusiona sin tocar la unidad registrada antes
        $segundo = $this->recordCountResultsUseCase->execute($countId, [
            'resultados' => [
                ['unidad_fisica_id' => $b, 'cantidad_encontrada' => 3],
                ['unidad_fisica_id' => $a, 'cantidad_encontrada' => 5, 'notas' => 'correcto'],
            ],
        ], self::ACTOR);

        $this->assertSame(2, $segundo['data']['unidades_contadas']);
        $this->assertSame([], $segundo['data']['pendientes']);

        $detalle = $this->countQuery->detail(self::T1, $countId);
        $this->assertSame(InventoryCount::ESTADO_EN_CURSO, $detalle['estado']);
        $this->assertCount(2, $detalle['resultados']);
    }

    public function testRegistrarUnaUnidadFueraDeLaHojaEs422(): void
    {
        $loc = $this->loc('LOC-01');
        $dentro = $this->seedUnit(self::T1, $loc, 1);
        $fuera = $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 1);

        $countId = $this->start(['ubicacion_id' => $loc])['data']['conteo']['conteo_id'];

        try {
            $this->recordCountResultsUseCase->execute($countId, [
                'resultados' => [
                    ['unidad_fisica_id' => $dentro, 'cantidad_encontrada' => 1],
                    ['unidad_fisica_id' => $fuera, 'cantidad_encontrada' => 1],
                ],
            ], self::ACTOR);
            $this->fail('Se esperaba 422 por unidad fuera de la lista teórica');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('unidad_fisica_id', $e->getField());
        }

        $fila = $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId]);
        $this->assertSame(InventoryCount::ESTADO_PENDIENTE, $fila['estado']);
    }

    public function testCompletarSinTodosLosResultadosEs422(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $b = $this->seedUnit(self::T1, $loc, 3);

        $countId = $this->start(['ubicacion_id' => $loc])['data']['conteo']['conteo_id'];
        $this->recordCountResultsUseCase->execute($countId, [
            'resultados' => [['unidad_fisica_id' => $a, 'cantidad_encontrada' => 5]],
        ], self::ACTOR);

        try {
            $this->completeCountUseCase->execute($countId, self::ACTOR);
            $this->fail('Se esperaba 422 por unidades sin resultado');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('resultados', $e->getField());
            $this->assertStringContainsString($b, $e->getMessage());
        }

        $fila = $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId]);
        $this->assertSame(InventoryCount::ESTADO_EN_CURSO, $fila['estado']);
    }

    public function testCompletarCalculaLasDiscrepanciasYIgnoraLasQueCoinciden(): void
    {
        $loc = $this->loc('LOC-01');
        $igual = $this->seedUnit(self::T1, $loc, 5);
        $falta = $this->seedUnit(self::T1, $loc, 3);

        $countId = $this->completedCount($loc, [$igual => 5, $falta => 2]);

        $fila = $this->row('SELECT * FROM inventory_counts WHERE id = ?', [$countId]);
        $this->assertSame(InventoryCount::ESTADO_COMPLETADO, $fila['estado']);

        $discrepancias = json_decode((string) $fila['discrepancias'], true);
        $this->assertCount(1, $discrepancias);
        $this->assertSame($falta, $discrepancias[0]['unidad_fisica_id']);
        $this->assertEqualsWithDelta(3.0, (float) $discrepancias[0]['cantidad_esperada'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $discrepancias[0]['cantidad_encontrada'], 0.0001);
        $this->assertEqualsWithDelta(-1.0, (float) $discrepancias[0]['diferencia'], 0.0001);
    }

    public function testCompletarUnConteoYaCompletadoEs422(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 5]);

        try {
            $this->completeCountUseCase->execute($countId, self::ACTOR);
            $this->fail('Se esperaba 422 al completar dos veces');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ====================================================================
    // RECONCILIACIÓN
    // ====================================================================

    public function testReconciliarExigeLaClaveDeIdempotencia(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 4]);

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR],
            ], null);
            $this->fail('Se esperaba 400 por Idempotency-Key obligatoria');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('idempotency-key', $e->getField());
        }
    }

    public function testAjustarActualizaUnidadLedgerYEventoConHash(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 3]);

        $result = $this->reconcile($countId, [
            ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame(InventoryCount::ESTADO_RECONCILIADO, $result['data']['conteo']['estado']);
        $this->assertSame(0, $result['data']['discrepancias_pendientes']);
        $this->assertSame(StockEvent::TIPO_AJUSTE_CANTIDAD, $result['data']['eventos'][0]['tipo_evento']);
        $this->assertNotEmpty($result['data']['eventos'][0]['hash_integridad']);
        $this->assertSame(self::ACTOR_AUTOR, $result['data']['acciones_aplicadas'][0]['actor_autoriza']);
        $this->assertSame(3.0, $result['data']['acciones_aplicadas'][0]['cantidad_ajustada']);

        $unidad = $this->row('SELECT * FROM stock_units WHERE id = ?', [$a]);
        $this->assertEqualsWithDelta(3.0, (float) $unidad['cantidad'], 0.0001);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$a]);
        $this->assertEqualsWithDelta(3.0, (float) $ledger['cantidad_fisica'], 0.0001);

        $event = $this->row("SELECT * FROM stock_events WHERE tipo_evento = 'AJUSTE_CANTIDAD'");
        $this->assertNotNull($event);
        $this->assertNotEmpty($event['hash_integridad']);
        $datos = json_decode((string) $event['datos_operacion'], true);
        $this->assertSame($countId, $datos['conteo_id']);
        $this->assertSame($a, $datos['unidad_fisica_id']);

        $fila = $this->row('SELECT * FROM inventory_counts WHERE id = ?', [$countId]);
        $ajustes = json_decode((string) $fila['ajustes_aplicados'], true);
        $this->assertCount(1, $ajustes);
        $this->assertSame(InventoryCount::ACCION_AJUSTAR, $ajustes[0]['accion']);
        $this->assertNotEmpty($ajustes[0]['fecha']);
    }

    public function testAjustarConCantidadCeroMarcaPerdidoYCreaLaIncidencia(): void
    {
        $loc = $this->loc('LOC-PICK');
        $a = $this->seedUnit(self::T1, $loc, 6);
        $this->setCapacidadUsada($loc, 6);
        $countId = $this->completedCount($loc, [$a => 0]);

        $result = $this->reconcile($countId, [
            [
                'unidad_fisica_id' => $a,
                'accion' => InventoryCount::ACCION_AJUSTAR,
                'justificacion' => 'No aparece en el recuento físico.',
            ],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['data']['incidencias']);
        $this->assertSame(
            'INVENTARIO_DESAPARECIDO',
            $result['data']['incidencias'][0]['tipo']
        );

        $unidad = $this->row('SELECT * FROM stock_units WHERE id = ?', [$a]);
        $this->assertSame('PERDIDO', $unidad['estado']);
        $this->assertNull($unidad['ubicacion_id']);

        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc]);
        $this->assertSame(0, (int) $zona['capacidad_usada']);

        $incidencia = $this->row("SELECT * FROM incidents WHERE tipo = 'INVENTARIO_DESAPARECIDO'");
        $this->assertSame($a, $incidencia['unidad_fisica_id']);
        $this->assertSame(InventoryCount::ESTADO_RECONCILIADO, $this->row(
            'SELECT estado FROM inventory_counts WHERE id = ?',
            [$countId]
        )['estado']);
    }

    // v1.9.1: AJUSTAR no se aplica sobre datos obsoletos. La hoja
    // (`cantidad_esperada`) es una instantánea al iniciar el conteo: si la
    // cantidad real de la unidad cambió después, responde 422 con el detalle
    // (unidad, esperada y actual) y no toca nada; hay que JUSTIFICAR o
    // iniciar un conteo nuevo.
    public function testAjustarConLaHojaVencidaEs422ConDetalleYSePuedeJustificar(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 4]);

        // La unidad cambia DESPUÉS de la instantánea de la hoja
        $this->pdo->prepare('UPDATE stock_units SET cantidad = 7 WHERE id = ?')
            ->execute([$a]);

        $eventosAntes = $this->countRows('stock_events');

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR],
            ], 'rec-stale-1');
            $this->fail('Se esperaba 422 por hoja vencida');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(InventoryException::CATEGORY_BUSINESS, $e->getCategory());
            $this->assertSame('cantidad', $e->getField());
            $this->assertStringContainsString($a, $e->getMessage());
            $this->assertStringContainsString('cantidad_esperada 5.0000', $e->getMessage());
            $this->assertStringContainsString('cantidad actual 7.0000', $e->getMessage());
            $this->assertStringContainsString('JUSTIFICAR', $e->getMessage());
            $this->assertStringContainsString('conteo nuevo', $e->getMessage());
        }

        // Nada se tocó: unidad, eventos, incidencias y conteo intactos
        $this->assertSame(
            7.0,
            (float) $this->row('SELECT cantidad FROM stock_units WHERE id = ?', [$a])['cantidad']
        );
        $this->assertSame($eventosAntes, $this->countRows('stock_events'));
        $this->assertSame(0, $this->countRows('incidents'));
        $this->assertSame(
            InventoryCount::ESTADO_COMPLETADO,
            $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId])['estado']
        );

        // JUSTIFICAR sigue permitido con la hoja vencida
        $justificada = $this->reconcile($countId, [
            [
                'unidad_fisica_id' => $a,
                'accion' => InventoryCount::ACCION_JUSTIFICAR,
                'justificacion' => 'Unidad recontada fuera del sistema durante el conteo.',
            ],
        ], 'rec-stale-2');

        $this->assertSame(200, $justificada['status']);
        $this->assertSame(InventoryCount::ESTADO_RECONCILIADO, $justificada['data']['conteo']['estado']);
        $this->assertSame(
            7.0,
            (float) $this->row('SELECT cantidad FROM stock_units WHERE id = ?', [$a])['cantidad']
        );
    }

    public function testAjustarUnaUnidadEnEstadoNoPermitidoEs422YSePuedeJustificar(): void
    {
        $loc = $this->loc('LOC-01');
        $reservada = $this->seedUnit(self::T1, $loc, 5, 'RESERVADO');
        $countId = $this->completedCount($loc, [$reservada => 4]);

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $reservada, 'accion' => InventoryCount::ACCION_AJUSTAR],
            ], 'rec-state-1');
            $this->fail('Se esperaba 422 por estado no permitido para AJUSTAR');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }

        $this->assertSame('RESERVADO', $this->row('SELECT estado FROM stock_units WHERE id = ?', [$reservada])['estado']);
        $this->assertSame(
            InventoryCount::ESTADO_COMPLETADO,
            $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId])['estado']
        );

        $justificada = $this->reconcile($countId, [
            [
                'unidad_fisica_id' => $reservada,
                'accion' => InventoryCount::ACCION_JUSTIFICAR,
                'justificacion' => 'Unidad reservada pendiente de expedición.',
            ],
        ], 'rec-state-2');

        $this->assertSame(200, $justificada['status']);
        $this->assertSame(InventoryCount::ESTADO_RECONCILIADO, $justificada['data']['conteo']['estado']);
        $this->assertSame('RESERVADO', $this->row('SELECT estado FROM stock_units WHERE id = ?', [$reservada])['estado']);
    }

    public function testJustificarSinJustificacionEs422YSinAccionesEs400(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 4]);

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_JUSTIFICAR],
            ], 'rec-just-1');
            $this->fail('Se esperaba 422 por justificación obligatoria');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('justificacion', $e->getField());
        }

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => 'DESTRUIR'],
            ], 'rec-just-2');
            $this->fail('Se esperaba 400 por acción desconocida');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('accion', $e->getField());
        }
    }

    public function testReconciliarUnConteoNoCompletadoEs422(): void
    {
        $loc = $this->loc('LOC-01');
        $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->start(['ubicacion_id' => $loc])['data']['conteo']['conteo_id'];

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => 'unit-x', 'accion' => InventoryCount::ACCION_AJUSTAR],
            ]);
            $this->fail('Se esperaba 422 por estado del conteo');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testReconciliarExcedeLaCapacidadDeLaUbicacionEs422(): void
    {
        // LOC-FULL tiene capacidad 5 y está llena: subir la cantidad de la
        // unidad a 10 excede el cupo (SUM(CEIL(cantidad))).
        $loc = $this->loc('LOC-FULL');
        $a = $this->seedUnit(self::T1, $loc, 1);
        $this->setCapacidadUsada($loc, 1);
        $countId = $this->completedCount($loc, [$a => 10]);

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR],
            ]);
            $this->fail('Se esperaba 422 por capacidad excedida');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('capacidad', $e->getField());
        }

        $this->assertEqualsWithDelta(1.0, (float) $this->scalar(
            'SELECT cantidad FROM stock_units WHERE id = ?',
            [$a]
        ), 0.0001);
        $this->assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM stock_events WHERE tipo_evento = 'AJUSTE_CANTIDAD'"
        ));
        $this->assertSame(
            InventoryCount::ESTADO_COMPLETADO,
            $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId])['estado']
        );
    }

    // ====================================================================
    // IDEMPOTENCIA (R7), ATOMICIDAD (R5) Y AISLAMIENTO
    // ====================================================================

    public function testRepetirLaReconciliacionConLaMismaClaveEsUnReplay(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->completedCount($loc, [$a => 2]);

        $acciones = [['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR]];
        $primera = $this->reconcile($countId, $acciones, 'rec-replay-1');
        $eventosTrasPrimera = $this->countRows('stock_events');

        $segunda = $this->reconcile($countId, $acciones, 'rec-replay-1');

        $this->assertSame(200, $primera['status']);
        $this->assertTrue($segunda['_replay']);
        $this->assertSame($primera['data'], $segunda['data']);
        $this->assertSame($eventosTrasPrimera, $this->countRows('stock_events'));
        $this->assertEqualsWithDelta(2.0, (float) $this->scalar(
            'SELECT cantidad FROM stock_units WHERE id = ?',
            [$a]
        ), 0.0001);
    }

    public function testLaReconciliacionEsAtomicaSiUnaAccionFallida(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $b = $this->seedUnit(self::T1, $loc, 4);
        $countId = $this->completedCount($loc, [$a => 3, $b => 4]);

        $eventosAntes = $this->countRows('stock_events');

        try {
            $this->reconcile($countId, [
                ['unidad_fisica_id' => $a, 'accion' => InventoryCount::ACCION_AJUSTAR],
                // 'b' no tiene discrepancia: falla y debe deshacer lo anterior
                ['unidad_fisica_id' => $b, 'accion' => InventoryCount::ACCION_AJUSTAR],
            ], 'rec-atomic-1');
            $this->fail('Se esperaba 400 por unidad sin discrepancia');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }

        $this->assertEqualsWithDelta(5.0, (float) $this->scalar(
            'SELECT cantidad FROM stock_units WHERE id = ?',
            [$a]
        ), 0.0001);
        $this->assertSame($eventosAntes, $this->countRows('stock_events'));
        $this->assertSame(
            InventoryCount::ESTADO_COMPLETADO,
            $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId])['estado']
        );
        $this->assertSame(
            [],
            json_decode((string) $this->scalar(
                'SELECT ajustes_aplicados FROM inventory_counts WHERE id = ?',
                [$countId]
            ), true)
        );
    }

    public function testLosConteosDeOtroTenantNoSeVenNiSePuedenTocar(): void
    {
        $loc = $this->loc('LOC-01');
        $a = $this->seedUnit(self::T1, $loc, 5);
        $countId = $this->start(['ubicacion_id' => $loc])['data']['conteo']['conteo_id'];

        $this->useTenant(self::T2);

        $query = new CountQueryService($this->pdo);
        $listado = $query->list(self::T2, []);
        $this->assertSame(0, $listado['summary']['total']);

        try {
            $query->detail(self::T2, $countId);
            $this->fail('Se esperaba 404 al detalle de otro conteo');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $this->recordCountResultsUseCase->execute($countId, [
                'resultados' => [['unidad_fisica_id' => $a, 'cantidad_encontrada' => 5]],
            ], self::ACTOR);
            $this->fail('Se esperaba 404 al registrar resultados en otro conteo');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $this->startCountUseCase->execute(['ubicacion_id' => $loc], 'otro-tenant', self::ACTOR);
            $this->fail('Se esperaba 404 al iniciar un conteo en ubicación ajena');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // El conteo del tenant 1 sigue intacto
        $this->useTenant(self::T1);
        $this->assertSame(
            InventoryCount::ESTADO_PENDIENTE,
            $this->row('SELECT estado FROM inventory_counts WHERE id = ?', [$countId])['estado']
        );
        $this->assertSame(1, $this->countRows('inventory_counts'));
    }
}
