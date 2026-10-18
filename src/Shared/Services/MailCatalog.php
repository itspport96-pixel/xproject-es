<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Container\Container;

/**
 * Catalogo de textos de correo por idioma (v1.18.x, deuda "los correos
 * solo existen en espanol").
 *
 * Los cuatro correos transaccionales (reset SSPR, reset administrativo,
 * bienvenida y cambio de contrasena) y el correo de prueba SMTP se componen
 * aqui: asunto + cuerpo HTML con las claves de texto traducidas a
 * es/en/pt/fr. El idioma se toma de `users.preferencias` (JSON, clave
 * `idioma`, whitelist igual a UserService::PREFERENCIAS_IDIOMA) del
 * destinatario; si el usuario no existe o no ha elegido idioma, se usa
 * espanol (idioma por defecto de la plataforma).
 */
final class MailCatalog
{
    /** Idiomas soportados por la plataforma (misma whitelist que preferencias). */
    public const IDIOMAS = ['es', 'en', 'pt', 'fr'];

    /**
     * Textos por kind e idioma.
     *
     * Claves: asunto, hola (saludo con {nombre}), intro (párrafo con {ttl}
     * donde aplique), cta (texto del enlace) y cierra (párrafo final).
     * 'changed' no tiene cta (no hay enlace).
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const TEXTS = [
        'reset' => [
            'es' => [
                'asunto' => 'Restablecer tu contrasena',
                'hola' => 'Hola {nombre}:',
                'intro' => 'Recibimos una solicitud para restablecer tu contrasena. Haz clic en el siguiente enlace (válido durante {ttl} minutos y de un solo uso):',
                'cta' => 'Restablecer mi contrasena',
                'cierra' => 'Si no fuiste tu, ignora este correo; tu contrasena no cambia.',
            ],
            'en' => [
                'asunto' => 'Reset your password',
                'hola' => 'Hello {nombre}:',
                'intro' => 'We received a request to reset your password. Click the following link (valid for {ttl} minutes and single use):',
                'cta' => 'Reset my password',
                'cierra' => 'If this was not you, ignore this email; your password has not changed.',
            ],
            'pt' => [
                'asunto' => 'Redefinir sua senha',
                'hola' => 'Olá {nombre}:',
                'intro' => 'Recebemos uma solicitação para redefinir sua senha. Clique no seguinte link (válido por {ttl} minutos e de uso único):',
                'cta' => 'Redefinir minha senha',
                'cierra' => 'Se não foi você, ignore este email; sua senha não mudou.',
            ],
            'fr' => [
                'asunto' => 'Réinitialiser votre mot de passe',
                'hola' => 'Bonjour {nombre} :',
                'intro' => 'Nous avons reçu une demande de réinitialisation de votre mot de passe. Cliquez sur le lien suivant (valable {ttl} minutes et à usage unique) :',
                'cta' => 'Réinitialiser mon mot de passe',
                'cierra' => 'Si ce n’était pas vous, ignorez cet email ; votre mot de passe n’a pas changé.',
            ],
        ],
        'admin' => [
            'es' => [
                'asunto' => 'Restablecer tu contrasena',
                'hola' => 'Hola {nombre}:',
                'intro' => 'Un administrador de la plataforma solicito restablecer tu contrasena. Para elegir una nueva, haz clic en el siguiente enlace (valido durante {ttl} minutos y de un solo uso):',
                'cta' => 'Restablecer mi contrasena',
                'cierra' => 'Tu contrasena anterior dejo de ser valida. Si no fuiste tu, contacta de inmediato con un administrador.',
            ],
            'en' => [
                'asunto' => 'Reset your password',
                'hola' => 'Hello {nombre}:',
                'intro' => 'A platform administrator requested a password reset. To choose a new one, click the following link (valid for {ttl} minutes and single use):',
                'cta' => 'Reset my password',
                'cierra' => 'Your previous password is no longer valid. If this was not you, contact an administrator immediately.',
            ],
            'pt' => [
                'asunto' => 'Redefinir sua senha',
                'hola' => 'Olá {nombre}:',
                'intro' => 'Um administrador da plataforma solicitou a redefinição da sua senha. Para escolher uma nova, clique no seguinte link (válido por {ttl} minutos e de uso único):',
                'cta' => 'Redefinir minha senha',
                'cierra' => 'Sua senha anterior deixou de ser válida. Se não foi você, contate um administrador imediatamente.',
            ],
            'fr' => [
                'asunto' => 'Réinitialiser votre mot de passe',
                'hola' => 'Bonjour {nombre} :',
                'intro' => 'Un administrateur de la plateforme a demandé la réinitialisation de votre mot de passe. Pour en choisir un nouveau, cliquez sur le lien suivant (valable {ttl} minutes et à usage unique) :',
                'cta' => 'Réinitialiser mon mot de passe',
                'cierra' => 'Votre ancien mot de passe n’est plus valide. Si ce n’était pas vous, contactez immédiatement un administrateur.',
            ],
        ],
        'welcome' => [
            'es' => [
                'asunto' => 'Bienvenido a la plataforma',
                'hola' => 'Hola {nombre}:',
                'intro' => 'Tu cuenta fue creada en la plataforma. Para elegir tu contrasena, haz clic en el siguiente enlace (válido durante {ttl} horas y de un solo uso):',
                'cta' => 'Establecer mi contrasena',
                'cierra' => 'Si no esperabas este correo, contacta con un administrador.',
            ],
            'en' => [
                'asunto' => 'Welcome to the platform',
                'hola' => 'Hello {nombre}:',
                'intro' => 'Your account was created on the platform. To choose your password, click the following link (valid for {ttl} hours and single use):',
                'cta' => 'Set my password',
                'cierra' => 'If you were not expecting this email, contact an administrator.',
            ],
            'pt' => [
                'asunto' => 'Bem-vindo à plataforma',
                'hola' => 'Olá {nombre}:',
                'intro' => 'Sua conta foi criada na plataforma. Para escolher sua senha, clique no seguinte link (válido por {ttl} horas e de uso único):',
                'cta' => 'Definir minha senha',
                'cierra' => 'Se não esperava este email, contate um administrador.',
            ],
            'fr' => [
                'asunto' => 'Bienvenue sur la plateforme',
                'hola' => 'Bonjour {nombre} :',
                'intro' => 'Votre compte a été créé sur la plateforme. Pour choisir votre mot de passe, cliquez sur le lien suivant (valable {ttl} heures et à usage unique) :',
                'cta' => 'Définir mon mot de passe',
                'cierra' => 'Si vous n’attendiez pas cet email, contactez un administrateur.',
            ],
        ],
        'changed' => [
            'es' => [
                'asunto' => 'Tu contrasena fue restablecida',
                'hola' => 'Hola {nombre}:',
                'intro' => 'Tu contrasena fue restablecida correctamente. Si no fuiste tu, contacta de inmediato con un administrador.',
                'cta' => '',
                'cierra' => '',
            ],
            'en' => [
                'asunto' => 'Your password was reset',
                'hola' => 'Hello {nombre}:',
                'intro' => 'Your password was reset successfully. If this was not you, contact an administrator immediately.',
                'cta' => '',
                'cierra' => '',
            ],
            'pt' => [
                'asunto' => 'Sua senha foi redefinida',
                'hola' => 'Olá {nombre}:',
                'intro' => 'Sua senha foi redefinida com sucesso. Se não foi você, contate um administrador imediatamente.',
                'cta' => '',
                'cierra' => '',
            ],
            'fr' => [
                'asunto' => 'Votre mot de passe a été réinitialisé',
                'hola' => 'Bonjour {nombre} :',
                'intro' => 'Votre mot de passe a été réinitialisé avec succès. Si ce n’était pas vous, contactez immédiatement un administrateur.',
                'cta' => '',
                'cierra' => '',
            ],
        ],
        'test' => [
            'es' => [
                'asunto' => 'Prueba SMTP - {tenant}',
                'hola' => '',
                'intro' => 'Correo de prueba enviado desde la configuración de {tenant}.',
                'cta' => '',
                'cierra' => '',
            ],
            'en' => [
                'asunto' => 'SMTP test - {tenant}',
                'hola' => '',
                'intro' => 'Test email sent from the configuration of {tenant}.',
                'cta' => '',
                'cierra' => '',
            ],
            'pt' => [
                'asunto' => 'Teste SMTP - {tenant}',
                'hola' => '',
                'intro' => 'Email de teste enviado a partir da configuração de {tenant}.',
                'cta' => '',
                'cierra' => '',
            ],
            'fr' => [
                'asunto' => 'Test SMTP - {tenant}',
                'hola' => '',
                'intro' => 'Email de test envoyé depuis la configuration de {tenant}.',
                'cta' => '',
                'cierra' => '',
            ],
        ],
    ];

    /**
     * Compone asunto y cuerpo HTML de un correo en el idioma del destinatario.
     *
     * @param string $kind    'reset' | 'admin' | 'welcome' | 'changed' | 'test'
     * @param string $destino Email del destinatario (para resolver el idioma)
     * @param string $nombre  Nombre visible en el saludo (o tenant en 'test')
     * @param array<string, string> $vars Placeholders extra ({ttl} y demas)
     * @return array{asunto: string, html: string}
     */
    public function compose(string $kind, string $destino, string $nombre, array $vars = []): array
    {
        return $this->composeEn($this->idiomaDelDestino($destino), $kind, $nombre, $vars);
    }

    /**
     * Compone el correo en un idioma explicito (sin consultar el destinatario).
     * Util para pruebas y para cuando el idioma ya se resolvio.
     *
     * @param string $idioma 'es' | 'en' | 'pt' | 'fr'
     * @param array<string, string> $vars Placeholders extra ({ttl}, {url}, ...)
     * @return array{asunto: string, html: string}
     */
    public function composeEn(string $idioma, string $kind, string $nombre, array $vars = []): array
    {
        $texts = self::TEXTS[$kind][$idioma] ?? self::TEXTS[$kind]['es'];

        $vars = $vars + [
            'nombre' => $nombre,
            'tenant' => $nombre,
        ];

        $saludo = $this->fill($texts['hola'] ?? '', $vars);

        $html = '';
        if ($saludo !== '') {
            $html .= '<p>' . htmlspecialchars($saludo, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $intro = $this->fill($texts['intro'] ?? '', $vars);
        if ($intro !== '') {
            $html .= '<p>' . $this->esc($intro) . '</p>';
        }

        if (($texts['cta'] ?? '') !== '') {
            $url = htmlspecialchars((string) ($vars['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<p><a href="' . $url . '">' . htmlspecialchars($texts['cta'], ENT_QUOTES, 'UTF-8') . '</a></p>';
        }

        if (($texts['cierra'] ?? '') !== '') {
            $html .= '<p>' . $this->esc($texts['cierra']) . '</p>';
        }

        return [
            'asunto' => $this->fill($texts['asunto'] ?? '', $vars),
            'html' => $html,
        ];
    }

    /** Idioma del destinatario desde users.preferencias; 'es' si no hay. */
    public function idiomaDelDestino(string $destino): string
    {
        try {
            $container = Container::getInstance();
            if (! $container->has('database')) {
                return 'es';
            }
            $db = $container->get('database');
            $stmt = $db->getConnection()->prepare(
                'SELECT preferencias FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute(['email' => $destino]);
            $json = $stmt->fetchColumn();

            if ($json === false || $json === null || $json === '') {
                return 'es';
            }

            $prefs = json_decode((string) $json, true);
            $idioma = is_array($prefs) ? (string) ($prefs['idioma'] ?? '') : '';

            return in_array($idioma, self::IDIOMAS, true) ? $idioma : 'es';
        } catch (\Throwable) {
            return 'es';
        }
    }

    /** Sustituye los placeholders {clave} por su valor (los no cubiertos quedan). */
    private function fill(string $text, array $vars): string
    {
        foreach ($vars as $clave => $valor) {
            $text = str_replace('{' . $clave . '}', (string) $valor, $text);
        }

        return $text;
    }

    /** Escapa texto de parrafo ya con placeholders rellenados. */
    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
