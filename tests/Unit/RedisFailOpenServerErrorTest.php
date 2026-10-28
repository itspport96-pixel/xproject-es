<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\RateLimiter;
use App\Shared\Container\Container;
use App\Shared\Services\RedisService;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Response\ServerException;

/**
 * RedisService cuyo cliente lanza ServerException en vez de conectar: es lo
 * que Predis devuelve cuando Redis esta vivo pero RECHAZA la operacion
 * (p. ej. MISCONF, que bloquea las escrituras tras un bgsave fallido).
 *
 * Ese error no es ConnectionException y tampoco es la RedisException propia
 * de connect(): con los catches antiguos escapaba y salia un 500 en la app
 * (les paso el 2026-10-28 al login y a /api/locales). El fail-open debe
 * degradar igual que con el servidor caido.
 */
class RedisServiceConErrorDeServidor extends RedisService
{
    public function __construct()
    {
    }

    public function getClient(): Client
    {
        return new class extends Client {
            public function __construct()
            {
            }

            public function __call($commandID, $arguments)
            {
                throw new ServerException(
                    'MISCONF Redis is configured to save RDB snapshots, '
                    . 'but it is currently not able to persist on disk.'
                );
            }
        };
    }
}

final class RedisFailOpenServerErrorTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    public function testRedisServiceDegradaAnteUnErrorDelServidor(): void
    {
        $svc = new RedisServiceConErrorDeServidor();

        $this->assertNull($svc->get('clave'));
        $this->assertFalse($svc->set('clave', ['a' => 1]));
        $this->assertFalse($svc->set('clave', 'valor', 60));
        $this->assertFalse($svc->delete('clave'));
        $this->assertFalse($svc->exists('clave'));
        $this->assertFalse($svc->expire('clave', 60));
        $this->assertFalse($svc->flush());
        $this->assertFalse($svc->ping());
    }

    public function testRateLimiterNoBloqueaAnteUnErrorDelServidor(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $container->instance('redis', new RedisServiceConErrorDeServidor());

        $rateLimiter = new RateLimiter(new RedisServiceConErrorDeServidor());

        // hit() devuelve 0 => el caller no cuenta el intento y no hay 429.
        $this->assertSame(0, $rateLimiter->hit('login|usuario', 60));
        $this->assertSame(0, $rateLimiter->getAttempts('login|usuario'));
        $this->assertSame(0, $rateLimiter->availableIn('login|usuario'));
        $this->assertFalse($rateLimiter->clear('login|usuario'));
        $this->assertFalse($rateLimiter->tooManyAttempts('login|usuario', 5, 60));
    }

    public function testServidorCaidoSigueSiendoFailOpen(): void
    {
        // Nadie escuchando en el puerto 1: conexion rechazada en localhost,
        // sin dependencias de la maquina (portable a Linux).
        $svc = new RedisService(['host' => '127.0.0.1', 'port' => 1]);

        $this->assertNull($svc->get('clave'));
        $this->assertFalse($svc->set('clave', 'valor'));
        $this->assertFalse($svc->ping());
    }
}
