<?php

declare(strict_types=1);

namespace App\Shared\Services;

/**
 * Catalogo de mensajes de error del backend (v1.13.35).
 *
 * Lee la seccion `api_errors` de resources/locales/{locale}.json y resuelve
 * messageKey + messageParams a un string traducido. El ErrorHandler de
 * public/index.php lo usa para devolver el mensaje en el idioma del usuario
 * (cabecera X-Locale del frontend, o Accept-Language del navegador).
 *
 * Fallbacks en cascada:
 *  1. locale pedido -> key
 *  2. es (fuente)   -> key
 *  3. null (el caller usa el mensaje en espanol del throw)
 *
 * Los valores vacios (fr/pt pendientes de convertir) se ignoran y caen al
 * siguiente fallback, igual que t() en el frontend.
 */
final class ErrorCatalog
{
    /** @var array<string, array<string, mixed>> cache estatica por locale */
    private static array $cache = [];

    private static ?string $baseDir = null;

    /** Para tests: sobreescribe el directorio de locales. */
    public static function setBaseDir(string $dir): void
    {
        self::$baseDir = $dir;
        self::$cache = [];
    }

    /**
     * Resuelve una clave del catalogo a mensaje traducido.
     *
     * @param string $key    Clave con puntos, ej. 'inventory.product_not_found'
     * @param array<string, scalar|null> $params Placeholders {nombre}
     * @param string $locale Locale destino (es, en, pt, fr)
     * @return string|null Mensaje traducido o null si no hay entrada util
     */
    public static function translate(string $key, array $params = [], string $locale = 'es'): ?string
    {
        $message = self::lookup($key, $locale);

        if ($message === null && $locale !== 'es') {
            $message = self::lookup($key, 'es');
        }

        if ($message === null || $message === '') {
            return null;
        }

        return self::interpolate($message, $params);
    }

    /** Lookup directo en la seccion api_errors del locale (sin fallback). */
    private static function lookup(string $key, string $locale): ?string
    {
        $dict = self::load($locale);
        if ($dict === null) {
            return null;
        }

        $node = $dict;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return is_string($node) ? $node : null;
    }

    /** Carga y cachea resources/locales/{locale}.json (solo api_errors). */
    private static function load(string $locale): ?array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        if (!in_array($locale, LocaleDictionaryService::SUPPORTED, true)) {
            self::$cache[$locale] = null;
            return null;
        }

        $dir = self::$baseDir ?? (defined('BASE_PATH') ? BASE_PATH . '/resources/locales' : null);
        if ($dir === null) {
            self::$cache[$locale] = null;
            return null;
        }

        $file = $dir . '/' . $locale . '.json';
        if (!is_file($file)) {
            self::$cache[$locale] = null;
            return null;
        }

        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['api_errors']) || !is_array($data['api_errors'])) {
            self::$cache[$locale] = null;
            return null;
        }

        self::$cache[$locale] = $data['api_errors'];
        return self::$cache[$locale];
    }

    /** Sustituye {placeholder} por el valor de $params (mismo estilo que el frontend). */
    private static function interpolate(string $message, array $params): string
    {
        if ($params === []) {
            return $message;
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use ($params): string {
                $name = $m[1];
                return array_key_exists($name, $params) ? (string) $params[$name] : $m[0];
            },
            $message
        );
    }
}
