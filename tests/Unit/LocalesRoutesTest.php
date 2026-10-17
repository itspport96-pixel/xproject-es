<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Shared\Exceptions\HttpException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\LocaleController;
use App\Shared\Router\Router;
use App\Shared\Services\LocaleDictionaryService;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint i18n (v1.10.1, spec Internacionalizacion):
 *  - GET /api/locales/{locale} sirve resources/locales/{locale}.json
 *  - whitelist es/en/pt/fr: 404 para cualquier otro (sin path traversal)
 *  - autenticado si, SIN permiso de modulo
 *  - ETag + version del diccionario + cache (capa Redis opcional en tests)
 */
final class LocalesRoutesTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $routes;

    /** @var string|null */
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }

        $router = new Router();
        $router->loadRoutes(BASE_PATH . '/routes/api.php');

        $prop = new \ReflectionProperty(Router::class, 'routes');
        $prop->setAccessible(true);
        $this->routes = $prop->getValue($router);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (strtoupper($route['method']) === $method && $route['path'] === $path) {
                return $route;
            }
        }

        return null;
    }

    public function testRutaDeDiccionariosRegistrada(): void
    {
        $route = $this->find('GET', '/api/locales/{locale}');

        $this->assertNotNull($route, 'Falta GET /api/locales/{locale}');
        $this->assertSame(LocaleController::class, $route['handler'][0]);
        $this->assertSame('show', $route['handler'][1]);

        $middleware = $route['middleware'];
        // v1.17.2: la ruta es publica para que login/forgot/reset puedan
        // descargar el diccionario pre-sesion y respetar el idioma elegido.
        $this->assertNotContains(AuthenticationMiddleware::class, $middleware, 'Ruta publica: sin autenticacion');
        $this->assertContains(RateLimitMiddleware::class . ':api', $middleware, 'Rate limit');
    }

    public function testNoRequierePermisoDeModuloNiTenant(): void
    {
        $route = $this->find('GET', '/api/locales/{locale}');

        $this->assertNotNull($route);
        $this->assertSame(
            [],
            array_values(array_filter(
                $route['middleware'],
                static fn(string $m): bool => str_starts_with($m, PermissionMiddleware::class)
            )),
            'Spec i18n: sin permiso de modulo'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localesValidos')]
    public function testWhitelistAceptaLosCuatroIdiomas(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, LocaleDictionaryService::normalizeLocale($entrada));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function localesValidos(): array
    {
        return [
            'es' => ['es', 'es'],
            'en' => ['en', 'en'],
            'pt' => ['pt', 'pt'],
            'fr' => ['fr', 'fr'],
            'forma con extension' => ['es.json', 'es'],
            'extension en mayusculas' => ['EN.JSON', 'en'],
            'con espacios' => [' es ', 'es'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localesInvalidos')]
    public function testWhitelistRechazaTodoLoDemas(string $entrada): void
    {
        $this->expectException(NotFoundException::class);
        LocaleDictionaryService::normalizeLocale($entrada);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localesInvalidos(): array
    {
        return [
            'idioma no soportado' => ['de'],
            'vacío' => [''],
            'path traversal relativo' => ['../../etc/passwd'],
            'path traversal absoluto' => ['/etc/passwd'],
            'solo puntos' => ['..'],
            'extension desconocida' => ['es.txt'],
            'extension con sufijo' => ['es.json.bak'],
            'separador de ruta' => ['es/..'],
            'url-encoded' => ['es%2F..%2Fpasswd'],
            'null byte' => ["es\x00.json"],
            'json sin idioma' => ['.json'],
        ];
    }

    public function testLoadSirveElDiccionarioConVersionYETag(): void
    {
        $dir = $this->tmpLocaleDir(['es' => '{"common":{"ok":"Aceptar"}}']);

        $service = new LocaleDictionaryService(null, $dir);
        $entry = $service->load('es');

        $this->assertSame('es', $entry['locale']);
        $this->assertSame(['common' => ['ok' => 'Aceptar']], $entry['dictionary']);
        $this->assertSame('{"common":{"ok":"Aceptar"}}', $entry['raw']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $entry['version']);
        $this->assertSame('"' . $entry['version'] . '"', $entry['etag']);

        // Version estable: mismo contenido => mismo ETag (cache revalidable).
        $again = $service->load('es');
        $this->assertSame($entry['version'], $again['version']);
        $this->assertSame($entry['etag'], $again['etag']);
    }

    public function testLoadConContenidoDistintoCambiaLaVersion(): void
    {
        $dir = $this->tmpLocaleDir(['es' => '{"a":"uno"}']);
        $service = new LocaleDictionaryService(null, $dir);
        $v1 = $service->load('es')['version'];

        file_put_contents($dir . '/es.json', '{"a":"dos"}');
        $v2 = $service->load('es')['version'];

        $this->assertNotSame($v1, $v2, 'La version debe reflejar el contenido');
    }

    public function testLoadDeIdiomaSoportadoSinFicheroDa404(): void
    {
        $dir = $this->tmpLocaleDir(['es' => '{}']);
        $service = new LocaleDictionaryService(null, $dir);

        $this->expectException(NotFoundException::class);
        $service->load('fr');
    }

    public function testLoadDeFicheroCorruptoDa500(): void
    {
        $dir = $this->tmpLocaleDir(['es' => '{no-es-json']);

        $service = new LocaleDictionaryService(null, $dir);

        $this->expectException(HttpException::class);
        $service->load('es');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ifNoneMatchValidos')]
    public function testEtagMatches(string $ifNoneMatch, bool $esperado): void
    {
        $service = new LocaleDictionaryService(null);
        $this->assertSame($esperado, $service->etagMatches($ifNoneMatch, '"abc123"'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function ifNoneMatchValidos(): array
    {
        return [
            'exacto' => ['"abc123"', true],
            'debil' => ['W/"abc123"', true],
            'lista' => ['"otro", "abc123"', true],
            'comodin' => ['*', true],
            'sin comillas no coincide' => ['abc123', false],
            'distinto' => ['"otro"', false],
            'vacio' => ['', false],
        ];
    }

    /**
     * Crea un directorio temporal con los diccionarios indicados.
     *
     * @param array<string, string> $files
     */
    private function tmpLocaleDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/xp-i18n-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDir = $dir;

        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name . '.json', $content);
        }

        return $dir;
    }
}
