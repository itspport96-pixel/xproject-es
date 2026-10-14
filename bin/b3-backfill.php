<?php
declare(strict_types=1);
/**
 * B3 backfill: cifra PII restante.
 * - shipments.destinatario_contacto, conductor_contacto
 * - deliveries.destinatario_real, firma_comprobante, observaciones
 * - audit_logs.valores_anteriores, valores_nuevos, detalles_adicionales
 *   (sólo los que no están ya cifrados; el hash se calculó sobre el claro)
 * Soporta --dry-run.
 */
require __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$encryption = Container::getInstance()->get(EncryptionService::class);
$pdo = Container::getInstance()->get('database')->getConnection();
$dryRun = in_array('--dry-run', $argv, true);

echo 'BD: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN' : 'EJECUCIÓN REAL') . "\n\n";

function isPlain(string $v): bool
{
    return $v !== '' && strpos($v, '=') === false && strpos($v, '/') === false && strpos($v, '+') === false;
}

// 1. shipments contactos
echo "=== 1. shipments contactos ===\n";
foreach (['destinatario_contacto', 'conductor_contacto'] as $col) {
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
    echo "  {$col}: {$count}\n";
}

// 2. deliveries PII
echo "\n=== 2. deliveries PII ===\n";
foreach (['destinatario_real', 'firma_comprobante', 'observaciones'] as $col) {
    $rows = $pdo->query("SELECT id, {$col} AS v FROM deliveries WHERE {$col} IS NOT NULL AND {$col} != ''")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        if (isPlain($r['v'])) {
            if (!$dryRun) {
                $stmt = $pdo->prepare("UPDATE deliveries SET {$col} = ? WHERE id = ?");
                $stmt->execute([$encryption->encrypt($r['v']), $r['id']]);
            }
            $count++;
        }
    }
    echo "  {$col}: {$count}\n";
}

// 2b. incidents texto libre (descripcion, observaciones)
echo "\n=== 2b. incidents texto libre ===\n";
foreach (['descripcion', 'observaciones'] as $col) {
    $rows = $pdo->query("SELECT id, {$col} AS v FROM incidents WHERE {$col} IS NOT NULL AND {$col} != ''")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        if (isPlain($r['v'])) {
            if (!$dryRun) {
                $stmt = $pdo->prepare("UPDATE incidents SET {$col} = ? WHERE id = ?");
                $stmt->execute([$encryption->encrypt($r['v']), $r['id']]);
            }
            $count++;
        }
    }
    echo "  {$col}: {$count}\n";
}

// 2c. incidents JSON (resolucion, restriccion_resolucion) con CHECK json_valid
echo "\n=== 2c. incidents JSON ===\n";
foreach (['resolucion', 'restriccion_resolucion'] as $col) {
    $rows = $pdo->query("SELECT id, {$col} AS v FROM incidents WHERE {$col} IS NOT NULL AND {$col} != '' AND {$col} != 'null'")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        $v = $r['v'];
        $startsJson = isset($v[0]) && ($v[0] === '{' || $v[0] === '[');
        if (!$startsJson) {
            continue;
        }
        $decoded = json_decode($v, true);
        if (is_array($decoded) && isset($decoded['__encrypted'])) {
            continue;
        }
        if (!$dryRun) {
            $wrapped = json_encode(
                ['__encrypted' => $encryption->encrypt($v)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $stmt = $pdo->prepare("UPDATE incidents SET {$col} = ? WHERE id = ?");
            $stmt->execute([$wrapped, $r['id']]);
        }
        $count++;
    }
    echo "  {$col}: {$count}\n";
}

// 3. audit_logs JSON (valores_anteriores, valores_nuevos, detalles_adicionales)
//    Envuelve el ciphertext en {"__encrypted":"base64"} para cumplir CHECK json_valid.
//    Heurística: si empieza con { o [ es JSON en claro; si no, probablemente
//    ya está cifrado (ciphertext base64 no empieza con esos caracteres).
echo "\n=== 3. audit_logs JSON ===\n";
foreach (['valores_anteriores', 'valores_nuevos', 'detalles_adicionales'] as $col) {
    $rows = $pdo->query("SELECT id, {$col} AS v FROM audit_logs WHERE {$col} IS NOT NULL AND {$col} != '' AND {$col} != 'null'")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        $v = $r['v'];
        // JSON en claro empieza con { o [
        $startsJson = isset($v[0]) && ($v[0] === '{' || $v[0] === '[');
        if (!$startsJson) {
            continue; // ya cifrado u otro formato
        }
        // Si ya es wrapper __encrypted, saltar
        $decoded = json_decode($v, true);
        if (is_array($decoded) && isset($decoded['__encrypted'])) {
            continue;
        }
        if (!$dryRun) {
            $wrapped = json_encode(
                ['__encrypted' => $encryption->encrypt($v)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $stmt = $pdo->prepare("UPDATE audit_logs SET {$col} = ? WHERE id = ?");
            $stmt->execute([$wrapped, $r['id']]);
        }
        $count++;
    }
    echo "  {$col}: {$count}\n";
}

echo "\n=== FIN ===\n";
