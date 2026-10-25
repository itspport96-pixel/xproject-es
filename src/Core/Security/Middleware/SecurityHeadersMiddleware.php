<?php
declare(strict_types=1);

namespace App\Core\Security\Middleware;

/**
 * SecurityHeadersMiddleware - Aplica headers HTTP de seguridad en cada response.
 *
 * Responsabilidades:
 * - Prevenir clickjacking (X-Frame-Options)
 * - Prevenir MIME-sniffing (X-Content-Type-Options)
 * - Forzar HTTPS (Strict-Transport-Security)
 * - Controlar referrer (Referrer-Policy)
 * - Restringir features del browser (Permissions-Policy)
 * - Content Security Policy (CSP) configurable
 * - Cross-Origin protection (COOP, CORP)
 * - Eliminar headers que fugan información (X-Powered-By, Server)
 *
 * Configuración vía .env:
 *   SECURITY_CSP_ENABLED=true
 *   SECURITY_HSTS_ENABLED=true
 *   SECURITY_HSTS_MAX_AGE=31536000
 *   SECURITY_FRAME_OPTIONS=SAMEORIGIN
 *   SECURITY_REFERRER_POLICY=strict-origin-when-cross-origin
 *   SECURITY_CSP_DIRECTIVES="default-src 'self'; script-src 'self'"
 *
 * Nonce (endurecimiento v1.18.x): el entry point genera un nonce por request
 * y lo pasa al constructor; el middleware lo apende a script-src y el HTML
 * inline lo repite en <script nonce="...">. Así script-src no necesita
 * 'unsafe-inline' (inyección de scripts bloqueada; los scripts del shell
 * siguen funcionando).
 */
final class SecurityHeadersMiddleware
{
    private bool $cspEnabled;
    private bool $hstsEnabled;
    private int $hstsMaxAge;
    private string $frameOptions;
    private string $referrerPolicy;
    private string $cspDirectives;
    private ?string $cspNonce;

    public function __construct(?string $cspNonce = null)
    {
        $this->cspNonce = ($cspNonce !== null && $cspNonce !== '') ? $cspNonce : null;
        $this->cspEnabled = filter_var(
            $_ENV['SECURITY_CSP_ENABLED'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );
        $this->hstsEnabled = filter_var(
            $_ENV['SECURITY_HSTS_ENABLED'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );
        $this->hstsMaxAge = (int) ($_ENV['SECURITY_HSTS_MAX_AGE'] ?? 31536000);
        $this->frameOptions = strtoupper(
            (string) ($_ENV['SECURITY_FRAME_OPTIONS'] ?? 'SAMEORIGIN')
        );
        $this->referrerPolicy = (string) (
            $_ENV['SECURITY_REFERRER_POLICY'] ?? 'strict-origin-when-cross-origin'
        );
        $this->cspDirectives = (string) (
            $_ENV['SECURITY_CSP_DIRECTIVES']
            ?? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
        );
    }

    /**
     * Aplica headers de seguridad antes de enviar la response.
     *
     * @param array $request Request actual (no se modifica)
     * @return array Request sin cambios (headers se envían directamente)
     */
    public function handle(array $request): array
    {
        // Solo aplicar en contexto HTTP (no CLI)
        if (PHP_SAPI === 'cli') {
            return $request;
        }

        // Headers ya enviados → no hacer nada
        if (headers_sent()) {
            return $request;
        }

        $this->applyCoreHeaders();
        $this->applyCrossOriginHeaders();
        $this->applyPermissionsPolicy();

        if ($this->hstsEnabled) {
            $this->applyHsts();
        }

        if ($this->cspEnabled) {
            $this->applyCsp();
        }

        return $request;
    }

    /**
     * Headers core de seguridad (siempre aplicados).
     */
    private function applyCoreHeaders(): void
    {
        // Prevenir MIME-sniffing
        header('X-Content-Type-Options: nosniff');

        // Prevenir clickjacking
        if (in_array($this->frameOptions, ['DENY', 'SAMEORIGIN'], true)) {
            header("X-Frame-Options: {$this->frameOptions}");
        }

        // Control de referrer
        header("Referrer-Policy: {$this->referrerPolicy}");

        // Prevenir XSS legacy (browsers antiguos)
        header('X-XSS-Protection: 1; mode=block');

        // Prevenir policies cross-domain legacy
        header('X-Permitted-Cross-Domain-Policies: none');

        // Eliminar headers que fugan información
        header_remove('X-Powered-By');
        header_remove('Server');
    }

    /**
     * Headers Cross-Origin (protección adicional).
     */
    private function applyCrossOriginHeaders(): void
    {
        // COOP: aísla el browsing context
        header('Cross-Origin-Opener-Policy: same-origin');

        // CORP: restringe recursos a mismo origen
        header('Cross-Origin-Resource-Policy: same-origin');

        // Nota: COEP (require-corp) se omite por compatibilidad con navegadores
        // antiguos; ya no hay recursos CDN externos (Tailwind y Alpine se
        // vendorizaron), asi que activarlo solo depende de la politica de la app.
    }

    /**
     * Permissions-Policy: restringe features del browser.
     */
    private function applyPermissionsPolicy(): void
    {
        // Deshabilitar features sensibles no utilizadas
        header(
            'Permissions-Policy: ' . implode(', ', [
                'camera=()',
                'microphone=()',
                'geolocation=()',
                'payment=()',
                'usb=()',
                'bluetooth=()',
                'interest-cohort=()',
            ])
        );
    }

    /**
     * Strict-Transport-Security (HSTS).
     * Fuerza HTTPS en futuras peticiones.
     */
    private function applyHsts(): void
    {
        // Solo aplicar si la request es HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80)) === 443
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        if ($isHttps) {
            $maxAge = $this->hstsMaxAge > 0 ? $this->hstsMaxAge : 31536000;
            header("Strict-Transport-Security: max-age={$maxAge}; includeSubDomains");
        }
    }

    /**
     * Content Security Policy (CSP).
     * Controla qué recursos puede cargar la página.
     * Si hay nonce por request se apende a script-src.
     */
    private function applyCsp(): void
    {
        $directives = $this->buildCspDirectives();
        if ($directives === '') {
            return;
        }

        header("Content-Security-Policy: {$directives}");
    }

    /**
     * Directives CSP con el nonce (si lo hay) ya inyectado en script-src.
     */
    private function buildCspDirectives(): string
    {
        $directives = $this->cspDirectives;
        if ($directives === '' || $this->cspNonce === null) {
            return $directives;
        }

        $nonceToken = "'nonce-{$this->cspNonce}'";
        if (preg_match('/script-src\s[^;]+/', $directives, $m)) {
            if (!str_contains($m[0], $nonceToken)) {
                $directives = str_replace($m[0], $m[0] . ' ' . $nonceToken, $directives);
            }

            return $directives;
        }

        return $directives . "; script-src 'self' {$nonceToken}";
    }

    /**
     * Retorna los headers que aplicarían (útil para tests/debug).
     *
     * @return array<string, string>
     */
    public function getHeadersToApply(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => $this->frameOptions,
            'Referrer-Policy' => $this->referrerPolicy,
            'X-XSS-Protection' => '1; mode=block',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($this->hstsEnabled) {
            $maxAge = $this->hstsMaxAge > 0 ? $this->hstsMaxAge : 31536000;
            $headers['Strict-Transport-Security'] = "max-age={$maxAge}; includeSubDomains";
        }

        if ($this->cspEnabled) {
            $csp = $this->buildCspDirectives();
            if ($csp !== '') {
                $headers['Content-Security-Policy'] = $csp;
            }
        }

        return $headers;
    }
}
