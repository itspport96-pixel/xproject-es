<?php

declare(strict_types=1);

/**
 * Limpia la clave de rate limit de otorgamiento CRITICAL de permisos
 * temporales (clave `rate_limit:temp_permission_grant_critical:{actor}`).
 *
 * Lo usa tools/e2e/e2e-v1110.ps1 al inicio (aislamiento entre lotes) y al
 * final (limpieza): el limite son 2 intentos/hora por actor y sin esta
 * limpieza una ejecucion posterior arrancaria con el contador de la anterior.
 *
 * Uso: php clear-rate-limit.php <clave-sin-prefijo>
 * Sale con `cleared <clave>` si la borró.
 */

if ($argc < 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "uso: php clear-rate-limit.php <clave-sin-prefijo>\n");
    exit(1);
}

require __DIR__ . '/../../vendor/autoload.php';

$clave = trim($argv[1]);
$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);

try {
    $redis = new Predis\Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port]);
    $borradas = $redis->del(['rate_limit:' . $clave, 'rate_limit:' . $clave . ':meta']);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "cleared {$clave} ({$borradas} claves)\n";
