<?php

declare(strict_types=1);

/**
 * Comando: siembra las ubicaciones fijas de cada tenant (v1.7.1 / v1.8.0):
 *   - ubicación virtual TRANSITO  (spec CU-006)
 *   - zona DAÑADOS                (spec CU-009, v1.8.0)
 *
 * Uso:
 *   php bin/seed-transit-locations.php                 # todos los tenants
 *   php bin/seed-transit-locations.php --tenant=<id>    # un solo tenant
 *
 * IDEMPOTENTE: si el tenant ya tiene una ubicación activa de cada tipo no
 * hace nada (o la reactiva si estaba desactivada). Necesario para los tenants
 * creados ANTES de v1.7.1/v1.8.0: sin TRANSITO, `POST /api/inventory/shipments/authorize`
 * responde 404 `transitLocationMissing`; sin DAÑADOS, `POST /api/inventory/returns/register`
 * con `estado_al_devolver` DAÑADO/DETERIORADO responde 404 `damagedZoneMissing`.
 *
 * Salida: dos líneas por tenant (creada / ya existente); exit code 0.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Modules\Inventory\Application\Services\TransitLocationService;

$container = App\Shared\Container\Container::getInstance();

$tenantFilter = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Uso: php bin/seed-transit-locations.php [--tenant=<id>]\n";
        exit(0);
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

    $sql = 'SELECT id, nombre_interno FROM tenants WHERE deleted_at IS NULL';
    $params = [];

    if ($tenantFilter !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $tenantFilter;
    }
    $sql .= ' ORDER BY created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($tenants === []) {
        echo "Sin tenants que procesar.\n";
        exit(0);
    }

    $service = $container->get(TransitLocationService::class);

    $creadas = 0;
    $existentes = 0;

    foreach ($tenants as $tenant) {
        $resultado = [
            'TRANSITO' => $service->ensureForTenant((string) $tenant['id']),
            'DAÑADOS' => $service->ensureDamagedZoneForTenant((string) $tenant['id']),
        ];

        foreach ($resultado as $etiqueta => $result) {
            if ($result['creada']) {
                $creadas++;
            } else {
                $existentes++;
            }

            printf(
                "[%s] %s: %s (%s)\n",
                $tenant['id'],
                $tenant['nombre_interno'],
                ($result['creada'] ? 'ubicación ' : 'ubicación existente ') . $etiqueta,
                $result['codigo']
            );
        }
    }

    printf(
        "Total: %d creada(s), %d ya existente(s) en %d tenant(s).\n",
        $creadas,
        $existentes,
        count($tenants) * 2
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Archivo: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
