<?php
/**
 * Backfill: cifra ip_address y user_agent existentes en audit_logs.
 *
 * El hash_integridad se calculó sobre el contenido en claro, por lo que
 * al cifrar solo cambia la representación en almacenamiento; el hash
 * permanece válido porque protege el contenido lógico, no el formato.
 *
 * Uso: php bin/encrypt-audit-backfill.php [--dry-run]
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$encryption = Container::getInstance()->get(EncryptionService::class);
$pdo = Container::getInstance()->get('database')->getConnection();

$dryRun = in_array('--dry-run', $argv, true);

echo "BD: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN (sin escribir)' : 'EJECUCIÓN REAL') . "\n\n";

// Contar filas con datos en claro (ip_address que empieza con dígito o "::")
$clearCount = $pdo->query(
    "SELECT COUNT(*) FROM audit_logs
     WHERE ip_address IS NOT NULL AND ip_address != ''
       AND (ip_address LIKE '%.%' OR ip_address LIKE '::%' OR ip_address REGEXP '^[0-9a-fA-F]+$')"
)->fetchColumn();

$uaClearCount = $pdo->query(
    "SELECT COUNT(*) FROM audit_logs
     WHERE user_agent IS NOT NULL AND user_agent != ''
       AND user_agent NOT LIKE 'enc:%'"
)->fetchColumn();

echo "Filas con ip_address en claro: {$clearCount}\n";
echo "Filas con user_agent en claro: {$uaClearCount}\n\n";

if ($clearCount == 0 && $uaClearCount == 0) {
    echo "Nada que hacer. Todos los datos ya están cifrados.\n";
    exit(0);
}

// Procesar en lotes de 500
$batchSize = 500;
$processed = 0;
$errors = 0;

$offset = 0;
while (true) {
    $stmt = $pdo->prepare(
        "SELECT id, ip_address, user_agent FROM audit_logs
         WHERE ip_address IS NOT NULL AND ip_address != ''
           AND (ip_address LIKE '%.%' OR ip_address LIKE '::%' OR ip_address REGEXP '^[0-9a-fA-F]+$')
         LIMIT {$batchSize} OFFSET {$offset}"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        try {
            $updates = [];
            $params = ['id' => $row['id']];

            // Cifrar ip_address si está en claro
            if ($row['ip_address'] !== null && $row['ip_address'] !== '') {
                $updates[] = 'ip_address = :ip_enc';
                $params['ip_enc'] = $encryption->encrypt($row['ip_address']);
            }

            // Cifrar user_agent si está en claro (no empieza con "enc:")
            if ($row['user_agent'] !== null && $row['user_agent'] !== '' && !str_starts_with($row['user_agent'], 'enc:')) {
                $updates[] = 'user_agent = :ua_enc';
                $params['ua_enc'] = $encryption->encrypt($row['user_agent']);
            }

            if (!empty($updates)) {
                if (!$dryRun) {
                    $sql = "UPDATE audit_logs SET " . implode(', ', $updates) . " WHERE id = :id";
                    $updStmt = $pdo->prepare($sql);
                    $updStmt->execute($params);
                }
                $processed++;
            }
        } catch (\Throwable $e) {
            $errors++;
            fwrite(STDERR, "Error en {$row['id']}: {$e->getMessage()}\n");
        }
    }

    $offset += $batchSize;
    echo "  Procesadas: {$processed} (offset {$offset})\n";
}

echo "\n=== RESULTADO ===\n";
echo "Filas cifradas: {$processed}\n";
echo "Errores: {$errors}\n";

if ($dryRun) {
    echo "\nDRY-RUN: no se escribió nada. Ejecuta sin --dry-run para aplicar.\n";
} else {
    echo "\nBackfill completado.\n";
}
