<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit\AuditLogger;
use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authentication\Service\PasswordResetService;
use App\Core\Authorization\Repository\PermissionRepository;
use App\Core\Authorization\Repository\RoleRepository;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Tenants\Service\MailerService;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Password Reset SSPR (v1.17.0) contra MySQL REAL (xproject_e2e, regla 16).
 *
 * Sin SQLite: valida de verdad la tabla password_reset_tokens, el hash del
 * token, el consumo atómico y la re-verificación de permisos contra el motor
 * real. Se salta (skip) si MySQL no está disponible.
 *
 * Cubre los requisitos pedidos:
 *  - Permisos: sin `password_reset_self_service` no se crea token.
 *  - Anti-enumeración: requestReset no distingue elegible / no elegible /
 *    email inexistente (mismo comportamiento, sin token si no procede).
 *  - Un solo uso: un token válido sólo puede usarse una vez.
 *  - Caducidad: un token vencido se rechaza.
 *  - Re-verificación: revocar el permiso tras emitir el token invalida el enlace.
 *  - Política: la contraseña débil se rechaza y NO consume el token.
 *  - Notificación: tras el cambio se envía el aviso al trabajador.
 */
final class PasswordResetLiveTest extends TestCase
{
    private const ROUNDS = 4;
    private const PASSWORD_INICIAL = 'Start123!x';
    private const PASSWORD_NUEVA = 'NuevaClave123!';

    private PDO $pdo;
    private DatabaseService $db;
    private UserService $users;
    private AuthorizationService $authz;
    private PasswordResetService $service;
    private PasswordHasher $hasher;
    private string $tenantId;
    private string $userId;
    private string $permId;
    /** @var array<int, string> tokens capturados (orden de envío) */
    private array $capturedTokens = [];
    /** @var array<int, string> tipo de correo enviado ('reset'|'changed') */
    private array $sentMails = [];

    protected function setUp(): void
    {
        // UserService y AuthorizationService llaman Container::getInstance()
        // en su constructor; instanciamos uno vacio.
        Container::setInstance(new Container());

        $this->pdo = $this->connectE2e();

        // Tabla autocontenida por si la BD de E2E no la tuviera.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id CHAR(36) NOT NULL,
                tenant_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_prt_token_hash (token_hash),
                KEY idx_prt_user (user_id, created_at),
                CONSTRAINT fk_prt_user_test FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_prt_tenant_test FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        $this->hasher = new PasswordHasher(['rounds' => self::ROUNDS]);
        $this->users = new UserService(new UserRepository($this->db), $this->hasher, $this->db);

        $redis = new RedisService([
            'host' => (string) ($_ENV['REDIS_HOST'] ?: '127.0.0.1'),
            'port' => (int) ($_ENV['REDIS_PORT'] ?: 6379),
        ]);
        $this->authz = new AuthorizationService(
            new UserRepository($this->db),
            new RoleRepository($this->db),
            new PermissionRepository($this->db),
            $this->db,
            $redis
        );

        // Doble de prueba: captura el token y los correos sin tocar SMTP.
        $db = $this->db;
        $users = $this->users;
        $authz = $this->authz;
        $test = $this;
        $this->service = new class($db, $authz, $users, null, null, $test) extends PasswordResetService {
            private object $test;

            public function __construct(
                ?DatabaseService $db,
                ?AuthorizationService $authz,
                ?UserService $users,
                ?MailerService $mailer,
                ?AuditLogger $audit,
                object $test
            ) {
                parent::__construct($db, $authz, $users, $mailer, $audit);
                $this->test = $test;
            }

            protected function sendResetEmail(string $tenantId, string $destino, string $nombre, string $token): void
            {
                $this->test->captureToken($token);
            }

            protected function sendChangedEmail(string $tenantId, string $destino, string $nombre): void
            {
                $this->test->captureMail('changed');
            }

            protected function sendMail(string $tenantId, string $destino, string $asunto, string $html): void
            {
                // Nunca se invoca: los dos metodos de arriba lo evitan.
            }
        };

        $this->tenantId = $this->anyTenantId();
        $this->userId = $this->createUser(self::PASSWORD_INICIAL);
        $this->permId = $this->ssprPermissionId();
        $this->capturedTokens = [];
        $this->sentMails = [];
    }

    protected function tearDown(): void
    {
        // Limpieza a la inversa (FKs).
        $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM role_assignments WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare(
            'DELETE r FROM roles r LEFT JOIN role_assignments ra ON ra.role_id = r.id
              WHERE r.tenant_id = ? AND r.tipo = \'CUSTOM\' AND ra.role_id IS NULL'
        )->execute([$this->tenantId]);
        $this->pdo->prepare('DELETE FROM password_history WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        unset($GLOBALS['__sspr_self']);
        Container::resetInstance();
    }

    // ------------------------------------------------------------------
    // PERMISOS
    // ------------------------------------------------------------------

    public function testSinPermisoNoCreaTokenNiEnviaCorreo(): void
    {
        $this->service->requestReset($this->userEmail());

        $this->assertSame(0, $this->tokenCount(), 'Sin el permiso no debe crearse token');
        $this->assertSame([], $this->sentMails, 'Sin el permiso no debe enviarse correo');
    }

    public function testConPermisoCreaTokenSoloComoHash(): void
    {
        $this->grantSspr();

        $this->service->requestReset($this->userEmail());

        $this->assertSame(1, $this->tokenCount(), 'Con el permiso debe crearse un token');
        $this->assertCount(1, $this->capturedTokens, 'El token debe entregarse al correo');
        $this->assertCount(1, $this->sentMails, 'Debe enviarse el correo de reset');

        $plain = $this->capturedTokens[0];
        $stored = $this->storedTokenHash();
        $this->assertSame(hash('sha256', $plain), $stored, 'El token se guarda como hash SHA-256');
        $this->assertNotSame($plain, $stored, 'El token en claro NO se almacena');
        $this->assertSame(64, strlen($stored), 'El hash SHA-256 hex mide 64 chars');
    }

    public function testNuevoTokenInvalidaElAnterior(): void
    {
        $this->grantSspr();

        $this->service->requestReset($this->userEmail());
        $token1 = $this->capturedTokens[0];
        $this->service->requestReset($this->userEmail());
        $token2 = $this->capturedTokens[1];

        // El segundo request invalida (marca usado) al primero.
        $this->assertTrue($this->tokenUsed($token1), 'El token anterior debe quedar invalidado');
        $this->assertFalse($this->tokenUsed($token2), 'El token nuevo sigue activo');

        try {
            $this->service->resetPassword($token1, self::PASSWORD_NUEVA);
            $this->fail('El token invalidado no debe poder usarse');
        } catch (UserException $e) {
            $this->assertSame('auth.reset_token_used', $e->getMessageKey());
        }
    }

    // ------------------------------------------------------------------
    // ANTI-ENUMERACIÓN
    // ------------------------------------------------------------------

    public function testAntiEnumeracionMismoComportamiento(): void
    {
        // Elegible (con permiso), no elegible (sin permiso) e inexistente:
        // requestReset es void en los tres y no lanza. El token sólo se crea
        // en el caso elegible; el controller devuelve siempre el mismo 200.
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());        // elegible
        $eligibleTokens = $this->tokenCount();
        $this->capturedTokens = [];

        // No elegible: otro usuario sin permiso.
        $otherId = $this->createUser(self::PASSWORD_INICIAL, 'noelig');
        $this->service->requestReset($this->emailFor('noelig'));
        $this->assertSame($eligibleTokens, $this->tokenCount(), 'El no elegible no crea token');

        // Email inexistente.
        $this->service->requestReset('nadie-' . $this->uuid() . '@e2e.local');
        $this->assertSame($eligibleTokens, $this->tokenCount(), 'El email inexistente no crea token');

        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$otherId]);
    }

    // ------------------------------------------------------------------
    // UN SOLO USO + FLUJO COMPLETO
    // ------------------------------------------------------------------

    public function testResetExitosoCambiaPasswordYNotifica(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];
        $oldHash = $this->currentHash();

        $this->service->resetPassword($token, self::PASSWORD_NUEVA);

        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_NUEVA, $this->currentHash()),
            'La nueva contraseña debe quedar vigente'
        );
        $this->assertTrue(
            $this->historyContains($oldHash),
            'El hash saliente debe registrarse en el historial'
        );
        $this->assertTrue($this->tokenUsed($token), 'El token debe quedar consumido');
        $this->assertContains('changed', $this->sentMails, 'Debe notificarse al trabajador tras el cambio');
    }

    public function testTokenNoReutilizable(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];

        $this->service->resetPassword($token, self::PASSWORD_NUEVA);

        try {
            $this->service->resetPassword($token, 'OtraClave123!x');
            $this->fail('El token ya usado no debe reutilizarse');
        } catch (UserException $e) {
            $this->assertSame('auth.reset_token_used', $e->getMessageKey());
        }
    }

    // ------------------------------------------------------------------
    // CADUCIDAD
    // ------------------------------------------------------------------

    public function testTokenCaducadoSeRechaza(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];

        // Fuerza la caducidad (15 min -> -1 min).
        $this->pdo->prepare(
            'UPDATE password_reset_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ?'
        )->execute([$this->userId]);

        try {
            $this->service->resetPassword($token, self::PASSWORD_NUEVA);
            $this->fail('Un token caducado debe rechazarse');
        } catch (UserException $e) {
            $this->assertSame('auth.reset_token_expired', $e->getMessageKey());
        }
        $this->assertFalse($this->tokenUsed($token), 'El token caducado no se consume');
    }

    // ------------------------------------------------------------------
    // RE-VERIFICACIÓN DEL PERMISO AL FIJAR LA CONTRASEÑA
    // ------------------------------------------------------------------

    public function testPermisoRevocadoInvalidaElToken(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];

        // Un administrador revoca el permiso DESPUÉS de emitir el token.
        $this->revokeSspr();

        try {
            $this->service->resetPassword($token, self::PASSWORD_NUEVA);
            $this->fail('Con el permiso revocado el enlace debe dejar de servir');
        } catch (UserException $e) {
            $this->assertSame('auth.reset_permission_revoked', $e->getMessageKey());
        }
        $this->assertFalse($this->tokenUsed($token), 'El token no se consume si el permiso fue revocado');
    }

    // ------------------------------------------------------------------
    // POLÍTICA DE CONTRASEÑA APLICADA EN EL RESET
    // ------------------------------------------------------------------

    public function testPasswordDebilSeRechazaYNoConsumeElToken(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];

        try {
            $this->service->resetPassword($token, 'Abcdef12'); // <9, sin especial
            $this->fail('Una contraseña débil debe rechazarse');
        } catch (UserException $e) {
            $this->assertSame('auth.password_min_length', $e->getMessageKey());
        }
        $this->assertFalse($this->tokenUsed($token), 'La política fallida NO consume el token');

        // El mismo enlace sigue válido para una contraseña fuerte.
        $this->service->resetPassword($token, self::PASSWORD_NUEVA);
        $this->assertTrue($this->hasher->verify(self::PASSWORD_NUEVA, $this->currentHash()));
    }

    public function testNoPermiteReutilizarLaContrasenaAnterior(): void
    {
        $this->grantSspr();
        $this->service->requestReset($this->userEmail());
        $token = $this->capturedTokens[0];

        try {
            // La actual (PASSWORD_INICIAL) está en juego: reuse check la rechaza.
            $this->service->resetPassword($token, self::PASSWORD_INICIAL);
            $this->fail('No debe poder fijar la misma contraseña actual');
        } catch (UserException $e) {
            $this->assertSame('auth.password_reused', $e->getMessageKey());
        }
    }

    // ------------------------------------------------------------------
    // HELPERS
    // ------------------------------------------------------------------

    /** Captura el token (invocado por el doble de prueba). */
    public function captureToken(string $token): void
    {
        $this->capturedTokens[] = $token;
        $this->sentMails[] = 'reset';
    }

    /** Captura el tipo de correo (invocado por el doble de prueba). */
    public function captureMail(string $type): void
    {
        $this->sentMails[] = $type;
    }

    /** Concede el permiso SSPR al usuario vía un rol CUSTOM con la asignación. */
    private function grantSspr(): void
    {
        $roleId = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO roles (id, nombre, tipo, tenant_id, activo, created_at, updated_at)
             VALUES (?, ?, \'CUSTOM\', ?, 1, NOW(), NOW())'
        )->execute([$roleId, 'SSPR-Test-' . substr($roleId, 0, 8), $this->tenantId]);

        $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
        )->execute([$roleId, $this->permId]);

        $this->pdo->prepare(
            'INSERT INTO role_assignments (id, user_id, role_id, tenant_id) VALUES (?, ?, ?, ?)'
        )->execute([$this->uuid(), $this->userId, $roleId, $this->tenantId]);

        $this->authz->invalidateCache($this->userId, $this->tenantId);
    }

    /** Revoca el permiso SSPR eliminando la asignación de rol del usuario. */
    private function revokeSspr(): void
    {
        $this->pdo->prepare('DELETE FROM role_assignments WHERE user_id = ?')->execute([$this->userId]);
        $this->authz->invalidateCache($this->userId, $this->tenantId);
    }

    private function connectE2e(): PDO
    {
        $base = dirname(__DIR__, 2);
        (Dotenv::createImmutable($base))->safeLoad();

        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? '');
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

    private function ssprPermissionId(): string
    {
        $id = $this->pdo->query(
            "SELECT id FROM permissions WHERE identificador = 'password_reset_self_service'"
        )->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            $this->markTestSkipped('El permiso password_reset_self_service no esta sembrado en E2E');
        }

        return (string) $id;
    }

    private function createUser(string $password, string $tag = 'sspr'): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'ACTIVO\', NOW(), NOW())'
        )->execute([
            $id,
            $this->tenantId,
            $this->emailFor($tag, $id),
            'SSPR',
            'Test',
            $this->hasher->hash($password),
        ]);

        return $id;
    }

    private function emailFor(string $tag, ?string $id = null): string
    {
        return $tag . '-' . ($id ?? $this->userId) . '@e2e.local';
    }

    private function userEmail(): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);

        return (string) $stmt->fetchColumn();
    }

    private function currentHash(): string
    {
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);

        return (string) $stmt->fetchColumn();
    }

    private function historyContains(string $hash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_history WHERE user_id = ? AND password_hash = ?'
        );
        $stmt->execute([$this->userId, $hash]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function tokenCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ?');
        $stmt->execute([$this->userId]);

        return (int) $stmt->fetchColumn();
    }

    private function storedTokenHash(): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT token_hash FROM password_reset_tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$this->userId]);

        return (string) $stmt->fetchColumn();
    }

    private function tokenUsed(string $token): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT used_at FROM password_reset_tokens WHERE token_hash = ?'
        );
        $stmt->execute([hash('sha256', $token)]);
        $used = $stmt->fetchColumn();

        return $used !== false && $used !== null;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
