<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\RateLimiter;
use App\Shared\Container\Container;
use App\Shared\Services\RedisService;
use PHPUnit\Framework\TestCase;

/**
 * RedisService stub que no abre conexión (RateLimiter solo requiere la instancia
 * para su constructor; los métodos probados no tocan Redis).
 */
class FakeRedisService extends RedisService
{
    public function __construct()
    {
    }
}

final class RateLimiterTest extends TestCase
{
    private array $prevServer = [];

    protected function setUp(): void
    {
        $this->prevServer = $_SERVER;

        $container = new Container();
        Container::setInstance($container);
        $container->instance('redis', new FakeRedisService());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->prevServer;
        Container::resetInstance();
    }

    public function testNormalizeEndpointReplacesUuidWithPlaceholder(): void
    {
        $_SERVER['REQUEST_URI'] = '/xp/public/api/users/9a805fe5-4c25-42e1-bf88-56264e0a010d';
        $this->assertSame('/xp/public/api/users/:id', RateLimiter::normalizeEndpoint());
    }

    public function testNormalizeEndpointReplacesNumericIds(): void
    {
        $_SERVER['REQUEST_URI'] = '/xp/public/api/inventory/products/42';
        $this->assertSame('/xp/public/api/inventory/products/:num', RateLimiter::normalizeEndpoint());
    }

    public function testEndpointForConfigStripsFrontControllerPrefix(): void
    {
        $_SERVER['REQUEST_URI'] = '/xp/public/api/auth/login';
        $this->assertSame('/api/auth/login', RateLimiter::endpointForConfig());
    }

    public function testEndpointForConfigStripsAnyPrefixUntilApi(): void
    {
        $_SERVER['REQUEST_URI'] = '/any/prefix/api/tenant/stats';
        $this->assertSame('/api/tenant/stats', RateLimiter::endpointForConfig());
    }

    public function testEndpointForConfigNormalizesIds(): void
    {
        $_SERVER['REQUEST_URI'] = '/xp/public/api/users/b65e4fad-980b-4c66-8c77-b189b3e9e2ef';
        $this->assertSame('/api/users/:id', RateLimiter::endpointForConfig());
    }

    public function testGetLimitsForActionUsesBuiltinActions(): void
    {
        $limiter = new RateLimiter(new FakeRedisService());

        $this->assertSame(['limit' => 5, 'window' => 300], $limiter->getLimitsForAction('login'));
        $this->assertSame(['limit' => 100, 'window' => 60], $limiter->getLimitsForAction('api'));
        $this->assertSame(['limit' => 10, 'window' => 60], $limiter->getLimitsForAction('critical'));
    }

    public function testGetLimitsForActionFallsBackToEnvDefaults(): void
    {
        $_ENV['RATE_LIMIT_REQUESTS'] = '42';
        $_ENV['RATE_LIMIT_WINDOW'] = '120';

        $limiter = new RateLimiter(new FakeRedisService());
        $this->assertSame(['limit' => 42, 'window' => 120], $limiter->getLimitsForAction('desconocida'));

        unset($_ENV['RATE_LIMIT_REQUESTS'], $_ENV['RATE_LIMIT_WINDOW']);
    }

    public function testHttpKeyContainsActionAndNormalizedEndpoint(): void
    {
        $_SERVER['REQUEST_URI'] = '/xp/public/api/auth/login';
        $limiter = new RateLimiter(new FakeRedisService());

        $key = $limiter->httpKey('login', 'ip:127.0.0.1');
        $this->assertSame('rl:login:ip:127.0.0.1:/xp/public/api/auth/login', $key);
    }

    public function testKeyHelpersFormats(): void
    {
        $limiter = new RateLimiter(new FakeRedisService());

        $this->assertSame('user:abc:api', $limiter->keyForUser('abc'));
        $this->assertSame('ip:1.2.3.4:login', $limiter->keyForIp('1.2.3.4', 'login'));
        $this->assertSame('endpoint:_api_users:abc', $limiter->keyForEndpoint('/api/users', 'abc'));
    }

    public function testGetHeadersAlwaysIncludesLimitAndRemaining(): void
    {
        $limiter = new RateLimiter(new FakeRedisService());

        $headers = $limiter->getHeaders(100, 97);
        $this->assertSame('100', $headers['X-RateLimit-Limit']);
        $this->assertSame('97', $headers['X-RateLimit-Remaining']);
        $this->assertArrayNotHasKey('Retry-After', $headers);

        $headersWithRetry = $limiter->getHeaders(100, 0, 30);
        $this->assertSame('30', $headersWithRetry['Retry-After']);
    }
}
