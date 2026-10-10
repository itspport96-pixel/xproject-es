<?php

declare(strict_types=1);

/**
 * Comprueba que database/schema/schema.sql refleja exactamente la BD viva.
 *
 * Uso:
 *   php bin/check-schema-drift.php
 *
 * Proceso:
 *   1. Crea una BD temporal (xproject_schema_check) y carga schema.sql en ella.
 *   2. Compara BD temporal vs BD viva usando information_schema:
 *      tablas (existencia, motor, colacion), columnas (tipo, nulabilidad,
 *      default, extra, juego de caracteres), indices (unicidad y columnas),
 *      enums y claves foraneas.
 *   3. Elimina la BD temporal y sale con 0 si no hay drift, 1 si lo hay.
 *
 * La BD viva es la fuente de verdad: si hay drift, regenera el fichero con
 *   php bin/regenerate-schema.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

// v1.8.3: el drift se comprueba SIEMPRE contra la BD de trabajo (.env). Si
// E2E_DB_NAME apunta a la BD de E2E, la comparacion seria contra otro esquema.
if ((string) (getenv('E2E_DB_NAME') ?: '') !== '') {
    fwrite(STDERR, "ERROR: E2E_DB_NAME definida; este comando opera sobre la BD de trabajo (.env)\n");
    exit(1);
}

const DRIFT_DB = 'xproject_schema_check';

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function identifier(string $value, string $label): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        fail("Identificador invalido ({$label}): {$value}");
    }

    return $value;
}

/**
 * @return array<string, mixed> tabla => informacion de estructura
 */
function readStructure(PDO $pdo, string $db): array
{
    $db = identifier($db, 'bd');

    $tables = [];
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE"
          ORDER BY TABLE_NAME'
    );
    $stmt->execute([$db]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tables[(string) $row['TABLE_NAME']] = sprintf(
            '%s|%s',
            $row['ENGINE'] ?? '-',
            $row['TABLE_COLLATION'] ?? '-'
        );
    }

    $columns = [];
    $enums = [];
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $stmt->execute([$db]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'];
        $columns[$key] = sprintf(
            '%s|nullable=%s|default=%s|extra=%s|charset=%s|collation=%s',
            $row['COLUMN_TYPE'],
            $row['IS_NULLABLE'],
            var_export($row['COLUMN_DEFAULT'], true),
            $row['EXTRA'],
            $row['CHARACTER_SET_NAME'] ?? '-',
            $row['COLLATION_NAME'] ?? '-'
        );

        if (str_starts_with((string) $row['COLUMN_TYPE'], 'enum')) {
            $enums[$key] = (string) $row['COLUMN_TYPE'];
        }
    }

    $indexes = [];
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    );
    $stmt->execute([$db]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];

        if (!isset($indexes[$key])) {
            $indexes[$key] = sprintf(
                'unique=%s|type=%s|columnas=',
                $row['NON_UNIQUE'] === 0 ? 'si' : 'no',
                $row['INDEX_TYPE']
            );
        }

        $indexes[$key] .= $row['COLUMN_NAME'] . ',';
    }

    $foreignKeys = [];
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );
    $stmt->execute([$db]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];

        if (!isset($foreignKeys[$key])) {
            $foreignKeys[$key] = '';
        }

        $foreignKeys[$key] .= sprintf(
            '%s->%s(%s),',
            $row['COLUMN_NAME'],
            $row['REFERENCED_TABLE_NAME'],
            $row['REFERENCED_COLUMN_NAME']
        );
    }

    ksort($tables);
    ksort($columns);
    ksort($enums);
    ksort($indexes);
    ksort($foreignKeys);

    return [
        'tablas' => $tables,
        'columnas' => $columns,
        'indices' => $indexes,
        'enums' => $enums,
        'claves foraneas' => $foreignKeys,
    ];
}

/**
 * @param array<string, string> $expected
 * @param array<string, string> $actual
 * @return list<string>
 */
function diff(string $label, array $expected, array $actual, string $expectedName, string $actualName): array
{
    $problems = [];

    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $actual)) {
            $problems[] = "[DRIFT] {$label} solo en {$expectedName}: {$key}";
            continue;
        }

        if ($actual[$key] !== $value) {
            $problems[] = sprintf(
                "[DRIFT] %s %s:\n        %-7s %s\n        %-7s %s",
                $label,
                $key,
                $expectedName . ':',
                $value,
                $actualName . ':',
                $actual[$key]
            );
        }
    }

    foreach ($actual as $key => $value) {
        if (!array_key_exists($key, $expected)) {
            $problems[] = "[DRIFT] {$label} solo en {$actualName}: {$key}";
        }
    }

    return $problems;
}

$schemaFile = realpath(__DIR__ . '/../database/schema/schema.sql');

if ($schemaFile === false || !is_file($schemaFile)) {
    fail('No existe database/schema/schema.sql');
}

$sql = file_get_contents($schemaFile);

if ($sql === false || trim($sql) === '') {
    fail('database/schema/schema.sql esta vacio');
}

if (str_starts_with($sql, "\xEF\xBB\xBF")) {
    fwrite(STDERR, "[AVISO] BOM UTF-8 al inicio de schema.sql: se ignora aqui, pero `mysql < schema.sql` lo rechazaria.\n");
    $sql = substr($sql, 3);
}

$container = App\Shared\Container\Container::getInstance();

/** @var PDO $live */
$live = $container->get('database')->getConnection();

$liveDb = identifier((string) ($_ENV['DB_DATABASE'] ?? ''), 'bd');
$tempDb = identifier(DRIFT_DB, 'bd');
$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
$password = (string) ($_ENV['DB_PASSWORD'] ?? '');

$serverOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

/** @var PDO $admin */
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    $serverOptions
);

printf("check-schema-drift\n");
printf("  BD viva : %s\n", $liveDb);
printf("  fichero : database/schema/schema.sql (%d bytes)\n", (int) filesize($schemaFile));
printf("  BD temp : %s (creada desde el fichero)\n", $tempDb);

$admin->exec("DROP DATABASE IF EXISTS `{$tempDb}`");
$admin->exec("CREATE DATABASE `{$tempDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$counts = [];

try {
    $options = $serverOptions;

    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
    }

    /** @var PDO $temp */
    $temp = new PDO(
        "mysql:host={$host};port={$port};dbname={$tempDb};charset=utf8mb4",
        $user,
        $password,
        $options
    );

    try {
        $temp->exec($sql);
    } catch (PDOException $e) {
        printf("\n[DRIFT] error al cargar schema.sql en la BD temporal: %s\n", $e->getMessage());
        printf("\nRESULTADO: DRIFT (fichero no instalable)\n");
        exit(1);
    }

    $expected = readStructure($temp, $tempDb);
    $actual = readStructure($live, $liveDb);

    $problems = [];

    foreach ($expected as $label => $structure) {
        $problems = array_merge($problems, diff($label, $structure, $actual[$label], 'fichero', 'viva'));
    }

    $counts = [];
    foreach ($expected as $label => $structure) {
        $counts[$label] = count($structure);
    }
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$tempDb}`");
}

printf("\n");

foreach ($counts as $label => $count) {
    printf("  %-16s %d\n", $label, $count);
}

printf("\n");

if ($problems === []) {
    printf("RESULTADO: SIN DRIFT (schema.sql == BD viva)\n");
    exit(0);
}

foreach ($problems as $problem) {
    printf("%s\n", $problem);
}

printf("\nRESULTADO: DRIFT (%d diferencia(s)). Regenera con: php bin/regenerate-schema.php\n", count($problems));
exit(1);
