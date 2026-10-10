<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\AuthController;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\JwtService;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Security\Service\InputSanitizer;
use App\Core\Security\Service\RateLimiter;
use App\Shared\Container\Container;
use App\Shared\Services\RedisService;
use Predis\Client;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Capa HTTP de POST /api/auth/login: bloqueo por CUENTA (v1.13.2).
 *
 * Cubre la deuda "AuthController sin tests" del lote de control:
 *  - Credenciales inválidas -> 401.
 *  - 4 fallo seguido para la misma cuenta -> 429 (umbral 3/300s), aunque
 *    el límite por IP (5) aún no se haya alcanzado.
 *  - El login exitoso limpia el contador de la cuenta: tras el éxito los
 *    siguientes fallos vuelven a empezar (sin el clear, el 2o fallo
 *    posterior saltaría a 429).
 *  - El bloqueo es por cuenta: otra cuenta sigue respondiendo 401.
 *
 * RedisService en memoria (counters del RateLimiter + valores de sesión),
 * repositorios y servicios REALES sobre SQLite.
 */
final class AuthControllerLoginTest extends PlatformControlSqliteTestCase
{
    private const USER = 'u-login';
    private const EMAIL = 'u-login@example.test';
    private const PASSWORD = 'Abcdef1!';

    private AuthController $controller;
    private LoginInMemoryRedisService $redis;
    private mixed $prevUri;
    private mixed $prevRemoteAddr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REQUEST_URI'] = '/xp/public/api/auth/login';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->seedUser(self::USER);
        $this->pdo->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado)
             VALUES (:id, :publico, :interno, \'ACTIVO\')'
        )->execute([
            'id' => self::TENANT,
            'publico' => 'Acme',
            'interno' => 'acme',
        ]);

        $this->redis = new LoginInMemoryRedisService();
        Container::getInstance()->instance('redis', $this->redis);

        $jwt = $this->createStub(JwtService::class);
        $jwt->method('generateToken')->willReturn('access-token');
        $jwt->method('generateRefreshToken')->willReturn('refresh-token');
        $jwt->method('getExpiration')->willReturn(3600);

        $this->controller = new AuthController(
            $jwt,
            new PasswordHasher(),
            new UserRepository($this->db),
            $this->db,
            new InputSanitizer(),
            new RateLimiter($this->redis),
            null,
            null,
            null,
            $this->authz
        );

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];

        if ($this->prevUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->prevUri;
        }

        if ($this->prevRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->prevRemoteAddr;
        }

        parent::tearDown();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Lanza login() con $_POST (php://input está vacío en CLI y login cae
     * a $_POST).
     *
     * @param array<string, mixed> $overrides
     * @return array{status: int, error?: string, data?: array<string, mixed>}
     */
    private function login(array $overrides = []): array
    {
        $_POST = array_merge([
            'email' => self::EMAIL,
            'password' => 'WrongPass1!',
            'tenant_id' => self::TENANT,
        ], $overrides);

        return $this->controller->login([
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'request_id' => 'req-login-test',
        ]);
    }

    // ====================================================================
    // TESTS
    // ====================================================================

    public function testCredencialesInvalidasDevuelve401(): void
    {
        $result = $this->login();

        $this->assertSame(401, $result['status']);
        $this->assertSame('Credenciales inválidas', $result['error']);
    }

    public function testCuartoFalloSeguidoDevuelve429PorCuenta(): void
    {
        $first = $this->login();
        $second = $this->login();
        $third = $this->login();
        $fourth = $this->login();

        $this->assertSame(401, $first['status'], 'Los 3 primeros fallos siguen dentro del umbral');
        $this->assertSame(401, $second['status']);
        $this->assertSame(401, $third['status']);
        $this->assertSame(429, $fourth['status'], 'El 4o fallo seguido bloquea la cuenta (3/300s)');
        $this->assertSame('Demasiados intentos. Espera unos minutos.', $fourth['error']);
    }

    public function testElLoginExitosoLimpiaElContadorDeLaCuenta(): void
    {
        $this->assertSame(401, $this->login()['status']);
        $this->assertSame(401, $this->login()['status']);

        $ok = $this->login(['password' => self::PASSWORD]);

        $this->assertSame(200, $ok['status']);
        $this->assertSame('access-token', $ok['data']['tokens']['access_token']);
        $this->assertSame(self::USER, $ok['data']['user']['id']);
        $this->assertSame(self::TENANT, $ok['data']['tenant_id']);
        $this->assertFalse($ok['data']['password_change_required']);

        // Sin el clear() el contador seguiría en 2 y el 2o fallo posterior
        // saltaría a 429; con el clear vuelven a empezar.
        $this->assertSame(401, $this->login()['status']);
        $this->assertSame(401, $this->login()['status']);
        $this->assertSame(401, $this->login()['status']);
    }

    public function testElBloqueoEsPorCuentaYNoAfectaAOtra(): void
    {
        $this->login();
        $this->login();
        $this->login();
        $blocked = $this->login();
        $this->assertSame(429, $blocked['status']);

        $other = $this->login(['email' => 'otra-cuenta@example.test']);

        $this->assertSame(401, $other['status'], 'Otra cuenta no está bloqueada por los fallos de la primera');
    }

    public function testTrasElBloqueoLaMismaCuentaSigueBloqueada(): void
    {
        $this->login();
        $this->login();
        $this->login();
        $this->login();

        $still = $this->login(['password' => self::PASSWORD]);

        $this->assertSame(429, $still['status'], 'El bloqueo se aplica antes de validar la contraseña');
    }
}

/**
 * RedisService en memoria: counters del RateLimiter (incr/expire/hset/get/
 * ttl/del vía getClient) y valores de sesión (set/get/ping). Sólo para
 * Tests\Unit\AuthControllerLoginTest.
 */
final class LoginInMemoryRedisService extends RedisService
{
    /** @var array<string, int> */
    public array $counters = [];

    /** @var array<string, mixed> */
    public array $values = [];

    public function __construct()
    {
    }

    public function getClient(): Client
    {
        return new LoginInMemoryRedisClient($this);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function ping(): bool
    {
        return true;
    }
}

/** Cliente en memoria con los comandos que usa RateLimiter::hit/clear. */
final class LoginInMemoryRedisClient extends Client
{
    public function __construct(private readonly LoginInMemoryRedisService $backend)
    {
    }

    public function incr(string $key): int
    {
        $this->backend->counters[$key] = ($this->backend->counters[$key] ?? 0) + 1;
        return $this->backend->counters[$key];
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function hset(string $key, string $field, int|string $value): int
    {
        $this->backend->values[$key . ':' . $field] = $value;
        return 1;
    }

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->backend->counters)) {
            return (string) $this->backend->counters[$key];
        }

        $value = $this->backend->values[$key] ?? null;
        return $value === null ? null : (string) $value;
    }

    public function ttl(string $key): int
    {
        return 300;
    }

    public function del(array $keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->backend->counters)) {
                unset($this->backend->counters[$key]);
                $removed++;
            }
            if (array_key_exists($key, $this->backend->values)) {
                unset($this->backend->values[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}
