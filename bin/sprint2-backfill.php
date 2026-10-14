<?php
/**
 * Sprint 2 backfill: calcula email_hmac y nombre_interno_hmac,
 * y cifra todos los campos PII pendientes.
 *
 * Uso: php bin/sprint2-backfill.php [--dry-run]
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\BlindIndexService;
use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$encryption = Container::getInstance()->get(EncryptionService::class);
$blindIndex = Container::getInstance()->get(BlindIndexService::class);
$pdo = Container::getInstance()->get('database')->getConnection();

$dryRun = in_array('--dry-run', $argv, true);

echo "BD: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'EJECUCIÓN REAL') . "\n\n";

// ============================================================
// 1. BACKFILL email_hmac (blind index)
//    Recalcula TODOS los que no tengan un HMAC válido (64 hex chars).
// ============================================================
echo "=== 1. email_hmac (users) ===\n";
$rows = $pdo->query("SELECT id, email, email_hmac FROM users WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_ASSOC);
$pending = 0;
foreach ($rows as $r) {
    $current = $r['email_hmac'] ?? null;
    $isValid = $current !== null && preg_match('/^[0-9a-f]{64}$/', $current) === 1;
    if (!$isValid) {
        $pending++;
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE users SET email_hmac = ? WHERE id = ?");
            $stmt->execute([$blindIndex->compute($r['email']), $r['id']]);
        }
    }
}
echo "  Pendientes: {$pending}\n";

// ============================================================
// 2. BACKFILL nombre_interno_hmac (tenants)
//    Recalcula TODOS los que no tengan un HMAC válido (64 hex chars).
// ============================================================
echo "\n=== 2. nombre_interno_hmac (tenants) ===\n";
$rows = $pdo->query("SELECT id, nombre_interno, nombre_interno_hmac FROM tenants WHERE nombre_interno IS NOT NULL AND nombre_interno != ''")->fetchAll(PDO::FETCH_ASSOC);
$pending = 0;
foreach ($rows as $r) {
    $current = $r['nombre_interno_hmac'] ?? null;
    $isValid = $current !== null && preg_match('/^[0-9a-f]{64}$/', $current) === 1;
    if (!$isValid) {
        $pending++;
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE tenants SET nombre_interno_hmac = ? WHERE id = ?");
            $stmt->execute([$blindIndex->compute($r['nombre_interno']), $r['id']]);
        }
    }
}
echo "  Pendientes: {$pending}\n";

// ============================================================
// 3. CIFRAR users.nombre, users.apellido, users.telefono
// ============================================================
echo "\n=== 3. Cifrar users.nombre/apellido/telefono ===\n";
$rows = $pdo->query("SELECT id, nombre, apellido, telefono FROM users WHERE nombre NOT LIKE '%=%' AND nombre IS NOT NULL AND nombre != ''")->fetchAll(PDO::FETCH_ASSOC);
echo "  Pendientes: " . count($rows) . "\n";
$count = 0;
foreach ($rows as $r) {
    if (!$dryRun) {
        $stmt = $pdo->prepare("UPDATE users SET nombre = ?, apellido = ?, telefono = ? WHERE id = ?");
        $stmt->execute([
            $encryption->encrypt($r['nombre']),
            $encryption->encrypt($r['apellido']),
            $r['telefono'] !== null && $r['telefono'] !== '' ? $encryption->encrypt($r['telefono']) : $r['telefono'],
            $r['id'],
        ]);
    }
    $count++;
}
echo "  Procesadas: {$count}\n";

// ============================================================
// 4. CIFRAR tenants.nombre_publico, email_contacto, telefono
// ============================================================
echo "\n=== 4. Cifrar tenants.nombre_publico/email_contacto/telefono ===\n";
$rows = $pdo->query("SELECT id, nombre_publico, email_contacto, telefono FROM tenants WHERE nombre_publico NOT LIKE '%=%' AND nombre_publico IS NOT NULL AND nombre_publico != ''")->fetchAll(PDO::FETCH_ASSOC);
echo "  Pendientes: " . count($rows) . "\n";
$count = 0;
foreach ($rows as $r) {
    if (!$dryRun) {
        $stmt = $pdo->prepare("UPDATE tenants SET nombre_publico = ?, email_contacto = ?, telefono = ? WHERE id = ?");
        $stmt->execute([
            $encryption->encrypt($r['nombre_publico']),
            $r['email_contacto'] !== null && $r['email_contacto'] !== '' ? $encryption->encrypt($r['email_contacto']) : $r['email_contacto'],
            $r['telefono'] !== null && $r['telefono'] !== '' ? $encryption->encrypt($r['telefono']) : $r['telefono'],
            $r['id'],
        ]);
    }
    $count++;
}
echo "  Procesadas: {$count}\n";

// ============================================================
// 5. CIFRAR shipments.destino_direccion/destinatario_nombre/conductor_nombre
// ============================================================
echo "\n=== 5. Cifrar shipments PII ===\n";
$rows = $pdo->query("SELECT id, destino_direccion, destinatario_nombre, conductor_nombre FROM shipments WHERE destinatario_nombre NOT LIKE '%=%' AND destinatario_nombre IS NOT NULL AND destinatario_nombre != ''")->fetchAll(PDO::FETCH_ASSOC);
echo "  Pendientes: " . count($rows) . "\n";
$count = 0;
foreach ($rows as $r) {
    if (!$dryRun) {
        $updates = [];
        $params = ['id' => $r['id']];
        if ($r['destino_direccion'] !== null && $r['destino_direccion'] !== '') {
            $updates[] = 'destino_direccion = :dd';
            $params['dd'] = $encryption->encrypt($r['destino_direccion']);
        }
        if ($r['destinatario_nombre'] !== null && $r['destinatario_nombre'] !== '') {
            $updates[] = 'destinatario_nombre = :dn';
            $params['dn'] = $encryption->encrypt($r['destinatario_nombre']);
        }
        if ($r['conductor_nombre'] !== null && $r['conductor_nombre'] !== '') {
            $updates[] = 'conductor_nombre = :cn';
            $params['cn'] = $encryption->encrypt($r['conductor_nombre']);
        }
        if ($updates) {
            $stmt = $pdo->prepare("UPDATE shipments SET " . implode(', ', $updates) . " WHERE id = :id");
            $stmt->execute($params);
        }
    }
    $count++;
}
echo "  Procesadas: {$count}\n";

// ============================================================
// 6. CIFRAR ip_address en rate_limit_log, refresh_tokens, secrets_access_log
// ============================================================
foreach (['rate_limit_log', 'refresh_tokens', 'secrets_access_log'] as $table) {
    echo "\n=== 6. Cifrar {$table}.ip_address ===\n";
    $rows = $pdo->query("SELECT id, ip_address FROM {$table} WHERE ip_address IS NOT NULL AND ip_address != '' AND ip_address NOT LIKE '%=%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "  Pendientes: " . count($rows) . "\n";
    $count = 0;
    foreach ($rows as $r) {
        if (!$dryRun) {
            $stmt = $pdo->prepare("UPDATE {$table} SET ip_address = ? WHERE id = ?");
            $stmt->execute([$encryption->encrypt($r['ip_address']), $r['id']]);
        }
        $count++;
    }
    echo "  Procesadas: {$count}\n";
}

echo "\n=== RESUMEN ===\n";
if ($dryRun) {
    echo "DRY-RUN: nada escrito. Ejecuta sin --dry-run para aplicar.\n";
} else {
    echo "Backfill completado.\n";
}
