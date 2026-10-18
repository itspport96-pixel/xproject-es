<?php

declare(strict_types=1);

/**
 * Comando: purga de mail_outbox por antiguedad.
 *
 * Uso:
 *   php bin/purge-mail-outbox.php                       # dry-run con 30 dias
 *   php bin/purge-mail-outbox.php --days=30 --apply     # borra de verdad
 *   php bin/purge-mail-outbox.php --days=7 --tenant=<id> --apply
 *
 * `mail_outbox` guarda el HTML de cada correo enviado (bienvenida, reset,
 * cambio de contrasena...) y crece sin limite; los transports son terminales
 * (`enviado` o `fallo`, no hay estado pendiente), de modo que toda fila con
 * `created_at` mas antigua que la retencion ya no tiene uso operativo y se
 * puede borrar. Sin `--apply` solo se cuenta lo que se borraria (dry-run).
 *
 * Retencion por defecto: 30 dias (`--days=<n>`, minimo 1).
 * Salida: numero de filas borradas (o candidatas en dry-run); exit code 0.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$container = App\Shared\Container\Container::getInstance();

$dias = 30;
$aplicar = false;
$tenantFilter = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/purge-mail-outbox.php [--days=<n>] [--tenant=<id>] [--apply]\n";
        echo "  Sin --apply hace dry-run (solo cuenta las filas candidatas).\n";
        exit(0);
    }
    if ($arg === '--apply') {
        $aplicar = true;
        continue;
    }
    if (str_starts_with($arg, '--days=')) {
        $dias = max(1, (int) substr($arg, strlen('--days=')));
        continue;
    }
    if (str_starts_with($arg, '--tenant=')) {
        $tenantFilter = substr($arg, strlen('--tenant='));
        continue;
    }

    fwrite(STDERR, "Argumento desconocido: {$arg} (usa --help)\n");
    exit(1);
}

try {
    $pdo = $container->get('database')->getConnection();

    $where = 'created_at < (NOW() - INTERVAL :dias DAY)';
    $params = ['dias' => $dias];

    if ($tenantFilter !== null) {
        $where .= ' AND tenant_id = :tenant';
        $params['tenant'] = $tenantFilter;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM mail_outbox WHERE {$where}");
    $stmt->execute($params);
    $candidatas = (int) $stmt->fetchColumn();

    if (! $aplicar) {
        printf(
            "Dry-run: %d fila(s) de mail_outbox con mas de %d dia(s)%s (anade --apply para borrar).\n",
            $candidatas,
            $dias,
            $tenantFilter !== null ? " en el tenant {$tenantFilter}" : ''
        );
        exit(0);
    }

    $stmt = $pdo->prepare("DELETE FROM mail_outbox WHERE {$where}");
    $stmt->execute($params);
    $borradas = $stmt->rowCount();

    printf(
        "Purga aplicada: %d fila(s) borrada(s) de mail_outbox con mas de %d dia(s)%s.\n",
        $borradas,
        $dias,
        $tenantFilter !== null ? " en el tenant {$tenantFilter}" : ''
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Archivo: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
