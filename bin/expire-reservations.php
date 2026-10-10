<?php

declare(strict_types=1);

/**
 * Comando: expiración de reservas vencidas (spec E7) - Sprint 6.3.
 *
 * Uso:
 *   php bin/expire-reservations.php                 # todos los tenants activos
 *   php bin/expire-reservations.php --tenant=<id>    # un solo tenant
 *   php bin/expire-reservations.php --limit=100      # tope por tanda
 *
 * Para cada reserva ACTIVA con `fecha_expiracion` pasada: se cancela, se
 * devuelve la unidad a DISPONIBLE, se descuenta `cantidad_reservada` del
 * ledger y se escribe el evento LIBERADO. Toda la tanda de cada tenant corre
 * en una transacción (si algo falla, no se cancela nada de ese tenant).
 *
 * Salida: línea por tenant con el número de reservas expiradas; exit code 0.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\ReservationExpirationService;

$container = App\Shared\Container\Container::getInstance();

$tenantFilter = null;
$limit = 500;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/expire-reservations.php [--tenant=<id>] [--limit=<n>]\n";
        exit(0);
    }
    if (str_starts_with($arg, '--tenant=')) {
        $tenantFilter = substr($arg, strlen('--tenant='));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, strlen('--limit=')));
        continue;
    }

    fwrite(STDERR, "Argumento desconocido: {$arg} (usa --help)\n");
    exit(1);
}

try {
    $pdo = $container->get('database')->getConnection();

    $sql = "SELECT id, nombre_interno FROM tenants WHERE deleted_at IS NULL";
    $params = [];

    if ($tenantFilter !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $tenantFilter;
    } else {
        $sql .= " AND estado = 'ACTIVO'";
    }
    $sql .= ' ORDER BY created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($tenants === []) {
        echo "Sin tenants que procesar.\n";
        exit(0);
    }

    $service = $container->get(ReservationExpirationService::class);
    $context = $container->get(TenantContext::class);

    $total = 0;
    $totalOmitidas = 0;

    foreach ($tenants as $tenant) {
        $context->setTenantId((string) $tenant['id']);

        $result = $service->expire($limit);
        $expiradas = (int) $result['expiradas'];
        $omitidas = (int) ($result['omitidas'] ?? 0);
        $total += $expiradas;
        $totalOmitidas += $omitidas;

        printf(
            "[%s] %s: %d reserva(s) expirada(s), %d omitida(s)\n",
            $tenant['id'],
            $tenant['nombre_interno'],
            $expiradas,
            $omitidas
        );

        foreach ($result['detalle'] as $detalle) {
            printf(
                "    - reserva %s → unidad %s (%.4f) estado_unidad: %s → %s\n",
                $detalle['reserva_id'],
                $detalle['unidad_fisica_id'],
                $detalle['cantidad'],
                $detalle['estado_unidad_anterior'] ?? 'n/a',
                $detalle['estado_unidad_nuevo'] ?? 'n/a'
            );
        }

        // v1.7.1: unidades que ya no estaban en RESERVADO: se omiten y se
        // registran (también en `audit_logs` con resultado OMITIDA).
        foreach (($result['omitidas_detalle'] ?? []) as $detalle) {
            printf(
                "    - OMITIDA reserva %s → unidad %s: %s\n",
                $detalle['reserva_id'],
                $detalle['unidad_fisica_id'],
                $detalle['motivo_omision'] ?? 'unidad fuera de RESERVADO'
            );
        }
    }

    printf(
        "Total: %d reserva(s) expirada(s), %d omitida(s) en %d tenant(s).\n",
        $total,
        $totalOmitidas,
        count($tenants)
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Archivo: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
