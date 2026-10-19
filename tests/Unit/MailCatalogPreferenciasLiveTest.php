<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\MailCatalog;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * MailCatalog contra la BD de E2E: el idioma del destinatario se resuelve
 * desde users.preferencias (JSON clave `idioma`); sin preferencias, con
 * idioma fuera de whitelist, sin la clave o sin usuario se usa 'es' (la BD
 * solo admite JSON bien formado por su CHECK json_valid).
 *
 * Solo lectura sobre users (INSERT/UPDATE/DELETE del usuario propio del
 * test, borrado en tearDown); nada toca la BD de trabajo xproject.
 */
final class MailCatalogPreferenciasLiveTest extends TestCase
{
    private PDO $pdo;
    private MailCatalog $catalog;
    private string $tenantId;
    private string $userId;
    private string $email;

    protected function setUp(): void
    {
        Container::setInstance(new Container());
        $this->pdo = $this->connectE2e();
        $this->tenantId = $this->anyTenantId();

        $db = new DatabaseService([]);
        $db->setConnection($this->pdo);
        Container::getInstance()->singleton('database', fn() => $db);

        $this->catalog = new MailCatalog();
        $this->userId = $this->createUser();
        $this->email = $this->userEmail();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM password_history WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        Container::resetInstance();
    }

    public function testSinPreferenciasUsaEspanol(): void
    {
        $this->assertSame('es', $this->catalog->idiomaDelDestino($this->email));

        $mail = $this->catalog->compose('reset', $this->email, 'SSPR', ['ttl' => '15', 'url' => 'u']);
        $this->assertSame('Restablecer tu contraseña', $mail['asunto']);
        $this->assertStringContainsString('válido durante 15 minutos', $mail['html']);
    }

    public function testPreferenciaDeIdiomaInglesComponeElCorreoEnIngles(): void
    {
        $this->setPreferencias('{"idioma":"en"}');

        $this->assertSame('en', $this->catalog->idiomaDelDestino($this->email));

        $mail = $this->catalog->compose('reset', $this->email, 'SSPR', ['ttl' => '15', 'url' => 'u']);
        $this->assertSame('Reset your password', $mail['asunto']);
        $this->assertStringContainsString('Hello SSPR:', $mail['html']);
        $this->assertStringContainsString('valid for 15 minutes', $mail['html']);
        $this->assertStringContainsString('Reset my password', $mail['html']);
    }

    public function testIdiomaFueraDeWhitelistCaeAEspanol(): void
    {
        $this->setPreferencias('{"idioma":"de"}');

        $this->assertSame('es', $this->catalog->idiomaDelDestino($this->email));
    }

    public function testPreferenciasSinClaveIdiomaCaeAEspanol(): void
    {
        // La BD exige json_valid (CHECK): solo preferencias bien formadas.
        // Con tema elegido pero sin idioma explicito, rige el default 'es'.
        $this->setPreferencias('{"tema":"dark"}');

        $this->assertSame('es', $this->catalog->idiomaDelDestino($this->email));
    }

    public function testDestinoInexistenteCaeAEspanol(): void
    {
        $this->assertSame('es', $this->catalog->idiomaDelDestino('nadie-' . $this->userId . '@e2e.local'));
    }

    public function testBienvenidaYAdministrativoTambienUsanElIdioma(): void
    {
        $this->setPreferencias('{"tema":"dark","idioma":"pt"}');

        $welcome = $this->catalog->compose('welcome', $this->email, 'SSPR', ['ttl' => '24', 'url' => 'u']);
        $this->assertSame('Bem-vindo à plataforma', $welcome['asunto']);

        $admin = $this->catalog->compose('admin', $this->email, 'SSPR', ['ttl' => '60', 'url' => 'u']);
        $this->assertSame('Redefinir sua senha', $admin['asunto']);
        $this->assertStringContainsString('deixou de ser válida', $admin['html']);
    }

    // ------------------------------------------------------------------

    private function setPreferencias(string $json): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET preferencias = ? WHERE id = ?');
        $stmt->execute([$json, $this->userId]);
    }

    private function createUser(): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'ACTIVO\', NOW(), NOW())'
        )->execute([
            $id,
            $this->tenantId,
            'mailcat-' . $id . '@e2e.local',
            'MailCat',
            'Test',
            password_hash('Start123!x', PASSWORD_DEFAULT),
        ]);

        return $id;
    }

    private function userEmail(): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);

        return (string) $stmt->fetchColumn();
    }

    private function connectE2e(): PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? '');

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname=xproject_e2e;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL xproject_e2e no disponible: ' . $e->getMessage());
        }

        return $pdo;
    }

    private function anyTenantId(): string
    {
        $id = $this->pdo->query('SELECT id FROM tenants LIMIT 1')->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            $this->markTestSkipped('No hay tenant en xproject_e2e');
        }

        return (string) $id;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
