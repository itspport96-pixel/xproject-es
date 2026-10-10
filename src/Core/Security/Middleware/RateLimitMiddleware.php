<?php
declare(strict_types=1);

namespace App\Core\Security\Middleware;

use App\Core\Security\Service\RateLimiter;
use App\Shared\Container\Container;
use App\Shared\Exceptions\SecurityException;

/**
 * RateLimitMiddleware - Protección contra abuso de endpoints.
 *
 * Responsabilidades:
 * - Limitar requests por usuario/IP/endpoint
 * - Retornar headers estándar: X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After
 * - Identificar al usuario por user_id (si autenticado) o IP (si anónimo),
 *   respetando `tipo_limit` de rate_limit_config cuando existe un override
 * - Soportar acciones predefinidas (login, api, critical, bulk, upload)
 * - Overrides por endpoint/tenant desde rate_limit_config (tuning en runtime)
 * - Auditar cada rechazo en rate_limit_log (spec §6.3)
 * - Lanzar SecurityException (429) al exceder el límite
 *
 * Uso en rutas:
 *   // Límite por defecto (api: 100 req/min)
 *   $router->get('/api/users', [UserController::class, 'index'], [
 *       AuthenticationMiddleware::class,
 *       RateLimitMiddleware::class
 *   ]);
 *
 *   // Límite específico (login: 5 intentos/5min)
 *   $router->post('/api/auth/login', [AuthController::class, 'login'], [
 *       RateLimitMiddleware::class . ':login'
 *   ]);
 *
 *   // Límite crítico (10 req/min)
 *   $router->post('/api/secrets', [SecretsController::class, 'create'], [
 *       AuthenticationMiddleware::class,
 *       RateLimitMiddleware::class . ':critical'
 *   ]);
 *
 *   // Límite custom (20 req/120s)
 *   $router->post('/api/bulk', [BulkController::class, 'import'], [
 *       AuthenticationMiddleware::class,
 *       RateLimitMiddleware::class . ':20,120'
 *   ]);
 */
final class RateLimitMiddleware
{
    private RateLimiter $limiter;
    private string $action;
    private ?int $customLimit;
    private ?int $customWindow;

    /**
     * @param string|null $config Configuración del rate limit:
     *   - null o vacío: usa defaults del .env
     *   - "login" | "api" | "critical" | "bulk" | "upload": acción predefinida
     *   - "20,120": límite custom (20 requests / 120 segundos)
     */
    public function __construct(?string $config = null, ?RateLimiter $limiter = null)
    {
        $container = Container::getInstance();
        $this->limiter = $limiter ?? $container->get(RateLimiter::class);

        $this->customLimit = null;
        $this->customWindow = null;
        $this->action = 'api';

        if ($config !== null && $config !== '') {
            $this->parseConfig($config);
        }
    }

    /**
     * Procesa el request aplicando rate limiting.
     *
     * @param array $request Request actual
     * @return array Request sin modificar si pasa el límite
     * @throws SecurityException 429 si se excede el límite
     */
    public function handle(array $request): array
    {
        // Solo aplicar en contexto HTTP (no CLI)
        if (PHP_SAPI === 'cli') {
            return $request;
        }

        // Override de rate_limit_config (spec §6.3): 1 SELECT por request como máximo
        $tenantId = null;
        $claims = $request['user'] ?? null;
        if (is_array($claims)) {
            $tenantId = isset($claims['tenant_id']) && $claims['tenant_id'] !== '' ? (string) $claims['tenant_id'] : null;
        }
        $config = $this->limiter->getEndpointConfig($tenantId);

        $identifier = $this->resolveIdentifier($request, $config);
        $key = $this->buildKey($identifier);

        // Obtener límites (config DB > custom "N,S" > acción predefinida)
        [$limit, $window] = $this->resolveLimits($config);

        try {
            $result = $this->limiter->attempt($key, $limit, $window);

            // Agregar headers de rate limit al response
            $this->applyHeaders($result['limit'], $result['remaining'], 0);

            return $request;
        } catch (SecurityException $e) {
            // Excedió el límite: headers, auditoría en rate_limit_log y429
            $retryAfter = $this->limiter->availableIn($key);
            $this->applyHeaders($limit, 0, $retryAfter);

            $this->limiter->logExceeded(
                $tenantId,
                is_array($claims) && !empty($claims['user_id']) ? (string) $claims['user_id'] : null,
                $this->getClientIp(),
                RateLimiter::endpointForConfig(),
                $this->limiter->getAttempts($key),
                $limit,
                $retryAfter
            );

            throw SecurityException::rateLimitExceeded($retryAfter);
        }
    }

    /**
     * Factory method para crear el middleware desde el router con sintaxis simplificada.
     *
     * Ejemplo: RateLimitMiddleware::create('login')
     */
    public static function create(string $config = 'api', ?RateLimiter $limiter = null): self
    {
        return new self($config, $limiter);
    }

    // ========================================================================
    // RESOLUCIÓN DE IDENTIFICADOR
    // ========================================================================

    /**
     * Resuelve el identificador único para rate limiting.
     * Respeta `tipo_limit` de rate_limit_config cuando hay override:
     *   POR_IP (forzar IP) > POR_ENDPOINT (global por endpoint) > POR_USUARIO.
     * Sin config: prioridad user_id (si autenticado) > IP address.
     */
    private function resolveIdentifier(array $request, ?array $config): string
    {
        $tipoLimit = is_array($config) ? (string) ($config['tipo_limit'] ?? '') : '';

        if ($tipoLimit === 'POR_IP') {
            return 'ip:' . $this->getClientIp();
        }

        if ($tipoLimit === 'POR_ENDPOINT') {
            return 'endpoint:global';
        }

        // POR_USUARIO (default): user_id si está autenticado, si no IP
        $claims = $request['user'] ?? null;
        if (is_array($claims) && !empty($claims['user_id'])) {
            return 'user:' . (string) $claims['user_id'];
        }

        // Fallback: IP address
        $ip = $this->getClientIp();
        return 'ip:' . $ip;
    }

    /**
     * Obtiene la IP real del cliente.
     * X-Forwarded-For es manipulable por el cliente: sólo se confía
     * cuando la conexión proviene de un proxy declarado en TRUSTED_PROXIES.
     */
    private function getClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))));

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        // X-Forwarded-For (puede contener múltiples IPs, tomar la primera)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim((string) $ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // X-Real-IP (nginx)
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // REMOTE_ADDR (directo)
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return (string) $_SERVER['REMOTE_ADDR'];
        }

        return '0.0.0.0';
    }

    // ========================================================================
    // CONSTRUCCIÓN DE CLAVE
    // ========================================================================

    /**
     * Construye la clave única para rate limiting.
     * La composición vive en RateLimiter::httpKey() para que AuthController
     * pueda limpiar exactamente la misma clave tras un login exitoso.
     */
    private function buildKey(string $identifier): string
    {
        return $this->limiter->httpKey($this->action, $identifier);
    }

    // ========================================================================
    // RESOLUCIÓN DE LÍMITES
    // ========================================================================

    /**
     * Resuelve los límites (max requests, ventana) según la configuración.
     * Prioridad: override de rate_limit_config > sintaxis custom > acción.
     *
     * @param array|null $config Fila activa de rate_limit_config (si existe)
     * @return array{0: int, 1: int} [limit, window]
     */
    private function resolveLimits(?array $config): array
    {
        // 1. Override por endpoint/tenant desde la BD (tuning en runtime)
        if (is_array($config)) {
            $limit = (int) ($config['max_requests'] ?? 0);
            $window = (int) ($config['tiempo_ventana'] ?? 0);
            if ($limit > 0 && $window > 0) {
                return [$limit, $window];
            }
        }

        // 2. Límites custom (sintaxis "20,120")
        if ($this->customLimit !== null && $this->customWindow !== null) {
            return [$this->customLimit, $this->customWindow];
        }

        // 3. Límites predefinidos por acción
        $limits = $this->limiter->getLimitsForAction($this->action);
        return [$limits['limit'], $limits['window']];
    }

    /**
     * Parsea la configuración del constructor.
     */
    private function parseConfig(string $config): void
    {
        // ¿Es un número (límite custom)?
        if (str_contains($config, ',')) {
            [$limitStr, $windowStr] = explode(',', $config, 2);
            $limit = (int) trim($limitStr);
            $window = (int) trim($windowStr);

            if ($limit > 0 && $window > 0) {
                $this->customLimit = $limit;
                $this->customWindow = $window;
                $this->action = 'custom';
                return;
            }
        }

        // ¿Es una acción predefinida?
        $validActions = ['login', 'api', 'critical', 'bulk', 'upload'];
        if (in_array($config, $validActions, true)) {
            $this->action = $config;
            return;
        }

        // Fallback: usar como action genérica
        $this->action = 'api';
    }

    // ========================================================================
    // HEADERS
    // ========================================================================

    /**
     * Aplica los headers estándar de rate limiting a la response.
     */
    private function applyHeaders(int $limit, int $remaining, int $retryAfter): void
    {
        if (headers_sent()) {
            return;
        }

        header("X-RateLimit-Limit: {$limit}");
        header("X-RateLimit-Remaining: {$remaining}");

        if ($retryAfter > 0) {
            header("Retry-After: {$retryAfter}");
            header("X-RateLimit-Reset: " . (time() + $retryAfter));
        }
    }
}
