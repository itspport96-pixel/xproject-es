<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');

require_once BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->load();
$dotenv->required(['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'JWT_SECRET', 'JWT_ALGORITHM']);

// M1: Validar que JWT_ALGORITHM sea uno de los algoritmos simétricos admitidos.
// Esto previene ataques de confusión de algoritmo (ej: 'none' o algoritmos asimétricos).
$jwtAlgorithm = $_ENV['JWT_ALGORITHM'] ?? '';
if (!in_array($jwtAlgorithm, ['HS256', 'HS384', 'HS512'], true)) {
    throw new \RuntimeException(
        "JWT_ALGORITHM inválido: '{$jwtAlgorithm}'. " .
        "Admitidos: HS256, HS384, HS512 (algoritmos simétricos HMAC-SHA)."
    );
}

// C6: APP_URL alimenta los enlaces de email (buildResetUrl). Un valor no
// http(s) (javascript:, data:, relativo...) acabaria como href clicable en
// el correo del usuario → XSS en su navegador. Se aborta al arrancar.
$appUrl = (string) (getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ''));
if ($appUrl === ''
    || filter_var($appUrl, FILTER_VALIDATE_URL) === false
    || !in_array(strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
    throw new \RuntimeException('APP_URL debe ser una URL http(s) absoluta válida.');
}

// Zona horaria de la app (v1.19.1): no depende del date.timezone del php.ini
// de la maquina. Manda la variable de entorno del proceso (getenv, mismo
// patron que appUrl() en public/index.php) y luego el .env (TIMEZONE, la
// misma clave que usa el helper now()); default UTC y si el valor no es una
// zona conocida se cae a UTC en vez de dejar la app a medias. my.ini/php.ini
// quedan como refuerzo para los procesos ajenos a la app.
$appTimeZone = (string) (getenv('TIMEZONE') ?: ($_ENV['TIMEZONE'] ?? 'Europe/Madrid'));
if (!in_array($appTimeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
    $appTimeZone = 'UTC';
}
date_default_timezone_set($appTimeZone);
// Una sola resolucion por proceso: el helper now() (env('TIMEZONE')) y date()
// ven lo mismo aunque el valor haya venido del entorno del proceso.
$_ENV['TIMEZONE'] = $appTimeZone;

if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED);
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
    'charset'  => 'utf8mb4',
    // v1.19.1: sesion MySQL portable (.env con default en codigo). Manda la
    // variable del proceso (getenv, como appUrl()) y luego el .env; los valores
    // se validan en DatabaseService::connect antes de montar el INIT_COMMAND.
    'sql_mode' => getenv('DB_SQL_MODE') ?: ($_ENV['DB_SQL_MODE'] ?? null),
    'time_zone' => getenv('DB_TIME_ZONE') ?: ($_ENV['DB_TIME_ZONE'] ?? null),
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

$container->singleton(
    App\Core\Authentication\Service\MfaService::class,
    fn($c) => new App\Core\Authentication\Service\MfaService(
        $c->get('database'),
        $c->get(App\Core\Security\Service\EncryptionService::class)
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

// Password Reset SSPR (v1.17.0): restablecimiento por correo transaccional.
$container->singleton(
    App\Core\Authentication\Service\PasswordResetService::class,
    fn($c) => new App\Core\Authentication\Service\PasswordResetService(
        $c->get('database'),
        $c->get(App\Core\Authorization\Service\AuthorizationService::class),
        $c->get(App\Core\Users\Service\UserService::class),
        $c->get(App\Core\Tenants\Service\MailerService::class),
        $c->get(App\Core\Audit\AuditLogger::class)
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
        $c->get('redis'),
        $c->get(App\Core\Security\Service\EncryptionService::class)
    )
);

$container->singleton(
    App\Core\Tenants\Service\MailerService::class,
    fn($c) => new App\Core\Tenants\Service\MailerService(
        $c->get(App\Core\Tenants\Service\TenantService::class)
    )
);

$container->singleton(
    App\Shared\Services\MailCatalog::class,
    fn() => new App\Shared\Services\MailCatalog()
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
