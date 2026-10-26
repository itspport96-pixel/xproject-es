<?php

declare(strict_types=1);

/**
 * Comando: devuelve la BD de trabajo al baseline operativo tras una prueba.
 *
 * Uso:
 *   php bin/reset-db.php                  # dry-run: que se borraria y que se conserva
 *   php bin/reset-db.php --apply          # resetea de verdad
 *   php bin/reset-db.php --apply --dump   # ... y regenera el dump origen limpio
 *   php bin/reset-db.php --apply --dump=<ruta>
 *   php bin/reset-db.php --help
 *
 * Motivo (v1.19.4): la plataforma se prueba de flujo completo en otra maquina
 * y tras cada prueba hay que volver al punto de partida SIN volver a instalar
 * seeds. Este comando conserva exactamente lo que hace operativa la BD:
 *
 *   - tenants            el tenant del usuario operativo (owner reasignado a el)
 *   - permissions        catalogo completo de permisos
 *   - roles              ADMIN, MANAGER, USER, VIEWER y AUDITOR (los BUILT_IN +
 *                        AUDITOR); cualquier otro rol (residuo de prueba) se borra
 *   - role_permissions   mapeo rol->permiso de los roles conservados
 *   - users              solo aleon@acme.local, con la contrasena de siempre,
 *                        sin MFA, sin sesiones y sin cambio de contrasena forzado
 *   - role_assignments   solo la asignacion ADMIN del usuario operativo
 *   - password_history   solo las filas del usuario operativo
 *   - rate_limit_config  configuracion de rate limit (tabla de config)
 *
 * Todo lo demas se vacia: inventario, envios, conteos, grupos, auditoria,
 * sesiones (refresh_tokens), tokens de reset, permisos temporales, rate_limit_log,
 * idempotency_keys, mail_outbox... La lista de tablas NO esta hardcodeada: se
 * descubre en tiempo de ejecucion desde information_schema, de modo que una tabla
 * nueva del esquema ya queda incluida sin tocar este fichero.
 *
 * Seguridad:
 *   - Sin --apply solo cuenta lo que borraria (dry-run), igual que
 *     bin/purge-mail-outbox.php.
 *   - Falla si E2E_DB_NAME esta definida: la BD de E2E se recrea con
 *     prepare-e2e-db --fresh (reglas 10 y 16 de AGENTS.md), nunca con este script.
 *   - Si no queda tenant o no existe el rol ADMIN no se inventa nada: se aborta
 *     con un mensaje (restaurar el dump o ejecutar PlatformSeeder.php, idempotente).
 *   - Tras aplicar invoca bin/check-data-integrity.php y propaga su exit code,
 *     de modo que un reset que dejara claves huerfanas sale con error.
 *
 * El dump por defecto es database/dumps/origen-limpio.sql (esta en .gitignore):
 * ese fichero ES el origen limpio de la plataforma y se regenera siempre desde
 * aqui, con --apply --dump.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

const OPERATOR_EMAIL = 'aleon@acme.local';
const OPERATOR_PASSWORD = 'Admin123!';
const OPERATOR_NAME = ['Leon', 'Jassiel'];
const OPERATOR_PREFERENCES = '{"idioma":"en"}';
const KEEP_ROLES = ['ADMIN', 'MANAGER', 'USER', 'VIEWER', 'AUDITOR'];
const DEFAULT_DUMP = 'database/dumps/origen-limpio.sql';

/**
 * Tablas que jamas se vacian enteras (datos operativos); en varias se filtra
 * por usuario/rol en sucesivos bloques.
 */
const PROTECTED_TABLES = [
    'tenants',
    'permissions',
    'roles',
    'role_permissions',
    'users',
    'role_assignments',
    'password_history',
    'rate_limit_config',
];

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function out(string $msg): void
{
    echo $msg . "\n";
}

/** @return list<string> */
function tablesOf(PDO $pdo, string $db): array
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE"
          ORDER BY TABLE_NAME'
    );
    $stmt->execute([$db]);

    /** @var list<string> */
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function rowsIn(PDO $pdo, string $table): int
{
    return (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
}

/**
 * Localiza mysqldump (XAMPP: C:\xampp\php\..\mysql\bin) o usa el del PATH.
 */
function resolveMysqldump(): string
{
    $candidates = [
        dirname(PHP_BINARY) . '/../mysql/bin/mysqldump.exe',
        'C:/xampp/mysql/bin/mysqldump.exe',
        'C:/xampp/mysql/bin/mysqldump',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return 'mysqldump';
}

/**
 * Vuelca la BD al fichero indicado. Con --routines; si el usuario no tiene
 * privilegios sobre los stored routines se reintenta sin esa opcion (los
 * procedimientos almacenados no los usa la plataforma).
 */
function dumpDatabase(PDO $pdo, string $db, string $target, string $user, string $password, string $host, string $port): void
{
    $dir = dirname($target);
    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        fail("No se pudo crear la carpeta del dump: {$dir}");
    }

    $mysqldump = resolveMysqldump();
    $base = sprintf(
        '%s -u %s -h %s -P %s --single-transaction --no-tablespaces --add-drop-table --default-character-set=utf8mb4',
        escapeshellarg($mysqldump),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($port)
    );
    // El password va por MYSQL_PWD: no debe aparecer en la linea de procesos.
    putenv('MYSQL_PWD=' . $password);

    $ultimaSalida = [];
    foreach (['--routines --triggers', '--triggers', ''] as $extra) {
        $cmd = trim($base . ' ' . $extra . ' ' . escapeshellarg($db) . ' > ' . escapeshellarg($target) . ' 2>&1');
        $salida = [];
        $codigo = 0;
        exec($cmd, $salida, $codigo);
        $ultimaSalida = $salida;

        clearstatcache();
        if ($codigo === 0 && is_file($target) && filesize($target) > 1000) {
            printf(
                "  dump: %s (%.1f KB)%s\n",
                $target,
                filesize($target) / 1024,
                $extra !== '' ? ' [' . $extra . ']' : ''
            );
            putenv('MYSQL_PWD');
            return;
        }
    }

    putenv('MYSQL_PWD');
    fail('mysqldump fallo: ' . implode("\n", array_slice($ultimaSalida, -5)));
}

// --------------------------------------------------------------- argumentos
$aplicar = false;
$dump = false;
$dumpRuta = '';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/reset-db.php [--apply] [--dump[=<ruta>]]\n";
        echo "  Sin --apply hace dry-run (solo cuenta lo que borraria).\n";
        echo "  --dump genera el origen limpio (por defecto " . DEFAULT_DUMP . ").\n";
        exit(0);
    }
    if ($arg === '--apply') {
        $aplicar = true;
        continue;
    }
    if ($arg === '--dump') {
        $dump = true;
        continue;
    }
    if (str_starts_with($arg, '--dump=')) {
        $dump = true;
        $dumpRuta = substr($arg, strlen('--dump='));
        continue;
    }

    fail("Argumento desconocido: {$arg} (usa --help)");
}

if ($dump && ! $aplicar) {
    fail('--dump exige --apply: el origen limpio siempre se genera tras un reset');
}

// ------------------------------------------------- aislamiento de la BD E2E
if ((string) (getenv('E2E_DB_NAME') ?: '') !== '') {
    fail('E2E_DB_NAME definida; este comando opera sobre la BD de trabajo (.env). '
        . 'Para la BD de E2E usa: php bin/prepare-e2e-db.php --fresh');
}

$container = App\Shared\Container\Container::getInstance();

/** @var PDO $pdo */
$pdo = $container->get('database')->getConnection();

$db = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?? '');
if ($db === '') {
    fail('No se pudo determinar la BD de trabajo (DB_DATABASE)');
}

$existe = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($db)
)->fetchColumn();
if ($existe === 0) {
    fail("No existe la BD {$db}");
}

$tables = tablesOf($pdo, $db);
printf("reset-db\n  BD: %s  (dry-run: %s)\n", $db, $aplicar ? 'NO, se aplicara' : 'si');

// ------------------------------------------------------------- dry-run/plan
$borrarEnteras = [];
foreach ($tables as $table) {
    if (in_array($table, PROTECTED_TABLES, true)) {
        continue;
    }
    $n = rowsIn($pdo, $table);
    if ($n > 0) {
        $borrarEnteras[$table] = $n;
    }
}

$total = array_sum($borrarEnteras);
printf("\n  tablas a vaciar: %d (con datos: %d, filas: %d)\n", count($tables) - count(PROTECTED_TABLES), count($borrarEnteras), $total);
foreach ($borrarEnteras as $table => $n) {
    printf("    %-24s %d\n", $table, $n);
}

if (! $aplicar) {
    out('');
    out('  Dry-run: anade --apply para borrar.');
    out(sprintf('  Se conserva: %s', implode(', ', PROTECTED_TABLES)));
    exit(0);
}

// ------------------------------------------------------------------ aplicar
// Se busca sin filtrar deleted_at: si un test dejo al operativo borrado, en vez
// de recrearlo con otro id se restaura el que ya existe (regla 11: el identificador
// se puede reutilizar porque la columna generada devuelve NULL con deleted_at).
$stmt = $pdo->prepare('SELECT id, tenant_id, email FROM users WHERE email = ? ORDER BY deleted_at IS NULL DESC LIMIT 1');
$stmt->execute([OPERATOR_EMAIL]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$tenantId = $usuario['tenant_id'] ?? null;
if ($tenantId === null) {
    $stmt = $pdo->query('SELECT id FROM tenants WHERE deleted_at IS NULL ORDER BY fecha_creacion LIMIT 1');
    $tenantId = $stmt->fetchColumn() ?: null;
}
if ($tenantId === null) {
    fail('No queda ningun tenant en la BD; restaura el origen limpio: '
        . 'mysql -u root ' . $db . ' < ' . DEFAULT_DUMP);
}

$rolAdmin = null;
$stmt = $pdo->prepare("SELECT id FROM roles WHERE nombre = 'ADMIN' AND deleted_at IS NULL LIMIT 1");
$stmt->execute();
$rolAdmin = $stmt->fetchColumn() ?: null;
if ($rolAdmin === null) {
    fail("Falta el rol ADMIN; ejecuta 'php database/seeders/PlatformSeeder.php' (idempotente) y vuelve a lanzar este script");
}

$hash = password_hash(OPERATOR_PASSWORD, PASSWORD_DEFAULT);
$borradas = [];

try {
    // FOREIGN_KEY_CHECKS solo es de sesion: fuera de la transaccion.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();

    // 1) todas las tablas no protegidas a cero (descubiertas en runtime)
    foreach ($tables as $table) {
        if (in_array($table, PROTECTED_TABLES, true)) {
            continue;
        }
        $n = rowsIn($pdo, $table);
        if ($n === 0) {
            continue;
        }
        $pdo->exec(sprintf('DELETE FROM `%s`', $table));
        $borradas[$table] = $n;
    }

    // 2) solo un tenant: el del usuario operativo
    $pdo->exec('DELETE FROM tenants WHERE id <> ' . $pdo->quote((string) $tenantId));

    // 3) usuarios: solo el operativo (los hijos ya estan vacios)
    $stmt = $pdo->prepare('DELETE FROM users WHERE id <> ?');
    $stmt->execute([$usuario['id'] ?? '__ninguno__']);
    if ($stmt->rowCount() > 0) {
        $borradas['users'] = ($borradas['users'] ?? 0) + $stmt->rowCount();
    }

    // 4) roles de prueba fuera; luego sus permisos (los hijos de roles)
    $placeholders = implode(',', array_fill(0, count(KEEP_ROLES), '?'));
    $stmt = $pdo->prepare("DELETE FROM roles WHERE nombre NOT IN ({$placeholders})");
    $stmt->execute(KEEP_ROLES);
    if ($stmt->rowCount() > 0) {
        $borradas['roles'] = $stmt->rowCount();
    }
    $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id NOT IN (SELECT id FROM roles WHERE nombre IN ({$placeholders}))");
    $stmt->execute(KEEP_ROLES);
    if ($stmt->rowCount() > 0) {
        $borradas['role_permissions'] = $stmt->rowCount();
    }

    // 5) asignaciones de rol: solo las del operativo en roles conservados
    $stmt = $pdo->prepare('DELETE FROM role_assignments WHERE user_id <> ? OR role_id NOT IN (SELECT id FROM roles WHERE nombre IN (' . $placeholders . '))');
    $stmt->execute(array_merge([$usuario['id'] ?? '__ninguno__'], KEEP_ROLES));
    if ($stmt->rowCount() > 0) {
        $borradas['role_assignments'] = $stmt->rowCount();
    }

    // 6) historial de contrasenas: solo el del operativo
    $stmt = $pdo->prepare('DELETE FROM password_history WHERE user_id <> ?');
    $stmt->execute([$usuario['id'] ?? '__ninguno__']);
    if ($stmt->rowCount() > 0) {
        $borradas['password_history'] = $stmt->rowCount();
    }

    // 7) baseline del usuario operativo: siempre la misma credencial y sin sesiones
    if ($usuario === false || $usuario === null) {
        $nuevoId = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            0x4000 | random_int(0, 0x0fff),
            0x8000 | random_int(0, 0x3fff),
            random_int(0, 0xffffffffffff)
        );
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, email_verificado,
                                fecha_expiracion_password, preferencias, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "ACTIVO", 0, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, NOW())'
        );
        $stmt->execute([
            $nuevoId, $tenantId, OPERATOR_EMAIL, OPERATOR_NAME[0], OPERATOR_NAME[1], $hash, OPERATOR_PREFERENCES,
        ]);
        $usuario = ['id' => $nuevoId, 'tenant_id' => $tenantId, 'email' => OPERATOR_EMAIL];
        out('  [baseline] recreado el usuario operativo ' . OPERATOR_EMAIL);
    } else {
        // Credencial, perfil y flags tal y como sale del origen limpio: nada de
        // sesiones, MFA, verificacion, avatar ni preferencias residuales de un test.
        $stmt = $pdo->prepare(
            'UPDATE users SET password = ?, estado = "ACTIVO", deleted_at = NULL,
                    email_verificado = 0, telefono = NULL, avatar_url = NULL, preferencias = ?,
                    fecha_expiracion_password = DATE_ADD(NOW(), INTERVAL 30 DAY),
                    mfa_habilitado = 0, mfa_metodo = NULL, mfa_secret = NULL, mfa_backup_codes = NULL,
                    password_change_forced = NULL, ultimo_login = NULL
              WHERE id = ?'
        );
        $stmt->execute([$hash, OPERATOR_PREFERENCES, $usuario['id']]);
    }

    // 8) asignacion ADMIN del operativo (si un test la borro o cambio de rol)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM role_assignments WHERE user_id = ? AND role_id = ?');
    $stmt->execute([$usuario['id'], $rolAdmin]);
    if ((int) $stmt->fetchColumn() === 0) {
        $nuevoId = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            0x4000 | random_int(0, 0x0fff),
            0x8000 | random_int(0, 0x3fff),
            random_int(0, 0xffffffffffff)
        );
        $stmt = $pdo->prepare(
            'INSERT INTO role_assignments (id, user_id, role_id, tenant_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$nuevoId, $usuario['id'], $rolAdmin, $tenantId]);
        out('  [baseline] restaurada la asignacion ADMIN del operativo');
    }

    // 9) el tenant queda a nombre del operativo
    $stmt = $pdo->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?');
    $stmt->execute([$usuario['id'], $tenantId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fail('Reset abortado (transaccion revertida): ' . $e->getMessage());
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

if ($borradas === []) {
    out('  tablas vaciadas: ninguna (la BD ya estaba en baseline)');
} else {
    out('  tablas vaciadas:');
    ksort($borradas);
    foreach ($borradas as $table => $n) {
        printf("    %-24s %d\n", $table, $n);
    }
}

// --------------------------------------------------------------- verificacion
out('');
out('  verificacion: bin/check-data-integrity.php');
$integrity = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-data-integrity.php');
exec($integrity, $salida, $codigo);
foreach ($salida as $linea) {
    if (str_contains($linea, 'RESULTADO') || str_contains($linea, 'BD:')) {
        out('    ' . trim($linea));
    }
}
$salida = [];
if ($codigo !== 0) {
    fail('La integridad no pasa tras el reset (exit ' . $codigo . ')');
}

// ---------------------------------------------------------------------- dump
if ($dump) {
    $ruta = $dumpRuta !== '' ? $dumpRuta : (dirname(__DIR__) . '/' . DEFAULT_DUMP);
    if (! str_starts_with($ruta, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $ruta)) {
        $ruta = dirname(__DIR__) . '/' . $ruta;
    }
    out('');
    out('  dump del origen limpio:');
    dumpDatabase(
        $pdo,
        $db,
        $ruta,
        (string) ($_ENV['DB_USERNAME'] ?? ''),
        (string) ($_ENV['DB_PASSWORD'] ?? ''),
        (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
        (string) ($_ENV['DB_PORT'] ?? '3306')
    );
}

out('');
out('OK: BD de trabajo en baseline operativo.');
exit(0);
