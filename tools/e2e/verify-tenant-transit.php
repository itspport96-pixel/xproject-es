<?php

declare(strict_types=1);

/**
 * Verificacion en vivo (mini-sprint v1.7.1, A1):
 * el alta de un tenant con TenantService::create() debe crear, en la MISMA
 * transaccion, la ubicacion virtual TRANSITO del tenant. Al terminar borra
 * lo que ha creado (tenant + ubicaciones) para no dejar rastro.
 *
 * Uso: php verify-tenant-transit.php
 * Sale con 0 si todo va bien, 1 si falta la ubicacion o el alta falla.
 */

require 'C:/xampp/htdocs/xp/bootstrap.php';

use App\Core\Tenants\Service\TenantService;
use App\Modules\Inventory\Application\Services\TransitLocationService;
use App\Shared\Container\Container;

$container = Container::getInstance();
$pdo = $container->get('database')->getConnection();
$slug = 'e2e-v171-' . substr(md5(uniqid('', true)), 0, 6);

$tenantId = null;
$status = 0;

try {
    $service = $container->get(TenantService::class);

    $tenant = $service->create([
        'nombre_publico' => 'E2E v1.7.1',
        'nombre_interno' => $slug,
        'plan' => 'FREE',
    ]);

    $tenantId = (string) $tenant['id'];
    echo "tenant creado: {$tenantId} ({$slug})\n";

    $stmt = $pdo->prepare(
        "SELECT id, codigo, tipo, capacidad, capacidad_usada, activa
         FROM locations
         WHERE tenant_id = ? AND tipo = 'TRANSITO' AND deleted_at IS NULL"
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== 1) {
        echo 'FALLO: se esperaba 1 ubicacion TRANSITO y hay ' . count($rows) . "\n";
        $status = 1;
    } else {
        $row = $rows[0];
        echo 'ubicacion TRANSITO: ' . $row['id'] . ' codigo=' . $row['codigo']
            . ' activa=' . $row['activa'] . ' capacidad=' . var_export($row['capacidad'], true) . "\n";

        if ((int) $row['activa'] !== 1) {
            echo "FALLO: la ubicacion no esta activa\n";
            $status = 1;
        }

        if ($row['capacidad'] !== null) {
            echo "FALLO: una ubicacion virtual no debe tener capacidad\n";
            $status = 1;
        }

        // Idempotencia en vivo: repetir la semilla no debe duplicar la fila
        $again = (new TransitLocationService($container->get('database')))
            ->ensureForTenant($tenantId);
        echo 'segunda llamada: creada=' . var_export($again['creada'], true)
            . ' codigo=' . $again['codigo'] . "\n";

        if ($again['creada'] !== false || $again['codigo'] !== $row['codigo']) {
            echo "FALLO: la semilla no es idempotente\n";
            $status = 1;
        }
    }

    if ($status === 0) {
        echo "OK: el alta de tenant crea su ubicacion TRANSITO\n";
    }
} catch (Throwable $e) {
    echo 'FALLO: ' . $e->getMessage() . "\n";
    echo '  ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $status = 1;
}

if ($tenantId !== null) {
    $pdo->prepare('DELETE FROM locations WHERE tenant_id = ?')->execute([$tenantId]);
    $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
    echo "limpieza: tenant y ubicaciones borrados\n";
}

exit($status);
