<?php
declare(strict_types=1);
/**
 * Revirtiendo users.nombre/apellido a claro: el Sprint 2 decidió NO cifrarlos
 * (rompen ORDER BY y LIKE search). Sólo se revierte lo que el backfill cifró.
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$container = Container::getInstance();
$encryption = $container->get(EncryptionService::class);
$pdo = $container->get('database')->getConnection();

echo "Revirtiendo users.nombre/apellido a claro...\n";

$rows = $pdo->query("SELECT id, nombre, apellido FROM users WHERE nombre IS NOT NULL AND nombre != ''")->fetchAll(PDO::FETCH_ASSOC);
$count = 0;
foreach ($rows as $r) {
    try {
        $nombreClaro = $encryption->decrypt($r['nombre']);
        $apellidoClaro = $encryption->decrypt($r['apellido']);
        $stmt = $pdo->prepare("UPDATE users SET nombre = ?, apellido = ? WHERE id = ?");
        $stmt->execute([$nombreClaro, $apellidoClaro, $r['id']]);
        echo "  OK: {$r['id']} => {$nombreClaro} {$apellidoClaro}\n";
        $count++;
    } catch (\Throwable $e) {
        // Ya está en claro o formato desconocido
        echo "  SKIP: {$r['id']} ({$e->getMessage()})\n";
    }
}
echo "Revertidos: {$count}\n";
