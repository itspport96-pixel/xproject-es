<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Services\DatabaseService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Política de contraseña y no-reutilización (v1.16.0) contra MySQL REAL.
 *
 * A diferencia del resto del suite (SQLite en memoria), estas pruebas corren
 * contra la BD de E2E `xproject_e2e` (MySQL, aislada; regla 16) para validar
 * de verdad la tabla password_history, las transacciones y los hashes bcrypt
 * contra el motor real. Se saltan (skip) si MySQL no está disponible.
 *
 * Cubre:
 *  - Política: mínimo 9, mayúscula, minúscula, número y carácter especial.
 *  - No-reutilización: la nueva no puede ser la actual ni ninguna del historial.
 *  - Historial: cada cambio registra el hash saliente (acumula filas).
 *  - El password temporal (reset de admin) cumple la política.
 */
final class PasswordPolicyLiveTest extends TestCase
{
    private const ROUNDS = 4;
    private const PASSWORD_INICIAL = 'Start123!x';

    private PDO $pdo;
    private DatabaseService $db;
    private UserService $service;
    private PasswordHasher $hasher;
    private string $userId;
    private string $tenantId;

    protected function setUp(): void
    {
        // UserService llama Container::getInstance() en su constructor aunque
        // se inyecten todas las dependencias; instanciamos uno vacio.
        Container::setInstance(new Container());

        $this->pdo = $this->connectE2e();

        // La tabla debe existir en la BD de E2E (se recrea desde schema.sql,
        // pero la creamos por si acaso para que el test sea autocontenido).
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_history (
                id CHAR(36) NOT NULL,
                tenant_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_password_history_user (user_id, created_at),
                CONSTRAINT fk_password_history_user_test FOREIGN KEY (user_id) REFERENCES users (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->hasher = new PasswordHasher(['rounds' => self::ROUNDS]);
        $this->service = new UserService(new UserRepository($this->db), $this->hasher, $this->db);

        $this->tenantId = $this->anyTenantId();
        $this->userId = $this->createUser(self::PASSWORD_INICIAL);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM password_history WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        Container::resetInstance();
    }

    // ------------------------------------------------------------------
    // POLÍTICA DE FORTALEZA
    // ------------------------------------------------------------------

    public function testRechazaMenosDe9Caracteres(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, 'Abcdef12');
            $this->fail('Debio rechazar menos de 9 caracteres');
        } catch (UserException $e) {
            $this->assertSame('auth.password_min_length', $e->getMessageKey());
        }
    }

    public function testRechazaNueveSinCaracterEspecial(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, 'Abcdefg12');
            $this->fail('Debio rechazar 9 caracteres sin especial');
        } catch (UserException $e) {
            $this->assertSame('auth.password_special', $e->getMessageKey());
        }
    }

    public function testRechazaSinMayuscula(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, 'abcdefghij1');
            $this->fail('Debio rechazar sin mayuscula');
        } catch (UserException $e) {
            $this->assertSame('auth.password_uppercase', $e->getMessageKey());
        }
    }

    public function testRechazaSinMinuscula(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, 'ABCDEFGHIJ1');
            $this->fail('Debio rechazar sin minuscula');
        } catch (UserException $e) {
            $this->assertSame('auth.password_lowercase', $e->getMessageKey());
        }
    }

    public function testRechazaSinDigito(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, 'Abcdefghij');
            $this->fail('Debio rechazar sin digito');
        } catch (UserException $e) {
            $this->assertSame('auth.password_digit', $e->getMessageKey());
        }
    }

    public function testAceptaPasswordValidaYRegistraHistorial(): void
    {
        $hashInicial = $this->currentHash();

        $this->service->changePassword($this->userId, $this->tenantId, 'Nueva123!x');

        $this->assertNotSame($hashInicial, $this->currentHash(), 'El hash debe cambiar');
        $this->assertSame(1, $this->historyCount(), 'El hash saliente debe registrarse en el historial');
        $this->assertTrue(
            $this->hasher->verify('Nueva123!x', $this->currentHash()),
            'La nueva contraseña debe quedar vigente'
        );
    }

    // ------------------------------------------------------------------
    // NO-REUTILIZACIÓN
    // ------------------------------------------------------------------

    public function testNoPermiteReutilizarLaActual(): void
    {
        try {
            $this->service->changePassword($this->userId, $this->tenantId, self::PASSWORD_INICIAL);
            $this->fail('Debio rechazar la password actual');
        } catch (UserException $e) {
            $this->assertSame('auth.password_reused', $e->getMessageKey());
        }
    }

    public function testNoPermiteReutilizarUnaAnterior(): void
    {
        $this->service->changePassword($this->userId, $this->tenantId, 'Nueva123!x');

        try {
            // Volver a la inicial: ya esta en el historial.
            $this->service->changePassword($this->userId, $this->tenantId, self::PASSWORD_INICIAL);
            $this->fail('Debio rechazar una password del historial');
        } catch (UserException $e) {
            $this->assertSame('auth.password_reused', $e->getMessageKey());
        }
    }

    public function testHistorialAcumulaCadaCambio(): void
    {
        $this->service->changePassword($this->userId, $this->tenantId, 'Nueva123!x');
        $this->service->changePassword($this->userId, $this->tenantId, 'Tercera123!y');

        $this->assertSame(2, $this->historyCount(), 'Cada cambio registra el hash saliente');
    }

    // ------------------------------------------------------------------
    // CREACIÓN Y RESET DE ADMIN
    // ------------------------------------------------------------------

    public function testCreaUsuarioConPasswordDebilRechazado(): void
    {
        try {
            $this->service->create([
                'tenant_id' => $this->tenantId,
                'email'     => 'debil-policy@e2e.local',
                'nombre'    => 'Test',
                'apellido'  => 'Policy',
                'password'  => 'corta1!',
            ]);
            $this->fail('Debio rechazar password debil en creacion');
        } catch (UserException $e) {
            $this->assertSame('auth.password_min_length', $e->getMessageKey());
        }
    }

    public function testPasswordTemporalDeAdminCumplePolitica(): void
    {
        $temporal = $this->service->resetPassword($this->userId, $this->tenantId);

        $this->assertNull(PasswordPolicy::validate($temporal), 'El temporal debe cumplir la politica completa');
        $this->assertGreaterThanOrEqual(1, $this->historyCount(), 'El reset debe registrar el hash saliente');
    }

    // ------------------------------------------------------------------
    // HELPERS
    // ------------------------------------------------------------------

    private function connectE2e(): PDO
    {
        $base = dirname(__DIR__, 2);
        (Dotenv::createImmutable($base))->safeLoad();

        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? '');
        // Siempre la BD de E2E (aislada), nunca la de trabajo.
        $dbname = 'xproject_e2e';

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
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

    private function createUser(string $password): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'ACTIVO\', NOW(), NOW())'
        )->execute([
            $id,
            $this->tenantId,
            'policy-' . $id . '@e2e.local',
            'Policy',
            'Test',
            $this->hasher->hash($password),
        ]);

        return $id;
    }

    private function currentHash(): string
    {
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);

        return (string) $stmt->fetchColumn();
    }

    private function historyCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_history WHERE user_id = ?');
        $stmt->execute([$this->userId]);

        return (int) $stmt->fetchColumn();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
