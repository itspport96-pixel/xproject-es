<?php

declare(strict_types=1);

/**
 * Regenera database/schema/schema.sql desde la BD viva (unico DDL del proyecto).
 *
 * Uso:
 *   php bin/regenerate-schema.php
 *
 * Proceso:
 *   1. Orden topologico de las tablas segun sus claves foraneas
 *      (information_schema.KEY_COLUMN_USAGE) para que el fichero se pueda
 *      recargar en una BD vacia en orden, sin depender del orden de fichas.
 *   2. mysqldump --no-data --skip-comments --skip-dump-date --tables <orden>
 *   3. Limpieza del volcado: se eliminan AUTO_INCREMENT=<n> y DEFINER=...
 *   4. Se escribe database/schema/schema.sql.
 *
 * El fichero generado es un esquema de SOLO ESTRUCTURA (sin filas): los datos
 * (permisos, roles, tenants demo) se crean con los seeders.
 *
 * Variables opcionales de entorno:
 *   MYSQLDUMP_BIN  ruta al ejecutable de mysqldump
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD (via .env)
 *
 * Exit 0 si se regenero; exit 1 si fallo.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

// v1.8.3: schema.sql se regenera SIEMPRE desde la BD de trabajo (.env). Con
// E2E_DB_NAME definida se escribiria el esquema de la BD de E2E en el fichero.
if ((string) (getenv('E2E_DB_NAME') ?: '') !== '') {
    fwrite(STDERR, "ERROR: E2E_DB_NAME definida; este comando opera sobre la BD de trabajo (.env)\n");
    exit(1);
}

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

/** @return list<string> */
function baseTables(PDO $pdo, string $schema, string $type): array
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?
          ORDER BY TABLE_NAME'
    );
    $stmt->execute([$schema, $type]);

    /** @var list<string> */
    return array_map(static fn(array $r): string => (string) $r['TABLE_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Orden topologico (Kahn) de las tablas: una tabla sale despues de todas las
 * tablas a las que apuntan sus claves foraneas. Empate determinista: alfabetico.
 *
 * @param list<string> $tables
 * @return list<string>
 */
function orderByDependencies(PDO $pdo, string $schema, array $tables): array
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
            AND TABLE_NAME <> REFERENCED_TABLE_NAME
          GROUP BY TABLE_NAME, REFERENCED_TABLE_NAME'
    );
    $stmt->execute([$schema]);

    $inDegree = array_fill_keys($tables, 0);
    $dependents = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string) $row['TABLE_NAME'];
        $ref = (string) $row['REFERENCED_TABLE_NAME'];

        if (!isset($inDegree[$table], $inDegree[$ref])) {
            continue;
        }

        $dependents[$ref][] = $table;
        $inDegree[$table]++;
    }

    $order = [];
    $pending = $inDegree;

    while ($pending !== []) {
        $next = null;

        foreach ($pending as $name => $degree) {
            if ($degree === 0) {
                $next = $name;
                break;
            }
        }

        if ($next === null) {
            fail(
                'Ciclo de claves foraneas entre: ' . implode(', ', array_keys($pending))
                . ' (no se puede calcular el orden de tablas)'
            );
        }

        $order[] = $next;
        unset($pending[$next]);

        foreach ($dependents[$next] ?? [] as $child) {
            if (isset($pending[$child])) {
                $pending[$child]--;
            }
        }
    }

    return $order;
}

function findMysqldump(): string
{
    $candidates = [
        getenv('MYSQLDUMP_BIN') ?: null,
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        '/opt/lampp/bin/mysqldump',
        '/usr/bin/mysqldump',
        'mysqldump',
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

    fail('No se encontro mysqldump (define MYSQLDUMP_BIN)');
}

/**
 * @param list<string> $args
 * @return array{0: string, 1: string} [stdout, stderr]
 */
function runCommand(array $args, string $password): array
{
    $cmd = implode(' ', array_map(static fn(string $a): string => escapeshellarg($a), $args));

    $env = array_merge(getenv(), $password !== '' ? ['MYSQL_PWD' => $password] : []);

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);

    if (!is_resource($process)) {
        fail('No se pudo ejecutar: ' . $args[0]);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fail("mysqldump termino con exit {$exitCode}: " . trim($stderr));
    }

    return [$stdout, $stderr];
}

function cleanDump(string $sql): string
{
    $sql = preg_replace('/[ \t]*AUTO_INCREMENT=\d+/', '', $sql) ?? $sql;
    $sql = preg_replace('/[ \t]*DEFINER=\S+/', '', $sql) ?? $sql;
    $sql = preg_replace("/\r\n/", "\n", $sql) ?? $sql;

    return $sql;
}

$container = App\Shared\Container\Container::getInstance();
/** @var PDO $pdo */
$pdo = $container->get('database')->getConnection();

$schema = (string) ($_ENV['DB_DATABASE'] ?? '');
$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');

if ($schema === '') {
    fail('DB_DATABASE no definido en .env');
}

$tables = baseTables($pdo, $schema, 'BASE TABLE');
$views = baseTables($pdo, $schema, 'VIEW');

if ($tables === []) {
    fail("La BD {$schema} no tiene tablas");
}

$orderByDeps = orderByDependencies($pdo, $schema, $tables);
$ordered = array_merge($orderByDeps, $views);

$mysqldump = findMysqldump();

$args = [
    $mysqldump,
    '--user=' . $user,
    '--host=' . $host,
    '--port=' . $port,
    '--protocol=TCP',
    '--no-data',
    '--skip-comments',
    '--skip-dump-date',
    '--default-character-set=utf8mb4',
    $schema,
    '--tables',
    ...$ordered,
];

[$raw] = runCommand($args, $password);

$dump = cleanDump($raw);

$header = implode("\n", [
    '-- ESQUEMA DE ESTRUCTURA (solo DDL, sin filas).',
    '-- GENERADO DESDE LA BD VIVA: no editar a mano.',
    '-- Regenerar: php bin/regenerate-schema.php',
    '-- Verificar: php bin/check-schema-drift.php',
    '',
    '',
]);

$schemaDir = realpath(__DIR__ . '/../database/schema');

if ($schemaDir === false) {
    fail('No existe el directorio database/schema');
}

$target = $schemaDir . '/schema.sql';

if (@file_put_contents($target, $header . $dump) === false) {
    fail("No se pudo escribir {$target}");
}

$created = substr_count($dump, 'CREATE TABLE');
$viewsCreated = substr_count($dump, 'CREATE VIEW');
$stripped = preg_match_all('/AUTO_INCREMENT=\d+|DEFINER=\S+/', $raw);

printf("schema.sql regenerado: %s\n", $target);
printf("  tablas: %d (orden de dependencias), vistas: %d, bytes: %d\n", $created, $viewsCreated, filesize($target));
printf("  AUTO_INCREMENT/DEFINER eliminados del volcado: %d\n", $stripped);
printf("  orden: %s\n", implode(', ', $ordered));
printf(
    "  vs BD viva: %d tabla(s), %d vista(s)\n",
    count($tables),
    count($views)
);

exit(0);
