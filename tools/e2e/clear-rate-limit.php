<?php

declare(strict_types=1);

/**
 * Limpia claves de rate limit (prefijo `rate_limit:`) para aislamiento E2E.
 *
 * Usos:
 * - Clave exacta (sin prefijo): `php clear-rate-limit.php temp_permission_grant_critical:1.2.3.4`
 *   (borra la clave y su `:meta`; lo usa e2e-v1110.ps1 al inicio y al final:
 *   el limite de grant CRITICAL son 2 intentos/hora por actor).
 * - Patron glob: `php clear-rate-limit.php 'rl:*auth/reset-password*'`
 *   (busca con SCAN `rate_limit:{patron}` y borra tambien cada `:meta`; el
 *   `*` final hace coincidir tambien los `:meta` huérfanos que quedan cuando
 *   el contador ya expiro; lo usa _env.ps1 al arrancar para que los consumes
 *   de /auth/reset-password de un script no arranquen con el contador de la
 *   ejecucion anterior: el limite son 5/900s por IP y la suite encadena
 *   scripts).
 *
 * Sale con `cleared <clave>` (o `cleared <patron> (n claves)` en glob).
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
    if (str_contains($clave, '*') || str_contains($clave, '?') || str_contains($clave, '[')) {
        $aBorrar = [];
        foreach ($redis->keys('rate_limit:' . $clave) as $k) {
            $aBorrar[] = $k;
            $aBorrar[] = $k . ':meta';
        }
        $borradas = empty($aBorrar) ? 0 : $redis->del($aBorrar);
        echo "cleared {$clave} ({$borradas} claves)\n";
        exit(0);
    }
    $borradas = $redis->del(['rate_limit:' . $clave, 'rate_limit:' . $clave . ':meta']);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "cleared {$clave} ({$borradas} claves)\n";
