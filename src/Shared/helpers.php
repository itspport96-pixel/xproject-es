<?php

declare(strict_types=1);

use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

if (!function_exists('uuid')) {
    function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower((string)$value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Container::getInstance()->get('config')->get($key, $default);
    }
}

if (!function_exists('db')) {
    function db(): App\Shared\Services\DatabaseService
    {
        return Container::getInstance()->get('database');
    }
}

if (!function_exists('redis')) {
    function redis(): App\Shared\Services\RedisService
    {
        return Container::getInstance()->get('redis');
    }
}

if (!function_exists('now')) {
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(env('TIMEZONE', 'UTC')));
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return CONFIG_PATH . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('success')) {
    function success(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        json_response([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

if (!function_exists('error')) {
    function error(string $message, int $status = 400, mixed $data = null): never
    {
        json_response([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = ''): never
    {
        throw new HttpException($message ?: "HTTP {$status}", $status);
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        static $payload = null;

        if ($payload === null) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $raw = file_get_contents('php://input');
                $payload = json_decode($raw, true) ?? [];
            } else {
                $payload = array_merge($_POST, $_GET);
            }
        }

        if ($key === null) {
            return $payload;
        }

        return $payload[$key] ?? $default;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never
    {
        if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
            exit;
        }

        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow:auto;">';
            echo htmlspecialchars(var_export($var, true), ENT_QUOTES, 'UTF-8');
            echo '</pre>';
        }
        exit;
    }
}

if (!function_exists('dump')) {
    function dump(mixed ...$vars): void
    {
        if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
            return;
        }

        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow:auto;">';
            echo htmlspecialchars(var_export($var, true), ENT_QUOTES, 'UTF-8');
            echo '</pre>';
        }
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))));

        // X-Forwarded-For es manipulable por el cliente: sólo se confía
        // cuando la conexión proviene de un proxy declarado en TRUSTED_PROXIES.
        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim((string) $ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

if (!function_exists('user_agent')) {
    function user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}
