<?php

declare(strict_types=1);

/**
 * Prepara la BD de test (xproject_test por defecto) SIN tocar la BD de
 * trabajo (xproject) ni la de E2E (xproject_e2e).
 *
 * BLOQUE R2 (v1.13.29): los tests de inventory corren contra MySQL real para
 * no dar falsos verdes que SQLite en memoria esconderia (ENUMs, sql_mode
 * estricto, decimal(15,4), FKs, CHECK json_valid). Este comando:
 *
 *   1. Crea la BD destino si no existe (mismo juego de caracteres que la fuente).
 *   2. La vacia por completo (DROP + CREATE) para que cada suite arranque limpio.
 *   3. Aplica database/schema/schema.sql (unico DDL de verdad).
 *   4. NO copia datos de la BD de trabajo: tenants, users, productos,
 *      ubicaciones y documentos los crea el propio test dentro de cada caso.
 *
 * Uso:
 *   php bin/prepare-test-db.php [--db=nombre] [--help]
 *
 *   --db=nombre  BD destino (prioridad); por defecto TEST_DB_NAME o xproject_test
 *
 * Variables de entorno: TEST_DB_NAME (nombre de la BD destino) y, via .env,
 * DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE (credenciales de la BD
 * de trabajo, que son las del servidor MySQL).
 *
 * Exit 0 si la BD quedo lista; exit 1 si fallo.
 */

use Dotenv\Dotenv;

$baseDir = dirname(__DIR__);

require_once $baseDir . '/vendor/autoload.php';

Dotenv::createImmutable($baseDir)->safeLoad();

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function findMysql(): string
{
    $candidates = [
        getenv('MYSQL_BIN') ?: null,
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        '/usr/bin/mysql',
        '/opt/lampp/bin/mysql',
        'mysql',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }

        if (strpbrk($candidate, '/\\') !== false) {
            if (is_file($candidate)) {
                return $candidate;
            }
            continue;
        }

        return $candidate;
    }

    fail('No se encontro mysql (define MYSQL_BIN)');
}

/**
 * Ejecuta mysql.exe con un fichero SQL por stdin y devuelve [exit, stderr+stdout].
 *
 * La contrasena via MYSQL_PWD: no queda en la linea de comandos. El comando se
 * lanza como array, sin shell, para que la codificacion llegue byte a byte.
 *
 * @return array{0: int, 1: string}
 */
function runMysqlFile(string $mysql, string $sqlFile, string $database, string $host, string $port, string $user, string $password): array
{
    $cmd = [
        $mysql,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $user,
        '--default-character-set=utf8mb4',
        $database,
    ];

    $descriptors = [
        0 => ['file', $sqlFile, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge(getenv(), $password !== '' ? ['MYSQL_PWD' => $password] : []);

    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);

    if (!is_resource($process)) {
        fail('No se pudo lanzar mysql para aplicar schema.sql');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$exit, trim($stdout . "\n" . $stderr)];
}

function scalar(PDO $pdo, string $sql): ?string
{
    $value = $pdo->query($sql)->fetchColumn();

    return $value === false ? null : (string) $value;
}

// ---------------------------------------------------------------- argumentos
$options = getopt('', ['db:', 'help']);

if (is_array($options) && array_key_exists('help', $options)) {
    echo "Uso: php bin/prepare-test-db.php [--db=nombre]\n";
    echo "Ver docblock del fichero para el detalle.\n";
    exit(0);
}

$target = is_array($options) && isset($options['db']) ? (string) $options['db'] : '';
if ($target === '') {
    $target = (string) (getenv('TEST_DB_NAME') ?: 'xproject_test');
}

$source = (string) ($_ENV['DB_DATABASE'] ?? '');
$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');

if ($source === '') {
    fail('DB_DATABASE no definido en .env');
}

foreach (['destino' => $target, 'fuente' => $source] as $etiqueta => $nombre) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $nombre)) {
        fail("Identificador invalido ({$etiqueta}): {$nombre}");
    }
}

if (strcasecmp($target, $source) === 0) {
    fail("La BD de test no puede ser la BD de trabajo ({$source})");
}

$schemaFile = $baseDir . '/database/schema/schema.sql';
if (!is_file($schemaFile)) {
    fail("No existe {$schemaFile}");
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // El juego de caracteres de la BD destino debe ser el de la fuente.
    $charset = $pdo->query(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($source)
    )->fetch();

    if ($charset === false) {
        fail("No existe la BD de trabajo {$source}");
    }

    // Vaciado total: cada suite arranca con el esquema exacto y sin filas.
    $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
    echo "DROP DATABASE {$target}\n";

    $pdo->exec(sprintf(
        'CREATE DATABASE `%s` CHARACTER SET `%s` COLLATE `%s`',
        $target,
        $charset['DEFAULT_CHARACTER_SET_NAME'],
        $charset['DEFAULT_COLLATION_NAME']
    ));

    [$exit, $salida] = runMysqlFile(findMysql(), $schemaFile, $target, $host, $port, $user, $password);
    if ($exit !== 0) {
        fail("schema.sql termino con exit {$exit}: {$salida}");
    }

    $tablas = (int) scalar(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$target}' AND TABLE_TYPE = 'BASE TABLE'"
    );
    if ($tablas === 0) {
        fail("schema.sql no creo tablas en {$target}: {$salida}");
    }

    echo "BD {$target}: esquema aplicado ({$tablas} tablas), sin datos de la BD de trabajo\n";
    echo "BD {$target} lista\n";
    exit(0);
} catch (PDOException $e) {
    fail('PDO: ' . $e->getMessage());
}
