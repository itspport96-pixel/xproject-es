<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;

$e = Container::getInstance()->get(EncryptionService::class);
$pdo = Container::getInstance()->get('database')->getConnection();
$rows = $pdo->query('SELECT id, email, nombre, apellido FROM users')->fetchAll(PDO::FETCH_ASSOC);
$fail = 0;
foreach ($rows as $r) {
    foreach (['email','nombre','apellido'] as $f) {
        try { $e->decrypt($r[$f]); }
        catch (\Throwable) { echo "FAIL {$r['id']}.{$f}: " . substr($r[$f],0,30) . "\n"; $fail++; }
    }
}
echo "Fails: {$fail}\n";
