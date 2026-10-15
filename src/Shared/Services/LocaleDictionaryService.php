<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Exceptions\HttpException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Diccionarios de i18n (v1.10.1, spec Internacionalizacion).
 *
 * Sirve resources/locales/{locale}.json con:
 *  - whitelist estricta de idiomas (es, en, pt, fr): cualquier otra cosa,
 *    incluido cualquier intento de path traversal, da 404
 *  - cache en Redis (TTL 1h) con fallback a fichero si Redis no esta
 *  - version estable del diccionario (sha256 corto del contenido) y ETag
 */
final class LocaleDictionaryService
{
    /** Idiomas admitidos (spec i18n: es, en, pt, fr). */
    public const SUPPORTED = ['es', 'en', 'pt', 'fr'];

    /** Idioma por defecto (fallback del frontend). */
    public const DEFAULT_LOCALE = 'es';

    /** TTL del cache en Redis. */
    private const CACHE_TTL = 3600;

    private readonly ?RedisService $redis;
    private readonly ?string $baseDir;

    public function __construct(?RedisService $redis = null, ?string $baseDir = null)
    {
        $this->redis = $redis;
        $this->baseDir = $baseDir;
    }

    /**
     * Normaliza el parametro de ruta y valida la whitelist.
     *
     * Acepta `es` o `es.json` (el frontend usa la forma con extension, como
     * en la spec `GET /api/locales/[locale].json`). Cualquier otro valor,
     * `..`, separadores o extensiones raras => 404 (sin traversal posible:
     * el nombre de fichero se construye DESPUES de pasar la whitelist).
     *
     * @throws NotFoundException si el idioma no esta en la whitelist
     */
    public static function normalizeLocale(string $raw): string
    {
        $locale = strtolower(trim($raw));

        if (str_ends_with($locale, '.json')) {
            $locale = substr($locale, 0, -5);
        }

        if (!in_array($locale, self::SUPPORTED, true)) {
            throw new NotFoundException('Locale not found: ' . $raw);
        }

        return $locale;
    }

    /**
     * Carga el diccionario completo (cache Redis -> fichero).
     *
     * @return array{locale: string, raw: string, dictionary: array<string, mixed>,
     *               version: string, etag: string}
     *
     * @throws NotFoundException si no existe el fichero del idioma
     * @throws HttpException si el fichero existe pero no es JSON valido
     */
    public function load(string $locale): array
    {
        $locale = self::normalizeLocale($locale);
        // v1.14.0: la fecha de modificacion del fichero entra en la clave,
        // asi un diccionario editado (git pull) no queda oculto 1h por Redis.
        $mtime = @filemtime(($this->baseDir ?? BASE_PATH . '/resources/locales') . DIRECTORY_SEPARATOR . $locale . '.json');
        $cacheKey = 'i18n:locale:' . $locale . ':' . ($mtime === false ? '0' : (string) $mtime);

        if ($this->redis !== null) {
            $cached = $this->redis->get($cacheKey);
            if (
                is_array($cached)
                && isset($cached['raw'], $cached['version'])
                && is_string($cached['raw'])
                && is_string($cached['version'])
            ) {
                $dictionary = json_decode($cached['raw'], true);
                if (is_array($dictionary)) {
                    return $this->assemble($locale, $cached['raw'], $dictionary, $cached['version']);
                }
            }
        }

        $dir = $this->baseDir ?? BASE_PATH . '/resources/locales';
        $realDir = realpath($dir);

        if ($realDir === false) {
            throw new NotFoundException('Locale not found: ' . $locale);
        }

        // Defense in depth: el fichero se construye desde la whitelist, pero
        // se comprueba igualmente que realpath() queda dentro del directorio.
        $file = $dir . DIRECTORY_SEPARATOR . $locale . '.json';
        $realFile = realpath($file);

        if ($realFile === false || !str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
            throw new NotFoundException('Locale not found: ' . $locale);
        }

        $raw = file_get_contents($realFile);

        if ($raw === false) {
            throw new NotFoundException('Locale not found: ' . $locale);
        }

        $dictionary = json_decode($raw, true);

        if (!is_array($dictionary)) {
            throw new HttpException('Locale dictionary corrupt: ' . $locale, 500);
        }

        $version = substr(hash('sha256', $raw), 0, 16);

        if ($this->redis !== null) {
            $this->redis->set($cacheKey, ['raw' => $raw, 'version' => $version], self::CACHE_TTL);
        }

        return $this->assemble($locale, $raw, $dictionary, $version);
    }

    /**
     * Compara un header If-None-Match con el ETag vigente.
     *
     * Soporta listas por coma, `*` y el prefijo debil `W/`.
     */
    public function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $dictionary
     * @return array{locale: string, raw: string, dictionary: array<string, mixed>,
     *               version: string, etag: string}
     */
    private function assemble(string $locale, string $raw, array $dictionary, string $version): array
    {
        return [
            'locale' => $locale,
            'raw' => $raw,
            'dictionary' => $dictionary,
            'version' => $version,
            'etag' => '"' . $version . '"',
        ];
    }
}
