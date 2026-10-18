<?php

declare(strict_types=1);

namespace App\Core\Tenants\Service;

use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envío de correos del tenant por su propio SMTP (v1.14.1, refact. v1.14.2,
 * ampliado v1.17.0 para el Password Reset SSPR).
 *
 * Usa PHPMailer (composer) con las columnas `smtp_*` del tenant (host, puerto,
 * encryption, username, password cifrada, from_email, from_name). La password
 * se descifra aquí con EncryptionService; nunca viaja en claro por la API
 * (Tenant::toPublicArray la enmascara).
 *
 * Usos: correo de prueba del formulario de settings (sendTest) y correos
 * transaccionales del SSPR, reset administrativo y bienvenida (sendCustom).
 *
 * Transporte (v1.18.0): variable MAIL_TRANSPORT, por este orden de prioridad
 *  - 'smtp'   (defecto): envio real por PHPMailer con el SMTP del tenant.
 *  - 'outbox': el correo NO sale; se guarda completo en la tabla mail_outbox
 *    (estado 'enviado', transport 'outbox'). Usado por la suite E2E para
 *    extraer la liga de reset sin enviar correos reales, y como historial de
 *    evidencias. Un valor desconocido se trata como 'smtp'.
 * En modo 'smtp' tambien se registra cada intento en mail_outbox (enviado o
 * fallo) salvo que el propio registro falle: un fallo del log nunca deshace
 * un envio ya realizado.
 */
final class MailerService
{
    private TenantService $tenants;

    public function __construct(?TenantService $tenants = null)
    {
        $this->tenants = $tenants ?? \App\Shared\Container\Container::getInstance()->get(TenantService::class);
    }

    /**
     * Envía un correo de prueba usando el SMTP configurado del tenant.
     *
     * @param string $tenantId Tenant cuyo SMTP se usa
     * @param string $destino  Email de destino (por defecto el contacto del tenant)
     * @throws TenantException Si no hay SMTP configurado o el envío falla
     */
    public function sendTest(string $tenantId, ?string $destino = null): string
    {
        /** @var Tenant|null $tenant Entidad cruda (password SMTP sin enmascarar). */
        $tenant = $this->tenants->findEntity($tenantId);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($tenantId);
        }

        $config = $tenant->configuracion() ?? [];
        $smtp = $config['smtp'] ?? null;
        if (!is_array($smtp) || empty($smtp['host'])) {
            throw (new TenantException('El tenant no tiene SMTP configurado.', 400))
                ->withMessageKey('tenants.smtp_not_configured');
        }

        $destino = $destino !== null && $destino !== ''
            ? trim($destino)
            : (string) ($tenant->emailContacto() ?? '');
        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            throw (new TenantException('No hay un email de destino válido.', 400))
                ->withMessageKey('tenants.smtp_no_destino');
        }

        $nombrePublico = (string) $tenant->nombrePublico();

        $this->deliver($tenant, $smtp, $destino, 'Prueba SMTP - ' . $nombrePublico,
            '<p>Correo de prueba enviado desde la configuración de <strong>'
            . htmlspecialchars($nombrePublico, ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>', 'test');

        return $destino;
    }

    /**
     * Envía un correo arbitrario por el SMTP del tenant (v1.17.0, SSPR;
     * v1.18.0 ampliado a reset administrativo y bienvenida).
     *
     * Valida tenant y destino y delega en el transporte común.
     *
     * @param string $kind Etiqueta de contexto para mail_outbox ('reset',
     *        'welcome', 'changed', 'test', ...); 'general' por defecto
     * @throws TenantException Si no hay SMTP configurado o el envío falla
     */
    public function sendCustom(string $tenantId, string $destino, string $asunto, string $htmlBody, string $kind = 'general'): void
    {
        $destino = trim($destino);
        $tenant = $this->assertSendable($tenantId, $destino);

        $this->deliver($tenant, $tenant->configuracion()['smtp'] ?? [], $destino, $asunto, $htmlBody, $kind);
    }

    /**
     * Prevalida que el tenant puede enviar a ese destino (v1.18.0).
     *
     * Para las fases previas a mutar nada (reset administrativo y alta):
     * devuelve 400 si el tenant no tiene SMTP configurado o si el destino no
     * es un email válido, sin intentar envío alguno.
     *
     * @return Tenant entidad cruda (password SMTP sin enmascarar)
     * @throws TenantException 404 tenant inexistente / 400 SMTP o destino
     */
    public function assertSendable(string $tenantId, string $destino): Tenant
    {
        /** @var Tenant|null $tenant Entidad cruda (password SMTP sin enmascarar). */
        $tenant = $this->tenants->findEntity($tenantId);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($tenantId);
        }

        $config = $tenant->configuracion() ?? [];
        $smtp = $config['smtp'] ?? null;
        if (!is_array($smtp) || empty($smtp['host'])) {
            throw (new TenantException('El tenant no tiene SMTP configurado.', 400))
                ->withMessageKey('tenants.smtp_not_configured');
        }

        $destino = trim($destino);
        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            throw (new TenantException('No hay un email de destino válido.', 400))
                ->withMessageKey('tenants.smtp_no_destino');
        }

        return $tenant;
    }

    /**
     * Transporte único de salida (v1.18.0): outbox (registro en mail_outbox)
     * o SMTP real, registrando siempre el intento.
     *
     * @param array<string, mixed> $smtp Config `smtp_*` de la entidad tenant
     * @throws TenantException Si el envío falla (o el registro en modo outbox)
     */
    private function deliver(Tenant $tenant, array $smtp, string $destino, string $asunto, string $htmlBody, string $kind): void
    {
        if ($this->transport() === 'outbox') {
            try {
                $this->recordOutbox($tenant, $destino, $asunto, $htmlBody, $kind, 'outbox', 'enviado', null);
            } catch (\Throwable $e) {
                throw (new TenantException('Fallo el envío SMTP: ' . $e->getMessage(), 502, $e))
                    ->withMessageKey('tenants.smtp_send_failed');
            }
            return;
        }

        try {
            $this->sendViaSmtp($tenant, $smtp, $destino, $asunto, $htmlBody);
        } catch (\Throwable $e) {
            // El intento fallido queda en mail_outbox; si el registro no
            // puede escribirse se conserva el error original de envio.
            try {
                $this->recordOutbox($tenant, $destino, $asunto, $htmlBody, $kind, 'smtp', 'fallo', $e->getMessage());
            } catch (\Throwable) {
            }
            throw $e;
        }

        // Envio realizado: el registro es best-effort (nunca un 502 tardio).
        try {
            $this->recordOutbox($tenant, $destino, $asunto, $htmlBody, $kind, 'smtp', 'enviado', null);
        } catch (\Throwable) {
        }
    }

    /** Prioridad: variable de entorno real (getenv) > .env ($_ENV) > 'smtp'. */
    private function transport(): string
    {
        $transport = getenv('MAIL_TRANSPORT');
        if ($transport === false || $transport === '') {
            $transport = $_ENV['MAIL_TRANSPORT'] ?? 'smtp';
        }
        return $transport === 'outbox' ? 'outbox' : 'smtp';
    }

    /**
     * Registra el correo (o su fallo) en mail_outbox: historial de evidencias
     * de los envios transaccionales.
     *
     * @throws \Throwable Si la fila no puede guardarse (el llamante decide)
     */
    private function recordOutbox(Tenant $tenant, string $destino, string $asunto, string $htmlBody, string $kind, string $transport, string $estado, ?string $error): void
    {
        $db = \App\Shared\Container\Container::getInstance()->get('database');
        $stmt = $db->getConnection()->prepare(
            'INSERT INTO mail_outbox (id, tenant_id, destino, asunto, html, kind, transport, estado, error)
             VALUES (:id, :tenant_id, :destino, :asunto, :html, :kind, :transport, :estado, :error)'
        );
        $stmt->execute([
            'id'         => $this->generateUuid(),
            'tenant_id'  => (string) $tenant->id(),
            'destino'    => mb_substr($destino, 0, 255),
            'asunto'     => mb_substr($asunto, 0, 255),
            'html'       => $htmlBody,
            'kind'       => mb_substr($kind, 0, 32),
            'transport'  => $transport,
            'estado'     => $estado,
            'error'      => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Transporte único PHPMailer para el SMTP del tenant (v1.17.0).
     *
     * @param array<string, mixed> $smtp Config `smtp_*` de la entidad tenant
     * @throws TenantException Si el envío falla
     */
    private function sendViaSmtp(Tenant $tenant, array $smtp, string $destino, string $asunto, string $htmlBody): void
    {
        $password = null;
        if (!empty($smtp['password'])) {
            $container = \App\Shared\Container\Container::getInstance();
            /** @var \App\Core\Security\Service\EncryptionService $encryption */
            $encryption = $container->get(\App\Core\Security\Service\EncryptionService::class);
            $password = $encryption->decryptFromStorage((string) $smtp['password']);
        }

        $nombrePublico = (string) $tenant->nombrePublico();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $smtp['host'];
            $mail->Port = (int) ($smtp['puerto'] ?? 587);
            $enc = (string) ($smtp['encryption'] ?? 'tls');
            $mail->SMTPSecure = match ($enc) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            if (!empty($smtp['username'])) {
                $mail->SMTPAuth = true;
                $mail->Username = (string) $smtp['username'];
                // App Password de Gmail: se pega con espacios de grupo
                // (`xxxx xxxx xxxx xxxx`) pero Gmail la exige SIN espacios en
                // AUTH LOGIN; se quitan antes de autenticar (v1.14.2).
                $mail->Password = $password !== null
                    ? (string) preg_replace('/\s+/', '', $password)
                    : '';
            }
            $from = (string) ($smtp['from_email'] ?? $smtp['username'] ?? '');
            if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $from = $destino;
            }
            $mail->setFrom($from, (string) ($smtp['from_name'] ?? $nombrePublico));
            $mail->addAddress($destino);
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body = $htmlBody;
            $mail->send();
        } catch (\Throwable $e) {
            throw (new TenantException('Fallo el envío SMTP: ' . $e->getMessage(), 502, $e))
                ->withMessageKey('tenants.smtp_send_failed');
        }
    }
}
