<?php

declare(strict_types=1);

/**
 * Comprueba la integridad de los DATOS de la BD (no del esquema).
 *
 * Uso:
 *   php bin/check-data-integrity.php [--db=nombre]
 *
 *   --db=nombre   BD a inspeccionar; por defecto E2E_DB_NAME o la de trabajo.
 *
 * Comprobaciones (cada una lista las violaciones encontradas):
 *   1. Enums invalidos: columnas ENUM con valor en el indice 0. MariaDB
 *      guarda '' (indice 0) cuando una insercion no coincide con ningun
 *      miembro del ENUM y el modo estricto esta desactivado; la hidratacion
 *      del dominio (p. ej. LocationType::fromString) luego lanza 400 y rompe
 *      el listado entero.
 *   2. Fechas cero: DATE/DATETIME/TIMESTAMP con '0000-00-00[ 00:00:00]'.
 *   3. Fechas absurdas: anio fuera del rango 1970-2100.
 *   4. NULL en columnas NOT NULL (sin default, sin auto_increment ni generadas).
 *   5. JSON invalido: columnas con restriccion CHECK json_valid(...) que
 *      guardan algo que no es JSON.
 *   6. Claves foraneas huerfanas: filas hijas cuyo padre ya no existe (se
 *      comprueba con LEFT JOIN; las filas hijas con la columna a NULL se
 *      ignoran, son opcionales por diseno).
 *
 * Sale con 0 si los datos son consistentes; 1 si hay violaciones.
 *
 * A diferencia de check-schema-drift.php SI se permite ejecutarlo contra la
 * BD de E2E: prepare-e2e-db.php lo invoca tras sembrar para garantizar que el
 * dataset de partida de los E2E esta limpio.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

/**
 * @return array{0: string, 1: list<string>} nombre de la BD y avisos
 */
function resolveDatabase(array $argv): array
{
    $warnings = [];
    $db = '';

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--db=')) {
            $db = substr($arg, strlen('--db='));
        }
    }

    if ($db === '') {
        $db = (string) (getenv('E2E_DB_NAME') ?: ($_ENV['DB_DATABASE'] ?? ''));
    }

    if ($db === '') {
        fail('No se pudo determinar la BD (define --db= o E2E_DB_NAME o DB_DATABASE)');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        fail("Identificador de BD invalido: {$db}");
    }

    return [$db, $warnings];
}

/**
 * Ejecuta un COUNT y devuelve el numero de filas que violan la condicion.
 */
function countViolations(PDO $pdo, string $db, string $table, string $condition, string $column): int
{
    $sql = sprintf(
        'SELECT COUNT(*) FROM `%s`.`%s` WHERE %s',
        $db,
        $table,
        str_replace('{col}', "`{$column}`", $condition)
    );

    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        fail("No se pudo contar en {$db}.{$table}.{$column}: {$e->getMessage()}");
    }
}

$argvList = $argv ?? [];
[$db, $warnings] = resolveDatabase($argvList);

$container = App\Shared\Container\Container::getInstance();

/** @var PDO $pdo */
$pdo = $container->get('database')->getConnection();

$existe = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($db)
)->fetchColumn();

if ($existe === 0) {
    fail("No existe la BD {$db}");
}

printf("check-data-integrity\n");
printf("  BD: %s\n", $db);

foreach ($warnings as $warning) {
    printf("  [AVISO] %s\n", $warning);
}

/** @var list<array<string, string>> $violations */
$violations = [];

function addViolation(array &$violations, string $check, string $table, string $column, int $count, string $hint = ''): void
{
    if ($count <= 0) {
        return;
    }

    $violations[] = [
        'comprobacion' => $check,
        'tabla' => $table,
        'columna' => $column,
        'filas' => $count,
        'pista' => $hint,
    ];
}

// ------------------------------------------------------------ 1) ENUMs
$enumCols = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND COLUMN_TYPE LIKE "enum%"
      ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$enumCols->execute([$db]);

$enumTotal = 0;
foreach ($enumCols->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $enumTotal++;
    $n = countViolations($pdo, $db, (string) $row['TABLE_NAME'], '{col} + 0 = 0', (string) $row['COLUMN_NAME']);
    addViolation(
        $violations,
        'ENUM invalido (indice 0)',
        (string) $row['TABLE_NAME'],
        (string) $row['COLUMN_NAME'],
        $n,
        'el valor no coincide con ningun miembro del ENUM'
    );
}

// --------------------------------------------------------- 2/3) FECHAS
$dateCols = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ("date", "datetime", "timestamp")
      ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$dateCols->execute([$db]);

$dateTotal = 0;
foreach ($dateCols->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dateTotal++;
    $table = (string) $row['TABLE_NAME'];
    $column = (string) $row['COLUMN_NAME'];

    $n = countViolations(
        $pdo,
        $db,
        $table,
        "{col} = '0000-00-00' OR {col} = '0000-00-00 00:00:00'",
        $column
    );
    addViolation($violations, 'Fecha cero', $table, $column, $n, "'0000-00-00' no es una fecha valida");

    $n = countViolations(
        $pdo,
        $db,
        $table,
        '{col} IS NOT NULL AND (YEAR({col}) < 1970 OR YEAR({col}) > 2100)',
        $column
    );
    addViolation($violations, 'Fecha absurda', $table, $column, $n, 'anio fuera de 1970-2100');
}

// ------------------------------------------------------ 4) NULL en NOT NULL
$notNullCols = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND IS_NULLABLE = "NO"
        AND COLUMN_DEFAULT IS NULL
        AND EXTRA NOT LIKE "%auto_increment%"
        AND EXTRA NOT LIKE "%GENERATED%"
      ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$notNullCols->execute([$db]);

$notNullTotal = 0;
foreach ($notNullCols->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $notNullTotal++;
    $n = countViolations($pdo, $db, (string) $row['TABLE_NAME'], '{col} IS NULL', (string) $row['COLUMN_NAME']);
    addViolation(
        $violations,
        'NULL en columna NOT NULL',
        (string) $row['TABLE_NAME'],
        (string) $row['COLUMN_NAME'],
        $n,
        'la columna no admite NULL'
    );
}

// ------------------------------------------------------------- 5) JSON
// Sólo las columnas con restriccion CHECK json_valid(...): el resto de
// longtext puede guardar texto libre y no debe validarse como JSON.
$jsonCols = $pdo->prepare(
    'SELECT TABLE_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = ? AND CHECK_CLAUSE LIKE "%json_valid%"
      ORDER BY TABLE_NAME, CONSTRAINT_NAME'
);
$jsonCols->execute([$db]);

$jsonTotal = 0;
foreach ($jsonCols->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $clause = (string) $row['CHECK_CLAUSE'];

    if (!preg_match('/json_valid\(`?([A-Za-z0-9_]+)`?\)/', $clause, $m)) {
        continue;
    }

    $jsonTotal++;
    $table = (string) $row['TABLE_NAME'];
    $column = $m[1];
    $n = countViolations($pdo, $db, $table, '{col} IS NOT NULL AND NOT JSON_VALID({col})', $column);
    addViolation($violations, 'JSON invalido', $table, $column, $n, 'el contenido no es JSON valido');
}

// ------------------------------------------- 6) CLAVES FORANEAS HUERFANAS
$fkRows = $pdo->prepare(
    'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
            REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
);
$fkRows->execute([$db]);

/** @var array<string, array<string, mixed>> $fks */
$fks = [];

foreach ($fkRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];

    if (!isset($fks[$key])) {
        $fks[$key] = [
            'table' => (string) $row['TABLE_NAME'],
            'refTable' => (string) $row['REFERENCED_TABLE_NAME'],
            'pairs' => [],
        ];
    }

    $fks[$key]['pairs'][] = [
        'child' => (string) $row['COLUMN_NAME'],
        'parent' => (string) $row['REFERENCED_COLUMN_NAME'],
    ];
}

$fkTotal = count($fks);

foreach ($fks as $fk) {
    $joins = [];
    $wheres = [];

    foreach ($fk['pairs'] as $pair) {
        $joins[] = sprintf('`p`.`%s` = `c`.`%s`', $pair['parent'], $pair['child']);
        $wheres[] = sprintf('`c`.`%s` IS NOT NULL', $pair['child']);
        // Huerfana = la fila hija existe pero el padre no coincide (LEFT JOIN a NULL).
        $wheres[] = sprintf('`p`.`%s` IS NULL', $pair['parent']);
    }

    $sql = sprintf(
        'SELECT COUNT(*) FROM `%s`.`%s` `c` LEFT JOIN `%s`.`%s` `p` ON %s WHERE %s',
        $db,
        $fk['table'],
        $db,
        $fk['refTable'],
        implode(' AND ', $joins),
        implode(' AND ', $wheres)
    );

    try {
        $n = (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        fail('No se pudo comprobar la FK ' . $fk['table'] . ': ' . $e->getMessage());
    }

    addViolation(
        $violations,
        'Clave foranea huerfana',
        (string) $fk['table'],
        '-',
        $n,
        sprintf('padre ausente en %s', $fk['refTable'])
    );
}

// ------------------------------------------------------------- informe
printf("\n");
printf("  %-14s %d columnas ENUM\n", 'cobertura:', $enumTotal);
printf("  %-14s %d columnas de fecha\n", '', $dateTotal);
printf("  %-14s %d columnas NOT NULL sin default\n", '', $notNullTotal);
printf("  %-14s %d columnas con CHECK json_valid\n", '', $jsonTotal);
printf("  %-14s %d claves foraneas\n\n", '', $fkTotal);

if ($violations === []) {
    printf("RESULTADO: DATOS CONSISTENTES (sin violaciones)\n");
    exit(0);
}

foreach ($violations as $v) {
    printf(
        "[VIOLACION] %s -> %s.%s: %d fila(s)%s\n",
        $v['comprobacion'],
        $v['tabla'],
        $v['columna'],
        $v['filas'],
        $v['pista'] !== '' ? ' (' . $v['pista'] . ')' : ''
    );
}

printf("\nRESULTADO: DATOS INCONSISTENTES (%d violacion(es))\n", count($violations));
exit(1);
