<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * AuditMiddleware - audita las peticiones de escritura (spec §7.1).
 *
 * Se instala PRIMERO en la cadena de middlewares para que su hook exista
 * incluso cuando un middleware posterior falle (401/403/429): el Router
 * invoca `$request['audit_finish']` tanto en el éxito como en la excepción.
 *
 * En el momento del hook el Router entrega la request YA enriquecida con los
 * claims (AuthenticationMiddleware / TenantMiddleware), de modo que el
 * registro incluye usuario y tenant reales.
 *
 * Sólo se auditan escrituras (POST/PUT/PATCH/DELETE): las lecturas GET no
 * generan registro (el middleware es inofensivo para ellas).
 */
final class AuditMiddleware
{
    private const NO_AUDIT_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private ?AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        if ($audit === null) {
            try {
                $container = Container::getInstance();
                $audit = $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;
            } catch (\Throwable) {
                $audit = null;
            }
        }

        $this->audit = $audit;
    }

    public function handle(array $request): array
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));

        if (in_array($method, self::NO_AUDIT_METHODS, true)) {
            return $request;
        }

        if ($this->audit === null) {
            return $request;
        }

        $requestId = $this->resolveRequestId($request);
        $request['request_id'] = $requestId;

        if (PHP_SAPI !== 'cli') {
            header('X-Request-Id: ' . $requestId);
        }

        $logger = $this->audit;
        $path = (string) ($request['path'] ?? $request['uri'] ?? '');
        $startedAt = microtime(true);

        $request['audit_finish'] = static function (
            ?int $status,
            ?\Throwable $error = null,
            array $result = [],
            array $finalRequest = []
        ) use ($logger, $method, $path, $requestId, $startedAt): void {
            // v1.7.1 (A3): replay idempotente detectado por el handler
            // (marcador `_replay`, retirado del body por Router). La operación
            // ya tiene su fila de auditoría de la ejecución original: aquí NO
            // se escribe ninguna nueva (ni para la respuesta ni para el fallo).
            if (!empty($result['_replay'])) {
                return;
            }

            $status ??= self::statusFor($error);
            $failed = $error !== null || $status >= 400;

            $claims = is_array($finalRequest['user'] ?? null) ? $finalRequest['user'] : null;
            $tenantId = $claims['tenant_id'] ?? ($finalRequest['tenant']['id'] ?? null);
            $userId = $claims['user_id'] ?? null;

            $logger->record([
                'tenant_id' => is_string($tenantId) ? $tenantId : null,
                'usuario_id' => is_string($userId) ? $userId : null,
                'operacion' => $method . ' ' . $path,
                'modulo' => self::deriveModule($path),
                'recurso' => self::deriveResource($path),
                'entidad_id' => self::deriveEntityId($finalRequest['params'] ?? []),
                'tipo_cambio' => self::tipoCambio($method),
                'valores_anteriores' => null,
                'valores_nuevos' => in_array($method, ['POST', 'PUT', 'PATCH'], true)
                    ? (($finalRequest['body'] ?? []) !== [] ? $finalRequest['body'] : null)
                    : null,
                'resultado' => $failed ? 'FAILURE' : 'SUCCESS',
                'razon_fallo' => $failed
                    ? ($error?->getMessage() ?? (string) ($result['error'] ?? $result['message'] ?? 'HTTP ' . $status))
                    : null,
                'ip_address' => $finalRequest['ip'] ?? null,
                'user_agent' => $finalRequest['user_agent'] ?? null,
                'request_id' => $requestId,
                'duracion_operacion' => (int) round((microtime(true) - $startedAt) * 1000),
                'detalles_adicionales' => [
                    'metodo_http' => $method,
                    'ruta' => $path,
                    'codigo_respuesta' => $status,
                    'idempotency_key' => $finalRequest['headers']['idempotency-key'] ?? null,
                    'query' => ($finalRequest['query'] ?? []) !== [] ? $finalRequest['query'] : null,
                ],
            ]);
        };

        return $request;
    }

    private static function statusFor(?\Throwable $error): int
    {
        if ($error === null) {
            return 200;
        }

        if ($error instanceof HttpException) {
            return $error->getStatusCode();
        }

        $code = (int) $error->getCode();

        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    private static function tipoCambio(string $method): string
    {
        return match ($method) {
            'DELETE' => 'DELETE',
            'PUT', 'PATCH' => 'UPDATE',
            default => 'EXECUTE',
        };
    }

    /** Segmento tras /api (inventory, users, auth, audit...). */
    private static function deriveModule(string $path): ?string
    {
        $segments = self::segments($path);

        return $segments[0] ?? null;
    }

    /**
     * Primer segmento tras el módulo que NO parezca un identificador
     * (products, users, movements...). Si el segmento es un id, se usa el módulo.
     */
    private static function deriveResource(string $path): ?string
    {
        $segments = self::segments($path);

        if ($segments === []) {
            return null;
        }

        $module = $segments[0];

        if (isset($segments[1]) && !self::looksLikeId($segments[1])) {
            return $segments[1];
        }

        return $module;
    }

    private static function deriveEntityId(array $routeParams): ?string
    {
        foreach ($routeParams as $value) {
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 64);
            }
        }

        return null;
    }

    /** @return array<int, string> Segmentos de la ruta sin el prefijo /api. */
    private static function segments(string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', trim(parse_url($path, PHP_URL_PATH) ?: $path, '/')),
            static fn (string $s): bool => $s !== ''
        ));

        if (($segments[0] ?? null) === 'api') {
            array_shift($segments);
        }

        return $segments;
    }

    private static function looksLikeId(string $segment): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }

        return ctype_digit($segment);
    }

    private function resolveRequestId(array $request): string
    {
        $header = (string) ($request['headers']['x-request-id'] ?? '');
        $header = preg_replace('/[^A-Za-z0-9\-_]/', '', $header) ?? '';

        if ($header !== '') {
            return mb_substr($header, 0, 64);
        }

        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
