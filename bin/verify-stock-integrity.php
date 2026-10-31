<?php

declare(strict_types=1);

/**
 * Verifica la integridad de stock_events y movements (hash_integridad).
 *
 * Uso:
 *   php bin/verify-stock-integrity.php [--db=nombre]
 *
 *   --db=nombre   BD a inspeccionar; por defecto E2E_DB_NAME o la de trabajo.
 *
 * Lee todas las filas de stock_events y movements, las hidrata con fromArray()
 * y verifica el hash_integridad con esIntegro(). Reporta OK si todas son
 * íntegras, o INCONSISTENTE con la lista de IDs violados.
 *
 * Sale con 0 si todas las filas son íntegras; 1 si hay violaciones.
 *
 * Herramienta de operación para detectar manipulación de stock_events y
 * movements en BD. Complementa la verificación de audit_logs (que ya existe).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Modules\Inventory\Domain\Entities\Movement;
use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;

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
            $db = trim(substr($arg, 5));
        }
    }

    if ($db === '') {
        $e2eDb = getenv('E2E_DB_NAME');
        if (is_string($e2eDb) && $e2eDb !== '') {
            $db = $e2eDb;
        } else {
            $db = (string) ($_ENV['DB_DATABASE'] ?? '');
        }
    }

    if ($db === '') {
        fail('BD no resuelta: pasa --db=nombre o define DB_DATABASE/E2E_DB_NAME');
    }

    $pattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    if (preg_match($pattern, $db) !== 1) {
        fail('Nombre de BD inválido: ' . $db);
    }

    return [$db, $warnings];
}

printf("verify-stock-integrity\n");

[$dbName, $warnings] = resolveDatabase($argv);

foreach ($warnings as $w) {
    printf("  aviso: %s\n", $w);
}

printf("  BD: %s\n\n", $dbName);

$container = Container::getInstance();
$dbService = $container->get(DatabaseService::class);
$pdo = $dbService->getConnection();

// Cambiar a la BD objetivo si es diferente
$currentDb = $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($currentDb !== $dbName) {
    $pdo->exec('USE ' . $pdo->quote($dbName));
}

$issues = [];

// ============================================
// 1. Verificar stock_events
// ============================================

printf("  stock_events: ");

$stmt = $pdo->query('SELECT * FROM stock_events ORDER BY created_at, id');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventIssues = [];
foreach ($rows as $row) {
    try {
        $event = StockEvent::fromArray($row);
        if (!$event->esIntegro()) {
            $eventIssues[] = $row['id'];
        }
    } catch (Throwable $e) {
        $eventIssues[] = $row['id'] . ' (error: ' . $e->getMessage() . ')';
    }
}

if ($eventIssues === []) {
    printf("OK (%d filas)\n", count($rows));
} else {
    printf("INCONSISTENTE (%d de %d)\n", count($eventIssues), count($rows));
    foreach ($eventIssues as $id) {
        $issues[] = "stock_events.{$id}";
    }
}

// ============================================
// 2. Verificar movements
// ============================================

printf("  movements: ");

$stmt = $pdo->query('SELECT * FROM movements ORDER BY timestamp_movimiento, id');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$movementIssues = [];
foreach ($rows as $row) {
    try {
        $movement = Movement::fromArray($row);
        if (!$movement->esIntegro()) {
            $movementIssues[] = $row['id'];
        }
    } catch (Throwable $e) {
        $movementIssues[] = $row['id'] . ' (error: ' . $e->getMessage() . ')';
    }
}

if ($movementIssues === []) {
    printf("OK (%d filas)\n", count($rows));
} else {
    printf("INCONSISTENTE (%d de %d)\n", count($movementIssues), count($rows));
    foreach ($movementIssues as $id) {
        $issues[] = "movements.{$id}";
    }
}

// ============================================
// Resultado final
// ============================================

printf("\n");

if ($issues === []) {
    printf("RESULTADO: OK (integridad verificada)\n");
    exit(0);
} else {
    printf("RESULTADO: INCONSISTENTE (%d filas violadas)\n", count($issues));
    foreach ($issues as $issue) {
        printf("  - %s\n", $issue);
    }
    exit(1);
}
