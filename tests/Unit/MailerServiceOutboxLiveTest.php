<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tenants\Exception\TenantException;
use App\Core\Tenants\Repository\TenantRepository;
use App\Core\Tenants\Service\MailerService;
use App\Core\Tenants\Service\TenantService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Transporte de correo MAIL_TRANSPORT (v1.18.0) contra MySQL REAL
 * (xproject_e2e, regla 16).
 *
 * Cobertura:
 *  - outbox: el correo NO sale por SMTP; queda registrado completo en
 *    mail_outbox (estado 'enviado', transport 'outbox').
 *  - smtp con destino caido: el intento se registra en mail_outbox con
 *    estado 'fallo' y el error se propaga como 502.
 *  - sin SMTP configurado: 400 y NO se registra ni se intenta envio.
 *  - destino invalido: 400 y NO se registra ni se intenta envio.
 *
 * Los tenants de prueba se crean y se borran en cada test (el FK de
 * mail_outbox a tenants limpia sus filas en cascada). El SMTP del tenant
 * Acme real NUNCA se usa: o esta desconfigurado en el tenant de prueba o
 * el host es 127.0.0.1:1 (conexion rechazada al instante).
 */
final class MailerServiceOutboxLiveTest extends TestCase
{
    private const SMTP_OK = [
        'smtp_host' => 'smtp.test.invalid',
        'smtp_puerto' => 587,
        'smtp_encryption' => 'tls',
        'smtp_from_email' => 'noreply@test.invalid',
    ];

    private PDO $pdo;
    private DatabaseService $db;
    private MailerService $mailer;
    /** @var array<int, string> tenantes creados por el test (se borran en tearDown) */
    private array $createdTenants = [];

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->pdo = $this->connectE2e();

        $this->db = new DatabaseService([]);
        $this->db->setConnection($this->pdo);

        // recordOutbox resuelve la BD por el nombre 'database' (patron comun).
        Container::getInstance()->singleton('database', fn() => $this->db);

        // TenantService solo usa el repositorio en findEntity(); el resto de
        // dependencias se resolveria contra el contenedor vacio: se inyecta
        // unicamente ese por reflection (mismo patron que TenantSettingsTest).
        $tenantService = (new ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(TenantService::class, 'tenants');
        $prop->setAccessible(true);
        $prop->setValue($tenantService, new TenantRepository($this->db));

        $this->mailer = new MailerService($tenantService);
    }

    protected function tearDown(): void
    {
        putenv('MAIL_TRANSPORT');
        foreach ($this->createdTenants as $id) {
            $stmt = $this->pdo->prepare('DELETE FROM tenants WHERE id = ?');
            $stmt->execute([$id]);
        }
        Container::resetInstance();
    }

    // ------------------------------------------------------------------
    // OUTBOX
    // ------------------------------------------------------------------

    public function testOutboxGuardaElCorreoCompletoEnMailOutbox(): void
    {
        putenv('MAIL_TRANSPORT=outbox');
        $tenantId = $this->createTenant('outbox', self::SMTP_OK);
        $marker = 'token-' . $this->uuid();
        $destino = 'destino-' . substr($marker, 6, 12) . '@test.local';

        $this->mailer->sendCustom(
            $tenantId,
            $destino,
            'Restablecer tu contrasena',
            '<p>Enlace: /#/reset-password?token=' . $marker . '</p>',
            'reset'
        );

        $row = $this->lastOutboxFor($destino);
        $this->assertNotFalse($row, 'El correo debe quedar registrado en mail_outbox');
        $this->assertSame('outbox', $row['transport'], 'En modo outbox el transporte es outbox');
        $this->assertSame('enviado', $row['estado'], 'El registro en outbox es un envio aceptado');
        $this->assertSame('reset', $row['kind'], 'Debe conservarse la etiqueta de contexto');
        $this->assertSame('Restablecer tu contrasena', $row['asunto']);
        $this->assertStringContainsString($marker, (string) $row['html'], 'El cuerpo se guarda completo');
        $this->assertNull($row['error'], 'Sin error no hay mensaje de fallo');
    }

    public function testTransporteDesconocidoSeTrataComoSmtp(): void
    {
        putenv('MAIL_TRANSPORT=una-basura');
        // Host caido: si se tratara como 'outbox' no habria error de red.
        $tenantId = $this->createTenant('inv', ['smtp_host' => '127.0.0.1', 'smtp_puerto' => 1]);
        $destino = 'inv-' . substr($this->uuid(), 0, 8) . '@test.local';

        try {
            $this->mailer->sendCustom($tenantId, $destino, 'x', '<p>x</p>', 'general');
            $this->fail('Con host caido el envio SMTP debe fallar (transporte = smtp)');
        } catch (TenantException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('tenants.smtp_send_failed', $e->getMessageKey());
        }

        $row = $this->lastOutboxFor($destino);
        $this->assertNotFalse($row, 'El intento fallido debe quedar registrado');
        $this->assertSame('fallo', $row['estado']);
        $this->assertSame('smtp', $row['transport']);
        $this->assertNotEmpty($row['error'], 'El error debe registrarse');
    }

    // ------------------------------------------------------------------
    // SMTP REAL CON DESTINO CAIDO
    // ------------------------------------------------------------------

    public function testFalloSmtpRegistraElIntentoYPropaga502(): void
    {
        putenv('MAIL_TRANSPORT=smtp');
        $tenantId = $this->createTenant('dead', ['smtp_host' => '127.0.0.1', 'smtp_puerto' => 1]);
        $destino = 'dead-' . substr($this->uuid(), 0, 8) . '@test.local';

        try {
            $this->mailer->sendCustom($tenantId, $destino, 'x', '<p>x</p>', 'welcome');
            $this->fail('La conexion a un host inaccesible debe fallar');
        } catch (TenantException $e) {
            $this->assertSame(502, $e->getCode(), 'El fallo de envio es 502');
            $this->assertSame('tenants.smtp_send_failed', $e->getMessageKey());
        }

        $row = $this->lastOutboxFor($destino);
        $this->assertNotFalse($row, 'El intento fallido debe quedar en mail_outbox');
        $this->assertSame('fallo', $row['estado']);
        $this->assertSame('smtp', $row['transport']);
        $this->assertSame('welcome', $row['kind']);
        $this->assertNotEmpty($row['error']);
    }

    // ------------------------------------------------------------------
    // VALIDACIONES (sin SMTP / destino invalido): 400 y sin registro
    // ------------------------------------------------------------------

    public function testSinSmtpConfiguradoDevuelve400SinRegistrar(): void
    {
        putenv('MAIL_TRANSPORT=outbox');
        $tenantId = $this->createTenant('nosmtp', []);
        $destino = 'nosmtp-' . substr($this->uuid(), 0, 8) . '@test.local';

        try {
            $this->mailer->sendCustom($tenantId, $destino, 'x', '<p>x</p>', 'reset');
            $this->fail('Sin SMTP configurado el envio debe rechazarse');
        } catch (TenantException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('tenants.smtp_not_configured', $e->getMessageKey());
        }

        $this->assertFalse($this->lastOutboxFor($destino), 'Un rechazo de validacion no genera registro');
    }

    public function testDestinoInvalidoDevuelve400SinRegistrar(): void
    {
        putenv('MAIL_TRANSPORT=outbox');
        $tenantId = $this->createTenant('badmail', self::SMTP_OK);

        try {
            $this->mailer->sendCustom($tenantId, 'no-es-un-email', 'x', '<p>x</p>', 'reset');
            $this->fail('Un destino invalido debe rechazarse');
        } catch (TenantException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('tenants.smtp_no_destino', $e->getMessageKey());
        }

        $this->assertFalse($this->lastOutboxFor('no-es-un-email'), 'Un rechazo de validacion no genera registro');
    }

    // ------------------------------------------------------------------
    // HELPERS
    // ------------------------------------------------------------------

    /** Crea un tenant de prueba con las columnas smtp_* indicadas (o ninguna). */
    private function createTenant(string $tag, array $smtp): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO tenants (id, nombre_publico, nombre_interno, estado, smtp_host, smtp_puerto,
                                  smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name,
                                  created_at, updated_at)
             VALUES (?, ?, ?, \'ACTIVO\', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $id,
            'MailerOutbox ' . $tag . ' ' . substr($id, 0, 6),
            'mailer-outbox-' . $tag . '-' . substr($id, 0, 6),
            $smtp['smtp_host'] ?? null,
            $smtp['smtp_puerto'] ?? null,
            $smtp['smtp_encryption'] ?? null,
            null,
            null,
            $smtp['smtp_from_email'] ?? null,
            null,
        ]);

        $this->createdTenants[] = $id;

        return $id;
    }

    private function lastOutboxFor(string $destino): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT destino, asunto, html, kind, transport, estado, error
             FROM mail_outbox WHERE destino = :d ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['d' => $destino]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : false;
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

        // La tabla existe desde el esquema v1.18.0; se crea por si acaso.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_outbox (
                id CHAR(36) NOT NULL,
                tenant_id CHAR(36) NOT NULL,
                destino VARCHAR(255) NOT NULL,
                asunto VARCHAR(255) NOT NULL,
                html MEDIUMTEXT NOT NULL,
                kind VARCHAR(32) NOT NULL DEFAULT \'general\',
                transport ENUM(\'smtp\',\'outbox\') NOT NULL DEFAULT \'smtp\',
                estado ENUM(\'enviado\',\'fallo\') NOT NULL DEFAULT \'enviado\',
                error VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_mo_tenant (tenant_id, created_at),
                CONSTRAINT fk_mo_tenant_test FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

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
