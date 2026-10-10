<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\Entities\Incident;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Application\Services\IncidentQueryService;
use Tests\Support\InventorySqliteTestCase;

/**
 * CU-010: REPORTAR / INVESTIGAR / RESOLVER INCIDENCIAS (Sprint 9, v1.9.0).
 *
 * Cubre: aislamiento entre tenants, transiciones de la incidencia
 * (ABIERTA → INVESTIGACION → RESUELTA/NO_RESUELTO), DAÑO con cambio de
 * estado sin tocar el cupo, PERDIDA con liberación del cupo por presencia
 * física, DISCREPANCIA_CANTIDAD que crea y vincula un conteo físico,
 * estados no permitidos (422), validaciones de entrada (400/404), replay
 * idempotente (R7) y atomicidad de la transacción (R5).
 */
final class IncidentReportUseCaseTest extends InventorySqliteTestCase
{
    private const ACTOR = 'usuario-10';
    private const ACTOR_OTRO = 'usuario-20';

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function loc(string $codigo, string $tenantId = self::T1): string
    {
        return 'loc-' . strtolower($codigo) . '-' . substr($tenantId, 0, 5);
    }

    /**
     * Unidad física + su ledger (la BD de tests no tiene FKs).
     */
    private function seedUnit(
        string $tenantId,
        string $ubicacionId,
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

    private function unidadRow(string $id): ?array
    {
        return $this->row('SELECT * FROM stock_units WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function report(array $input, ?string $key = 'inc-key-1'): array
    {
        return $this->reportIncidentUseCase->execute($input, $key, self::ACTOR);
    }

    // ====================================================================
    // DAÑO
    // ====================================================================

    public function testReportarDanoCambiaLaUnidadAYDañadoSinTocarElCupo(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 3, 'DISPONIBLE');
        $this->setCapacidadUsada($loc, 3);

        $result = $this->report([
            'tipo' => Incident::TIPO_DANO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'La estantería cayó sobre la unidad.',
            'observaciones' => 'Revisado in situ.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(Incident::ESTADO_ABIERTA, $result['data']['incidencia']['estado']);
        $this->assertSame('DAÑADO', $result['data']['unidad']['estado']);
        $this->assertSame(StockEvent::TIPO_DANADO, $result['data']['eventos'][0]['tipo_evento']);
        $this->assertNotEmpty($result['data']['eventos'][0]['hash_integridad']);
        $this->assertSame('Revisado in situ.', $result['data']['incidencia']['observaciones']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('DAÑADO', $fila['estado']);
        // La ubicación NO cambia: la unidad sigue presente (capacidad intacta)
        $this->assertSame($loc, $fila['ubicacion_id']);

        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc]);
        $this->assertSame(3, (int) $zona['capacidad_usada']);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit]);
        $this->assertSame('DAÑADO', $ledger['estado_actual']);
        $this->assertEqualsWithDelta(3.0, (float) $ledger['cantidad_fisica'], 0.0001);

        $event = $this->row('SELECT * FROM stock_events WHERE unidad_fisica_id = ?', [$unit]);
        $this->assertSame(StockEvent::TIPO_DANADO, $event['tipo_evento']);
        $this->assertNotEmpty($event['hash_integridad']);
        $this->assertSame('DISPONIBLE', $event['estado_anterior']);
        $this->assertSame('DAÑADO', $event['estado_posterior']);

        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [
            $result['data']['incidencia']['incidencia_id'],
        ]);
        $this->assertSame(Incident::ESTADO_ABIERTA, $incidencia['estado']);
        $this->assertSame($unit, $incidencia['unidad_fisica_id']);
        $this->assertSame(self::ACTOR, $incidencia['actor_id_reporta']);
    }

    // ====================================================================
    // PERDIDA
    // ====================================================================

    public function testReportarPerdidaDejaLaUnidadPerdidoYSueltaElCupo(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 4, 'DISPONIBLE');
        $this->setCapacidadUsada($loc, 4);

        $result = $this->report([
            'tipo' => Incident::TIPO_PERDIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'La unidad desapareció del almacén.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('PERDIDO', $result['data']['unidad']['estado']);
        $this->assertNull($result['data']['unidad']['ubicacion_id']);
        $this->assertSame(StockEvent::TIPO_PERDIDO, $result['data']['eventos'][0]['tipo_evento']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('PERDIDO', $fila['estado']);
        $this->assertNull($fila['ubicacion_id']);

        // El cupo se libera con el criterio de presencia física
        $zona = $this->row('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc]);
        $this->assertSame(0, (int) $zona['capacidad_usada']);

        $ledger = $this->row('SELECT * FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit]);
        $this->assertSame('PERDIDO', $ledger['estado_actual']);
    }

    public function testReportarPerdidaDeUnaUnidadYaPerdidaEs422(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 1, 'PERDIDO');

        try {
            $this->report([
                'tipo' => Incident::TIPO_PERDIDA,
                'unidad_fisica_id' => $unit,
                'descripcion' => 'Duplicado.',
            ]);
            $this->fail('Se esperaba 422 por transición terminal');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('incidents'));
    }

    // ====================================================================
    // DISCREPANCIA_CANTIDAD → CREA CONTEO VINCULADO
    // ====================================================================

    public function testReportarDiscrepanciaCreaElConteoFisicoVinculado(): void
    {
        $loc = $this->loc('LOC-01');
        $unit = $this->seedUnit(self::T1, $loc, 5, 'DISPONIBLE');

        $result = $this->report([
            'tipo' => Incident::TIPO_DISCREPANCIA_CANTIDAD,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'El recuento no coincide con el sistema.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertNotNull($result['data']['conteo']);
        $this->assertSame('PENDIENTE', $result['data']['conteo']['estado']);
        $this->assertSame($loc, $result['data']['conteo']['ubicacion_id']);
        $this->assertSame(1, $result['data']['conteo']['unidades_lista']);
        $this->assertSame(
            $result['data']['conteo']['conteo_id'],
            $result['data']['incidencia']['inventory_count_id']
        );

        // Fila real en inventory_counts + FK lógica en incidents
        $this->assertSame(1, $this->countRows('inventory_counts'));
        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [
            $result['data']['incidencia']['incidencia_id'],
        ]);
        $this->assertSame($result['data']['conteo']['conteo_id'], $incidencia['inventory_count_id']);

        $conteo = $this->row('SELECT * FROM inventory_counts WHERE id = ?', [
            $result['data']['conteo']['conteo_id'],
        ]);
        $lista = json_decode((string) $conteo['lista_teorica'], true);
        $this->assertSame($unit, $lista[0]['unidad_fisica_id']);
        $this->assertEqualsWithDelta(5.0, (float) $lista[0]['cantidad_esperada'], 0.0001);

        // El tipo de discrepancia no altera estados de unidad
        $this->assertSame('DISPONIBLE', $this->unidadRow($unit)['estado']);
    }

    public function testReportarDiscrepanciaSinUbicacionEs400(): void
    {
        try {
            $this->report([
                'tipo' => Incident::TIPO_DISCREPANCIA_CANTIDAD,
                'descripcion' => 'Sin ubicación.',
            ]);
            $this->fail('Se esperaba 400 por ubicacion_id obligatoria');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('ubicacion_id', $e->getField());
        }

        $this->assertSame(0, $this->countRows('inventory_counts'));
        $this->assertSame(0, $this->countRows('incidents'));
    }

    // ====================================================================
    // TIPOS SIN EFECTO + VALIDACIONES DE ENTRADA
    // ====================================================================

    public function testUnTipoSinEfectoSoloCreaLaIncidenciaAbierta(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 2, 'DISPONIBLE');

        $result = $this->report([
            'tipo' => Incident::TIPO_OTRO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Revisión general.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(Incident::ESTADO_ABIERTA, $result['data']['incidencia']['estado']);
        $this->assertSame('DISPONIBLE', $this->unidadRow($unit)['estado']);
        // El evento se crea con el nombre genérico de la spec
        $this->assertSame('INCIDENCIA', $result['data']['eventos'][0]['tipo_evento']);
    }

    public function testTipoDesconocidoDevuelve400(): void
    {
        try {
            $this->report(['tipo' => 'FANTASMA', 'descripcion' => 'x']);
            $this->fail('Se esperaba 400 por tipo desconocido');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('tipo', $e->getField());
        }
    }

    public function testSinDescripcionDevuelve400(): void
    {
        try {
            $this->report(['tipo' => Incident::TIPO_DANO]);
            $this->fail('Se esperaba 400 por descripcion obligatoria');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('descripcion', $e->getField());
        }
    }

    public function testUnidadInexistenteDevuelve404(): void
    {
        try {
            $this->report([
                'tipo' => Incident::TIPO_DANO,
                'unidad_fisica_id' => 'unit-que-no-existe',
                'descripcion' => 'x',
            ]);
            $this->fail('Se esperaba 404 por unidad inexistente');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('incidents'));
    }

    // ====================================================================
    // AISLAMIENTO ENTRE TENANTS
    // ====================================================================

    public function testNoSePuedeReportarSobreUnaUnidadDeOtroTenant(): void
    {
        $unitT2 = $this->seedUnit(self::T2, $this->loc('LOC-T2', self::T2), 3, 'DISPONIBLE');
        $this->useTenant(self::T1);

        try {
            $this->report([
                'tipo' => Incident::TIPO_DANO,
                'unidad_fisica_id' => $unitT2,
                'descripcion' => 'Intento cruzado.',
            ]);
            $this->fail('Se esperaba 404 por aislamiento de tenant');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(0, $this->countRows('incidents'));
    }

    // v1.9.1: PERDIDA pasa por assertCanTransition (matriz §4 + desviación
    // DISPONIBLE → PERDIDO). Los orígenes no contemplados en la spec
    // rechazan con 422 y no tocan nada.
    public function testReportarPerdidaDesdeUnEstadoNoContempladoEs422(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 2, 'EN_TRANSITO');

        try {
            $this->report([
                'tipo' => Incident::TIPO_PERDIDA,
                'unidad_fisica_id' => $unit,
                'descripcion' => 'Unidad en tránsito: no se puede marcar perdida desde este estado.',
            ], 'inc-key-perdida-en-transito');
            $this->fail('Se esperaba 422 por transición fuera de la matriz');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('EN_TRANSITO', $e->getMessage());
            $this->assertStringContainsString('PERDIDO', $e->getMessage());
        }

        $fila = $this->unidadRow($unit);
        $this->assertSame('EN_TRANSITO', $fila['estado']);
        $this->assertSame($this->loc('LOC-01'), $fila['ubicacion_id']);
        $this->assertSame(0, $this->countRows('incidents'));
        $this->assertSame(0, $this->countRows('stock_events'));
    }

    public function testReportarPerdidaDesdeDanadoEstaPermitida(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 3, 'DAÑADO');

        $result = $this->report([
            'tipo' => Incident::TIPO_PERDIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'La unidad dañada desapareció de la zona de dañados.',
        ], 'inc-key-perdida-desde-danado');

        $this->assertSame(201, $result['status']);
        $this->assertSame('PERDIDO', $result['data']['unidad']['estado']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('PERDIDO', $fila['estado']);
        $this->assertNull($fila['ubicacion_id']);
    }


    public function testLasIncidenciasDeOtroTenantNoSeVenNiSePuedenInvestigar(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 1, 'DISPONIBLE');
        $creada = $this->report([
            'tipo' => Incident::TIPO_DANO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Del tenant 1.',
        ]);
        $incidenciaId = $creada['data']['incidencia']['incidencia_id'];

        $this->useTenant(self::T2);

        $query = new IncidentQueryService($this->pdo);
        $listado = $query->list(self::T2, []);
        $this->assertSame(0, $listado['summary']['total']);

        try {
            $query->detail(self::T2, $incidenciaId);
            $this->fail('Se esperaba 404 al detalle de otra incidencia');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $this->investigateIncidentUseCase->execute($incidenciaId, null, self::ACTOR_OTRO);
            $this->fail('Se esperaba 404 al investigar otra incidencia');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // La incidencia del tenant 1 sigue intacta
        $this->useTenant(self::T1);
        $fila = $this->row('SELECT estado FROM incidents WHERE id = ?', [$incidenciaId]);
        $this->assertSame(Incident::ESTADO_ABIERTA, $fila['estado']);
    }

    // ====================================================================
    // TRANSICIONES: INVESTIGAR / RESOLVER
    // ====================================================================

    public function testLaIncidenciaPasaDeAbiertaAInvestigacionYSeResuelve(): void
    {
        $creada = $this->report([
            'tipo' => Incident::TIPO_DANO,
            'descripcion' => 'Daño en almacén.',
        ]);
        $id = $creada['data']['incidencia']['incidencia_id'];

        $investigada = $this->investigateIncidentUseCase->execute($id, 'inv-key-1', self::ACTOR);
        $this->assertSame(200, $investigada['status']);
        $this->assertSame(Incident::ESTADO_INVESTIGACION, $investigada['data']['incidencia']['estado']);
        $this->assertSame(
            Incident::ESTADO_INVESTIGACION,
            $this->row('SELECT estado FROM incidents WHERE id = ?', [$id])['estado']
        );

        $resuelta = $this->resolveIncidentUseCase->execute($id, [
            'estado' => Incident::ESTADO_RESUELTA,
            'descripcion_resolucion' => 'Unidad retirada del servicio.',
            'acciones_tomadas' => 'Se dio de baja la unidad.',
            'compensacion' => 'Reposición por compra.',
        ], 'res-key-1', self::ACTOR);

        $this->assertSame(200, $resuelta['status']);
        $this->assertSame(Incident::ESTADO_RESUELTA, $resuelta['data']['incidencia']['estado']);

        $detalle = $resuelta['data']['resolucion'];
        $this->assertSame(Incident::ESTADO_RESUELTA, $detalle['estado_final']);
        $this->assertSame(self::ACTOR, $detalle['actor_resuelve']);
        $this->assertSame('Unidad retirada del servicio.', $detalle['descripcion_resolucion']);
        $this->assertSame('Se dio de baja la unidad.', $detalle['acciones_tomadas']);
        $this->assertSame('Reposición por compra.', $detalle['compensacion']);
        $this->assertNotEmpty($detalle['fecha_resolucion']);
    }

    public function testSePuedeResolverSinInvestigarYEnEstadoNoResuelto(): void
    {
        $creada = $this->report(['tipo' => Incident::TIPO_OTRO, 'descripcion' => 'Sin evidencias.']);
        $id = $creada['data']['incidencia']['incidencia_id'];

        $resultado = $this->resolveIncidentUseCase->execute($id, [
            'estado' => Incident::ESTADO_NO_RESUELTO,
            'descripcion_resolucion' => 'No se pudo reproducir.',
            'acciones_tomadas' => 'Ninguna.',
        ], null, self::ACTOR);

        $this->assertSame(200, $resultado['status']);
        $this->assertSame(Incident::ESTADO_NO_RESUELTO, $resultado['data']['incidencia']['estado']);
    }

    public function testTransicionesNoPermitidasDevuelven422(): void
    {
        $creada = $this->report(['tipo' => Incident::TIPO_OTRO, 'descripcion' => 'Base.']);
        $id = $creada['data']['incidencia']['incidencia_id'];

        // Cerrar directamente también es válido; probamos el doble cierre
        $this->resolveIncidentUseCase->execute($id, [
            'estado' => Incident::ESTADO_RESUELTA,
            'descripcion_resolucion' => 'Primera.',
            'acciones_tomadas' => 'Acción.',
        ], null, self::ACTOR);

        try {
            $this->resolveIncidentUseCase->execute($id, [
                'estado' => Incident::ESTADO_RESUELTA,
                'descripcion_resolucion' => 'Segunda.',
                'acciones_tomadas' => 'Acción.',
            ], null, self::ACTOR);
            $this->fail('Se esperaba 422 al resolver dos veces');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $this->investigateIncidentUseCase->execute($id, null, self::ACTOR);
            $this->fail('Se esperaba 422 al investigar una incidencia cerrada');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testResolverExigeLosCamposObligatorios(): void
    {
        $creada = $this->report(['tipo' => Incident::TIPO_OTRO, 'descripcion' => 'Base.']);
        $id = $creada['data']['incidencia']['incidencia_id'];

        try {
            $this->resolveIncidentUseCase->execute($id, ['estado' => Incident::ESTADO_RESUELTA], null, self::ACTOR);
            $this->fail('Se esperaba 400 por descripcion_resolucion');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('descripcion_resolucion', $e->getField());
        }

        try {
            $this->resolveIncidentUseCase->execute($id, [
                'estado' => 'CERRADA',
                'descripcion_resolucion' => 'x',
                'acciones_tomadas' => 'y',
            ], null, self::ACTOR);
            $this->fail('Se esperaba 400 por estado final desconocido');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    // ====================================================================
    // IDEMPOTENCIA (R7) Y ATOMICIDAD (R5)
    // ====================================================================

    public function testRepetirElReporteConLaMismaClaveEsUnReplay(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 2, 'DISPONIBLE');
        $input = [
            'tipo' => Incident::TIPO_DANO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Reporte repetido.',
        ];

        $primera = $this->report($input, 'rep-key-1');
        $segunda = $this->report($input, 'rep-key-1');

        $this->assertSame(201, $primera['status']);
        $this->assertTrue($segunda['_replay']);
        $this->assertSame($primera['data'], $segunda['data']);
        $this->assertSame(1, $this->countRows('incidents'));
        $this->assertSame(1, $this->countRows('stock_events'));
    }

    public function testLaTransaccionEsAtomicaSiFallaElEfectoSobreLaUnidad(): void
    {
        // EN_TRANSITO → DAÑADO no está permitida en la matriz §4: el use case
        // guarda la incidencia ANTES de aplicar el efecto y la transacción
        // debe deshacerlo todo (R5).
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-01'), 1, 'EN_TRANSITO');

        $incidenciasAntes = $this->countRows('incidents');
        $eventosAntes = $this->countRows('stock_events');

        try {
            $this->report([
                'tipo' => Incident::TIPO_DANO,
                'unidad_fisica_id' => $unit,
                'descripcion' => 'No debe persistirse.',
            ]);
            $this->fail('Se esperaba 422 por transición no permitida');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame($incidenciasAntes, $this->countRows('incidents'));
        $this->assertSame($eventosAntes, $this->countRows('stock_events'));
        $this->assertSame('EN_TRANSITO', $this->unidadRow($unit)['estado']);
    }

    // ====================================================================
    // v1.10.0 (E4): BLOQUEO AL REPORTAR Y RESOLUCIÓN CON RESULTADO
    // ====================================================================

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolve(string $incidentId, array $input, ?string $key = 'res-e4-1'): array
    {
        return $this->resolveIncidentUseCase->execute(
            $incidentId,
            $input + [
                'estado' => Incident::ESTADO_RESUELTA,
                'descripcion_resolucion' => 'Unidad recuperada.',
                'acciones_tomadas' => 'Revisado el almacén.',
            ],
            $key,
            self::ACTOR
        );
    }

    public function testReportarUbicacionDesconocidaBloqueaLaUnidadSinTocarElCupo(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 3, 'DISPONIBLE');
        $this->setCapacidadUsada($loc, 3);

        $result = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'No aparece en la estantería.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(Incident::PRIORIDAD_NORMAL, $result['data']['incidencia']['prioridad']);
        $this->assertSame('BLOQUEADO', $result['data']['unidad']['estado']);
        $this->assertSame('BLOQUEADO', $result['data']['eventos'][0]['tipo_evento']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('BLOQUEADO', $fila['estado']);
        $this->assertSame($loc, $fila['ubicacion_id']);
        $this->assertSame(
            3,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );
        $this->assertSame(
            'BLOQUEADO',
            $this->row('SELECT estado_actual FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit])['estado_actual']
        );
    }

    public function testReportarInventarioDesaparecidoBloqueaTambienUnaUnidadReservada(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 2, 'RESERVADO');
        $this->setCapacidadUsada($loc, 2);

        $result = $this->report([
            'tipo' => Incident::TIPO_INVENTARIO_DESAPARECIDO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'El recuento no la encuentra.',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('BLOQUEADO', $result['data']['unidad']['estado']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('BLOQUEADO', $fila['estado']);
        $this->assertSame($loc, $fila['ubicacion_id']);
        $this->assertSame(
            2,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );
    }

    public function testReportarUbicacionDesconocidaEnUnidadEntregadoAbreLaIncidenciaSinTocarla(): void
    {
        $loc = $this->loc('LOC-01');
        $unit = $this->seedUnit(self::T1, $loc, 1, 'ENTREGADO');

        $result = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Unidad ya entregada reportada otra vez.',
        ]);

        // ENTREGADO → BLOQUEADO no está en la matriz: la incidencia se abre
        // igualmente y la unidad queda intacta.
        $this->assertSame(201, $result['status']);
        $this->assertSame('ENTREGADO', $result['data']['unidad']['estado']);
        $this->assertSame('INCIDENCIA', $result['data']['eventos'][0]['tipo_evento']);
        $this->assertSame('ENTREGADO', $this->unidadRow($unit)['estado']);
    }

    public function testResolverEncontradaDevuelveLaUnidadADisponible(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 3, 'BLOQUEADO');
        $this->setCapacidadUsada($loc, 3);

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'La unidad no estaba en su sitio.',
        ])['data']['incidencia'];

        $result = $this->resolve($incidencia['incidencia_id'], [
            'resultado' => Incident::RESULTADO_ENCONTRADA,
        ], 'res-e4-encontrada');

        $this->assertSame(200, $result['status']);
        $this->assertSame(Incident::ESTADO_RESUELTA, $result['data']['incidencia']['estado']);
        $this->assertSame(Incident::RESULTADO_ENCONTRADA, $result['data']['unidad']['resultado']);
        $this->assertSame('DISPONIBLE', $result['data']['unidad']['estado']);
        $this->assertSame('DISPONIBLE', $result['data']['unidad']['evento']['tipo_evento']);
        $this->assertNotEmpty($result['data']['unidad']['evento']['hash_integridad']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('DISPONIBLE', $fila['estado']);
        $this->assertSame($loc, $fila['ubicacion_id']);
        $this->assertSame(
            'DISPONIBLE',
            $this->row('SELECT estado_actual FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit])['estado_actual']
        );
        // La presencia física no cambia: el cupo sigue igual
        $this->assertSame(
            3,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );

        $guardada = $this->row('SELECT resolucion FROM incidents WHERE id = ?', [$incidencia['incidencia_id']]);
        $detalle = json_decode((string) $guardada['resolucion'], true);
        $this->assertSame(Incident::RESULTADO_ENCONTRADA, $detalle['resultado']);
        $this->assertSame(self::ACTOR, $detalle['actor_resuelve']);
    }

    public function testResolverEncontradaConDestinoReubicaYRecalculaElCupo(): void
    {
        $origen = $this->loc('LOC-PICK');
        $destino = $this->seedLocation(self::T1, 'LOC-02', null, 0);
        $unit = $this->seedUnit(self::T1, $origen, 4, 'BLOQUEADO');
        $this->setCapacidadUsada($origen, 4);

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_INVENTARIO_DESAPARECIDO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Encontrada en otra zona.',
        ])['data']['incidencia'];

        $result = $this->resolve($incidencia['incidencia_id'], [
            'resultado' => Incident::RESULTADO_ENCONTRADA,
            'ubicacion_destino_id' => $destino,
        ], 'res-e4-reubica');

        $this->assertSame(200, $result['status']);
        $this->assertSame('DISPONIBLE', $result['data']['unidad']['estado']);
        $this->assertSame($destino, $result['data']['unidad']['ubicacion_id']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('DISPONIBLE', $fila['estado']);
        $this->assertSame($destino, $fila['ubicacion_id']);

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$origen])
        );
        $this->assertSame(
            4,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$destino])
        );
    }

    public function testResolverNoEncontradaLaMarcaPerdidaYLiberadoElCupo(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 3, 'BLOQUEADO');
        $this->setCapacidadUsada($loc, 3);

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_INVENTARIO_DESAPARECIDO,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'No se encuentra tras la búsqueda.',
        ])['data']['incidencia'];

        $result = $this->resolve($incidencia['incidencia_id'], [
            'resultado' => Incident::RESULTADO_NO_ENCONTRADA,
        ], 'res-e4-nf');

        $this->assertSame(200, $result['status']);
        $this->assertSame('PERDIDO', $result['data']['unidad']['estado']);
        $this->assertNull($result['data']['unidad']['ubicacion_id']);
        $this->assertSame('PERDIDO', $result['data']['unidad']['evento']['tipo_evento']);

        $fila = $this->unidadRow($unit);
        $this->assertSame('PERDIDO', $fila['estado']);
        $this->assertNull($fila['ubicacion_id']);
        $this->assertSame(
            'PERDIDO',
            $this->row('SELECT estado_actual FROM stock_ledger WHERE unidad_fisica_id = ?', [$unit])['estado_actual']
        );
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT capacidad_usada FROM locations WHERE id = ?', [$loc])
        );
    }

    public function testResolverTipoConUnidadSinResultadoDevuelve400(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 1, 'BLOQUEADO');

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Falta el resultado.',
        ])['data']['incidencia'];

        try {
            $this->resolve($incidencia['incidencia_id'], [], 'res-e4-sin-resultado');
            $this->fail('Sin resultado debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('resultado', $e->getField());
        }

        $this->assertSame(Incident::ESTADO_ABIERTA, $this->row(
            'SELECT estado FROM incidents WHERE id = ?',
            [$incidencia['incidencia_id']]
        )['estado']);
        $this->assertSame('BLOQUEADO', $this->unidadRow($unit)['estado']);
    }

    public function testResolverConResultadoEnTipoSinUnidadDevuelve400(): void
    {
        $result = $this->report([
            'tipo' => Incident::TIPO_DANO,
            'unidad_fisica_id' => null,
            'descripcion' => 'Daño sin unidad asociada.',
        ]);
        $incidenciaId = $result['data']['incidencia']['incidencia_id'];

        try {
            $this->resolve($incidenciaId, [
                'resultado' => Incident::RESULTADO_ENCONTRADA,
            ], 'res-e4-resultado-invalido');
            $this->fail('resultado en tipo DAÑO debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('resultado', $e->getField());
        }
    }

    public function testResolverNoEncontradaConDestinoDevuelve400(): void
    {
        $unit = $this->seedUnit(self::T1, $this->loc('LOC-PICK'), 1, 'BLOQUEADO');

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Destino con NO_ENCONTRADA no admite.',
        ])['data']['incidencia'];

        try {
            $this->resolve($incidencia['incidencia_id'], [
                'resultado' => Incident::RESULTADO_NO_ENCONTRADA,
                'ubicacion_destino_id' => $this->loc('LOC-01'),
            ], 'res-e4-destino-invalido');
            $this->fail('ubicacion_destino_id sin ENCONTRADA debe devolver 400');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('ubicacion_destino_id', $e->getField());
        }
    }

    public function testResolverConResultadoRepiteElEfectoSoloUnaVezEnReplay(): void
    {
        $loc = $this->loc('LOC-PICK');
        $unit = $this->seedUnit(self::T1, $loc, 2, 'BLOQUEADO');
        $this->setCapacidadUsada($loc, 2);

        $incidencia = $this->report([
            'tipo' => Incident::TIPO_UBICACION_DESCONOCIDA,
            'unidad_fisica_id' => $unit,
            'descripcion' => 'Replay idempotente.',
        ])['data']['incidencia'];

        $input = ['resultado' => Incident::RESULTADO_NO_ENCONTRADA];
        $primera = $this->resolve($incidencia['incidencia_id'], $input, 'res-e4-replay');
        $segunda = $this->resolve($incidencia['incidencia_id'], $input, 'res-e4-replay');

        $this->assertSame(200, $primera['status']);
        $this->assertTrue($segunda['_replay']);
        $this->assertSame($primera['data'], $segunda['data']);
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id = ? AND tipo_evento = 'PERDIDO'",
                [$unit]
            )
        );
    }
}
