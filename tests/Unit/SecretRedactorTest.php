<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\SecretRedactor;
use PHPUnit\Framework\TestCase;

/**
 * SecretRedactor: enmascarado de secretos (spec §7.1: la bitácora nunca
 * guarda claves en claro).
 */
final class SecretRedactorTest extends TestCase
{
    public function testEnmascaraClavesSensiblesPorSubcadena(): void
    {
        $sensibles = ['password', 'passwd', 'pwd', 'secret', 'token', 'api_key',
            'apikey', 'private_key', 'authorization', 'credential', 'credencial',
            'clave', 'bearer', 'cookie', 'csrf', 'session_key'];

        foreach ($sensibles as $key) {
            $this->assertTrue(SecretRedactor::isSensitiveKey($key), $key);
            $this->assertTrue(SecretRedactor::isSensitiveKey(strtoupper($key)), $key . ' (mayúsculas)');
            $this->assertSame(SecretRedactor::MASK, SecretRedactor::redact('valor', $key), $key);
        }

        // Subcadena dentro de claves reales.
        $this->assertTrue(SecretRedactor::isSensitiveKey('client_secret'));
        $this->assertTrue(SecretRedactor::isSensitiveKey('refresh_token'));
        $this->assertTrue(SecretRedactor::isSensitiveKey('app_apikey'));
        $this->assertTrue(SecretRedactor::isSensitiveKey('csrf_token'));
    }

    public function testConservaClavesInocentes(): void
    {
        foreach (['email', 'nombre', 'sku', 'resultado', 'nota'] as $key) {
            $this->assertFalse(SecretRedactor::isSensitiveKey($key), $key);
            $this->assertSame('valor', SecretRedactor::redact('valor', $key), $key);
        }
    }

    public function testEnmascaraValoresConFormaDeSecreto(): void
    {
        $this->assertTrue(SecretRedactor::isSensitiveValue(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.firma'
        ));
        $this->assertTrue(SecretRedactor::isSensitiveValue('Bearer abc.def'));
        $this->assertTrue(SecretRedactor::isSensitiveValue('Basic dXNlcjpwYXNz'));

        $this->assertFalse(SecretRedactor::isSensitiveValue('demo@acme.local'));
        $this->assertFalse(SecretRedactor::isSensitiveValue('SKU-ALFA'));
    }

    public function testRecorreArraysAnidados(): void
    {
        $redacted = SecretRedactor::redact([
            'email' => 'demo@acme.local',
            'password' => 'Admin123!',
            'nested' => [
                'token' => 'abc',
                'ok' => 1,
                'deep' => ['api_key' => 'sk-live', 'sku' => 'SKU-1'],
            ],
            'lista' => ['Bearer xyz'],
        ]);

        $this->assertSame('demo@acme.local', $redacted['email']);
        $this->assertSame(SecretRedactor::MASK, $redacted['password']);
        $this->assertSame(SecretRedactor::MASK, $redacted['nested']['token']);
        $this->assertSame(1, $redacted['nested']['ok']);
        $this->assertSame(SecretRedactor::MASK, $redacted['nested']['deep']['api_key']);
        $this->assertSame('SKU-1', $redacted['nested']['deep']['sku']);
        $this->assertSame([SecretRedactor::MASK], $redacted['lista']);
    }
}
