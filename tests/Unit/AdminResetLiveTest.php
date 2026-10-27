<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authentication\Service\PasswordResetService;
use App\Core\Authorization\Repository\PermissionRepository;
use App\Core\Authorization\Repository\RoleRepository;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Repository\TenantRepository;
use App\Core\Tenants\Service\MailerService;
use App\Core\Tenants\Service\TenantService;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\MySqlSession;

/**
 * Reset administrativo y bienvenida (v1.18.0, cumplimiento) contra MySQL
 * REAL (xproject_e2e, regla 16).
 *
 * Cobertura:
 *  - El alta y el reset NO entregan ninguna contraseña al administrador.
 *  - issueAdminReset invalida la contraseña AL INSTANTE (nadie la conoce) y
 *    emite token kind 'admin' con trazabilidad requested_by y TTL 60 min.
 *  - El kind 'admin'/'welcome' consume SIN el permiso opt-in SSPR (la
 *    autorización es del emisor); el kind 'sspr' sigue exigiéndolo
 *    (cubierto en PasswordResetLiveTest).
 *  - Validaciones ANTES de mutar: SMTP del tenant (400) y email del
 *    usuario (400) no dejan rastro (password intacta, sin tokens).
 *  - Si el correo falla tras emitir, el token se borra (reintento limpio)
 *    y la contraseña YA está invalidada (semántica A).
 *  - El alta rechaza un password explícito (400 users.password_not_allowed).
 */
final class AdminResetLiveTest extends TestCase
{
    private const ROUNDS = 4;
    private const PASSWORD_INICIAL = 'Start123!x';
    private const PASSWORD_NUEVA = 'NuevaClave123!';

    private PDO $pdo;
    private DatabaseService $db;
    private UserService $users;
    private PasswordResetService $service;
    private PasswordHasher $hasher;
    private string $tenantId;
    /** @var array<int, string> tokens capturados (formato: token|kind) */
    private array $capturedTokens = [];
    /** @var array<int, string> kinds de correo capturados */
    private array $sentMails = [];
    private bool $failSend = false;
    /** @var array<int, string> usuarios creados (se borran en tearDown) */
    private array $createdUsers = [];
    /** @var array<int, string> tenantes creados (se borran en tearDown) */
    private array $createdTenants = [];

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->pdo = $this->connectE2e();

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);
        Container::getInstance()->singleton('database', fn() => $this->db);

        $this->hasher = new PasswordHasher(['rounds' => self::ROUNDS]);
        $this->users = new UserService(new UserRepository($this->db), $this->hasher, $this->db);

        $redis = new RedisService([
            'host' => (string) ($_ENV['REDIS_HOST'] ?: '127.0.0.1'),
            'port' => (int) ($_ENV['REDIS_PORT'] ?: '6379'),
        ]);
        $authz = new AuthorizationService(
            new UserRepository($this->db),
            new RoleRepository($this->db),
            new PermissionRepository($this->db),
            $this->db,
            $redis
        );

        // MailerService REAL (solo assertSendable: los envios se capturan
        // sobreescribiendo sendMail, punto unico de salida).
        $tenantService = (new ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(TenantService::class, 'tenants');
        $prop->setValue($tenantService, new TenantRepository($this->db));
        $mailer = new MailerService($tenantService);

        // Doble: captura token/kind y permite inyectar fallo de envio.
        $db = $this->db;
        $users = $this->users;
        $test = $this;
        $this->service = new class($db, $authz, $users, $mailer, $test) extends PasswordResetService {
            private object $test;

            public function __construct(
                ?DatabaseService $db,
                ?AuthorizationService $authz,
                ?UserService $users,
                ?MailerService $mailer,
                object $test
            ) {
                parent::__construct($db, $authz, $users, $mailer, null);
                $this->test = $test;
            }

            protected function sendMail(string $tenantId, string $destino, string $asunto, string $html, string $kind = 'general'): void
            {
                if ($this->test->sendShouldFail()) {
                    throw new \RuntimeException('SMTP simulado caido');
                }
                if (preg_match('/token=([0-9a-f]{64})/', $html, $m)) {
                    $this->test->captureToken($m[1], $kind);
                }
                $this->test->captureMail($kind);
            }
        };

        $this->tenantId = $this->anyTenantWithSmtpId();
        $this->capturedTokens = [];
        $this->sentMails = [];
        $this->failSend = false;
        $this->createdUsers = [];
        $this->createdTenants = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM password_history WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        foreach ($this->createdTenants as $tenantId) {
            $this->pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
        }
        Container::resetInstance();
    }

    // ------------------------------------------------------------------
    // EMISION ADMINISTRATIVA
    // ------------------------------------------------------------------

    public function testAdminResetEmiteTokenKindAdminConTrazabilidad(): void
    {
        $userId = $this->createUser($this->tenantId, 'admin-emit@e2e.local');
        $actorId = $this->createUser($this->tenantId, 'admin-actor@e2e.local');

        $email = $this->service->issueAdminReset($userId, $this->tenantId, $actorId, ['ip' => '127.0.0.1']);

        $this->assertSame('admin-emit@e2e.local', $email, 'Devuelve el email destino');
        $this->assertCount(1, $this->capturedTokens, 'Debe emitirse un unico enlace');
        [$plain, $kind] = $this->splitToken($this->capturedTokens[0]);
        $this->assertSame(PasswordResetService::KIND_ADMIN, $kind);

        $row = $this->lastTokenFor($userId);
        $this->assertNotFalse($row, 'El token debe persistirse');
        $this->assertSame(hash('sha256', $plain), $row['token_hash'], 'Sólo se guarda el hash');
        $this->assertSame('admin', $row['kind']);
        $this->assertSame($actorId, $row['requested_by'], 'Quien ordeno el reset queda trazado');
        $this->assertNull($row['used_at']);

        // TTL admin: 60 minutos (±5 min de tolerancia).
        $ttl = strtotime((string) $row['expires_at']) - time();
        $this->assertGreaterThan(3300, $ttl, 'El TTL admin es de 60 minutos');
        $this->assertLessThan(3900, $ttl, 'El TTL admin es de 60 minutos');
    }

    public function testAdminResetInvalidaLaPasswordAlInstante(): void
    {
        $userId = $this->createUser($this->tenantId, 'admin-inv@e2e.local');
        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)),
            'Precondicion: la password inicial vale'
        );

        $this->service->issueAdminReset($userId, $this->tenantId, $this->anyUserId());

        $this->assertFalse(
            $this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)),
            'La password anterior debe quedar invalidada al instante'
        );
        $this->assertNotFalse($this->currentForcedMarker($userId), 'Debe marcarse el cambio forzado');
        $this->assertGreaterThanOrEqual(1, $this->historyCount($userId), 'El hash saliente va al historial');
    }

    public function testAdminResetFalloDeCorreoBorraElTokenYConservaLaInvalidacion(): void
    {
        $userId = $this->createUser($this->tenantId, 'admin-fail@e2e.local');
        $this->failSend = true;

        try {
            $this->service->issueAdminReset($userId, $this->tenantId, $this->anyUserId());
            $this->fail('El fallo de envio debe propagarse');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP simulado caido', $e->getMessage());
        }

        $this->assertSame(0, $this->activeTokenCount($userId), 'Sin envio no queda ningun enlace utilizable');
        $this->assertFalse(
            $this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)),
            'La password ya invalidada NO se restaura (intencion del admin)'
        );

        // Reintento con el correo operativo: emite el enlace.
        $this->failSend = false;
        $this->service->issueAdminReset($userId, $this->tenantId, $this->anyUserId());
        $this->assertSame(1, $this->activeTokenCount($userId), 'El reintento deja un token activo');
    }

    // ------------------------------------------------------------------
    // VALIDACIONES PREVIAS (sin mutar)
    // ------------------------------------------------------------------

    public function testSinSmtpDelTenantNoEmiteNiInvalida(): void
    {
        $tenantId = $this->createTenantWithoutSmtp();
        $userId = $this->createUser($tenantId, 'nosmtp-reset@e2e.local');

        try {
            $this->service->issueAdminReset($userId, $tenantId, $this->anyUserId());
            $this->fail('Sin SMTP configurado el reset debe rechazarse');
        } catch (TenantException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('tenants.smtp_not_configured', $e->getMessageKey());
        }

        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)),
            'La password NO se toca si la validacion falla'
        );
        $this->assertSame(0, $this->activeTokenCount($userId), 'No se emite token sin SMTP');
        $this->assertNull($this->currentForcedMarker($userId), 'Sin exito no hay cambio forzado');
    }

    public function testEmailInvalidoDelUsuarioNoEmiteNiInvalida(): void
    {
        $userId = $this->createUser($this->tenantId, 'sin-email-valido');

        try {
            $this->service->issueAdminReset($userId, $this->tenantId, $this->anyUserId());
            $this->fail('Un usuario sin email valido debe rechazarse');
        } catch (UserException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('users.reset_email_missing', $e->getMessageKey());
        }

        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)),
            'La password NO se toca si la validacion falla'
        );
        $this->assertSame(0, $this->activeTokenCount($userId));
    }

    // ------------------------------------------------------------------
    // FLUJO COMPLETO (emitir -> consumir sin permiso SSPR)
    // ------------------------------------------------------------------

    public function testFlujoCompletoAdminConsumeSinPermisoSspr(): void
    {
        $userId = $this->createUser($this->tenantId, 'admin-full@e2e.local');

        $this->service->issueAdminReset($userId, $this->tenantId, $this->anyUserId());
        [$plain, $kind] = $this->splitToken($this->capturedTokens[0]);
        $this->assertSame('admin', $kind);

        // Sin NINGUN rol asignado (no tiene password_reset_self_service).
        $this->service->resetPassword($plain, self::PASSWORD_NUEVA, ['ip' => '127.0.0.1']);

        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_NUEVA, $this->currentHash($userId)),
            'La password fijada via liga queda vigente'
        );
        $this->assertFalse($this->hasher->verify(self::PASSWORD_INICIAL, $this->currentHash($userId)));
        $this->assertNull($this->currentForcedMarker($userId), 'El consumo limpia el cambio forzado');
        $this->assertTrue($this->tokenUsed($plain), 'El token queda consumido');
        $this->assertContains('changed', $this->sentMails, 'Debe notificarse el cambio');
    }

    // ------------------------------------------------------------------
    // BIENVENIDA + ALTA
    // ------------------------------------------------------------------

    public function testBienvenidaEmiteTokenKindWelcomeYConsumeSinPermisoSspr(): void
    {
        $res = $this->users->create([
            'tenant_id' => $this->tenantId,
            'email'     => 'welcome-new@e2e.local',
            'nombre'    => 'Welcome',
            'apellido'  => 'Test',
        ]);
        $userId = (string) $res['id'];
        $this->createdUsers[] = $userId;

        $email = $this->service->issueWelcome($userId, $this->tenantId, $this->anyUserId());

        $this->assertSame('welcome-new@e2e.local', $email);
        $this->assertCount(1, $this->capturedTokens);
        [, $kind] = $this->splitToken($this->capturedTokens[0]);
        $this->assertSame(PasswordResetService::KIND_WELCOME, $kind);

        $row = $this->lastTokenFor($userId);
        $this->assertSame('welcome', $row['kind']);
        $ttl = strtotime((string) $row['expires_at']) - time();
        $this->assertGreaterThan(83400, $ttl, 'El TTL de bienvenida es de 24 horas');
        $this->assertLessThan(89400, $ttl, 'El TTL de bienvenida es de 24 horas');

        [$plain] = $this->splitToken($this->capturedTokens[0]);
        $this->service->resetPassword($plain, self::PASSWORD_NUEVA, []);

        $this->assertTrue(
            $this->hasher->verify(self::PASSWORD_NUEVA, $this->currentHash($userId)),
            'El usuario nuevo fija su password por la liga'
        );
        $this->assertNull($this->currentForcedMarker($userId));
    }

    public function testAltaRechazaPasswordExplicito(): void
    {
        try {
            $this->users->create([
                'tenant_id' => $this->tenantId,
                'email'     => 'alta-con-pass@e2e.local',
                'nombre'    => 'Alta',
                'apellido'  => 'Test',
                'password'  => 'ClaveQueNoDebe1!',
            ]);
            $this->fail('El alta no admite un password en claro');
        } catch (UserException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('users.password_not_allowed', $e->getMessageKey());
            $this->assertSame('password', $e->getField());
        }
    }

    public function testAltaNoExponePasswordTemporal(): void
    {
        $res = $this->users->create([
            'tenant_id' => $this->tenantId,
            'email'     => 'alta-sin-temporal@e2e.local',
            'nombre'    => 'Alta',
            'apellido'  => 'SinTemporal',
        ]);
        $this->createdUsers[] = (string) $res['id'];

        $this->assertArrayNotHasKey('password_temporal', $res, 'El alta ya nunca devuelve una clave');
        $this->assertTrue($res['password_change_required'], 'El cambio forzado sigue marcado');

        $stmt = $this->pdo->prepare('SELECT password_change_forced FROM users WHERE id = ?');
        $stmt->execute([$res['id']]);
        $this->assertNotFalse($stmt->fetchColumn(), 'El marcador persiste en BD');
    }

    // ------------------------------------------------------------------
    // HELPERS (invocados por el doble de prueba)
    // ------------------------------------------------------------------

    public function captureToken(string $token, string $kind): void
    {
        $this->capturedTokens[] = $token . '|' . $kind;
    }

    public function captureMail(string $kind): void
    {
        $this->sentMails[] = $kind;
    }

    public function sendShouldFail(): bool
    {
        return $this->failSend;
    }

    private function splitToken(string $combined): array
    {
        [$token, $kind] = explode('|', $combined);
        return [$token, $kind];
    }

    private function createUser(string $tenantId, string $email): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, nombre, apellido, password, estado, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'ACTIVO\', NOW(), NOW())'
        )->execute([$id, $tenantId, $email, 'AdminReset', 'Test', $this->hasher->hash(self::PASSWORD_INICIAL)]);

        $this->createdUsers[] = $id;

        return $id;
    }

    private function createTenantWithoutSmtp(): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado, created_at, updated_at)
             VALUES (?, ?, ?, \'ACTIVO\', NOW(), NOW())'
        )->execute([$id, 'AdminReset NoSMTP ' . substr($id, 0, 6), 'admin-reset-nosmtp-' . substr($id, 0, 6)]);

        $this->createdTenants[] = $id;

        return $id;
    }

    private function anyTenantWithSmtpId(): string
    {
        $id = $this->pdo->query(
            'SELECT id FROM tenants WHERE smtp_host IS NOT NULL AND deleted_at IS NULL LIMIT 1'
        )->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            $this->markTestSkipped('No hay tenant con SMTP en xproject_e2e (ejecuta prepare-e2e-db)');
        }

        return (string) $id;
    }

    private function anyUserId(): string
    {
        // Actor generico: sirve para requested_by (FK a users).
        $id = $this->pdo->query(
            'SELECT id FROM users WHERE deleted_at IS NULL LIMIT 1'
        )->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            $this->markTestSkipped('No hay usuarios en xproject_e2e');
        }

        return (string) $id;
    }

    private function currentHash(string $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    private function currentForcedMarker(string $userId): string|false|null
    {
        $stmt = $this->pdo->prepare('SELECT password_change_forced FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn();
    }

    private function historyCount(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_history WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function activeTokenCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function lastTokenFor(string $userId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT token_hash, kind, requested_by, expires_at, used_at
             FROM password_reset_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : false;
    }

    private function tokenUsed(string $plainToken): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT used_at FROM password_reset_tokens WHERE token_hash = ?'
        );
        $stmt->execute([hash('sha256', $plainToken)]);
        $used = $stmt->fetchColumn();

        return $used !== false && $used !== null;
    }

    private function connectE2e(): PDO
    {
        $base = dirname(__DIR__, 2);
        (Dotenv::createImmutable($base))->safeLoad();

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

        MySqlSession::applyTimeZone($pdo);

        return $pdo;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
