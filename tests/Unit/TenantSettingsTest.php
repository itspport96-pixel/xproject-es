<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Service\TenantService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Configuración del tenant (v1.14.0): contacto en toPublicArray, marca
 * pública (toBrandingArray), logo y validación de `configuracion`.
 */
final class TenantSettingsTest extends TestCase
{
    private function tenant(?array $config = null): Tenant
    {
        return Tenant::fromArray([
            'id' => 'cb22db62-5379-4b83-8c51-a3c695c6de51',
            'nombre_publico' => 'Acme Corporation',
            'nombre_interno' => 'acme-corp',
            'estado' => 'ACTIVO',
            'plan' => 'FREE',
            'created_at' => '2026-09-16 21:56:02',
            'email_contacto' => 'contact@acme.local',
            'telefono' => '+52 55 1234 5678',
            'zona_horaria' => 'America/Mexico_City',
            'idioma_default' => 'es',
            'configuracion' => $config,
        ]);
    }

    private function validate(array $config): void
    {
        $m = new ReflectionMethod(TenantService::class, 'validateConfiguracion');
        $m->invoke(null, $config);
    }

    private function validLogo(string $url): bool
    {
        $m = new ReflectionMethod(TenantService::class, 'isValidLogoUrl');
        return $m->invoke(null, $url);
    }

    public function testMergeParcialConservaOtrasSecciones(): void
    {
        $m = new ReflectionMethod(TenantService::class, 'mergeConfiguracion');
        $actual = [
            'perfil' => ['direccion' => 'Calle 1'],
            'regional' => ['moneda' => 'MXN', 'formato_fecha' => 'DD/MM/YYYY'],
            'apariencia' => ['color_primario' => '#0066D6'],
        ];

        $r = $m->invoke(null, $actual, ['apariencia' => ['color_primario' => '#AF52DE']]);
        $this->assertSame('Calle 1', $r['perfil']['direccion']);
        $this->assertSame('MXN', $r['regional']['moneda']);
        $this->assertSame('#AF52DE', $r['apariencia']['color_primario']);

        $r = $m->invoke(null, $actual, ['apariencia' => ['color_primario' => null], 'regional' => ['moneda' => null]]);
        $this->assertArrayNotHasKey('apariencia', $r);
        $this->assertSame(['formato_fecha' => 'DD/MM/YYYY'], $r['regional']);
    }

    public function testMonedaFueraDeCatalogoLanza(): void
    {
        $this->expectException(TenantException::class);
        $this->validate(['regional' => ['moneda' => 'XXX']]);
    }

    public function testPublicArrayIncluyeContacto(): void
    {
        $data = $this->tenant()->toPublicArray();
        $this->assertSame('contact@acme.local', $data['email_contacto']);
        $this->assertSame('+52 55 1234 5678', $data['telefono']);
    }

    public function testBrandingArraySoloMarcaYRegional(): void
    {
        $data = $this->tenant([
            'apariencia' => ['color_primario' => '#248A3D'],
            'regional' => ['moneda' => 'MXN', 'formato_fecha' => 'DD/MM/YYYY'],
            'perfil' => ['direccion' => 'Av. Reforma 1'],
        ])->toBrandingArray();

        $this->assertSame('Acme Corporation', $data['nombre_publico']);
        $this->assertSame('#248A3D', $data['apariencia']['color_primario']);
        $this->assertSame('MXN', $data['regional']['moneda']);
        $this->assertArrayNotHasKey('email_contacto', $data);
        $this->assertArrayNotHasKey('plan', $data);
        $this->assertArrayNotHasKey('perfil', $data);
    }

    public function testBrandingSinConfiguracion(): void
    {
        $data = $this->tenant()->toBrandingArray();
        $this->assertNull($data['apariencia']);
        $this->assertNull($data['regional']);
    }

    public function testLogoNullOVacioLoQuita(): void
    {
        $tenant = $this->tenant();
        $tenant->actualizarLogo('uploads/tenants/x/logo-1.png');
        $this->assertSame('uploads/tenants/x/logo-1.png', $tenant->logoUrl());
        $tenant->actualizarLogo('');
        $this->assertNull($tenant->logoUrl());
        $tenant->actualizarLogo(null);
        $this->assertNull($tenant->logoUrl());
    }

    public function testLogoUrlValidas(): void
    {
        $this->assertTrue($this->validLogo('https://cdn.example.com/logo.png'));
        $this->assertTrue($this->validLogo('uploads/tenants/cb22db62-5379-4b83-8c51-a3c695c6de51/logo-1792105724.png'));
        $this->assertFalse($this->validLogo('javascript:alert(1)'));
        $this->assertFalse($this->validLogo('uploads/tenants/../../.env'));
        $this->assertFalse($this->validLogo('https://x.com/"onerror="a'));
    }

    public function testConfiguracionValida(): void
    {
        $this->validate([
            'perfil' => ['direccion' => 'Calle 1'],
            'apariencia' => ['color_primario' => '#0066D6'],
            'regional' => ['moneda' => 'EUR', 'formato_fecha' => 'YYYY-MM-DD'],
            'otra' => ['libre' => true],
        ]);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: array}> */
    public static function configuracionesInvalidas(): array
    {
        return [
            'color sin #' => [['apariencia' => ['color_primario' => '0066D6']]],
            'color con css' => [['apariencia' => ['color_primario' => 'red;background:url(x)']]],
            'moneda minusculas' => [['regional' => ['moneda' => 'mxn']]],
            'formato desconocido' => [['regional' => ['formato_fecha' => 'D/M/Y']]],
            'direccion larga' => [['perfil' => ['direccion' => str_repeat('a', 501)]]],
            'seccion no objeto' => [['regional' => 'MXN']],
        ];
    }

    /** @dataProvider configuracionesInvalidas */
    #[\PHPUnit\Framework\Attributes\DataProvider('configuracionesInvalidas')]
    public function testConfiguracionInvalidaLanza(array $config): void
    {
        $this->expectException(TenantException::class);
        $this->validate($config);
    }
}
