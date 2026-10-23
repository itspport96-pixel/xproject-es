<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Users\Exception\UserPreferencesException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * UserService::getPreferences/updatePreferences (v1.10.3) sobre SQLite:
 *  - users.preferencias es un JSON longtext; GET lo decodifica, PATCH hace merge
 *  - valida idioma en es|en|pt|fr y tema en dark|light|system (valores existentes)
 *  - claves desconocidas del body se ignoran; las guardadas se conservan
 *  - 400 (validacion) y 404 (usuario/tenant inexistente o borrado)
 */
final class UserPreferencesServiceTest extends TestCase
{
    private const TENANT = 'tenant-pref-1';
    private const OTHER_TENANT = 'tenant-pref-2';
    private const USER = 'user-pref-1';

    private PDO $pdo;
    private DatabaseService $db;
    private UserService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ext-pdo_sqlite no disponible');
        }

        Container::setInstance(new Container());

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                email TEXT,
                nombre TEXT,
                apellido TEXT,
                password TEXT,
                estado TEXT DEFAULT \'ACTIVO\' NOT NULL,
                telefono TEXT,
                preferencias TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )'
        );

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->service = new UserService(
            new UserRepository($this->db),
            $this->createStub(PasswordHasher::class),
            $this->db
        );

        $this->seedUser(self::USER, self::TENANT);
        $this->seedUser('user-pref-con-prefs', self::TENANT, '{"idioma":"pt","otra_clave":42}');
        $this->seedUser('user-pref-otro-tenant', self::OTHER_TENANT);
        $this->seedUser('user-pref-borrado', self::TENANT, null, true);
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    private function seedUser(
        string $id,
        string $tenantId,
        ?string $preferencias = null,
        bool $borrado = false
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, preferencias, created_at, updated_at, deleted_at)
             VALUES (:id, :tenant, :email, :nombre, :apellido, :password, \'ACTIVO\', :preferencias, :now, :now, :deleted)'
        );
        $stmt->execute([
            'id' => $id,
            'tenant' => $tenantId,
            'email' => "{$id}@example.test",
            'nombre' => 'Usuario',
            'apellido' => 'Pref',
            'password' => password_hash('Abcdef1!', PASSWORD_BCRYPT),
            'preferencias' => $preferencias,
            'now' => date('Y-m-d H:i:s'),
            'deleted' => $borrado ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowPreferencias(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT preferencias FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ====================================================================
    // GET
    // ====================================================================

    public function testGetSinPreferenciasGuardadasDevuelveVacio(): void
    {
        $this->assertSame([], $this->service->getPreferences(self::USER, self::TENANT));
    }

    public function testGetDecodificaElJsonGuardado(): void
    {
        $prefs = $this->service->getPreferences('user-pref-con-prefs', self::TENANT);

        $this->assertSame('pt', $prefs['idioma']);
        $this->assertSame(42, $prefs['otra_clave'], 'Las claves guardadas se devuelven tal cual');
    }

    public function testGetDeUsuarioInexistenteBorradoODeOtroTenantDa404(): void
    {
        foreach ([
            'inexistente' => ['no-existe', self::TENANT],
            'borrado' => ['user-pref-borrado', self::TENANT],
            'otro tenant' => [self::USER, self::OTHER_TENANT],
        ] as $caso => [$userId, $tenantId]) {
            try {
                $this->service->getPreferences($userId, $tenantId);
                $this->fail("Debio lanzar 404 ({$caso})");
            } catch (UserPreferencesException $e) {
                $this->assertSame(404, $e->getStatusCode(), "Caso: {$caso}");
                $this->assertSame(UserPreferencesException::CATEGORY_NOT_FOUND, $e->getCategory());
            }
        }
    }

    // ====================================================================
    // PATCH
    // ====================================================================

    public function testUpdateIdiomaYTemaValidosSePersisten(): void
    {
        $resultado = $this->service->updatePreferences(self::USER, self::TENANT, [
            'idioma' => 'en',
            'tema' => 'dark',
        ]);

        $this->assertSame(['idioma' => 'en', 'tema' => 'dark'], $resultado);

        $guardado = json_decode((string) $this->rowPreferencias(self::USER)['preferencias'], true);
        $this->assertSame(['idioma' => 'en', 'tema' => 'dark'], $guardado);
    }

    public function testUpdateParcialConservaLasClavesPrevias(): void
    {
        $this->service->updatePreferences(self::USER, self::TENANT, ['tema' => 'light']);
        $resultado = $this->service->updatePreferences(self::USER, self::TENANT, ['idioma' => 'fr']);

        $this->assertSame(['tema' => 'light', 'idioma' => 'fr'], $resultado);

        $guardado = json_decode((string) $this->rowPreferencias(self::USER)['preferencias'], true);
        $this->assertSame('light', $guardado['tema'], 'El merge no borra claves no enviadas');
        $this->assertSame('fr', $guardado['idioma']);
    }

    public function testUpdatePreservaClavesDesconocidasYaGuardadas(): void
    {
        $resultado = $this->service->updatePreferences('user-pref-con-prefs', self::TENANT, [
            'idioma' => 'es',
        ]);

        $this->assertSame('es', $resultado['idioma']);
        $this->assertSame(42, $resultado['otra_clave'], 'otra_clave existente se conserva');

        $guardado = json_decode((string) $this->rowPreferencias('user-pref-con-prefs')['preferencias'], true);
        $this->assertSame(42, $guardado['otra_clave']);
    }

    public function testClavesDesconocidasDelBodySeIgnoranSinEscribir(): void
    {
        $antes = $this->rowPreferencias(self::USER)['preferencias'];

        $resultado = $this->service->updatePreferences(self::USER, self::TENANT, [
            'color' => 'azul',
            'otro' => 1,
        ]);

        $this->assertSame([], $resultado, 'Sin claves conocidas no hay preferencias que devolver');
        $this->assertSame($antes, $this->rowPreferencias(self::USER)['preferencias'], 'No se escribe nada');
    }

    public function testUpdateConBodyVacioDevuelveLasActuales(): void
    {
        $this->service->updatePreferences('user-pref-con-prefs', self::TENANT, ['idioma' => 'en']);
        $resultado = $this->service->updatePreferences('user-pref-con-prefs', self::TENANT, []);

        $this->assertSame('en', $resultado['idioma']);
        $this->assertSame(42, $resultado['otra_clave']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('idiomasValidos')]
    public function testIdiomasAceptados(string $idioma): void
    {
        $resultado = $this->service->updatePreferences(self::USER, self::TENANT, ['idioma' => $idioma]);

        $this->assertSame($idioma, $resultado['idioma']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function idiomasValidos(): array
    {
        return [
            'es' => ['es'],
            'en' => ['en'],
            'pt' => ['pt'],
            'fr' => ['fr'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('temasValidos')]
    public function testTemasAceptados(string $tema): void
    {
        $resultado = $this->service->updatePreferences(self::USER, self::TENANT, ['tema' => $tema]);

        $this->assertSame($tema, $resultado['tema']);
    }

    /**
     * Valores existentes del codigo (VALID_THEMES del frontend).
     *
     * @return array<string, array{string}>
     */
    public static function temasValidos(): array
    {
        return [
            'dark' => ['dark'],
            'light' => ['light'],
            'system' => ['system'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valoresInvalidos')]
    public function testValoresFueraDeWhitelistDan400(string $clave, mixed $valor): void
    {
        try {
            $this->service->updatePreferences(self::USER, self::TENANT, [$clave => $valor]);
            $this->fail("Debio lanzar 400 ({$clave})");
        } catch (UserPreferencesException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame(UserPreferencesException::CATEGORY_VALIDATION, $e->getCategory());
            $this->assertSame($clave, $e->getField());
        }

        $this->assertNull($this->rowPreferencias(self::USER)['preferencias'], 'Nada se persiste en invalido');
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function valoresInvalidos(): array
    {
        return [
            'idioma no soportado' => ['idioma', 'de'],
            'idioma con region' => ['idioma', 'es-MX'],
            'idioma vacio' => ['idioma', ''],
            'idioma no string' => ['idioma', 5],
            'idioma objeto' => ['idioma', ['es']],
            'tema en espanol claro' => ['tema', 'claro'],
            'tema en espanol oscuro' => ['tema', 'oscuro'],
            'tema desconocido' => ['tema', 'blue'],
            'tema no string' => ['tema', true],
        ];
    }

    public function testUpdateDeUsuarioInexistenteODeOtroTenantDa404(): void
    {
        try {
            $this->service->updatePreferences('no-existe', self::TENANT, ['idioma' => 'en']);
            $this->fail('Debio lanzar 404');
        } catch (UserPreferencesException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $this->service->updatePreferences('user-pref-otro-tenant', self::TENANT, ['idioma' => 'en']);
            $this->fail('Debio lanzar 404 por aislamiento de tenant');
        } catch (UserPreferencesException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testUpdateNoTocaOtrasColumnasDelUsuario(): void
    {
        $antes = $this->pdo->query('SELECT * FROM users WHERE id = ' . $this->pdo->quote(self::USER))
            ->fetch(PDO::FETCH_ASSOC);

        $this->service->updatePreferences(self::USER, self::TENANT, ['idioma' => 'en']);

        $despues = $this->pdo->query('SELECT * FROM users WHERE id = ' . $this->pdo->quote(self::USER))
            ->fetch(PDO::FETCH_ASSOC);

        foreach (['id', 'tenant_id', 'email', 'nombre', 'apellido', 'password', 'estado', 'telefono', 'created_at', 'deleted_at'] as $columna) {
            $this->assertSame($antes[$columna], $despues[$columna], "La columna {$columna} no debe cambiar");
        }
    }
}
