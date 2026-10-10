<?php

declare(strict_types=1);

/**
 * Comando: recálculo de `stock_ledger.cantidad_disponible` (v1.8.3).
 *
 * Uso:
 *   php bin/recalculate-ledger.php                   # dry-run (sólo lista)
 *   php bin/recalculate-ledger.php --tenant=<id>     # un solo tenant
 *   php bin/recalculate-ledger.php --apply           # corrige + auditoría
 *
 * Por defecto es DRY-RUN: imprime las diferencias entre el
 * `cantidad_disponible` guardado y el valor que corresponde al estado de la
 * unidad, sin escribir nada. Con `--apply` se actualiza cada ledger distinto
 * (y se deja una fila en `audit_logs` por cada corrección, fuera de la
 * transacción, porque AuditLogger abre la suya).
 *
 * Regla de cálculo (R1, v1.8.3):
 *
 *   estado de la unidad = DISPONIBLE
 *     → cantidad_disponible = cantidad_fisica - cantidad_reservada - cantidad_en_transito
 *   cualquier otro estado (RESERVADO, EN_PREPARACION, CARGADO, EN_TRANSITO,
 *   ENTREGADO, DEVUELTO, DAÑADO, BLOQUEADO, PERDIDO)
 *     → cantidad_disponible = 0
 *
 * El estado de referencia es el de `stock_units.estado` (la fuente de verdad);
 * si `stock_ledger.estado_actual` no coincide también se corrige, porque es
 * el dato con el que cada flujo decide si suma disponible.
 *
 * Salida: una línea por ledger con diferencia (o "sin diferencias");
 * exit code 0 salvo error de ejecución (1).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit\AuditLogger;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\ValueObjects\StockState;

$container = App\Shared\Container\Container::getInstance();

$apply = false;
$tenantFilter = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/recalculate-ledger.php [--tenant=<id>] [--apply]\n";
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (str_starts_with($arg, '--tenant=')) {
        $tenantFilter = substr($arg, strlen('--tenant='));
        continue;
    }

    fwrite(STDERR, "Argumento desconocido: {$arg} (usa --help)\n");
    exit(1);
}

$modo = $apply ? 'APLICAR' : 'DRY-RUN (sin escribir; usa --apply)';
echo "== recalculate-ledger.php [{$modo}] ==\n";

try {
    $pdo = $container->get('database')->getConnection();

    $sql = 'SELECT l.id, l.tenant_id, l.unidad_fisica_id, l.cantidad_fisica,
                   l.cantidad_reservada, l.cantidad_en_transito, l.cantidad_disponible,
                   l.estado_actual, u.estado AS estado_unidad, u.codigo_barcode,
                   t.nombre_interno
              FROM stock_ledger l
              JOIN stock_units u ON u.id = l.unidad_fisica_id
              JOIN tenants t ON t.id = l.tenant_id
             WHERE t.deleted_at IS NULL';
    $params = [];

    if ($tenantFilter !== null) {
        $sql .= ' AND l.tenant_id = :tenant';
        $params['tenant'] = $tenantFilter;
    }

    $sql .= ' ORDER BY t.created_at ASC, l.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($ledgers === []) {
        echo "Sin ledgers que procesar.\n";
        exit(0);
    }

    $audit = $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;
    $context = $container->has(TenantContext::class) ? $container->get(TenantContext::class) : null;

    $totalDiff = 0;
    $totalAplicadas = 0;
    $totalErrores = 0;
    /** @var array<string, int> $diferenciasPorTenant */
    $diferenciasPorTenant = [];

    foreach ($ledgers as $ledger) {
        $tenantId = (string) $ledger['tenant_id'];

        if ($context !== null) {
            $context->setTenantId($tenantId);
        }

        $estadoUnidad = (string) ($ledger['estado_unidad'] ?? StockState::DISPONIBLE);
        $estadoLedger = $ledger['estado_actual'] !== null ? (string) $ledger['estado_actual'] : null;

        $fisica = (float) ($ledger['cantidad_fisica'] ?? 0);
        $reservada = (float) ($ledger['cantidad_reservada'] ?? 0);
        $enTransito = (float) ($ledger['cantidad_en_transito'] ?? 0);
        $guardado = (float) ($ledger['cantidad_disponible'] ?? 0);

        $esperado = StockState::fromString($estadoUnidad)->isDisponible()
            ? $fisica - $reservada - $enTransito
            : 0.0;

        $estadoDesalineado = $estadoLedger === null || $estadoLedger !== $estadoUnidad;

        if (abs($guardado - $esperado) < 0.0001 && !$estadoDesalineado) {
            continue;
        }

        $totalDiff++;
        $diferenciasPorTenant[$ledger['nombre_interno']] = ($diferenciasPorTenant[$ledger['nombre_interno']] ?? 0) + 1;

        $linea = sprintf(
            "[%s] %s (%s): cantidad_disponible %s -> %s%s",
            $ledger['nombre_interno'],
            $ledger['codigo_barcode'],
            $ledger['unidad_fisica_id'],
            number_format($guardado, 4, '.', ''),
            number_format($esperado, 4, '.', ''),
            $estadoDesalineado
                ? sprintf(' (estado ledger=%s unidad=%s)', $estadoLedger ?? 'NULL', $estadoUnidad)
                : ''
        );

        if (!$apply) {
            echo $linea . "\n";
            continue;
        }

        if ($esperado < 0) {
            $totalErrores++;
            echo $linea . "  ERROR: el disponible resultaría negativo; NO se aplica.\n";
            continue;
        }

        $pdo->prepare(
            'UPDATE stock_ledger
                SET cantidad_disponible = :disponible,
                    estado_actual = :estado,
                    ultima_actualizacion = NOW()
              WHERE id = :id'
        )->execute([
            'disponible' => $esperado,
            'estado' => $estadoUnidad,
            'id' => $ledger['id'],
        ]);

        $totalAplicadas++;

        if ($audit !== null) {
            // Fuera de la transacción de datos: AuditLogger abre la suya.
            $audit->record([
                'tenant_id' => $tenantId,
                'usuario_id' => null,
                'operacion' => 'CLI bin/recalculate-ledger',
                'modulo' => 'inventory',
                'recurso' => 'stock_ledger',
                'entidad_id' => (string) $ledger['id'],
                'tipo_cambio' => 'UPDATE',
                'valores_anteriores' => [
                    'cantidad_disponible' => $guardado,
                    'estado_actual' => $estadoLedger,
                ],
                'valores_nuevos' => [
                    'cantidad_disponible' => $esperado,
                    'estado_actual' => $estadoUnidad,
                ],
                'resultado' => 'SUCCESS',
                'detalles_adicionales' => [
                    'comando' => 'bin/recalculate-ledger.php',
                    'unidad_fisica_id' => (string) $ledger['unidad_fisica_id'],
                    'codigo_barcode' => (string) $ledger['codigo_barcode'],
                    'estado_unidad' => $estadoUnidad,
                    'cantidad_fisica' => $fisica,
                    'cantidad_reservada' => $reservada,
                    'cantidad_en_transito' => $enTransito,
                ],
            ]);
        }

        echo $linea . "  [Aplicado]\n";
    }

    if ($totalDiff === 0) {
        echo "Sin diferencias: todos los ledgers ya cumplen R1 (v1.8.3).\n";
    }

    foreach ($diferenciasPorTenant as $nombreTenant => $n) {
        echo "[{$nombreTenant}] {$n} ledger(s) con diferencia.\n";
    }

    printf(
        "Total: %d diferencia(s), %d corregida(s), %d error(es).%s\n",
        $totalDiff,
        $totalAplicadas,
        $totalErrores,
        $apply ? '' : ' (dry-run: nada escrito)'
    );

    exit($totalErrores > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Archivo: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
