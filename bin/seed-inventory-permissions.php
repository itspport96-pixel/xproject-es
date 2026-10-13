<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$c = App\Shared\Container\Container::getInstance();
$db = $c->get('database');
$seeder = new App\Core\Authorization\Seeder\InventoryPermissionSeeder($db->getConnection());
$result = $seeder->seed();
echo json_encode($result), PHP_EOL;
