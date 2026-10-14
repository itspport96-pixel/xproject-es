<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Adapters\Persistence\IncidentRepository;
use App\Modules\Inventory\Adapters\Persistence\LocationRepository;
use App\Modules\Inventory\Adapters\Persistence\StockEventRepository;
use App\Modules\Inventory\Adapters\Persistence\StockLedgerRepository;
use App\Modules\Inventory\Adapters\Persistence\StockUnitRepository;
use App\Modules\Inventory\Application\Services\ReceiveDocumentUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Services\IdempotencyService;
use Tests\Support\InventoryMysqlTestCase;

/**
 * Flujo definitivo de ReceiveDocumentUseCase contra MySQL real (v1.13.32).
 *
 * Reglas que se verifican (una a una):
 *  1. El esperado lo fija el documento: el payload NO admite cantidad_esperada
 *     (ni en la raiz ni por linea) -> 422.
 *  2. Quien planifica no recibe: el creador -> 403 (authorization), tambien ADMIN.
 *  3. Coincidir -> unidad con lo contado + ledger + evento + linea RECIBIDA.
 *  4. Discrepancia -> unidad con lo contado (SIEMPRE) + incidencia
 *     DISCREPANCIA_CANTIDAD (faltante con prioridad por umbral; sobre-recepcion
 *     ALTA); la linea queda cerrada; sin reconteo ni segunda recepcion.
 *  5. rechazo_total -> lineas RECHAZADA sin stock + una incidencia
 *     ENTREGA_RECHAZADA ALTA restringida (ni receptor ni planificador).
 *  6. Concurrencia: la linea ya cerrada (RECIBIDA/RECHAZADA) devuelve 409; las
 *     lineas se bloquean con SELECT ... FOR UPDATE (el perdedor espera el lock
 *     y luego recibe 409). Sin Idempotency-Key duplicado no se procesa dos veces.
 *  7. Documento: PLANIFICADA -> EN_RECEPCION -> CERRADA. ANULADA/CERRADA no se
 *     reciben. Idempotencia y aislamiento por tenant.
 */
final class ReceiveDocumentUseCaseTest extends InventoryMysqlTestCase
{
    private const IDEMPOTENCY_KEY = 'key-test-001';

    // ====================================================================
    // 1. CANTIDAD ESPERADA NO ADMITIDA
    // ====================================================================

    public function testElPayloadNoAdmiteCantidadEsperadaEnLaRaiz(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'cantidad_esperada' => 10,
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por cantidad_esperada en la raiz.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('cantidad_esperada', $e->getField());
        }
    }

    public function testElPayloadNoAdmiteCantidadEsperadaEnUnaLinea(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [[
                        'sku' => 'SKU-ALFA',
                        'cantidad_recibida' => 10,
                        'cantidad_esperada' => 10,
                    ]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por cantidad_esperada en una linea.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('cantidad_esperada', $e->getField());
        }
    }

    // ====================================================================
    // 2. CREADOR NO PUEDE RECIBIR (403)
    // ====================================================================

    public function testElCreadorDelDocumentoNoPuedeRecibirlo(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $planificador
            );
            $this->fail('Se esperaba InventoryException por recepcion del creador.');
        } catch (InventoryException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('authorization', $e->getCategory());
            $this->assertStringContainsString('planific', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?', [$this->tenantId])
        );
    }

    // ====================================================================
    // 3. COINCIDENCIA
    // ====================================================================

    public function testLaCoincidenciaCreaUnidadLedgerYEvento(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [[
                    'sku' => 'SKU-ALFA',
                    'cantidad_recibida' => 10,
                    'lote' => 'L-2026',
                    'numero_serie' => 'SN-001',
                ]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $this->assertSame('RECIBIDO', $resultado['data']['resultado']);
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);
        $this->assertFalse($resultado['data']['rechazo_total']);

        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('SKU-ALFA', $linea['sku']);
        $this->assertSame('RECIBIDA', $linea['estado_linea']);
        $this->assertSame(10.0, (float) $linea['cantidad_recibida']);
        $this->assertNotNull($linea['unidad_fisica_id']);

        $unidadId = $linea['unidad_fisica_id'];
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_units
                  WHERE tenant_id = ? AND id = ? AND estado = 'DISPONIBLE' AND cantidad = 10
                    AND lote = 'L-2026' AND numero_serie = 'SN-001' AND ubicacion_id IS NOT NULL",
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND unidad_fisica_id = ?',
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM stock_events
                  WHERE tenant_id = ? AND unidad_fisica_id = ? AND tipo_evento = 'RECIBIDO'",
                [$this->tenantId, $unidadId]
            )
        );
        $this->assertSame(
            'RECIBIDA',
            $this->scalar('SELECT estado FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );
        $this->assertSame(
            'CERRADA',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );

        $auditoria = $resultado['_auditoria'];
        $this->assertSame($doc['numero_documento'], $auditoria['numero_documento']);
        $this->assertSame('LOC-01', $auditoria['ubicacion_codigo']);
        $this->assertSame($planificador, $auditoria['creado_por']);
        $this->assertSame($receptor, $auditoria['recibido_por']);
        $this->assertSame($linea['line_id'], $auditoria['lineas'][0]['line_id']);
        $this->assertSame(10.0, (float) $auditoria['lineas'][0]['cantidad_esperada']);
        $this->assertSame(10.0, (float) $auditoria['lineas'][0]['cantidad_recibida']);
        $this->assertSame('RECIBIDA', $auditoria['lineas'][0]['resultado']);
    }

    // ====================================================================
    // 4. FALTANTE: UNIDAD CON LO CONTADO + INCIDENCIA
    // ====================================================================

    public function testElFaltanteCreaUnidadConLoContadoEIncidencia(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('RECIBIDA_CON_INCIDENCIA', $linea['estado_linea']);
        $this->assertSame(7.0, (float) $linea['cantidad_recibida']);
        $this->assertNotNull($linea['unidad_fisica_id']);
        $this->assertNotNull($linea['incidencia_id']);
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);

        // El stock refleja SIEMPRE lo contado: entra 7, no 10.
        $unidadId = $linea['unidad_fisica_id'];
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ? AND id = ? AND cantidad = 7',
                [$this->tenantId, $unidadId]
            )
        );

        // La linea se cierra en un unico intento: no hay reconteo.
        $this->assertSame(
            'RECIBIDA',
            $this->scalar('SELECT estado FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );

        $incidenciaId = $linea['incidencia_id'];
        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [$incidenciaId]);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $incidencia['tipo']);
        // Faltante 7/10 = 30 % >= umbral por defecto (5 %) => prioridad ALTA.
        $this->assertSame('ALTA', $incidencia['prioridad']);
        $restriccion = json_decode((string) $incidencia['restriccion_resolucion'], true);
        $this->assertSame($receptor, $restriccion['receptor']);
        $this->assertSame($planificador, $restriccion['planificador']);
    }

    public function testElFaltantePequenoAbreIncidenciaNormal(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 9.9]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [
            $resultado['data']['lineas'][0]['incidencia_id'],
        ]);
        // Faltante 1 % < umbral (5 %) => prioridad NORMAL.
        $this->assertSame('NORMAL', $incidencia['prioridad']);
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ? AND cantidad = 9.9',
                [$this->tenantId]
            )
        );
    }

    // ====================================================================
    // 5. SOBRE-RECEPCION: PRIORIDAD ALTA
    // ====================================================================

    public function testLaSobreRecepcionAbreIncidenciaAltaYEntraTodoElContado(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 15]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('RECIBIDA_CON_INCIDENCIA', $linea['estado_linea']);
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ? AND id = ? AND cantidad = 15',
                [$this->tenantId, $linea['unidad_fisica_id']]
            )
        );

        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [$linea['incidencia_id']]);
        $this->assertSame('DISCREPANCIA_CANTIDAD', $incidencia['tipo']);
        $this->assertSame('ALTA', $incidencia['prioridad']);
    }

    // ====================================================================
    // 6. RESOLUCION RESTRINGIDA A UN TERCERO
    // ====================================================================

    public function testNiElReceptorNiElPlanificadorPuedenResolverLaIncidencia(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 7]],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );
        $incidenciaId = $resultado['data']['lineas'][0]['incidencia_id'];

        $payload = [
            'estado' => 'RESUELTA',
            'descripcion_resolucion' => 'Cierre tras revisar el faltante.',
            'acciones_tomadas' => 'Se recuenta con el proveedor.',
        ];

        try {
            $this->resolveIncidentUseCase->execute(
                $incidenciaId,
                $payload,
                'res-1',
                $receptor
            );
            $this->fail('Se esperaba 422 por restriccion de resolucion (receptor).');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('receptor', $e->getMessage());
        }

        try {
            $this->resolveIncidentUseCase->execute(
                $incidenciaId,
                $payload,
                'res-2',
                $planificador
            );
            $this->fail('Se esperaba 422 por restriccion de resolucion (planificador).');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('receptor', $e->getMessage());
        }

        $tercero = $this->seedUser($this->tenantId);
        $respuesta = $this->resolveIncidentUseCase->execute(
            $incidenciaId,
            $payload,
            'res-3',
            $tercero
        );

        $this->assertSame(200, $respuesta['status']);
        $this->assertSame(
            'RESUELTA',
            $this->scalar('SELECT estado FROM incidents WHERE id = ?', [$incidenciaId])
        );
    }

    // ====================================================================
    // 7. RECHAZO TOTAL
    // ====================================================================

    public function testElRechazoTotalCierraLineaSinStockConIncidenciaAlta(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'rechazo_total' => true,
                'lineas' => [['sku' => 'SKU-ALFA']],
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertSame(200, $resultado['status']);
        $this->assertTrue($resultado['data']['rechazo_total']);
        $this->assertSame('RECHAZADA', $resultado['data']['resultado']);
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);

        $linea = $resultado['data']['lineas'][0];
        $this->assertSame('RECHAZADA', $linea['estado_linea']);
        $this->assertSame(0.0, (float) $linea['cantidad_recibida']);
        $this->assertNull($linea['unidad_fisica_id']);
        $this->assertNotNull($linea['incidencia_id']);

        // Sin stock por el rechazo.
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?', [$this->tenantId])
        );
        $this->assertSame(
            'RECHAZADA',
            $this->scalar('SELECT estado FROM receiving_document_lines WHERE document_id = ?', [$doc['id']])
        );

        $incidencia = $this->row('SELECT * FROM incidents WHERE id = ?', [$linea['incidencia_id']]);
        $this->assertSame('ENTREGA_RECHAZADA', $incidencia['tipo']);
        $this->assertSame('ALTA', $incidencia['prioridad']);
        $this->assertSame('ABIERTA', $incidencia['estado']);
        $restriccion = json_decode((string) $incidencia['restriccion_resolucion'], true);
        $this->assertSame($receptor, $restriccion['receptor']);
        $this->assertSame($planificador, $restriccion['planificador']);
    }

    public function testElRechazoTotalSinLineasRechazaTodasLasPendientes(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $this->seedProduct($this->tenantId, 'SKU-BETA');
        $doc = $this->createReceivingDocument($planificador, 'PO-RECHAZO-DOS', [
            ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10.0],
            ['sku' => 'SKU-BETA', 'cantidad_esperada' => 5.0],
        ]);

        $resultado = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'rechazo_total' => true,
            ],
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertCount(2, $resultado['data']['lineas']);
        foreach ($resultado['data']['lineas'] as $linea) {
            $this->assertSame('RECHAZADA', $linea['estado_linea']);
        }
        $this->assertSame('CERRADA', $resultado['data']['estado_documento']);
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?', [$this->tenantId])
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM incidents WHERE tenant_id = ? AND tipo = 'ENTREGA_RECHAZADA'",
                [$this->tenantId]
            )
        );
    }

    // ====================================================================
    // 8. LINEA CERRADA: SIN SEGUNDA RECEPCION
    // ====================================================================

    public function testUnaLineaRecibidaNoSePuedeRecibirOtraVez(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $this->seedProduct($this->tenantId, 'SKU-BETA');
        $doc = $this->createReceivingDocument($planificador, 'PO-DOSLINEAS', [
            ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10.0],
            ['sku' => 'SKU-BETA', 'cantidad_esperada' => 5.0],
        ]);

        // ALFA coincide (RECIBIDA); BETA sigue PENDIENTE => EN_RECEPCION.
        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );

        $this->assertSame(
            'EN_RECEPCION',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY . '-p2',
                $receptor
            );
            $this->fail('Se esperaba 409 por linea ya recibida.');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('ya fue recibida', $e->getMessage());
        }

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?', [$this->tenantId])
        );
    }

    public function testUnaLineaRechazadaNoSePuedeRecibirOtraVez(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $this->seedProduct($this->tenantId, 'SKU-BETA');
        $doc = $this->createReceivingDocument($planificador, 'PO-RECH-PARCIAL', [
            ['sku' => 'SKU-ALFA', 'cantidad_esperada' => 10.0],
            ['sku' => 'SKU-BETA', 'cantidad_esperada' => 5.0],
        ]);

        // Rechazo total SOLO de ALFA (se envia en el desglose): BETA sigue
        // PENDIENTE => el documento queda EN_RECEPCION.
        $this->receiveDocumentUseCase->execute(
            $doc['id'],
            [
                'ubicacion_codigo' => 'LOC-01',
                'rechazo_total' => true,
                'lineas' => [['sku' => 'SKU-ALFA']],
            ],
            self::IDEMPOTENCY_KEY . '-p1',
            $receptor
        );

        $this->assertSame(
            'EN_RECEPCION',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'rechazo_total' => true,
                    'lineas' => [['sku' => 'SKU-ALFA']],
                ],
                self::IDEMPOTENCY_KEY . '-p2',
                $receptor
            );
            $this->fail('Se esperaba 409 por linea cerrada como RECHAZADA.');
        } catch (InventoryException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('RECHAZADA', $e->getMessage());
        }
    }

    // ====================================================================
    // 9. REGLAS DE LINEA Y DOCUMENTO
    // ====================================================================

    public function testUnSkuNoPerteneceAlDocumento(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-NO-EXISTE', 'cantidad_recibida' => 1]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por SKU no perteneciente.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no es una l', $e->getMessage());
        }
    }

    public function testUnDocumentoAnuladoNoSePuedeRecibir(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador, 'PO-ANULADA-001', estado: 'ANULADA');

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento no recibible.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('ANULADA', $e->getMessage());
        }
    }

    public function testUnDocumentoCerradoNoSePuedeRecibir(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador, 'PO-CERRADA-001', estado: 'CERRADA');

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento no recibible.');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('CERRADA', $e->getMessage());
        }
    }

    public function testUnaLineaRequiereUbicacionDestino(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        try {
            $this->receiveDocumentUseCase->execute(
                $doc['id'],
                ['lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]]],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por ubicacion obligatoria.');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('ubicacion_codigo', $e->getField());
        }
    }

    // ====================================================================
    // 10. IDEMPOTENCIA
    // ====================================================================

    public function testLaClaveDeIdempotenciaHaceReplayDeLaRespuesta(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $payload = [
            'ubicacion_codigo' => 'LOC-01',
            'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
        ];

        $primera = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            $payload,
            self::IDEMPOTENCY_KEY,
            $receptor
        );
        $replay = $this->receiveDocumentUseCase->execute(
            $doc['id'],
            $payload,
            self::IDEMPOTENCY_KEY,
            $receptor
        );

        $this->assertArrayHasKey('_replay', $replay);
        $this->assertTrue($replay['_replay']);
        $this->assertSame(
            $primera['data']['documento_id'],
            $replay['data']['documento_id']
        );
        $this->assertSame(
            $primera['data']['lineas'][0]['unidad_fisica_id'],
            $replay['data']['lineas'][0]['unidad_fisica_id']
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM stock_units WHERE tenant_id = ?',
                [$this->tenantId]
            )
        );
    }

    // ====================================================================
    // 11. AISLAMIENTO ENTRE TENANTS
    // ====================================================================

    public function testUnDocumentoDeOtroTenantNoSePuedeRecibir(): void
    {
        $planificador = $this->seedUser($this->tenantId);
        $receptor = $this->seedUser($this->tenantId);
        $doc = $this->createReceivingDocument($planificador);

        $otroTenant = self::uuid('tenant');
        $ownerOtro = self::uuid('user');
        $this->control->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado, plan, owner_user_id, created_at, updated_at)
             VALUES (:id, :np, :ni, :estado, :plan, :owner, :created, :updated)'
        )->execute([
            'id' => $otroTenant,
            'np' => 'Otro ' . $otroTenant,
            'ni' => 'otro-' . $otroTenant,
            'estado' => 'ACTIVO',
            'plan' => 'FREE',
            'owner' => $ownerOtro,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ]);

        // El contexto de tenant es el que aísla: con el tenant activo cambiado
        // al ajeno, el documento no existe para ese contexto.
        $otroContexto = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $otroContexto->setTenantId($otroTenant);

        $useCaseAjeno = new ReceiveDocumentUseCase(
            $this->db,
            new IdempotencyService($this->control),
            new LocationRepository($this->db, $otroContexto),
            new StockUnitRepository($this->db, $otroContexto),
            new StockLedgerRepository($this->db, $otroContexto),
            new StockEventRepository($this->db, $otroContexto),
            new IncidentRepository($this->db, $otroContexto),
            $this->calculation,
            $this->barcodeService,
            $otroContexto
        );

        try {
            $useCaseAjeno->execute(
                $doc['id'],
                [
                    'ubicacion_codigo' => 'LOC-01',
                    'lineas' => [['sku' => 'SKU-ALFA', 'cantidad_recibida' => 10]],
                ],
                self::IDEMPOTENCY_KEY,
                $receptor
            );
            $this->fail('Se esperaba InventoryException por documento de otro tenant.');
        } catch (InventoryException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(
            'PLANIFICADA',
            $this->scalar('SELECT estado FROM receiving_documents WHERE id = ?', [$doc['id']])
        );
    }
}
