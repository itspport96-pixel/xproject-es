<?php
declare(strict_types=1);

/**
 * Entry Point HTTP
 *
 * Todas las peticiones pasan por aqui (via .htaccess)
 * - /api/* -> Router API (JSON)
 * - /*    -> Layout SPA (HTML)
 */

// Cargar bootstrap (retorna container)
$container = require dirname(__DIR__) . '/bootstrap.php';

use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use App\Shared\Services\ErrorCatalog;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Security\Middleware\SecurityHeadersMiddleware;

// Registrar servicios faltantes (si no están ya en bootstrap)
if (!$container->has('password_hasher')) {
    $container->singleton('password_hasher', function() {
        return new PasswordHasher([
            'rounds' => (int)($_ENV['BCRYPT_ROUNDS'] ?? 12),
        ]);
    });
}

if (!$container->has(UserRepository::class)) {
    $container->singleton(UserRepository::class, function($container) {
        return new UserRepository($container->get('database'));
    });
}

// ============================================
// Manejo global de errores
// ============================================
set_exception_handler(function (Throwable $e) use ($container) {
    if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
        error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    if (isApiRequest()) {
        $isAuthError = $e instanceof \App\Shared\Exceptions\InvalidTokenException
                    || $e instanceof \App\Shared\Exceptions\TokenExpiredException
                    || $e instanceof \App\Shared\Exceptions\AuthenticationException;

        if ($isAuthError) {
            $statusCode = 401;
        } elseif ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
        } elseif ($e instanceof \App\Shared\Exceptions\SecurityException) {
            // Rate limit u otro error de seguridad: 429 (no 500).
            $code = (int) $e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 429;
        } else {
            $statusCode = 500;
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        if ($statusCode === 429) {
            $msg = $e->getMessage();
            if (preg_match('/Retry after (\d+) second/i', $msg, $m)) {
                header('Retry-After: ' . $m[1]);
            }
        }

        if ($isAuthError) {
            $message = resolveErrorMessage($e, 'auth.session_expired', 'No autenticado o sesión expirada');
        } elseif ($e instanceof HttpException || $e instanceof \App\Shared\Exceptions\SecurityException) {
            $message = resolveErrorMessage($e, null, $e->getMessage() !== '' ? $e->getMessage() : 'Internal server error');
        } else {
            $message = 'Internal server error';
        }

        $data = [
            'field' => method_exists($e, 'getField') ? $e->getField() : null,
            'category' => method_exists($e, 'getCategory') ? $e->getCategory() : null,
            // Codigo de maquina para la UI (v1.13.20): ej. ROLE_NOT_DIRECT
            // en un 409 permite a la UI mostrar un mensaje i18n propio.
            'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
        ];
        if (isDebugEnabled()) {
            $data = array_merge($data, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        echo json_encode([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    http_response_code($e instanceof \App\Shared\Exceptions\HttpException ? $e->getStatusCode() : 500);
    renderErrorPage($e);
});

function isApiRequest(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    return str_contains($path, '/api/');
}

/**
 * Locale del request para mensajes de error: X-Locale del frontend,
 * Accept-Language del navegador, o es.
 */
function resolveRequestLocale(): string
{
    $xLocale = strtolower(trim($_SERVER['HTTP_X_LOCALE'] ?? ''));
    if ($xLocale !== '' && in_array($xLocale, ['es', 'en', 'pt', 'fr'], true)) {
        return $xLocale;
    }

    $accept = strtolower(trim($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($accept !== '') {
        foreach (explode(',', $accept) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            if (in_array($code, ['es', 'en', 'pt', 'fr'], true)) {
                return $code;
            }
            if (str_starts_with($code, 'es')) {
                return 'es';
            }
            if (str_starts_with($code, 'en')) {
                return 'en';
            }
        }
    }

    return 'es';
}

/**
 * Resuelve el mensaje de una excepcion: si tiene messageKey, busca en el
 * catalogo api_errors del locale del request; si no hay entrada (o el valor
 * esta vacio, fr/pt pendientes), cae al fallback (mensaje en espanol del
 * throw o el texto indicado).
 */
function resolveErrorMessage(Throwable $e, ?string $defaultKey, string $fallback): string
{
    $key = method_exists($e, 'getMessageKey') ? $e->getMessageKey() : null;
    $params = method_exists($e, 'getMessageParams') ? $e->getMessageParams() : [];

    if ($key === null && $defaultKey !== null) {
        $key = $defaultKey;
    }

    if ($key !== null) {
        $translated = ErrorCatalog::translate($key, $params, resolveRequestLocale());
        if ($translated !== null && $translated !== '') {
            return $translated;
        }
    }

    return $fallback;
}

// Los detalles internos (clase, archivo, línea, traza) sólo salen con
// APP_DEBUG=true; APP_ENV por sí sólo no basta para filtrarlos.
function isDebugEnabled(): bool
{
    return filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Version de cache-busting para JS/CSS: max(filemtime) de los assets del SPA.
 * Con mod_expires (1 semana) un deploy sin ?v= deja a los navegadores con JS
 * antiguo; el query fuerza la revalidacion cuando cambia cualquier asset.
 */
function assetsVersion(): string
{
    $files = array_merge(
        glob(__DIR__ . '/js/*.js') ?: [],
        glob(__DIR__ . '/js/*/*.js') ?: [],
        glob(__DIR__ . '/js/*/*/*.js') ?: [],
        glob(__DIR__ . '/css/*.css') ?: []
    );
    $max = 0;
    foreach ($files as $file) {
        $mtime = filemtime($file);
        if ($mtime > $max) {
            $max = $mtime;
        }
    }

    return (string) $max;
}

/**
 * URL base de la app: la variable de entorno del proceso (getenv) manda sobre
 * .env, asi un servidor embebido/contenedor puede fijar su propia URL (los E2E
 * usan http://127.0.0.1:8099; si no, el shell apuntaria la API a Apache y a la
 * BD de trabajo). phpdotenv createImmutable no pisa valores existentes, pero
 * como variables_order no incluye E, $_ENV solo lo rellena desde .env.
 */
function appUrl(): string
{
    $fromEnv = getenv('APP_URL');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }

    return $_ENV['APP_URL'] ?? '';
}

function renderErrorPage(Throwable $e): void
{
    $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
    $message = $e instanceof HttpException ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'Internal Server Error';
    $isDev = isDebugEnabled();
    // Los tokens y las clases de la página de error viven en /css (themes.css
    // trae los tokens de claro/oscuro, error.css sólo las clases de esta página).
    $baseUrlHtml = htmlspecialchars(rtrim(appUrl(), '/'), ENT_QUOTES, 'UTF-8');
    $assetsVersion = assetsVersion();

    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {$code}</title>
    <script>
        (function() {
            var theme = null;
            try {
                var raw = localStorage.getItem('xp.theme');
                if (raw) {
                    var parsed = JSON.parse(raw);
                    theme = (parsed && parsed.v) ? parsed.v : raw;
                }
            } catch (e) {
                theme = null;
            }
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var isDark = theme === 'dark' || (!theme && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.classList.toggle('light', !isDark);
        })();
    </script>
    <link rel="stylesheet" href="{$baseUrlHtml}/css/themes.css?v={$assetsVersion}">
    <link rel="stylesheet" href="{$baseUrlHtml}/css/error.css?v={$assetsVersion}">
</head>
<body class="error-page">
    <div class="error-card">
        <h1 class="error-title">Error {$code}</h1>
        <p class="error-message">{$message}</p>
HTML;
    if ($isDev) {
        $trace = htmlspecialchars($e->getTraceAsString());
        echo "<pre class=\"error-detail\">{$trace}</pre>";
    }
    echo '</div></body></html>';
}

// ============================================
// Security Headers (aplicar globalmente ANTES de cualquier response)
// ============================================
try {
    $securityHeaders = new SecurityHeadersMiddleware();
    $securityHeaders->handle([]);
} catch (\Throwable $e) {
    error_log('SecurityHeadersMiddleware failed: ' . $e->getMessage());
}

// ============================================
// Routing principal
// ============================================
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = rtrim(dirname($scriptName), '/');
if ($basePath !== '/' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '') {
        $path = '/';
    }
}

// API -> Router
if (isApiRequest()) {
    $router = $container->get('router');
    $router->loadRoutes(BASE_PATH . '/routes/api.php');
    $router->dispatch($method, $path);
    exit;
}

// SPA -> Layout HTML base
renderSpaLayout();

function renderSpaLayout(): void
{
    $appNameHtml = htmlspecialchars($_ENV['APP_NAME'] ?? 'XP Platform', ENT_QUOTES, 'UTF-8');
    $baseUrlRaw = rtrim(appUrl(), '/');
    $baseUrlHtml = htmlspecialchars($baseUrlRaw, ENT_QUOTES, 'UTF-8');
    $appNameJs = json_encode($_ENV['APP_NAME'] ?? 'XP Platform');
    $appTaglineJs = json_encode($_ENV['APP_TAGLINE'] ?? 'Gestión de Inventario Multi-Tenant');
    $baseUrlJs = json_encode($baseUrlRaw);
    $apiBaseJs = json_encode($baseUrlRaw . '/api');
    $sessionTimeoutJs = (int) ($_ENV['SESSION_TIMEOUT'] ?? 1800);
    $assetsVersion = assetsVersion();
    $assetsVersionJs = json_encode($assetsVersion);

    echo <<<HTML
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Sin color fijo: persistence.js lo rellena leyendo --color-background -->
    <meta name="theme-color" content="">
    <title>{$appNameHtml}</title>
    <script>
        (function() {
            var theme = null;
            try {
                var raw = localStorage.getItem('xp.theme');
                if (raw) {
                    var parsed = JSON.parse(raw);
                    theme = (parsed && parsed.v) ? parsed.v : raw;
                }
            } catch (e) {
                theme = null;
            }
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var isDark = theme === 'dark' || (!theme && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.classList.toggle('light', !isDark);
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        // Coherente con los tokens de themes.css: el acento
                        // claro es azul-600 (AA como texto y como fondo con
                        // texto blanco); .text-primary/.bg-primary rara vez
                        // se usan, pero si se usan deben coincidir.
                        primary: { DEFAULT: '#2563eb', dark: '#60a5fa' }
                    },
                    // Reconciliacion con los tokens de themes.css (D8): si no,
                    // .rounded / .shadow los definiria el CDN con la escala
                    // estatica de Tailwind y el resultado dependeria del orden
                    // de carga entre el CDN y los ficheros locales.
                    borderRadius: {
                        sm: '0.25rem',
                        DEFAULT: '0.375rem'
                    },
                    boxShadow: {
                        sm: 'var(--shadow-sm)',
                        DEFAULT: 'var(--shadow-md)',
                        lg: 'var(--shadow-lg)'
                    }
                }
            }
        };
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="{$baseUrlHtml}/css/themes.css?v={$assetsVersion}">
    <link rel="stylesheet" href="{$baseUrlHtml}/css/style.css?v={$assetsVersion}">
    <link rel="stylesheet" href="{$baseUrlHtml}/css/components.css?v={$assetsVersion}">
</head>
<body class="min-h-screen">
    <div id="app" class="app-layout">
        <!-- Sidebar Container -->
        <aside id="sidebar" class="sidebar hidden">
            <!-- Rendered by AppSidebar -->
        </aside>

        <!-- Mobile Overlay -->
        <div id="sidebar-overlay" class="sidebar-overlay"></div>

        <!-- Main Content Area -->
        <div class="main-content">
            <header class="app-header">
                <div class="flex items-center gap-3">
                    <button id="sidebar-toggle" class="sidebar-toggle btn btn-ghost btn-sm" aria-label="Toggle menu" data-i18n-aria="shell.toggle_menu" aria-controls="sidebar" aria-expanded="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                </div>
                <div class="flex items-center gap-3">
                    <button id="theme-toggle" class="btn btn-ghost header-icon-btn" aria-label="Toggle theme" data-i18n-aria="shell.toggle_theme" title="">
                        <span>...</span>
                    </button>
                    <div id="lang-menu" class="relative">
                        <button id="lang-toggle" class="btn btn-ghost header-icon-btn" aria-haspopup="true" aria-expanded="false" aria-controls="lang-dropdown" aria-label="Seleccionar idioma" data-i18n-aria="shell.toggle_language" title="">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span id="lang-code" class="text-xs font-medium" aria-hidden="true">ES</span>
                        </button>
                        <div id="lang-dropdown" class="hidden absolute right-0 mt-2 w-40 py-1 bg-surface border border-default rounded-lg shadow-lg z-50" role="menu" aria-label="Idioma" data-i18n-aria="shell.language"></div>
                    </div>
                    <div id="user-menu" x-data="{ open: false }" @keydown.escape.window="open = false" class="relative">
                        <button @click="open = !open" class="btn btn-ghost header-icon-btn" aria-haspopup="true"
                                aria-expanded="false" :aria-expanded="open.toString()" aria-controls="user-dropdown"
                                aria-label="Cuenta" data-i18n-aria="shell.account" title="">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            <span class="account-label hidden md:inline text-xs font-medium" data-i18n="shell.account">Cuenta</span>
                        </button>
                        <div id="user-dropdown" x-show="open" x-cloak @click.away="open = false" @click="open = false"
                             class="absolute right-0 mt-2 w-48 py-1 bg-surface border border-default rounded-lg shadow-lg z-50">
                            <!-- Items pintados por ui/account-menu.js desde ACCOUNT_ITEMS -->
                        </div>
                    </div>
                </div>
            </header>
            <main id="main-content">
                <div id="module-container"></div>
            </main>
        </div>
    </div>

    <!-- Core Scripts -->
    <script src="{$baseUrlHtml}/js/core/storage.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/http.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/event-bus.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/i18n.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/toast.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/loader.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/router.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/themes/persistence.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/themes/theme-manager.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/modules/auth/state.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/modules/auth/login.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/core/idle.js?v={$assetsVersion}"></script>

    <!-- Shared UI components (v1.9.3): deben ir antes de los modulos -->
    <script src="{$baseUrlHtml}/js/ui/dom.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/render.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/view.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/modal.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/actions.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/permission-picker.js?v={$assetsVersion}"></script>
    <script src="{$baseUrlHtml}/js/ui/account-menu.js?v={$assetsVersion}"></script>

    <!-- Dashboard / App Shell Scripts -->
    <script src="{$baseUrlHtml}/js/modules/dashboard/sidebar.js?v={$assetsVersion}"></script>

    <script>
        window.AppConfig = {
            baseUrl: {$baseUrlJs},
            apiBase: {$apiBaseJs},
            appName: {$appNameJs},
            appTagline: {$appTaglineJs},
            sessionTimeout: {$sessionTimeoutJs},
            assetsVersion: {$assetsVersionJs}
        };
        document.addEventListener('DOMContentLoaded', function() {
            const boot = () => {
                // Orden: estado de auth primero (sidebar/router dependen de él)
                if (window.AuthState) AuthState.init();
                if (window.ThemeManager) ThemeManager.init();
                if (window.AppSidebar) AppSidebar.init();
                if (window.AppRouter) AppRouter.init();
                if (window.AccountMenu) AccountMenu.init();

                // Mobile sidebar toggle logic
                const toggleBtn = document.getElementById('sidebar-toggle');
                const overlay = document.getElementById('sidebar-overlay');
                if (toggleBtn && window.AppSidebar) {
                    toggleBtn.addEventListener('click', () => window.AppSidebar.toggle());
                }
                if (overlay && window.AppSidebar) {
                    overlay.addEventListener('click', () => window.AppSidebar.close());
                }
            };

            // i18n primero: descarga el diccionario (nunca rechaza) y aplica
            // data-i18n del shell antes de montar la app.
            if (window.i18n) {
                i18n.init().then(boot, boot);
            } else {
                boot();
            }
        });
    </script>
</body>
</html>
HTML;
}
