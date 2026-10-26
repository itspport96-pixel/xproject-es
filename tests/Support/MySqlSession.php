<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Utilidades de sesion para los tests que hablan con MySQL REAL
 * (xproject_e2e, regla 16).
 */
final class MySqlSession
{
    /**
     * Fija la zona horaria de la sesion igual que hace DatabaseService con la
     * BD de la aplicacion (DB_TIME_ZONE del .env, por defecto +00:00).
     *
     * Sin esto la sesion hereda el time_zone GLOBAL del servidor: con la zona
     * local del host, las comparaciones con NOW() (p. ej. la caducidad de un
     * token de reset, forzada con DATE_SUB(NOW(), ...)) se desplazan respecto
     * de lo que escribe la aplicacion y el test falla. Un reinicio de mysqld
     * devuelve el global a SYSTEM, de ahi la dependencia (v1.19.5).
     */
    public static function applyTimeZone(PDO $pdo): void
    {
        $timeZone = (string) (getenv('DB_TIME_ZONE') ?: ($_ENV['DB_TIME_ZONE'] ?? '+00:00'));
        $pdo->exec('SET time_zone = ' . $pdo->quote($timeZone));
    }
}
