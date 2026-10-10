<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Security\Repository\RateLimitRepository;
use App\Shared\Exceptions\RedisException;
use App\Shared\Exceptions\SecurityException;
use App\Shared\Services\RedisService;
use Predis\Connection\ConnectionException;

/**
 * RateLimiter - Protección contra abuso y ataques de fuerza bruta.
 *
 * Responsabilidades:
 * - Limitar requests por usuario/IP/endpoint
 * - Usar Redis para conteo distribuido
 * - Ventana deslizante de tiempo configurable
 * - Headers estándar: X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After
 *
 * Configuración vía .env:
 *   RATE_LIMIT_REQUESTS=100
 *   RATE_LIMIT_WINDOW=60
 */
final class RateLimiter
{
    private RedisService $redis;
    private ?RateLimitRepository $repository;
    private int $defaultLimit;
    private int $defaultWindow;
    private const KEY_PREFIX = 'rate_limit:';

    /** @var array<string, ?array> Caché de overrides por request (endpoint|tenant => fila|null) */
    private array $configCache = [];

    public function __construct(?RedisService $redis = null, ?RateLimitRepository $repository = null)
    {
        $container = \App\Shared\Container\Container::getInstance();
        $this->redis = $redis ?? $container->get('redis');
        if ($repository === null && $container->has(RateLimitRepository::class)) {
            $repository = $container->get(RateLimitRepository::class);
        }
        $this->repository = $repository;
        $this->defaultLimit = (int) ($_ENV['RATE_LIMIT_REQUESTS'] ?? 100);
        $this->defaultWindow = (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60);
    }

    /**
     * Verifica si se excedió el límite de requests.
     *
     * @param string $key Clave única (ej: "user:123:login" o "ip:192.168.1.1:api")
     * @param int $maxAttempts Máximo de intentos permitidos
     * @param int $decaySeconds Ventana de tiempo en segundos
     * @return bool True si se excedió el límite
     */
    public function tooManyAttempts(string $key, int $maxAttempts = 0, int $decaySeconds = 0): bool
    {
        $maxAttempts = $maxAttempts > 0 ? $maxAttempts : $this->defaultLimit;
        $decaySeconds = $decaySeconds > 0 ? $decaySeconds : $this->defaultWindow;

        $attempts = $this->getAttempts($key);

        return $attempts >= $maxAttempts;
    }

    /**
     * Incrementa el contador de intentos y verifica si se excedió el límite.
     * Lanza excepción si se excede.
     *
     * @param string $key Clave única
     * @param int $maxAttempts Máximo de intentos
     * @param int $decaySeconds Ventana de tiempo
     * @return array{limit: int, remaining: int, retry_after: int}
     * @throws SecurityException Si se excede el límite
     */
    public function attempt(string $key, int $maxAttempts = 0, int $decaySeconds = 0): array
    {
        $maxAttempts = $maxAttempts > 0 ? $maxAttempts : $this->defaultLimit;
        $decaySeconds = $decaySeconds > 0 ? $decaySeconds : $this->defaultWindow;

        $attempts = $this->hit($key, $decaySeconds);
        $remaining = max(0, $maxAttempts - $attempts);

        if ($attempts > $maxAttempts) {
            $retryAfter = $this->availableIn($key);
            throw SecurityException::rateLimitExceeded($retryAfter);
        }

        return [
            'limit' => $maxAttempts,
            'remaining' => $remaining,
            'retry_after' => 0,
        ];
    }

    /**
     * Incrementa el contador de intentos.
     *
     * @param string $key Clave única
     * @param int $decaySeconds Ventana de tiempo
     * @return int Número total de intentos después del incremento
     */
    public function hit(string $key, int $decaySeconds = 0): int
    {
        $decaySeconds = $decaySeconds > 0 ? $decaySeconds : $this->defaultWindow;
        $redisKey = self::KEY_PREFIX . $key;

        try {
            $client = $this->redis->getClient();

            // Incrementar contador
            $attempts = (int) $client->incr($redisKey);

            // Si es el primer intento, establecer TTL
            if ($attempts === 1) {
                $client->expire($redisKey, $decaySeconds);
            }

            // Registrar timestamp del último intento (con TTL: evita claves huérfanas)
            $client->hset($redisKey . ':meta', 'last_attempt', time());
            $client->expire($redisKey . ':meta', $decaySeconds);

            return $attempts;
        } catch (ConnectionException|RedisException $e) {
            error_log("RateLimiter hit failed (fail-open): {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Obtiene el número actual de intentos.
     *
     * @param string $key Clave única
     * @return int Número de intentos
     */
    public function getAttempts(string $key): int
    {
        $redisKey = self::KEY_PREFIX . $key;

        try {
            $attempts = $this->redis->getClient()->get($redisKey);
            return $attempts !== null ? (int) $attempts : 0;
        } catch (ConnectionException|RedisException $e) {
            error_log("RateLimiter getAttempts failed (fail-open): {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Calcula segundos hasta que se pueda reintentar.
     *
     * @param string $key Clave única
     * @return int Segundos hasta reset
     */
    public function availableIn(string $key): int
    {
        $redisKey = self::KEY_PREFIX . $key;

        try {
            $ttl = (int) $this->redis->getClient()->ttl($redisKey);
            return $ttl > 0 ? $ttl : 0;
        } catch (ConnectionException|RedisException $e) {
            error_log("RateLimiter availableIn failed (fail-open): {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Limpia el contador de intentos.
     *
     * @param string $key Clave única
     * @return bool True si se limpió
     */
    public function clear(string $key): bool
    {
        $redisKey = self::KEY_PREFIX . $key;

        try {
            $this->redis->getClient()->del([$redisKey, $redisKey . ':meta']);
            return true;
        } catch (ConnectionException|RedisException $e) {
            error_log("RateLimiter clear failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Genera clave única para rate limiting por usuario.
     *
     * @param string $userId ID del usuario
     * @param string $action Acción/endpoint (ej: "login", "api")
     * @return string Clave única
     */
    public function keyForUser(string $userId, string $action = 'api'): string
    {
        return "user:{$userId}:{$action}";
    }

    /**
     * Genera clave única para rate limiting por IP.
     *
     * @param string $ip Dirección IP
     * @param string $action Acción/endpoint
     * @return string Clave única
     */
    public function keyForIp(string $ip, string $action = 'api'): string
    {
        return "ip:{$ip}:{$action}";
    }

    /**
     * Genera clave única para el bloqueo por cuenta (v1.13.2): identifica al
     * OBJETIVO del intento (tenant + email), no al cliente, de modo que un
     * atacante con muchas IPs no puede fuerza-brutar una cuenta concreta.
     *
     * @param string $tenantId Tenant resuelto del intento de login
     * @param string $email Email normalizado del intento
     * @param string $action Acción/endpoint (ej: "login")
     * @return string Clave única
     */
    public function keyForAccount(string $tenantId, string $email, string $action = 'login'): string
    {
        return "email:{$tenantId}:{$email}:{$action}";
    }

    /**
     * Genera clave única para rate limiting por endpoint.
     *
     * @param string $endpoint Ruta del endpoint
     * @param string $identifier ID adicional (user_id o ip)
     * @return string Clave única
     */
    public function keyForEndpoint(string $endpoint, string $identifier): string
    {
        $endpoint = str_replace(['/', '\\'], '_', $endpoint);
        return "endpoint:{$endpoint}:{$identifier}";
    }

    /**
     * Construye la clave HTTP que usa RateLimitMiddleware (sin prefijo KEY_PREFIX).
     * Formato: "rl:{action}:{identifier}:{endpoint normalizado}".
     * Centralizada aquí para que AuthController pueda limpiar exactamente la
     * misma clave que genera el middleware (login exitoso reinicia el contador).
     */
    public function httpKey(string $action, string $identifier): string
    {
        return "rl:{$action}:{$identifier}:" . self::normalizeEndpoint();
    }

    /**
     * Normaliza el path del request actual: UUIDs → :id, números → :num.
     */
    public static function normalizeEndpoint(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $path = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', ':id', $path);
        $path = preg_replace('/\/\d+/', '/:num', $path);

        return $path;
    }

    /**
     * Path del request normalizado para casar con rate_limit_config.endpoint:
     * se descarta cualquier prefijo del front controller (ej: /xp/public) y se
     * conserva desde /api en adelante. UUIDs → :id, números → :num.
     *
     * Ej: /xp/public/api/inventory/products/123 → /api/inventory/products/:num
     */
    public static function endpointForConfig(): string
    {
        $path = self::normalizeEndpoint();

        if (preg_match('#(/api/.*)$#', $path, $m)) {
            return $m[1];
        }

        return $path;
    }

    /**
     * Override de límites desde rate_limit_config para el endpoint actual.
     * Retorna null si no hay fila activa (se usan los límites de código).
     * Caché por request: máximo 1 SELECT por request.
     */
    public function getEndpointConfig(?string $tenantId): ?array
    {
        if ($this->repository === null) {
            return null;
        }

        $endpoint = self::endpointForConfig();
        $cacheKey = $endpoint . '|' . ($tenantId ?? '-');

        if (array_key_exists($cacheKey, $this->configCache)) {
            return $this->configCache[$cacheKey];
        }

        try {
            $config = $this->repository->findActiveOverride($endpoint, $tenantId);
        } catch (\Throwable $e) {
            error_log("RateLimiter getEndpointConfig failed (fail-open): {$e->getMessage()}");
            $config = null;
        }

        $this->configCache[$cacheKey] = $config;
        return $config;
    }

    /**
     * Persiste un evento de rechazo en rate_limit_log. Fail-safe:
     * un fallo de BD nunca debe impedir devolver el 429.
     */
    public function logExceeded(
        ?string $tenantId,
        ?string $userId,
        string $ipAddress,
        string $endpoint,
        int $requestsContados,
        int $limite,
        ?int $duracionBloqueo = null
    ): void {
        if ($this->repository === null) {
            return;
        }

        try {
            $this->repository->logExceeded(
                $tenantId,
                $userId,
                $ipAddress,
                $endpoint,
                $requestsContados,
                $limite,
                'REJECT',
                $duracionBloqueo
            );
        } catch (\Throwable $e) {
            error_log("RateLimiter logExceeded failed: {$e->getMessage()}");
        }
    }

    /**
     * Retorna headers HTTP estándar para rate limiting.
     *
     * @param int $limit Límite máximo
     * @param int $remaining Intentos restantes
     * @param int $retryAfter Segundos hasta reset (0 si no hay bloqueo)
     * @return array Headers HTTP
     */
    public function getHeaders(int $limit, int $remaining, int $retryAfter = 0): array
    {
        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
        ];

        if ($retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return $headers;
    }

    /**
     * Límites predefinidos para diferentes tipos de operaciones.
     */
    public function getLimitsForAction(string $action): array
    {
        return match ($action) {
            'login' => ['limit' => 5, 'window' => 300],        // 5 intentos / 5 min
            'api' => ['limit' => 100, 'window' => 60],         // 100 req / 1 min
            'critical' => ['limit' => 10, 'window' => 60],     // 10 req / 1 min
            'bulk' => ['limit' => 5, 'window' => 60],          // 5 req / 1 min
            'upload' => ['limit' => 10, 'window' => 3600],     // 10 uploads / 1 hora
            default => ['limit' => $this->defaultLimit, 'window' => $this->defaultWindow],
        };
    }
}
