<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Container\Container;
use App\Shared\Services\LocaleDictionaryService;
use App\Shared\Services\RedisService;

/**
 * GET /api/locales/{locale} (v1.10.1, spec i18n).
 *
 * Sirve resources/locales/{locale}.json (es, en, pt, fr) con cache en Redis,
 * ETag y version del diccionario. Autenticado si (AuthenticationMiddleware),
 * SIN permiso de modulo. 304 con If-None-Match; 404 para cualquier idioma
 * fuera de la whitelist (incluido path traversal, ver normalizeLocale()).
 */
final class LocaleController
{
    /**
     * @param string $locale parametro de ruta: `es`, `en`, `pt`, `fr` (con o
     *                        sin `.json`)
     */
    public function show(string $locale): never
    {
        $service = self::service();
        $normalized = LocaleDictionaryService::normalizeLocale($locale);
        $entry = $service->load($normalized);

        $etag = $entry['etag'];
        $commonHeaders = [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'X-Dictionary-Version' => $entry['version'],
        ];

        $ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');

        if ($ifNoneMatch !== '' && $service->etagMatches($ifNoneMatch, $etag)) {
            http_response_code(304);

            foreach ($commonHeaders as $name => $value) {
                header("{$name}: {$value}");
            }

            exit;
        }

        json_response([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'locale' => $entry['locale'],
                'version' => $entry['version'],
                'dictionary' => $entry['dictionary'],
            ],
        ], 200, $commonHeaders);
    }

    private static function service(): LocaleDictionaryService
    {
        $container = Container::getInstance();
        $redis = $container->has('redis') ? $container->get('redis') : null;

        return new LocaleDictionaryService($redis instanceof RedisService ? $redis : null);
    }
}
