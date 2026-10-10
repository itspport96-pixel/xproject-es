<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Shared\Exceptions\SecurityException;
use App\Shared\Services\RedisService;

/**
 * CsrfToken - Generación y validación de tokens CSRF.
 *
 * Responsabilidades:
 * - Generar tokens CSRF únicos por sesión/usuario
 * - Validar tokens en formularios y requests stateful
 * - Almacenar tokens en Redis con TTL configurable
 * - Soporte para múltiples tokens simultáneos (por formulario)
 *
 * Nota: En SPA con JWT Bearer, CSRF es menos crítico.
 * Este servicio está listo para cuando se usen cookies httpOnly
 * o formularios HTML clásicos.
 *
 * Configuración vía .env:
 *   CSRF_TOKEN_TTL=3600
 *   CSRF_TOKEN_LENGTH=32
 */
final class CsrfToken
{
    private RedisService $redis;
    private int $ttl;
    private int $tokenLength;
    private const KEY_PREFIX = 'csrf:';

    public function __construct(?RedisService $redis = null)
    {
        $this->redis = $redis ?? \App\Shared\Container\Container::getInstance()->get('redis');
        $this->ttl = (int) ($_ENV['CSRF_TOKEN_TTL'] ?? 3600);
        $this->tokenLength = (int) ($_ENV['CSRF_TOKEN_LENGTH'] ?? 32);
    }

    /**
     * Genera un token CSRF para un usuario/sesión.
     *
     * @param string $identifier Identificador único (user_id, session_id)
     * @param string $formName Nombre del formulario (opcional, para tokens por formulario)
     * @return string Token CSRF
     */
    public function generate(string $identifier, string $formName = 'default'): string
    {
        $token = bin2hex(random_bytes($this->tokenLength));
        $key = $this->buildKey($identifier, $formName);

        $this->redis->getClient()->setex($key, $this->ttl, $token);

        return $token;
    }

    /**
     * Valida un token CSRF.
     *
     * @param string $identifier Identificador único (user_id, session_id)
     * @param string $token Token a validar
     * @param string $formName Nombre del formulario
     * @param bool $destroyAfterUse Si true, elimina el token tras validación exitosa
     * @return bool True si el token es válido
     */
    public function validate(
        string $identifier,
        string $token,
        string $formName = 'default',
        bool $destroyAfterUse = true
    ): bool {
        if ($token === '') {
            return false;
        }

        $key = $this->buildKey($identifier, $formName);
        $storedToken = $this->redis->getClient()->get($key);

        if ($storedToken === null) {
            return false;
        }

        // Comparación constante en tiempo (previene timing attacks)
        $isValid = hash_equals((string) $storedToken, $token);

        if ($isValid && $destroyAfterUse) {
            $this->redis->getClient()->del([$key]);
        }

        return $isValid;
    }

    /**
     * Valida un token y lanza excepción si es inválido.
     *
     * @throws SecurityException Si el token es inválido
     */
    public function validateOrFail(
        string $identifier,
        string $token,
        string $formName = 'default',
        bool $destroyAfterUse = true
    ): void {
        if (!$this->validate($identifier, $token, $formName, $destroyAfterUse)) {
            throw SecurityException::invalidCsrfToken();
        }
    }

    /**
     * Invalida todos los tokens de un usuario/sesión.
     *
     * @param string $identifier Identificador único
     * @return int Número de tokens eliminados
     */
    public function invalidateAll(string $identifier): int
    {
        $pattern = self::KEY_PREFIX . $identifier . ':*';
        $client = $this->redis->getClient();
        $count = 0;

        // Usar SCAN para no bloquear Redis con KEYS
        $iterator = null;
        do {
            $keys = $client->scan($iterator, ['MATCH' => $pattern, 'COUNT' => 100]);
            if ($keys !== false && !empty($keys)) {
                $client->del($keys);
                $count += count($keys);
            }
        } while ($iterator > 0);

        return $count;
    }

    /**
     * Invalida un token específico.
     *
     * @param string $identifier Identificador único
     * @param string $formName Nombre del formulario
     * @return bool True si se eliminó
     */
    public function invalidate(string $identifier, string $formName = 'default'): bool
    {
        $key = $this->buildKey($identifier, $formName);
        return (bool) $this->redis->getClient()->del([$key]);
    }

    /**
     * Verifica si existe un token activo para un formulario.
     *
     * @param string $identifier Identificador único
     * @param string $formName Nombre del formulario
     * @return bool True si existe un token activo
     */
    public function hasActiveToken(string $identifier, string $formName = 'default'): bool
    {
        $key = $this->buildKey($identifier, $formName);
        return (bool) $this->redis->getClient()->exists($key);
    }

    /**
     * Obtiene el TTL restante de un token.
     *
     * @param string $identifier Identificador único
     * @param string $formName Nombre del formulario
     * @return int Segundos restantes (-2 si no existe, -1 si no tiene TTL)
     */
    public function getRemainingTtl(string $identifier, string $formName = 'default'): int
    {
        $key = $this->buildKey($identifier, $formName);
        return (int) $this->redis->getClient()->ttl($key);
    }

    /**
     * Genera un campo HTML oculto con el token CSRF.
     * Útil para formularios HTML clásicos.
     *
     * @param string $identifier Identificador único
     * @param string $formName Nombre del formulario
     * @param string $fieldName Nombre del campo input
     * @return string HTML del campo oculto
     */
    public function generateHtmlField(
        string $identifier,
        string $formName = 'default',
        string $fieldName = '_csrf_token'
    ): string {
        $token = $this->generate($identifier, $formName);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $escapedField = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');

        return "<input type=\"hidden\" name=\"{$escapedField}\" value=\"{$escapedToken}\">";
    }

    /**
     * Genera un meta tag HTML con el token CSRF.
     * Útil para SPA que leen el token desde el DOM.
     *
     * @param string $identifier Identificador único
     * @param string $formName Nombre del formulario
     * @return string HTML del meta tag
     */
    public function generateMetaTag(
        string $identifier,
        string $formName = 'default'
    ): string {
        $token = $this->generate($identifier, $formName);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "<meta name=\"csrf-token\" content=\"{$escapedToken}\">";
    }

    /**
     * Extrae el token CSRF de un request.
     * Busca en: header X-CSRF-Token, body _csrf_token, query _csrf_token.
     *
     * @return string Token extraído o string vacío
     */
    public function extractTokenFromRequest(): string
    {
        // 1. Header (preferido para AJAX/SPA)
        $header = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';
        if ($header !== '') {
            return trim($header);
        }

        // 2. Body (formularios)
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json['_csrf_token'])) {
            return trim((string) $json['_csrf_token']);
        }

        if (!empty($_POST['_csrf_token'])) {
            return trim((string) $_POST['_csrf_token']);
        }

        // 3. Query string
        if (!empty($_GET['_csrf_token'])) {
            return trim((string) $_GET['_csrf_token']);
        }

        return '';
    }

    /**
     * Construye la clave Redis para un token.
     */
    private function buildKey(string $identifier, string $formName): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier) ?? $identifier;
        $safeForm = preg_replace('/[^a-zA-Z0-9_-]/', '_', $formName) ?? $formName;

        return self::KEY_PREFIX . $safeId . ':' . $safeForm;
    }
}
