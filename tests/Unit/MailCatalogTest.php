<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\MailCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MailCatalog: composicion de los 5 correos transaccionales en los 4
 * idiomas, render de placeholders, escape HTML y fallback a espanol.
 *
 * Los casos de idioma resuelto desde users.preferencias viven en
 * MailCatalogPreferenciasLiveTest (requieren BD); aqui solo se prueba la
 * composicion pura y el fallback sin contenedor.
 */
final class MailCatalogTest extends TestCase
{
    private MailCatalog $catalog;

    protected function setUp(): void
    {
        Container::setInstance(new Container());
        $this->catalog = new MailCatalog();
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    public function testEspanolEsElIdiomaPorDefectoSinContenedor(): void
    {
        // Sin binding 'database' en el contenedor: fallback a 'es'.
        $mail = $this->catalog->compose('reset', 'ana@test.local', 'Ana', [
            'ttl' => '15',
            'url' => 'https://xp/#/reset-password?token=abc',
        ]);

        $this->assertSame('Restablecer tu contraseña', $mail['asunto']);
        $this->assertStringContainsString('Hola Ana:', $mail['html']);
        $this->assertStringContainsString('válido durante 15 minutos', $mail['html']);
        $this->assertStringContainsString('https://xp/#/reset-password?token=abc', $mail['html']);
        $this->assertStringContainsString('Restablecer mi contraseña', $mail['html']);
    }

    public function testIdiomasSoportados(): void
    {
        $this->assertSame(['es', 'en', 'pt', 'fr'], MailCatalog::IDIOMAS);
    }

    /**
     * @return array<string, array{string, string}> asunto esperado por kind e idioma
     */
    public static function asuntosProvider(): array
    {
        return [
            'reset es'  => ['reset', 'es', 'Restablecer tu contraseña'],
            'reset en'  => ['reset', 'en', 'Reset your password'],
            'reset pt'  => ['reset', 'pt', 'Redefinir sua senha'],
            'reset fr'  => ['reset', 'fr', 'Réinitialiser votre mot de passe'],
            'admin es'  => ['admin', 'es', 'Restablecer tu contraseña'],
            'admin en'  => ['admin', 'en', 'Reset your password'],
            'admin pt'  => ['admin', 'pt', 'Redefinir sua senha'],
            'admin fr'  => ['admin', 'fr', 'Réinitialiser votre mot de passe'],
            'welcome es' => ['welcome', 'es', 'Bienvenido a la plataforma'],
            'welcome en' => ['welcome', 'en', 'Welcome to the platform'],
            'welcome pt' => ['welcome', 'pt', 'Bem-vindo à plataforma'],
            'welcome fr' => ['welcome', 'fr', 'Bienvenue sur la plateforme'],
            'changed es' => ['changed', 'es', 'Tu contraseña fue restablecida'],
            'changed en' => ['changed', 'en', 'Your password was reset'],
            'changed pt' => ['changed', 'pt', 'Sua senha foi redefinida'],
            'changed fr' => ['changed', 'fr', 'Votre mot de passe a été réinitialisé'],
            'test es'    => ['test', 'es', 'Prueba SMTP - Tienda X'],
            'test en'    => ['test', 'en', 'SMTP test - Tienda X'],
            'test pt'    => ['test', 'pt', 'Teste SMTP - Tienda X'],
            'test fr'    => ['test', 'fr', 'Test SMTP - Tienda X'],
        ];
    }

    #[DataProvider('asuntosProvider')]
    public function testAsuntoPorKindEIdioma(string $kind, string $idioma, string $asuntoEsperado): void
    {
        $mail = $this->catalog->composeEn($idioma, $kind, 'Tienda X', [
            'ttl' => '60',
            'url' => 'https://xp/#/reset-password?token=xyz',
        ]);

        $this->assertSame($asuntoEsperado, $mail['asunto']);
        $this->assertNotSame('', $mail['html']);
    }

    public function testIdiomaDesconocidoCaeAEspanol(): void
    {
        $mail = $this->catalog->composeEn('de', 'welcome', 'Ana', ['ttl' => '24', 'url' => 'u']);

        $this->assertSame('Bienvenido a la plataforma', $mail['asunto']);
        $this->assertStringContainsString('válido durante 24 horas', $mail['html']);
    }

    public function testCuerpoTieneSaludoIntroCtaYCierre(): void
    {
        $mail = $this->catalog->composeEn('es', 'reset', 'Ana', ['ttl' => '15', 'url' => 'https://u']);

        $this->assertStringContainsString('<p>Hola Ana:</p>', $mail['html']);
        $this->assertStringContainsString('<a href="https://u">Restablecer mi contraseña</a>', $mail['html']);
        $this->assertStringContainsString('Si no fuiste tú, ignora este correo', $mail['html']);
    }

    public function testChangedNoLlevaEnlace(): void
    {
        $mail = $this->catalog->composeEn('en', 'changed', 'Ana', ['url' => 'https://u']);

        $this->assertStringNotContainsString('<a ', $mail['html']);
        $this->assertStringContainsString('Hello Ana:', $mail['html']);
        $this->assertStringContainsString('contact an administrator immediately', $mail['html']);
    }

    public function testNombreVacioUsaElDestinoEnElSaludo(): void
    {
        $mail = $this->catalog->composeEn('es', 'reset', 'sin-nombre@test.local', ['ttl' => '15', 'url' => 'u']);

        $this->assertStringContainsString('Hola sin-nombre@test.local:', $mail['html']);
    }

    public function testNombreConHtmlSeEscapa(): void
    {
        $mail = $this->catalog->composeEn('es', 'reset', '<b>ana</b>', ['ttl' => '15', 'url' => 'u']);

        $this->assertStringContainsString('&lt;b&gt;ana&lt;/b&gt;', $mail['html']);
        $this->assertStringNotContainsString('<b>ana</b>', $mail['html']);
    }

    public function testTestKindSustituyeElPlaceholderTenant(): void
    {
        $mail = $this->catalog->composeEn('es', 'test', 'Tienda X');

        $this->assertStringContainsString('Prueba SMTP - Tienda X', $mail['asunto']);
        $this->assertStringContainsString('configuración de Tienda X', $mail['html']);
        $this->assertStringNotContainsString('{tenant}', $mail['html']);
    }

    public function testComposeUsaElIdiomaDelDestinoCuandoHayPreferencias(): void
    {
        // Contenedor con base sqlite en memoria (sin tabla users):
        // idiomaDelDestino captura la excepcion y devuelve 'es'.
        $db = $this->createMock(DatabaseService::class);
        $db->method('getConnection')->willThrowException(new \RuntimeException('sin tabla'));
        Container::getInstance()->singleton('database', fn() => $db);

        $mail = $this->catalog->compose('welcome', 'ana@test.local', 'Ana', ['ttl' => '24', 'url' => 'u']);

        $this->assertSame('Bienvenido a la plataforma', $mail['asunto']);
    }
}
