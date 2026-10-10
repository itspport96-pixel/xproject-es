<?php

declare(strict_types=1);

/**
 * Comando: corrección de la unidad "en limbo" (v1.8.2).
 *
 * Uso:
 *   php bin/fix-unit-limbo.php                 # dry-run: sólo imprime el plan
 *   php bin/fix-unit-limbo.php --apply         # pide confirmación y aplica
 *   php bin/fix-unit-limbo.php --apply --yes   # idem sin preguntar (automatización)
 *
 * La unidad 8dc0c59b-2281-4ae1-9b56-efed07573837 quedó con 10 unidades y una
 * reserva/picking/envío de 5: el resto no existía como fila. Este comando la
 * divide en dos de 5:
 *
 *   - MADRE: conserva id y barcode (SU-7PFDXZ-XYMATS7M), queda en
 *     EN_TRANSITO en T-E1-EA1 y sigue ligada a la reserva 7c88543c-...,
 *     al picking_operation y al shipment_item (ambos se ajustan a 5).
 *   - HIJA: fila nueva con barcode propio, DISPONIBLE en A-E1-EA1 (y esa
 *     ubicación reocupa 5 slots con validación de capacidad).
 *
 * Se escribe UNA transacción: división (StockUnitSplitService: unidades,
 * ledger de ambas y eventos DIVIDIDO con `hash_integridad`) + ajustes de
 * picking/envío + capacidad de destino. La auditoría (`audit_logs`) se
 * registra DESPUÉS del commit (AuditLogger abriría una transacción anidada)
 * y incluye el plan de reversión, con lo que la operación es reversible por
 * registro.
 *
 * Idempotente: si la unidad ya fue dividida (evento DIVIDIDO) sale con 0 y no
 * toca nada. `P1-MAIN-TGU` (capacidad_usada fantasma) NO se corrige aquí: lo
 * hace bin/recalculate-capacity.php.
 *
 * Salida: dry-run imprime antes/después; exit code 0, 1 si algo falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit\AuditLogger;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\StockUnitSplitService;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;

$UNIDAD_ID = '8dc0c59b-2281-4ae1-9b56-efed07573837';
$BARCODE_ESPERADO = 'SU-7PFDXZ-XYMATS7M';
$RESERVA_ID = '7c88543c-c83b-4e04-8f30-2c308848a0d1';
$CANTIDAD_TOTAL = 10.0;
$CANTIDAD_MITAD = 5.0;
$UBICACION_DESTINO_CODIGO = 'A-E1-EA1';

$container = App\Shared\Container\Container::getInstance();

$apply = false;
$yes = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/fix-unit-limbo.php [--apply] [--yes]\n";
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--yes') {
        $yes = true;
        continue;
    }

    fwrite(STDERR, "Argumento desconocido: {$arg} (usa --help)\n");
    exit(1);
}

$modo = $apply ? 'APLICAR' : 'DRY-RUN (sin escribir; usa --apply)';
echo "== fix-unit-limbo.php [{$modo}] ==\n";

try {
    $pdo = $container->get('database')->getConnection();
    $context = $container->get(TenantContext::class);

    // ------------------------------------------------------------------
    // 1. Estado actual
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare(
        'SELECT u.id, u.tenant_id, u.producto_id, u.codigo_barcode, u.cantidad,
                u.estado, u.ubicacion_id, u.lote, u.tenant_id,
                t.nombre_interno AS tenant_nombre,
                lo.codigo AS ubicacion_codigo
           FROM stock_units u
           JOIN tenants t ON t.id = u.tenant_id
      LEFT JOIN locations lo ON lo.id = u.ubicacion_id
          WHERE u.id = :id'
    );
    $stmt->execute(['id' => $UNIDAD_ID]);
    $unidad = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($unidad === false) {
        fwrite(STDERR, "ERROR: la unidad {$UNIDAD_ID} no existe.\n");
        exit(1);
    }

    $tenantId = (string) $unidad['tenant_id'];
    $context->setTenantId($tenantId);

    echo "Unidad: {$unidad['id']}\n";
    echo "  tenant: {$tenantId} ({$unidad['tenant_nombre']})\n";
    echo "  barcode: {$unidad['codigo_barcode']}\n";
    echo "  lote: " . ($unidad['lote'] ?? 'n/a') . "\n";
    echo sprintf(
        "  cantidad: %.4f | estado: %s | ubicacion: %s\n",
        (float) $unidad['cantidad'],
        $unidad['estado'],
        $unidad['ubicacion_codigo'] ?? 'n/a'
    );

    // ------------------------------------------------------------------
    // 2. Idempotencia: ¿ya se dividió?
    // ------------------------------------------------------------------
    $yaDividida = $pdo->prepare(
        "SELECT id, hash_integridad FROM stock_events
          WHERE unidad_fisica_id = :id AND tipo_evento = 'DIVIDIDO' AND tenant_id = :tenant"
    );
    $yaDividida->execute(['id' => $UNIDAD_ID, 'tenant' => $tenantId]);
    $eventoPrevio = $yaDividida->fetch(PDO::FETCH_ASSOC);

    if ($eventoPrevio !== false) {
        echo "YA CORREGIDA: ya existe un evento DIVIDIDO para esta unidad"
            . " (evento {$eventoPrevio['id']}). Nada que hacer.\n";
        exit(0);
    }

    // ------------------------------------------------------------------
    // 3. Registros ligados a la unidad
    // ------------------------------------------------------------------
    $ledgerRow = null;
    $stmt = $pdo->prepare(
        'SELECT cantidad_fisica, cantidad_reservada, cantidad_en_transito,
                cantidad_disponible, estado_actual
           FROM stock_ledger WHERE unidad_fisica_id = :id'
    );
    $stmt->execute(['id' => $UNIDAD_ID]);
    $ledgerRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $picking = null;
    $stmt = $pdo->prepare(
        'SELECT id, id_lote_picking, cantidad, estado, ubicacion_origen_id
           FROM picking_operations
          WHERE unidad_fisica_id = :id AND tenant_id = :tenant'
    );
    $stmt->execute(['id' => $UNIDAD_ID, 'tenant' => $tenantId]);
    $picking = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $shipmentItem = null;
    $stmt = $pdo->prepare(
        'SELECT i.id, i.cantidad, i.estado, i.shipment_id
           FROM shipment_items i
          WHERE i.unidad_fisica_id = :id AND i.tenant_id = :tenant'
    );
    $stmt->execute(['id' => $UNIDAD_ID, 'tenant' => $tenantId]);
    $shipmentItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $reserva = null;
    $stmt = $pdo->prepare(
        'SELECT id, cantidad, estado FROM reservations WHERE id = :id'
    );
    $stmt->execute(['id' => $RESERVA_ID]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $destinoRow = null;
    $stmt = $pdo->prepare(
        "SELECT id, codigo, capacidad, capacidad_usada, tipo
           FROM locations WHERE codigo = :codigo AND tenant_id = :tenant AND deleted_at IS NULL"
    );
    $stmt->execute(['codigo' => $UBICACION_DESTINO_CODIGO, 'tenant' => $tenantId]);
    $destinoRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($destinoRow === null) {
        fwrite(STDERR, "ERROR: no existe la ubicacion {$UBICACION_DESTINO_CODIGO} en este tenant.\n");
        exit(1);
    }

    // ------------------------------------------------------------------
    // 4. Validaciones de precondición
    // ------------------------------------------------------------------
    $errores = [];

    if (abs((float) $unidad['cantidad'] - $CANTIDAD_TOTAL) > 0.0001) {
        $errores[] = sprintf('la unidad tiene %.4f y se esperaba %.4f', (float) $unidad['cantidad'], $CANTIDAD_TOTAL);
    }
    if ((string) $unidad['codigo_barcode'] !== $BARCODE_ESPERADO) {
        $errores[] = "el barcode es {$unidad['codigo_barcode']} y se esperaba {$BARCODE_ESPERADO}";
    }
    if ($reserva === null) {
        $errores[] = "no existe la reserva {$RESERVA_ID}";
    } elseif (abs((float) $reserva['cantidad'] - $CANTIDAD_MITAD) > 0.0001) {
        $errores[] = sprintf('la reserva tiene %.4f y se esperaba %.4f', (float) $reserva['cantidad'], $CANTIDAD_MITAD);
    }

    if ($errores !== []) {
        foreach ($errores as $error) {
            fwrite(STDERR, "PRECONDICION: {$error}\n");
        }
        fwrite(STDERR, "Nada aplicado.\n");
        exit(1);
    }

    // ------------------------------------------------------------------
    // 5. Plan (dry-run y también como cabecera de --apply)
    // ------------------------------------------------------------------
    echo "\nPLAN\n";
    echo " 1. MADRE {$unidad['id']} (conserva id y barcode {$BARCODE_ESPERADO})\n";
    echo sprintf(
        "      cantidad %.4f -> %.4f | estado %s (sin cambio) | ubicacion %s (sin cambio)\n",
        (float) $unidad['cantidad'],
        (float) $unidad['cantidad'] - $CANTIDAD_MITAD,
        $unidad['estado'],
        $unidad['ubicacion_codigo'] ?? 'n/a'
    );
    if ($ledgerRow !== null) {
        echo sprintf(
            "      ledger  fisica %.4f -> %.4f | en_transito %.4f -> %.4f | reservada %.4f | disponible %.4f\n",
            (float) $ledgerRow['cantidad_fisica'],
            (float) $ledgerRow['cantidad_fisica'] - $CANTIDAD_MITAD,
            (float) $ledgerRow['cantidad_en_transito'],
            min((float) $ledgerRow['cantidad_en_transito'], (float) $unidad['cantidad'] - $CANTIDAD_MITAD),
            (float) $ledgerRow['cantidad_reservada'],
            (float) $ledgerRow['cantidad_disponible']
        );
    }
    if ($reserva !== null) {
        echo "      reserva {$reserva['id']} ({$reserva['estado']}, {$reserva['cantidad']}) sigue ligada -> sin cambios\n";
    }
    if ($picking !== null) {
        echo sprintf("      picking_operation %s (lote %s): cantidad %.4f -> %.4f\n", $picking['id'], $picking['id_lote_picking'], (float) $picking['cantidad'], $CANTIDAD_MITAD);
    }
    if ($shipmentItem !== null) {
        echo sprintf(
            "      shipment_item %s (shipment %s): cantidad %.4f -> %.4f\n",
            $shipmentItem['id'],
            $shipmentItem['shipment_id'],
            (float) $shipmentItem['cantidad'],
            $CANTIDAD_MITAD
        );
    }

    echo " 2. HIJA  id y barcode NUEVOS (se generan en --apply)\n";
    echo sprintf("      cantidad %.4f | estado DISPONIBLE | ubicacion %s\n", $CANTIDAD_MITAD, $UBICACION_DESTINO_CODIGO);
    echo sprintf("      ledger nuevo: fisica %.4f | disponible %.4f\n", $CANTIDAD_MITAD, $CANTIDAD_MITAD);
    echo sprintf(
        "      ubicacion %s capacidad_usada %d -> %d%s\n",
        $destinoRow['codigo'],
        (int) $destinoRow['capacidad_usada'],
        (int) $destinoRow['capacidad_usada'] + (int) $CANTIDAD_MITAD,
        $destinoRow['capacidad'] !== null ? " (capacidad {$destinoRow['capacidad']})" : ''
    );

    echo " 3. EVENTOS DIVIDIDO x2 (madre e hija) con hash_integridad\n";
    echo " 4. AUDITORIA post-commit (registro de la correccion + plan de reversa)\n";
    echo " 5. TODO en una sola transaccion (rollback automatico si algo falla)\n";

    echo "\nSIN CAMBIOS\n";
    echo "  - T-E1-EA1 (TRANSITO, virtual): capacidad_usada 0\n";
    echo "  - P1-MAIN-TGU: capacidad_usada 10 con 0 unidades -> lo corrige bin/recalculate-capacity.php\n";

    if (!$apply) {
        echo "\nDRY-RUN: nada escrito. Ejecuta con --apply para aplicar el plan.\n";
        exit(0);
    }

    // ------------------------------------------------------------------
    // 6. Confirmación
    // ------------------------------------------------------------------
    if (!$yes) {
        echo "\nAtencion: esto modifica stock_units, stock_ledger, stock_events,\n";
        echo "picking_operations, shipment_items y locations en la BD viva.\n";
        fwrite(STDOUT, 'Escribe SI para aplicar: ');
        $respuesta = trim((string) fgets(STDIN));

        if (strtoupper($respuesta) !== 'SI') {
            echo "Cancelado: no se aplico nada.\n";
            exit(1);
        }
    }

    // ------------------------------------------------------------------
    // 7. Aplicación (una transacción)
    // ------------------------------------------------------------------
    $stockUnits = $container->get(StockUnitRepositoryInterface::class);
    $locations = $container->get(LocationRepositoryInterface::class);
    $split = $container->get(StockUnitSplitService::class);

    $descripcion = 'Correccion de unidad en limbo 10 -> 5 + 5 (v1.8.2)';
    $datosOperacion = [
        'origen' => 'bin/fix-unit-limbo.php',
        'reserva_id' => $RESERVA_ID,
        'lote' => $unidad['lote'],
        'codigos_barcode_padre' => $BARCODE_ESPERADO,
    ];

    $pdo->beginTransaction();

    try {
        // Bloqueo de la fila para que nadie la modifique a mitad de camino
        $lock = $pdo->prepare(
            'SELECT cantidad, estado FROM stock_units WHERE id = :id AND tenant_id = :tenant FOR UPDATE'
        );
        $lock->execute(['id' => $UNIDAD_ID, 'tenant' => $tenantId]);
        $fila = $lock->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || abs((float) $fila['cantidad'] - $CANTIDAD_TOTAL) > 0.0001) {
            throw new RuntimeException('La unidad cambio mientras se preparaba la correccion; abortado.');
        }

        $unidadEntidad = $stockUnits->findById($UNIDAD_ID);

        if ($unidadEntidad === null) {
            throw new RuntimeException("La unidad {$UNIDAD_ID} desaparecio; abortado.");
        }

        $resultado = $split->dividir(
            $unidadEntidad,
            $CANTIDAD_MITAD,
            'DISPONIBLE',
            (string) $destinoRow['id'],
            null, // estado de la madre: se conserva (EN_TRANSITO -> DISPONIBLE no existe en la matriz)
            null, // ubicacion de la madre: se conserva (T-E1-EA1)
            $descripcion,
            $datosOperacion
        );

        $hija = $resultado['hija'];

        // Ajuste de picking/envío a la mitad que queda en la madre
        $filasPicking = 0;
        if ($picking !== null) {
            $upd = $pdo->prepare(
                'UPDATE picking_operations
                    SET cantidad = :cantidad, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant AND unidad_fisica_id = :unidad'
            );
            $upd->execute([
                'cantidad' => $CANTIDAD_MITAD,
                'id' => $picking['id'],
                'tenant' => $tenantId,
                'unidad' => $UNIDAD_ID,
            ]);
            $filasPicking = $upd->rowCount();
        }

        $filasShipment = 0;
        if ($shipmentItem !== null) {
            $upd = $pdo->prepare(
                'UPDATE shipment_items
                    SET cantidad = :cantidad, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant AND unidad_fisica_id = :unidad'
            );
            $upd->execute([
                'cantidad' => $CANTIDAD_MITAD,
                'id' => $shipmentItem['id'],
                'tenant' => $tenantId,
                'unidad' => $UNIDAD_ID,
            ]);
            $filasShipment = $upd->rowCount();
        }

        // La hija ocupa 5 slots reales en A-E1-EA1 (valida capacidad <= 20)
        $destino = $locations->findById((string) $destinoRow['id']);

        if ($destino === null) {
            throw new RuntimeException('La ubicacion de destino desaparecio; abortado.');
        }

        $capacidadAntes = $destino->capacidadUsada();
        $destino->incrementarCapacidadUsada((int) $CANTIDAD_MITAD);
        $locations->update($destino);
        $capacidadDespues = $destino->capacidadUsada();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // ------------------------------------------------------------------
    // 8. Auditoria (post-commit) + plan de reversa
    // ------------------------------------------------------------------
    $eventoMadreId = (string) $resultado['evento_madre']->id();
    $eventoHijaId = (string) $resultado['evento_hija']->id();
    $hijaId = (string) $hija->id();
    $hijaBarcode = (string) $hija->codigoBarcode();

    $reversa = [
        'Aviso' => 'Manual, en una transaccion; despues ejecutar bin/recalculate-capacity.php --apply',
        'sql' => [
            "UPDATE stock_units SET cantidad = {$CANTIDAD_TOTAL} WHERE id = '{$UNIDAD_ID}';",
            "UPDATE stock_ledger SET cantidad_fisica = {$CANTIDAD_TOTAL}, cantidad_reservada = 0, cantidad_en_transito = {$CANTIDAD_TOTAL}, cantidad_disponible = 0, estado_actual = 'EN_TRANSITO' WHERE unidad_fisica_id = '{$UNIDAD_ID}';",
            "UPDATE picking_operations SET cantidad = {$CANTIDAD_TOTAL} WHERE unidad_fisica_id = '{$UNIDAD_ID}' AND tenant_id = '{$tenantId}';",
            "UPDATE shipment_items SET cantidad = {$CANTIDAD_TOTAL} WHERE unidad_fisica_id = '{$UNIDAD_ID}' AND tenant_id = '{$tenantId}';",
            "UPDATE locations SET capacidad_usada = {$capacidadAntes} WHERE id = '{$destinoRow['id']}';",
            "DELETE FROM stock_events WHERE id IN ('{$eventoMadreId}', '{$eventoHijaId}');",
            "DELETE FROM stock_ledger WHERE unidad_fisica_id = '{$hijaId}';",
            "DELETE FROM stock_units WHERE id = '{$hijaId}';",
            "INSERT en audit_logs con operacion 'CLI bin/fix-unit-limbo --revert' y detalle del reversion.",
        ],
    ];

    $audit = $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;

    if ($audit !== null) {
        $audit->record([
            'tenant_id' => $tenantId,
            'usuario_id' => null,
            'operacion' => 'CLI bin/fix-unit-limbo',
            'modulo' => 'inventory',
            'recurso' => 'stock_units',
            'entidad_id' => $UNIDAD_ID,
            'tipo_cambio' => 'UPDATE',
            'valores_anteriores' => [
                'cantidad' => $CANTIDAD_TOTAL,
                'estado' => $unidad['estado'],
                'ubicacion_id' => $unidad['ubicacion_id'],
                'picking_operation_cantidad' => $picking !== null ? (float) $picking['cantidad'] : null,
                'shipment_item_cantidad' => $shipmentItem !== null ? (float) $shipmentItem['cantidad'] : null,
                'ledger' => $ledgerRow !== null ? $ledgerRow : null,
            ],
            'valores_nuevos' => [
                'cantidad_madre' => $CANTIDAD_MITAD,
                'cantidad_hija' => $CANTIDAD_MITAD,
                'unidad_hija_id' => $hijaId,
                'unidad_hija_barcode' => $hijaBarcode,
                'picking_operation_cantidad' => $picking !== null ? $CANTIDAD_MITAD : null,
                'shipment_item_cantidad' => $shipmentItem !== null ? $CANTIDAD_MITAD : null,
            ],
            'resultado' => 'SUCCESS',
            'detalles_adicionales' => [
                'comando' => 'bin/fix-unit-limbo.php',
                'evento_madre_id' => $eventoMadreId,
                'evento_hija_id' => $eventoHijaId,
                'evento_madre_hash' => (string) $resultado['evento_madre']->hashIntegridad(),
                'evento_hija_hash' => (string) $resultado['evento_hija']->hashIntegridad(),
                'reserva_id' => $RESERVA_ID,
                'picking_filas_afectadas' => $filasPicking,
                'shipment_filas_afectadas' => $filasShipment,
                'capacidad_destino_antes' => $capacidadAntes,
                'capacidad_destino_despues' => $capacidadDespues,
                'reversa' => $reversa,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // 9. Resultado
    // ------------------------------------------------------------------
    echo "\nAPLICADO\n";
    echo sprintf("  MADRE  %s: %.4f %s en %s\n", $UNIDAD_ID, $CANTIDAD_MITAD, $unidad['estado'], $unidad['ubicacion_codigo'] ?? 'n/a');
    echo sprintf("  HIJA   %s (barcode %s): %.4f DISPONIBLE en %s\n", $hijaId, $hijaBarcode, $CANTIDAD_MITAD, $UBICACION_DESTINO_CODIGO);
    if ($picking !== null) {
        echo sprintf("  picking_operation %s: %d fila(s) -> %.4f\n", $picking['id'], $filasPicking, $CANTIDAD_MITAD);
    }
    if ($shipmentItem !== null) {
        echo sprintf("  shipment_item %s: %d fila(s) -> %.4f\n", $shipmentItem['id'], $filasShipment, $CANTIDAD_MITAD);
    }
    echo sprintf("  ubicacion %s: capacidad_usada %d -> %d\n", $destinoRow['codigo'], $capacidadAntes, $capacidadDespues);
    echo sprintf("  eventos DIVIDIDO: %s (madre, hash %s) / %s (hija, hash %s)\n",
        $eventoMadreId,
        substr((string) $resultado['evento_madre']->hashIntegridad(), 0, 12),
        $eventoHijaId,
        substr((string) $resultado['evento_hija']->hashIntegridad(), 0, 12)
    );
    echo "  auditoria: 1 registro con el plan de reversa (post-commit)\n";

    echo "\nSIGUIENTE PASO\n";
    echo "  php bin/recalculate-capacity.php      (debe proponer P1-MAIN-TGU: 10 -> 0)\n";

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Archivo: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
