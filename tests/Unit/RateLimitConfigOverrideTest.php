<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Repository\RateLimitRepository;
use App\Core\Security\Service\RateLimiter;
use App\Shared\Services\RedisService;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Override de límites desde rate_limit_config (spec §6.3).
 *
 * La tabla estaba vacía en la BD viva y sin ni un test: se cubre aquí la
 * única puerta de entrada que usa RateLimitMiddleware,
 * RateLimiter::getEndpointConfig(), con el repositorio REAL sobre SQLite
 * (rate_limit_config espeja las columnas de la BD viva).
 *
 * El bucle completo (fila -> 429 en HTTP) lo cubre e2e-v1133.ps1; el
 * middleware no es testeable en unidad porque hace skip en CLI
 * (PHP_SAPI === 'cli').
 */
final class RateLimitConfigOverrideTest extends PlatformControlSqliteTestCase
{
    private const ENDPOINT = '/api/tenant/stats';

    private RateLimiter $limiter;
    private mixed $prevUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/xp/public' . self::ENDPOINT;

        $this->limiter = new RateLimiter(
            $this->createStub(RedisService::class),
            new RateLimitRepository($this->db)
        );
    }

    protected function tearDown(): void
    {
        if ($this->prevUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->prevUri;
        }

        parent::tearDown();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedOverride(array $overrides = []): void
    {
        $row = array_merge([
            'id' => 'rlc-test-1',
            'tenant_id' => null,
            'endpoint' => self::ENDPOINT,
            'max_requests' => 100,
            'tiempo_ventana' => 60,
            'tipo_limit' => 'POR_USUARIO',
            'activo' => 1,
        ], $overrides);

        $this->pdo->prepare(
            'INSERT INTO rate_limit_config
                (id, tenant_id, endpoint, max_requests, tiempo_ventana, tipo_limit, activo)
             VALUES (:id, :tenant_id, :endpoint, :max_requests, :tiempo_ventana, :tipo_limit, :activo)'
        )->execute($row);
    }

    // ====================================================================
    // TESTS
    // ====================================================================

    public function testSinFilaActivaDevuelveNullYLosLimitesDeCodigo(): void
    {
        $this->assertNull($this->limiter->getEndpointConfig(self::TENANT));
        $this->assertNull($this->limiter->getEndpointConfig(null));
    }

    public function testFilaActivaGlobalExponeLosLimitesDelEndpoint(): void
    {
        $this->seedOverride(['max_requests' => 7, 'tiempo_ventana' => 120]);

        $config = $this->limiter->getEndpointConfig(self::TENANT);

        $this->assertIsArray($config);
        $this->assertSame(self::ENDPOINT, $config['endpoint']);
        $this->assertSame(7, (int) $config['max_requests']);
        $this->assertSame(120, (int) $config['tiempo_ventana']);
        $this->assertSame('POR_USUARIO', $config['tipo_limit']);
    }

    public function testFilaInactivaSeIgnora(): void
    {
        $this->seedOverride(['max_requests' => 7, 'activo' => 0]);

        $this->assertNull($this->limiter->getEndpointConfig(self::TENANT));
    }

    public function testFilaDeOtroEndpointNoAplica(): void
    {
        $this->seedOverride(['endpoint' => '/api/users', 'max_requests' => 7]);

        $this->assertNull($this->limiter->getEndpointConfig(self::TENANT));
    }

    public function testPrefiereLaFilaDelTenantSobreLaGlobal(): void
    {
        $this->seedOverride(['id' => 'rlc-global', 'max_requests' => 100, 'tiempo_ventana' => 60]);
        $this->seedOverride([
            'id' => 'rlc-tenant',
            'tenant_id' => self::TENANT,
            'max_requests' => 3,
            'tiempo_ventana' => 300,
        ]);

        $config = $this->limiter->getEndpointConfig(self::TENANT);

        $this->assertIsArray($config);
        $this->assertSame(3, (int) $config['max_requests']);
        $this->assertSame(300, (int) $config['tiempo_ventana']);
    }

    public function testSinTenantSigueUsandoLaFilaGlobal(): void
    {
        $this->seedOverride(['id' => 'rlc-global', 'max_requests' => 9, 'tiempo_ventana' => 45]);
        $this->seedOverride([
            'id' => 'rlc-tenant',
            'tenant_id' => self::TENANT,
            'max_requests' => 3,
            'tiempo_ventana' => 300,
        ]);

        $config = $this->limiter->getEndpointConfig(null);

        $this->assertIsArray($config);
        $this->assertSame(9, (int) $config['max_requests']);
    }
}
