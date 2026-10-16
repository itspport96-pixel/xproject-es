<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Audit\Seeder\AuditPermissionSeeder;
use App\Core\Authorization\Seeder\InventoryPermissionSeeder;
use App\Core\Authorization\Seeder\PlatformControlPermissionSeeder;
use App\Core\Security\Service\BlindIndexService;
use App\Core\Security\Service\EncryptionService;
use App\Core\Tenants\Service\TenantContext;
use App\Shared\Container\Container;

const TENANT_DATA = [
    'nombre_publico' => 'Acme Corp',
    'nombre_interno' => 'acme-corp',
    'plan'           => 'FREE',
    'email_contacto' => 'contact@acme.local',
    'telefono'       => '+52 55 1234 5678',
    'pais'           => 'MX',
    'zona_horaria'   => 'America/Mexico_City',
    'idioma_default' => 'es',
];

/**
 * PlatformSeeder - Seeding inicial de la plataforma.
 *
 * ADVERTENCIA DE SEGURIDAD: Este seeder contiene credenciales hardcodeadas
 * SOLO para desarrollo y testing. NUNCA ejecutar en producción.
 * Cambiar la contraseña inmediatamente después del primer login.
 */

const ADMIN_DATA = [
    'email'    => 'admin@acme.local',
    'nombre'   => 'Admin',
    'apellido' => 'Acme',
    'password' => 'Admin123!',
];

function output(string $message, string $color = 'white'): void
{
    $colors = [
        'white' => "\033[0m", 'green' => "\033[32m", 'red' => "\033[31m",
        'yellow' => "\033[33m", 'blue' => "\033[34m", 'cyan' => "\033[36m",
    ];
    echo ($colors[$color] ?? $colors['white']) . $message . "\033[0m" . PHP_EOL;
}

function success(string $m): void { output("✓ {$m}", 'green'); }
function error(string $m): void   { output("✗ {$m}", 'red'); }
function info(string $m): void    { output("→ {$m}", 'cyan'); }
function warn(string $m): void    { output("⚠ {$m}", 'yellow'); }

function generateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

output('', 'white');
output('╔════════════════════════════════════════════════════════════╗', 'cyan');
output('║          PLATFORM SEEDER - Tenant Demo + Admin            ║', 'cyan');
output('╚════════════════════════════════════════════════════════════╝', 'cyan');
output('', 'white');

try {
    $container = Container::getInstance();
    $db = $container->get('database');
    $pdo = $db->getConnection();
    $blindIndex = $container->has(BlindIndexService::class) ? $container->get(BlindIndexService::class) : null;
    $encryption = $container->has(EncryptionService::class) ? $container->get(EncryptionService::class) : null;

    // PASO 1: Verificar rol ADMIN
    info('Verificando rol BUILT_IN ADMIN...');
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE nombre = :nombre AND tipo = 'BUILT_IN' AND deleted_at IS NULL");
    $stmt->execute(['nombre' => 'ADMIN']);
    $adminRoleId = $stmt->fetchColumn();

    if ($adminRoleId === false) {
        error('El rol BUILT_IN ADMIN no existe. Verifica el dump inicial.');
        exit(1);
    }
    success("Rol ADMIN encontrado (ID: {$adminRoleId})");

    // PASO 2: Verificar tenant
    info('Verificando si el tenant ya existe...');
    $tenantLookupValue = $blindIndex?->compute(TENANT_DATA['nombre_interno']) ?? TENANT_DATA['nombre_interno'];
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE nombre_interno_hmac = :ni AND deleted_at IS NULL");
    $stmt->execute(['ni' => $tenantLookupValue]);
    $existingTenantId = $stmt->fetchColumn();

    if ($existingTenantId !== false) {
        warn("Tenant '" . TENANT_DATA['nombre_interno'] . "' ya existe (ID: {$existingTenantId})");
        $tenantId = $existingTenantId;
        $tenantCreated = false;
    } else {
        $tenantId = generateUuid();
        $tenantCreated = true;
    }

    // PASO 3: Verificar usuario admin
    info('Verificando si el usuario admin ya existe...');
    $emailLookupValue = $blindIndex?->compute(ADMIN_DATA['email']) ?? ADMIN_DATA['email'];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email_hmac = :email AND deleted_at IS NULL");
    $stmt->execute(['email' => $emailLookupValue]);
    $existingUserId = $stmt->fetchColumn();

    if ($existingUserId !== false) {
        warn("Usuario '" . ADMIN_DATA['email'] . "' ya existe (ID: {$existingUserId})");
        $adminUserId = $existingUserId;
        $adminCreated = false;
    } else {
        $adminUserId = generateUuid();
        $adminCreated = true;
    }

    // PASO 4: Transacción
    info('Iniciando transacción...');
    $pdo->beginTransaction();

    try {
        if ($tenantCreated) {
            info('Creando tenant...');
            $now = date('Y-m-d H:i:s');
            $sql = "INSERT INTO tenants
                    (id, nombre_publico, nombre_interno, nombre_interno_hmac, estado, plan,
                     email_contacto, telefono, pais, zona_horaria, idioma_default,
                     fecha_creacion, created_at, updated_at)
                    VALUES
                    (:id, :np, :ni, :nih, 'ACTIVO', :plan,
                     :ec, :tel, :pais, :zh, :idioma,
                     :fc, :ca, :ua)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id' => $tenantId,
                'np' => $encryption?->encrypt(TENANT_DATA['nombre_publico']) ?? TENANT_DATA['nombre_publico'],
                'ni' => TENANT_DATA['nombre_interno'],
                'nih' => $blindIndex?->compute(TENANT_DATA['nombre_interno']),
                'plan' => TENANT_DATA['plan'],
                'ec' => $encryption?->encrypt(TENANT_DATA['email_contacto']) ?? TENANT_DATA['email_contacto'],
                'tel' => $encryption?->encrypt(TENANT_DATA['telefono']) ?? TENANT_DATA['telefono'],
                'pais' => TENANT_DATA['pais'], 'zh' => TENANT_DATA['zona_horaria'],
                'idioma' => TENANT_DATA['idioma_default'],
                'fc' => $now, 'ca' => $now, 'ua' => $now,
            ]);
            success("Tenant creado (ID: {$tenantId})");
        }

        if ($adminCreated) {
            info('Creando usuario admin...');
            // CORRECCIÓN: usar alias string 'password_hasher' en vez de PasswordHasher::class
            $hasher = $container->get('password_hasher');
            $passwordHash = $hasher->hash(ADMIN_DATA['password']);
            $now = date('Y-m-d H:i:s');

            $sql = "INSERT INTO users
                    (id, tenant_id, email, email_hmac, nombre, apellido, password, estado, email_verificado, created_at, updated_at)
                    VALUES
                    (:id, :tid, :email, :emailh, :nombre, :apellido, :password, 'ACTIVO', 1, :ca, :ua)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id' => $adminUserId, 'tid' => $tenantId,
                'email' => $encryption?->encrypt(ADMIN_DATA['email']) ?? ADMIN_DATA['email'],
                'emailh' => $blindIndex?->compute(ADMIN_DATA['email']),
                'nombre' => ADMIN_DATA['nombre'],
                'apellido' => ADMIN_DATA['apellido'], 'password' => $passwordHash,
                'ca' => $now, 'ua' => $now,
            ]);
            success("Usuario admin creado (ID: {$adminUserId})");
        }

        // Asignar rol ADMIN
        info('Verificando asignación de rol ADMIN...');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE user_id = :uid AND role_id = :rid AND tenant_id = :tid");
        $stmt->execute(['uid' => $adminUserId, 'rid' => $adminRoleId, 'tid' => $tenantId]);

        if ((int) $stmt->fetchColumn() === 0) {
            $sql = "INSERT INTO role_assignments (id, user_id, role_id, tenant_id, fecha_asignacion, created_at, updated_at) VALUES (:id, :uid, :rid, :tid, NOW(), NOW(), NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => generateUuid(), 'uid' => $adminUserId, 'rid' => $adminRoleId, 'tid' => $tenantId]);
            success('Rol ADMIN asignado');
        } else {
            warn('Rol ADMIN ya asignado');
        }

        // Asignar owner
        info('Verificando owner del tenant...');
        $stmt = $pdo->prepare("SELECT owner_user_id FROM tenants WHERE id = :id");
        $stmt->execute(['id' => $tenantId]);
        $owner = $stmt->fetchColumn();

        if ($owner === null || $owner === false) {
            $stmt = $pdo->prepare("UPDATE tenants SET owner_user_id = :owner, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['owner' => $adminUserId, 'id' => $tenantId]);
            success('Owner asignado al tenant');
        } else {
            warn("Tenant ya tiene owner (ID: {$owner})");
        }

        // Permisos inventory.* + asignación a roles BUILT_IN (0a Sprint 6)
        // Garantiza que un tenant nuevo quede funcional sin SQL manual y
        // corrige cualquier drift entre el seeder y la BD viva.
        info('Sembrando permisos inventory.* y roles...');
        $permissionStats = (new InventoryPermissionSeeder($pdo))->seed();
        success(sprintf(
            'Permisos inventory: %d creados, %d corregidos; roles actualizados: %d',
            $permissionStats['permissions_created'],
            $permissionStats['permissions_updated'],
            $permissionStats['roles_updated']
        ));

        // Permiso audit.log.view + asignación a roles (spec §7.1)
        info('Sembrando permiso audit.log.view y roles...');
        $auditStats = (new AuditPermissionSeeder($pdo))->seed();
        success(sprintf(
            'Permisos auditoría: %d creados, %d corregidos; roles actualizados: %d',
            $auditStats['permissions_created'],
            $auditStats['permissions_updated'],
            $auditStats['roles_updated']
        ));

        // Permisos de la capa de control (v1.11.0): users.user.status,
        // users.password.reset, permissions/groups/temporary.*
        info('Sembrando permisos de la capa de control (v1.11.0)...');
        $controlStats = (new PlatformControlPermissionSeeder($pdo))->seed();
        success(sprintf(
            'Permisos capa de control: %d creados, %d corregidos; roles actualizados: %d',
            $controlStats['permissions_created'],
            $controlStats['permissions_updated'],
            $controlStats['roles_updated']
        ));

        $pdo->commit();
        success('Transacción confirmada');

    } catch (\Throwable $e) {
        $pdo->rollBack();
        error('Error: ' . $e->getMessage());
        throw $e;
    }

    output('', 'white');
    output('╔════════════════════════════════════════════════════════════╗', 'green');
    output('║                    SEED COMPLETADO                         ║', 'green');
    output('╚════════════════════════════════════════════════════════════╝', 'green');
    output('', 'white');
    output("  Tenant ID:      {$tenantId}", 'cyan');
    output("  Tenant Nombre:  " . TENANT_DATA['nombre_publico'], 'cyan');
    output("  Admin Email:    " . ADMIN_DATA['email'], 'cyan');
    output("  Admin Password: (ver .env o cambiar tras primer login)", 'cyan');
    output('', 'white');
    exit(0);

} catch (\Throwable $e) {
    output('', 'white');
    error('Error fatal: ' . $e->getMessage());
    error('Archivo: ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}
