<?php
declare(strict_types=1);
/**
 * B2 backfill: cifra users.nombre/apellido/email/telefono pendientes,
 * shipments PII pendiente, y limpia email_hmac/nombre_interno_hmac en borrados.
 * Soporta --dry-run.
 */
require __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$container = Container::getInstance();
$encryption = $container->get(EncryptionService::class);
$pdo = $container->get('database')->getConnection();

$dryRun = in_array('--dry-run', $argv, true);
echo 'BD: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN' : 'EJECUCIÓN REAL') . "\n\n";

function isPlain(string $v): bool
{
    // Ciphertext base64 AES-GCM típicamente contiene =, / o + y es largo.
    // Valor en claro: sin esos caracteres y razonablemente corto.
    return $v !== '' && strpos($v, '=') === false && strpos($v, '/') === false && strpos($v, '+') === false;
}

// ============================================================
// 1. CIFRAR users.email (hueco Sprint 2: backfill solo calculó HMAC)
// ============================================================
echo "=== 1. Cifrar users.email pendientes ===\n";
$rows = $pdo->query("SELECT id, email FROM users WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_ASSOC);
$count = 0;
foreach ($rows as $r) {
    if (isPlain($r['email'])) {
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$encryption->encrypt($r['email']), $r['id']]);
        }
        $count++;
    }
}
echo "  Pendientes: {$count}\n";

// ============================================================
// 2. CIFRAR users.nombre y apellido
// ============================================================
echo "\n=== 2. Cifrar users.nombre/apellido ===\n";
$rows = $pdo->query("SELECT id, nombre, apellido FROM users WHERE (nombre IS NOT NULL AND nombre != '') OR (apellido IS NOT NULL AND apellido != '')")->fetchAll(PDO::FETCH_ASSOC);
$count = 0;
foreach ($rows as $r) {
    $updates = [];
    $params = ['id' => $r['id']];
    if ($r['nombre'] !== null && $r['nombre'] !== '' && isPlain($r['nombre'])) {
        $updates[] = 'nombre = :n';
        $params['n'] = $encryption->encrypt($r['nombre']);
    }
    if ($r['apellido'] !== null && $r['apellido'] !== '' && isPlain($r['apellido'])) {
        $updates[] = 'apellido = :a';
        $params['a'] = $encryption->encrypt($r['apellido']);
    }
    if ($updates) {
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id");
            $stmt->execute($params);
        }
        $count++;
    }
}
echo "  Procesadas: {$count}\n";

// ============================================================
// 3. CIFRAR users.telefono pendiente
// ============================================================
echo "\n=== 3. Cifrar users.telefono pendientes ===\n";
$rows = $pdo->query("SELECT id, telefono FROM users WHERE telefono IS NOT NULL AND telefono != ''")->fetchAll(PDO::FETCH_ASSOC);
$count = 0;
foreach ($rows as $r) {
    if (isPlain($r['telefono'])) {
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE users SET telefono = ? WHERE id = ?");
            $stmt->execute([$encryption->encrypt($r['telefono']), $r['id']]);
        }
        $count++;
    }
}
echo "  Pendientes: {$count}\n";

// ============================================================
// 4. CIFRAR shipments PII pendiente (filtro por campo, no por destinatario)
// ============================================================
echo "\n=== 4. Cifrar shipments PII pendientes ===\n";
foreach (['destino_direccion', 'destinatario_nombre', 'conductor_nombre'] as $col) {
    $rows = $pdo->query("SELECT id, {$col} AS v FROM shipments WHERE {$col} IS NOT NULL AND {$col} != ''")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        if (isPlain($r['v'])) {
            if (!$dryRun) {
                $stmt = $pdo->prepare("UPDATE shipments SET {$col} = ? WHERE id = ?");
                $stmt->execute([$encryption->encrypt($r['v']), $r['id']]);
            }
            $count++;
        }
    }
    echo "  {$col}: {$count} pendientes\n";
}

// ============================================================
// 5. LIMPIAR email_hmac en usuarios borrados (índice único)
// ============================================================
echo "\n=== 5. Limpiar email_hmac en usuarios borrados ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL AND email_hmac IS NOT NULL")->fetchColumn();
echo "  Pendientes: {$count}\n";
if (!$dryRun && $count > 0) {
    $pdo->exec("UPDATE users SET email_hmac = NULL WHERE deleted_at IS NOT NULL AND email_hmac IS NOT NULL");
}

// ============================================================
// 6. LIMPIAR nombre_interno_hmac en tenants borrados
// ============================================================
echo "\n=== 6. Limpiar nombre_interno_hmac en tenants borrados ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NOT NULL AND nombre_interno_hmac IS NOT NULL")->fetchColumn();
echo "  Pendientes: {$count}\n";
if (!$dryRun && $count > 0) {
    $pdo->exec("UPDATE tenants SET nombre_interno_hmac = NULL WHERE deleted_at IS NOT NULL AND nombre_interno_hmac IS NOT NULL");
}

echo "\n=== FIN ===\n";
