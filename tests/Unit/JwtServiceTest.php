<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Service\JwtService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    private array $prevEnv = [];

    protected function setUp(): void
    {
        $this->prevEnv = $_ENV;
        $_ENV['JWT_SECRET'] = str_repeat('s', 64);
        $_ENV['JWT_ALGORITHM'] = 'HS256';
        $_ENV['JWT_EXPIRATION'] = '3600';
        $_ENV['JWT_REFRESH_EXPIRATION'] = '604800';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->prevEnv;
    }

    public function testRequiresSecretConfigured(): void
    {
        $_ENV['JWT_SECRET'] = '';

        $this->expectException(\RuntimeException::class);
        new JwtService();
    }

    public function testAccessTokenRoundtrip(): void
    {
        $jwt = new JwtService();
        // jti/fam los inyecta AuthController antes de firmar; deben sobrevivir el roundtrip
        $token = $jwt->generateToken([
            'user_id' => 'u-1',
            'tenant_id' => 't-1',
            'jti' => 'j-1',
            'fam' => 'f-1',
        ]);

        $claims = (array) $jwt->decodeToken($token);

        $this->assertSame('u-1', $claims['user_id']);
        $this->assertSame('t-1', $claims['tenant_id']);
        $this->assertSame('access', $claims['type']);
        $this->assertSame('j-1', $claims['jti']);
        $this->assertSame('f-1', $claims['fam']);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('iat', $claims);
    }

    public function testRefreshTokenCarriesRefreshType(): void
    {
        $jwt = new JwtService();
        $token = $jwt->generateRefreshToken(['user_id' => 'u-1']);

        $claims = (array) $jwt->decodeToken($token);

        $this->assertSame('refresh', $claims['type']);
        $this->assertGreaterThan(time(), (int) $claims['exp']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = new JwtService();
        $expired = JWT::encode(
            ['user_id' => 'u-1', 'type' => 'access', 'exp' => time() - 100, 'iat' => time() - 200],
            str_repeat('s', 64),
            'HS256'
        );

        $this->expectException(\Throwable::class);
        $jwt->decodeToken($expired);
    }

    public function testTokenSignedWithDifferentSecretIsRejected(): void
    {
        $jwt = new JwtService();
        $foreign = JWT::encode(
            ['user_id' => 'u-1', 'type' => 'access', 'exp' => time() + 3600, 'iat' => time()],
            str_repeat('other', 20),
            'HS256'
        );

        $this->expectException(\Throwable::class);
        $jwt->decodeToken($foreign);
    }

    public function testAlgNoneTokenIsRejected(): void
    {
        $jwt = new JwtService();

        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'user_id' => 'u-1',
            'type' => 'access',
            'iat' => time(),
            'exp' => time() + 3600,
        ])), '+/', '-_'), '=');
        $token = $header . '.' . $payload . '.';

        $this->expectException(\Throwable::class);
        $jwt->decodeToken($token);
    }
}
