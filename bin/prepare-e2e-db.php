<?php

declare(strict_types=1);

/**
 * Prepara la BD de E2E (xproject_e2e por defecto) sin tocar la BD de trabajo.
 *
 * v1.8.3: los E2E dejan de crear filas en la BD de trabajo. Este comando:
 *   1. Crea la BD destino si no existe (mismo juego de caracteres que la fuente).
 *   2. Si no tiene tablas, aplica database/schema/schema.sql (unico DDL).
 *   3. Copia desde la BD de trabajo SOLO las tablas de identidad y acceso:
 *      tenants, users, roles, permissions, role_assignments. Nunca stock,
 *      movimientos ni auditoria: cada E2E genera sus propios datos.
 *   4. Garantiza los fixtures que los E2E asumen: producto PROD-001 y las
 *      ubicaciones ALMACENAMIENTO y PICKING activas. TRANSITO y DANADOS los
 *      crea el seeder real del dominio (bin/seed-transit-locations.php).
 *
 * Uso:
 *   php bin/prepare-e2e-db.php [--db=nombre] [--fresh] [--sin-fixtures]
 *
 *   --db=nombre     BD destino (prioridad); por defecto E2E_DB_NAME o xproject_e2e
 *   --fresh         borra y recrea la BD destino (pierde lo que hubiera dentro)
 *   --sin-fixtures  no crea producto/ubicaciones (solo esquema y copia)
 *
 * Variables de entorno: E2E_DB_NAME (nombre de la BD) y, via .env,
 * DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE (credenciales de la fuente).
 *
 * Exit 0 si la BD quedo lista; exit 1 si fallo.
 */

use Dotenv\Dotenv;

$baseDir = dirname(__DIR__);

require_once $baseDir . '/vendor/autoload.php';

Dotenv::createImmutable($baseDir)->safeLoad();

/** @var list<string> Tablas de identidad y acceso copiadas desde la BD de trabajo. */
$tablasIdentidad = ['tenants', 'users', 'roles', 'permissions', 'role_assignments'];

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

/**
 * Lanza un CLI PHP con E2E_DB_NAME en el entorno (para seeders de bin/).
 *
 * @return array{0: int, 1: string}
 */
function runPhp(string $script, string $e2eDb): array
{
    $cmd = [PHP_BINARY, $script];

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge(getenv(), ['E2E_DB_NAME' => $e2eDb]);

    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);

    if (!is_resource($process)) {
        fail('No se pudo lanzar: ' . $script);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$exit, trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''))];
}

function scalar(PDO $pdo, string $sql): ?string
{
    $value = $pdo->query($sql)->fetchColumn();

    return $value === false ? null : (string) $value;
}

// ---------------------------------------------------------------- argumentos
$options = getopt('', ['db:', 'fresh', 'sin-fixtures', 'help']);

if (is_array($options) && array_key_exists('help', $options)) {
    echo "Uso: php bin/prepare-e2e-db.php [--db=nombre] [--fresh] [--sin-fixtures]\n";
    echo "Ver docblock del fichero para el detalle de cada opcion.\n";
    exit(0);
}

$fresh = is_array($options) && array_key_exists('fresh', $options);
$fixtures = !(is_array($options) && array_key_exists('sin-fixtures', $options));

$target = is_array($options) && isset($options['db']) ? (string) $options['db'] : '';
if ($target === '') {
    $target = (string) (getenv('E2E_DB_NAME') ?: 'xproject_e2e');
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
    fail("La BD de E2E no puede ser la BD de trabajo ({$source})");
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

    // ---------------------------------------------------------------- 1) BD
    $charset = $pdo->query(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($source)
    )->fetch();

    if ($charset === false) {
        fail("No existe la BD de trabajo {$source}");
    }

    if ($fresh) {
        $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
        echo "DROP DATABASE {$target}\n";
    }

    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET `%s` COLLATE `%s`',
        $target,
        $charset['DEFAULT_CHARACTER_SET_NAME'],
        $charset['DEFAULT_COLLATION_NAME']
    ));

    $tablas = (int) scalar(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$target}' AND TABLE_TYPE = 'BASE TABLE'"
    );

    $nacida = false;
    if ($tablas === 0) {
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

        $nacida = true;
        echo "BD {$target}: esquema aplicado ({$tablas} tablas)\n";
    } else {
        echo "BD {$target}: ya existe ({$tablas} tablas), esquema intacto\n";
    }

    // ------------------------------------------------------------ 2) copia
    // Solo identidad y acceso. Las tablas de negocio las generan los propios
    // E2E; nada de stock, movimientos ni auditoria de la BD de trabajo.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $copiadas = [];

    try {
        foreach ($tablasIdentidad as $tabla) {
            $destino = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$target}`.`{$tabla}`");
            $origen = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$source}`.`{$tabla}`");

            if ($destino > 0) {
                $copiadas[] = "{$tabla}={$destino} (ya con datos)";
                continue;
            }

            if ($origen === 0) {
                $copiadas[] = "{$tabla}=0 (sin filas en la fuente)";
                continue;
            }

            // Sin SELECT *: las columnas generadas STORED (p. ej.
            // users.email_activo) no admiten INSERT y romperian la copia.
            $colsRow = scalar(
                $pdo,
                "SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$source}' AND TABLE_NAME = '{$tabla}'
                   AND EXTRA NOT LIKE '%GENERATED%'"
            );
            if (!is_string($colsRow) || $colsRow === '') {
                fail("No se pudieron listar las columnas de {$source}.{$tabla}");
            }
            $pdo->exec("INSERT INTO `{$target}`.`{$tabla}` ({$colsRow}) SELECT {$colsRow} FROM `{$source}`.`{$tabla}`");
            $nuevo = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$target}`.`{$tabla}`");
            $copiadas[] = "{$tabla}={$nuevo}";
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    echo 'Copia identidad/acceso: ' . implode(', ', $copiadas) . "\n";

    $tenantId = scalar(
        $pdo,
        "SELECT id FROM `{$target}`.tenants WHERE deleted_at IS NULL ORDER BY created_at ASC, id ASC LIMIT 1"
    );

    if ($tenantId === null || !preg_match('/^[0-9a-fA-F-]{36}$/', $tenantId)) {
        fail("La BD {$target} no tiene ningun tenant util tras la copia");
    }

    // ---------------------------------------------------------- 3) fixtures
    if ($fixtures) {
        $pdo->exec(sprintf(
            "INSERT IGNORE INTO `{$target}`.products
                (id, tenant_id, sku, nombre, descripcion, categoria, unidad_medida,
                 es_serializable, es_loteable, activo, created_at, updated_at)
             VALUES
                ('e2e00000-0000-4000-8000-000000000001', %s, 'PROD-001',
                 'Unidad RAM DDR5', 'Fixture de E2E (bin/prepare-e2e-db.php)',
                 'E2E', 'UNIDAD', 0, 0, 1, NOW(), NOW())",
            $pdo->quote($tenantId)
        ));

        $pdo->exec(sprintf(
            "INSERT IGNORE INTO `{$target}`.locations
                (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada,
                 activa, created_at, updated_at)
             VALUES
                ('e2e00000-0000-4000-8000-000000000002', %s, 'E2E-ALMACEN',
                 'Almacen origen E2E', 'ALMACENAMIENTO', NULL, 0, 1, NOW(), NOW()),
                ('e2e00000-0000-4000-8000-000000000003', %s, 'E2E-PICK',
                 'Zona picking E2E', 'PICKING', 1000, 0, 1, NOW(), NOW())",
            $pdo->quote($tenantId),
            $pdo->quote($tenantId)
        ));

        $producto = scalar($pdo, "SELECT sku FROM `{$target}`.products WHERE sku = 'PROD-001'");
        $origen = scalar($pdo, "SELECT codigo FROM `{$target}`.locations WHERE codigo = 'E2E-ALMACEN'");
        $picking = scalar($pdo, "SELECT codigo FROM `{$target}`.locations WHERE codigo = 'E2E-PICK'");

        echo 'Fixtures: producto ' . ($producto ?? 'AUSENTE')
            . ', ubicaciones ' . ($origen ?? 'AUSENTE') . '/' . ($picking ?? 'AUSENTE') . "\n";

        if ($producto === null || $origen === null || $picking === null) {
            fail('No se pudieron crear los fixtures de E2E');
        }
    }

    // ------------------------------------------------- 4) TRANSITO y DANADOS
    // El seeder del dominio (idempotente) es quien crea esas dos ubicaciones:
    // asi los E2E prueban el codigo real y no una copia paralela.
    [$exit, $salida] = runPhp($baseDir . '/bin/seed-transit-locations.php', $target);
    if ($exit !== 0) {
        fail("seed-transit-locations.php termino con exit {$exit}: {$salida}");
    }

    foreach (explode("\n", $salida) as $linea) {
        if (trim($linea) !== '') {
            echo "  seed-transit-locations: {$linea}\n";
        }
    }

    $transito = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$target}`.locations WHERE tipo = 'TRANSITO'");
    $danados = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$target}`.locations WHERE tipo LIKE 'DA__DOS'");
    echo "Ubicaciones fijas: TRANSITO={$transito}, DANADOS={$danados}\n";

    if ($transito === 0 || $danados === 0) {
        fail('El sembrador no dejo TRANSITO/DANADOS activos');
    }

    echo ($nacida ? "BD {$target} creada y lista\n" : "BD {$target} lista\n");
    exit(0);
} catch (PDOException $e) {
    fail('PDO: ' . $e->getMessage());
}
