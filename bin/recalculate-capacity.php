<?php

declare(strict_types=1);

/**
 * Comando: recálculo de `locations.capacidad_usada` (v1.8.2).
 *
 * Uso:
 *   php bin/recalculate-capacity.php                   # dry-run (sólo lista)
 *   php bin/recalculate-capacity.php --tenant=<id>     # un solo tenant
 *   php bin/recalculate-capacity.php --apply           # corrige + auditoría
 *   php bin/recalculate-capacity.php --apply --e2e     # sólo BD de E2E (v1.11.0)
 *
 * Aislamiento E2E (v1.11.0): si el proceso exporta E2E_DB_NAME y se pide
 * --apply (o --e2e explícito), la conexión DEBE apuntar a esa BD; si
 * apunta a la BD de trabajo (DB_DATABASE) o a otra distinta, el comando
 * falla con exit 1 sin escribir nada. Sirve para que los scripts de
 * tools\e2e\ puedan recalcular capacidad sin riesgo de tocar la BD viva.
 *
 * Por defecto es DRY-RUN: imprime las diferencias entre el
 * `capacidad_usada` guardado y el valor derivado de las unidades físicas
 * presentes, sin escribir nada. Con `--apply` se actualiza cada ubicación
 * distinta (y se deja una fila en `audit_logs` por cada corrección, fuera de
 * la transacción, porque AuditLogger abre la suya).
 *
 * Regla de cálculo (decisión v1.8.2):
 *
 *   capacidad_usada = SUM(CEIL(cantidad)) de las unidades cuya
 *                     `ubicacion_id` es esa ubicación
 *   ubicaciones VIRTUALES (TRANSITO) = 0  (no llevan contador)
 *
 * Es el mismo criterio con el que cada flujo suma/resta slots
 * (recepción, movimiento, picking, fallo de entrega y devolución usan
 * `ceil(cantidad)`); con cantidades enteras coincide con la suma simple de
 * cantidades. Si el valor esperado excede `capacidad` se reporta como ERROR
 * y NO se aplica (mismo 422 que `Location::setCapacidadUsada`).
 *
 * Salida: una línea por ubicación con diferencia (o "sin diferencias");
 * exit code 0 si todo se resolvió, 1 si hubo errores.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit\AuditLogger;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\ValueObjects\LocationType;

$container = App\Shared\Container\Container::getInstance();

$apply = false;
$tenantFilter = null;
$e2e = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/recalculate-capacity.php [--tenant=<id>] [--apply] [--e2e]\n";
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--e2e') {
        $e2e = true;
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
echo "== recalculate-capacity.php [{$modo}] ==\n";

try {
    $pdo = $container->get('database')->getConnection();

    // Aislamiento E2E (v1.11.0): con --e2e, o con --apply cuando el proceso
    // exporta E2E_DB_NAME, la conexión DEBE apuntar a la BD de E2E. Si
    // apunta a la BD de trabajo o a otra distinta, se aborta sin escribir.
    $envE2e = getenv('E2E_DB_NAME');
    $envE2e = ($envE2e === false) ? '' : (string) $envE2e;
    $workDb = (string) ($_ENV['DB_DATABASE'] ?? '');
    if ($e2e || ($apply && $envE2e !== '')) {
        if ($envE2e === '') {
            fwrite(STDERR, "FALLO: --e2e/--apply en contexto E2E requiere E2E_DB_NAME en el entorno.\n");
            exit(1);
        }
        if ($workDb !== '' && $envE2e === $workDb) {
            fwrite(STDERR, "FALLO: E2E_DB_NAME ({$envE2e}) apunta a la BD de trabajo.\n");
            exit(1);
        }
        $actual = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        if ($actual !== $envE2e) {
            $extra = ($actual !== '' && $actual === $workDb) ? ' (BD de trabajo!)' : '';
            fwrite(STDERR, "FALLO: la conexion apunta a '{$actual}'{$extra}; se esperaba '{$envE2e}'.\n");
            exit(1);
        }
        echo "[e2e] BD verificada: {$actual}\n";
    }

    $sql = 'SELECT id, nombre_interno FROM tenants WHERE deleted_at IS NULL';
    $params = [];

    if ($tenantFilter !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $tenantFilter;
    } else {
        $sql .= " AND estado = 'ACTIVO'";
    }
    $sql .= ' ORDER BY created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($tenants === []) {
        echo "Sin tenants que procesar.\n";
        exit(0);
    }

    $audit = $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;
    $context = $container->has(TenantContext::class) ? $container->get(TenantContext::class) : null;

    $totalDiff = 0;
    $totalAplicadas = 0;
    $totalErrores = 0;

    foreach ($tenants as $tenant) {
        $tenantId = (string) $tenant['id'];

        if ($context !== null) {
            $context->setTenantId($tenantId);
        }

        // 1. Unidades físicas presentes, agrupadas por ubicación
        $unidades = $pdo->prepare(
            'SELECT ubicacion_id, cantidad
               FROM stock_units
              WHERE tenant_id = :tenant AND ubicacion_id IS NOT NULL'
        );
        $unidades->execute(['tenant' => $tenantId]);

        /** @var array<string, int> $ocupacion */
        $ocupacion = [];

        foreach ($unidades->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $ubicacionId = (string) $fila['ubicacion_id'];
            $ocupacion[$ubicacionId] = ($ocupacion[$ubicacionId] ?? 0) + (int) ceil((float) $fila['cantidad']);
        }

        // 2. Ubicaciones del tenant (sin borrar)
        $locs = $pdo->prepare(
            'SELECT id, codigo, tipo, capacidad, capacidad_usada
               FROM locations
              WHERE tenant_id = :tenant AND deleted_at IS NULL
              ORDER BY codigo'
        );
        $locs->execute(['tenant' => $tenantId]);
        $locations = $locs->fetchAll(PDO::FETCH_ASSOC);

        $diferenciasTenant = 0;

        foreach ($locations as $location) {
            $id = (string) $location['id'];
            $tipo = (string) ($location['tipo'] ?? LocationType::ALMACENAMIENTO);
            $actual = (int) ($location['capacidad_usada'] ?? 0);
            $capacidad = $location['capacidad'] !== null ? (int) $location['capacidad'] : null;

            // Ubicación virtual: nunca lleva contador
            $esperado = $tipo === LocationType::TRANSITO ? 0 : (int) ($ocupacion[$id] ?? 0);

            if ($esperado === $actual) {
                continue;
            }

            $diferenciasTenant++;
            $totalDiff++;

            $linea = sprintf(
                "[%s] %s (%s): capacidad_usada %d -> %d%s",
                $tenant['nombre_interno'],
                $location['codigo'],
                $tipo,
                $actual,
                $esperado,
                $capacidad !== null ? " (capacidad {$capacidad})" : ''
            );

            if (!$apply) {
                echo $linea . "\n";
                continue;
            }

            if ($capacidad !== null && $esperado > $capacidad) {
                $totalErrores++;
                echo $linea . "  ERROR: excede la capacidad; NO se aplica.\n";
                continue;
            }

            $pdo->prepare('UPDATE locations SET capacidad_usada = :usada, updated_at = NOW() WHERE id = :id')
                ->execute(['usada' => $esperado, 'id' => $id]);

            $totalAplicadas++;

            if ($audit !== null) {
                // Fuera de la transacción de datos: AuditLogger abre la suya.
                $audit->record([
                    'tenant_id' => $tenantId,
                    'usuario_id' => null,
                    'operacion' => 'CLI bin/recalculate-capacity',
                    'modulo' => 'inventory',
                    'recurso' => 'locations',
                    'entidad_id' => $id,
                    'tipo_cambio' => 'UPDATE',
                    'valores_anteriores' => ['capacidad_usada' => $actual],
                    'valores_nuevos' => ['capacidad_usada' => $esperado],
                    'resultado' => 'SUCCESS',
                    'detalles_adicionales' => [
                        'comando' => 'bin/recalculate-capacity.php',
                        'codigo' => $location['codigo'],
                        'tipo' => $tipo,
                        'unidades_presentes' => (int) ($ocupacion[$id] ?? 0),
                    ],
                ]);
            }

            echo $linea . "  [APlicado]\n";
        }

        if ($diferenciasTenant === 0) {
            echo "[{$tenant['nombre_interno']}] sin diferencias.\n";
        }
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
