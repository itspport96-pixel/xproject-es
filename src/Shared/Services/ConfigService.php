<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Exceptions\ConfigException;

class ConfigService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->resolve($key);

        if ($value === null) {
            return $default;
        }

        $this->cache[$key] = $value;
        return $value;
    }

    public function getEnv(string $var, mixed $default = null): mixed
    {
        return $_ENV[$var] ?? $default;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new ConfigException("Required config key missing: {$key}");
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->cache[$key] = $value;
    }

    public function all(): array
    {
        return $this->cache;
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    private function resolve(string $key): mixed
    {
        // 1. Variable de entorno directa
        if (isset($_ENV[$key])) {
            return $this->cast($_ENV[$key]);
        }

        // 2. Notación dot: database.host => CONFIG_PATH/database.php['host']
        if (str_contains($key, '.')) {
            return $this->resolveDotNotation($key);
        }

        return null;
    }

    private function resolveDotNotation(string $key): mixed
    {
        [$file, $path] = explode('.', $key, 2);

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $file)) {
            return null;
        }

        $filePath = CONFIG_PATH . '/' . $file . '.php';

        if (!file_exists($filePath)) {
            return null;
        }

        $config = require $filePath;

        if (!is_array($config)) {
            return null;
        }

        $segments = explode('.', $path);
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function cast(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $lower = strtolower($value);

        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => is_numeric($value) ? ($value + 0) : $value,
        };
    }
}
