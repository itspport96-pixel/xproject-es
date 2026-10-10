<?php

/**
 * Router para el servidor PHP embebido de los E2E (v1.8.3).
 *
 * Uso (lo arranca tools\e2e\_env.ps1):
 *   php -S 127.0.0.1:<puerto> -t public tools\e2e\router.php
 *
 * Los ficheros estaticos de public/ se sirven tal cual (return false); el
 * resto de rutas (api/..., index.php) caen en public/index.php. Asi los E2E
 * no dependen de Apache ni del subdirectorio /xp/public de la BD de trabajo:
 * el proceso PHP hereda E2E_DB_NAME y toda la aplicacion corre contra la BD
 * de E2E.
 */

declare(strict_types=1);

$public = realpath(__DIR__ . '/../../public');
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$target = realpath($public . $uri);

if ($target !== false
    && is_file($target)
    && ($public === dirname($target) || str_starts_with($target, $public . DIRECTORY_SEPARATOR))) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';

// El servidor PHP embebido no fija Content-Length, y sin ese cabecero
// PowerShell 5.1 no rellena ErrorDetails.Message con el cuerpo del error:
// los E2E que comparan el JSON de un 4xx/5xx desde el catch lo verian vacio.
// Apache si manda el cabecero, asi que aqui se replica desde el buffer de
// salida (se instala ANTES de index.php para cubrir tambien las respuestas
// que salen desde el manejador global de excepciones, que termina el script).
ob_start(static function (string $buffer): string {
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($buffer), true);
    }

    return $buffer;
});

require $public . '/index.php';
