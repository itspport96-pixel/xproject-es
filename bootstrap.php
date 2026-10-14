<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');

require_once BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->load();
$dotenv->required(['APP_ENV', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'JWT_SECRET']);

if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

$container = new App\Shared\Container\Container();

// ============================================
// SHARED INFRASTRUCTURE
// ============================================
$container->singleton('config', fn() => new App\Shared\Services\ConfigService());

// Aislamiento E2E (v1.8.3): si E2E_DB_NAME esta definida, TODA la aplicacion
// (API embebida, CLI de bin/) se conecta a esa BD en lugar de DB_DATABASE.
// La BD de trabajo sigue siendo la de .env para cualquier proceso que no
// exporte la variable. Usan .env para las credenciales, nunca para el nombre.
$e2eDatabase = getenv('E2E_DB_NAME');
if ($e2eDatabase === false || $e2eDatabase === '') {
    $e2eDatabase = $_ENV['DB_DATABASE'];
}

$container->singleton('database', fn() => new App\Shared\Services\DatabaseService([
    'host'     => $_ENV['DB_HOST'],
    'port'     => $_ENV['DB_PORT'] ?? '3306',
    'database' => $e2eDatabase,
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
    'charset'  => 'utf8mb4'
]));
$container->singleton('redis', fn() => new App\Shared\Services\RedisService([
    'host'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'port'     => $_ENV['REDIS_PORT'] ?? '6379',
    'password' => $_ENV['REDIS_PASSWORD'] ?? null
]));
$container->singleton('router', fn() => new App\Shared\Router\Router());

// ============================================
// AUTHENTICATION
// ============================================
$container->singleton('jwt', fn() => new App\Core\Authentication\Service\JwtService());

$container->singleton(
    App\Core\Authentication\Service\JwtService::class,
    fn($c) => $c->get('jwt')
);

$container->singleton('password_hasher', fn() => new App\Core\Authentication\Service\PasswordHasher([
    'rounds' => (int) ($_ENV['BCRYPT_ROUNDS'] ?? 12)
]));
$container->singleton(
    App\Core\Authentication\Repository\UserRepositoryInterface::class,
    fn($c) => new App\Core\Authentication\Repository\UserRepository(
        $c->get('database')
    )
);

// ============================================
// AUTHORIZATION
// ============================================
$container->singleton(
    App\Core\Authorization\Repository\RoleRepositoryInterface::class,
    fn($c) => new App\Core\Authorization\Repository\RoleRepository($c->get('database'))
);
$container->singleton(
    App\Core\Authorization\Repository\PermissionRepositoryInterface::class,
    fn($c) => new App\Core\Authorization\Repository\PermissionRepository($c->get('database'))
);
$container->singleton(
    App\Core\Authorization\Repository\TemporaryPermissionRepositoryInterface::class,
    fn($c) => new App\Core\Authorization\Repository\TemporaryPermissionRepository(
        $c->get('database')
    )
);

// ============================================
// GROUPS (v1.11.0)
// ============================================
$container->singleton(
    App\Core\Groups\Repository\UserGroupRepositoryInterface::class,
    fn($c) => new App\Core\Groups\Repository\UserGroupRepository(
        $c->get('database')
    )
);

// ============================================
// TENANTS
// ============================================
$container->singleton(
    App\Core\Tenants\Repository\TenantRepositoryInterface::class,
    fn($c) => new App\Core\Tenants\Repository\TenantRepository(
        $c->get('database')
    )
);
$container->singleton(
    App\Core\Tenants\Service\TenantContext::class,
    fn($c) => new App\Core\Tenants\Service\TenantContext($c->get(App\Core\Tenants\Repository\TenantRepositoryInterface::class))
);

// ============================================
// USERS
// ============================================
$container->singleton(
    App\Core\Users\Service\UserService::class,
    fn($c) => new App\Core\Users\Service\UserService(
        $c->get(App\Core\Authentication\Repository\UserRepositoryInterface::class),
        $c->get('password_hasher'),
        $c->get('database')
    )
);

// Revocación de sesiones al cambiar/resetear contraseñas (v1.11.0)
$container->singleton(
    App\Core\Authentication\Service\SessionRevokerService::class,
    fn($c) => new App\Core\Authentication\Service\SessionRevokerService(
        $c->get('database'),
        $c->get('redis')
    )
);

// ============================================
// AUTHORIZATION SERVICE
// ============================================
$container->singleton(
    App\Core\Authorization\Service\AuthorizationService::class,
    fn($c) => new App\Core\Authorization\Service\AuthorizationService(
        $c->get(App\Core\Authentication\Repository\UserRepositoryInterface::class),
        $c->get(App\Core\Authorization\Repository\RoleRepositoryInterface::class),
        $c->get(App\Core\Authorization\Repository\PermissionRepositoryInterface::class),
        $c->get('database'),
        $c->get('redis')
    )
);

// ============================================
// TENANT SERVICE
// ============================================
$container->singleton(
    App\Core\Tenants\Service\TenantService::class,
    fn($c) => new App\Core\Tenants\Service\TenantService(
        $c->get(App\Core\Tenants\Repository\TenantRepositoryInterface::class),
        $c->get(App\Core\Users\Service\UserService::class),
        $c->get(App\Core\Authentication\Repository\UserRepositoryInterface::class),
        $c->get('password_hasher'),
        $c->get('database'),
        $c->get('redis')
    )
);

// ============================================
// SECURITY DOMAIN (Sprint 0)
// ============================================
$container->singleton(
    App\Core\Security\Service\EncryptionService::class,
    fn() => new App\Core\Security\Service\EncryptionService()
);

$container->singleton(
    App\Core\Security\Service\InputSanitizer::class,
    fn() => new App\Core\Security\Service\InputSanitizer()
);

$container->singleton(
    App\Core\Security\Repository\RateLimitRepository::class,
    fn($c) => new App\Core\Security\Repository\RateLimitRepository($c->get('database'))
);

$container->singleton(
    App\Core\Security\Service\RateLimiter::class,
    fn($c) => new App\Core\Security\Service\RateLimiter(
        $c->get('redis'),
        $c->get(App\Core\Security\Repository\RateLimitRepository::class)
    )
);

$container->singleton(
    App\Core\Security\Service\CsrfToken::class,
    fn($c) => new App\Core\Security\Service\CsrfToken($c->get('redis'))
);

$container->singleton(
    App\Core\Security\Repository\SecretRepositoryInterface::class,
    fn($c) => new App\Core\Security\Repository\SecretRepository($c->get('database'))
);

$container->singleton(
    App\Core\Security\Service\SecretsVaultService::class,
    fn($c) => new App\Core\Security\Service\SecretsVaultService(
        $c->get(App\Core\Security\Repository\SecretRepositoryInterface::class),
        $c->get(App\Core\Security\Service\EncryptionService::class),
        $c->get(App\Core\Tenants\Service\TenantContext::class),
        $c->get('database')
    )
);

// ============================================
// AUDIT TRAIL (spec §7.1)
// ============================================
$container->singleton(
    App\Core\Audit\AuditPort::class,
    fn($c) => new App\Core\Audit\PdoAuditPort($c->get('database'))
);

$container->singleton(
    App\Core\Audit\AuditLogger::class,
    fn($c) => new App\Core\Audit\AuditLogger(
        $c->get(App\Core\Audit\AuditPort::class),
        $c->get(App\Core\Tenants\Service\TenantContext::class)
    )
);

$container->singleton(
    App\Core\Audit\Service\AuditLogQueryService::class,
    fn($c) => new App\Core\Audit\Service\AuditLogQueryService($c->get('database'))
);

// ============================================
// INVENTORY MODULE (Sprint 4)
// ============================================
$inventoryProvider = new App\Modules\Inventory\Providers\InventoryServiceProvider();
$inventoryProvider->register($container);

// ============================================
// FINALIZACIÓN
// ============================================
App\Shared\Container\Container::setInstance($container);

return $container;
