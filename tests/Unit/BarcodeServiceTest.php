<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\BarcodeService;
use PHPUnit\Framework\TestCase;

/**
 * Generador de código de barras (1.5.1): prefijo fijo SU + aleatoria.
 *
 * Formato: SU-XXXXXX-XXXXXXXX (6 + 8 chars del alfabeto sin ambiguos).
 * El código NO deriva de tenant, producto ni fecha.
 */
final class BarcodeServiceTest extends TestCase
{
    /** ^SU-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{6}-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{8}$ */
    private const REGEX = '/^SU-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{6}-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{8}$/';

    private BarcodeService $service;

    protected function setUp(): void
    {
        $this->service = new BarcodeService();
    }

    public function testPrefijoEsFijoSU(): void
    {
        $this->assertSame('SU', BarcodeService::PREFIX);

        for ($i = 0; $i < 25; $i++) {
            $this->assertStringStartsWith('SU-', $this->service->generate());
        }
    }

    public function testElCodigoCumpleElRegexDeFormato(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $barcode = $this->service->generate();
            $this->assertMatchesRegularExpression(self::REGEX, $barcode);
            $this->assertTrue($this->service->matches($barcode));
        }
    }

    public function testFormatoEsPrefijoMas6Mas8(): void
    {
        $parts = explode('-', $this->service->generate());

        $this->assertCount(3, $parts);
        $this->assertSame('SU', $parts[0]);
        $this->assertSame(6, strlen($parts[1]));
        $this->assertSame(8, strlen($parts[2]));
    }

    public function testNoUsaCaracteresAmbiguosEnLaParteAleatoria(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $parts = explode('-', $this->service->generate());
            $random = $parts[1] . $parts[2];

            foreach (['0', '1', 'O', 'I', 'U'] as $ambiguous) {
                $this->assertStringNotContainsString($ambiguous, $random);
            }
            $this->assertMatchesRegularExpression('/^[A-Z2-9]+$/', $random);
        }
    }

    public function testLosCodigosSonUnicosEntreSi(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $code = $this->service->generate();
            $this->assertArrayNotHasKey($code, $seen, "Barcode repetido: {$code}");
            $seen[$code] = true;
        }

        $this->assertCount(500, $seen);
    }

    public function testMatchesAceptaSoloElFormatoValido(): void
    {
        $this->assertTrue($this->service->matches($this->service->generate()));

        // Prefijo antiguo (1.5.0) ya no es válido
        $this->assertFalse($this->service->matches('CB22DB-G4K5WM-65K987LV'));
        // Longitudes incorrectas
        $this->assertFalse($this->service->matches('SU-G4K5W-65K987LV'));
        $this->assertFalse($this->service->matches('SU-G4K5WM1-65K987LV'));
        // Caracteres ambiguos / fuera del charset
        $this->assertFalse($this->service->matches('SU-G4K5WI-65K987LV'));
        $this->assertFalse($this->service->matches('SU-G4K5W1-65K987LX'));
        // Minúsculas no válidas
        $this->assertFalse($this->service->matches('su-g4k5wm-65k987lv'));
        // Basura
        $this->assertFalse($this->service->matches(''));
        $this->assertFalse($this->service->matches('SU'));
    }
}
