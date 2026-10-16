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
 * transaccionales del SSPR (sendCustom: enlace de reset y aviso de cambio).
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

        $this->sendViaSmtp($tenant, $smtp, $destino, 'Prueba SMTP - ' . $nombrePublico,
            '<p>Correo de prueba enviado desde la configuración de <strong>'
            . htmlspecialchars($nombrePublico, ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>');

        return $destino;
    }

    /**
     * Envía un correo arbitrario por el SMTP del tenant (v1.17.0, SSPR).
     *
     * Valida tenant y destino y delega en el transporte común.
     *
     * @throws TenantException Si no hay SMTP configurado o el envío falla
     */
    public function sendCustom(string $tenantId, string $destino, string $asunto, string $htmlBody): void
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

        $this->sendViaSmtp($tenant, $smtp, $destino, $asunto, $htmlBody);
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
