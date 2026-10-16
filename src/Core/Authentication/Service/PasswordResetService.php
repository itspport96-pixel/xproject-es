<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use App\Core\Audit\AuditLogger;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Tenants\Service\MailerService;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Password Reset SSPR (v1.17.0): restablecimiento de contraseña por correo
 * transaccional.
 *
 * Garantías:
 *  - Anti-enumeración: requestReset() SIEMPRE termina igual (void) y no
 *    distingue entre email inexistente, cuenta inactiva, sin permiso o envío
 *    correcto. El controller devuelve siempre el mismo mensaje genérico.
 *  - Autorización: el usuario sólo puede usar el SSPR si tiene activo el
 *    permiso `password_reset_self_service`, concedido/revocado por
 *    administradores. El permiso se re-verifica TAMBIÉN al fijar la nueva
 *    contraseña (un token emitido deja de servir si se revoca antes de usarlo).
 *  - Token: criptográficamente seguro (random_bytes(32) en hex), almacenado
 *    SOLO como hash SHA-256, de un solo uso y con caducidad de 15 minutos.
 *  - Consumo atómico: el token se invalida con UPDATE ... WHERE used_at IS
 *    NULL; si dos peticiones concurrentes compiten, sólo una gana.
 *  - Auditoría: se registran los eventos de seguridad SIN almacenar tokens ni
 *    contraseñas (sólo email, usuario, tenant y resultado).
 */
class PasswordResetService
{
    /** Caducidad del token de reset (minutos). */
    public const TOKEN_TTL_MINUTES = 15;

    /** Permiso que debe tener el usuario para usar el SSPR. */
    public const PERMISSION = 'password_reset_self_service';

    private ?DatabaseService $db;
    private ?AuthorizationService $authz;
    private ?UserService $users;
    private ?MailerService $mailer;
    private ?AuditLogger $audit;

    public function __construct(
        ?DatabaseService $db = null,
        ?AuthorizationService $authz = null,
        ?UserService $users = null,
        ?MailerService $mailer = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db;
        $this->authz = $authz;
        $this->users = $users;
        $this->mailer = $mailer;
        $this->audit = $audit;
    }

    // ========================================================================
    // FLUJO SSPR
    // ========================================================================

    /**
     * Paso 1 (POST /auth/forgot-password): procesa la solicitud de reset.
     *
     * Anti-enumeración: SIEMPRE devuelve void. El token sólo se crea si el
     * usuario existe, la cuenta y el tenant están activos y tiene el permiso
     * `password_reset_self_service`. Cualquier otro caso se audita y se sale
     * sin crear token ni devolver información diferencial.
     *
     * @param array<string, mixed> $context Contexto de auditoría (ip, ua, ...)
     */
    public function requestReset(string $email, array $context = []): void
    {
        $tenantId = $this->deduceTenantIdFromEmail($email);
        if ($tenantId === null) {
            $this->auditRequest($context, null, null, $email, 'FAILURE', 'Email no encontrado');
            return;
        }

        $user = $this->findUserByEmail($email, $tenantId);
        if ($user === null) {
            $this->auditRequest($context, $tenantId, null, $email, 'FAILURE', 'Usuario no encontrado');
            return;
        }

        $userId = (string) $user['id'];
        if (!$this->isUserActive($user) || !$this->isTenantActive($tenantId)) {
            $this->auditRequest($context, $tenantId, $userId, $email, 'FAILURE', 'Cuenta o tenant inactivo');
            return;
        }

        if (!$this->authz()->hasPermission($userId, $tenantId, self::PERMISSION)) {
            $this->auditRequest($context, $tenantId, $userId, $email, 'FAILURE', 'Sin permiso ' . self::PERMISSION);
            return;
        }

        // Un único token activo por usuario: los anteriores sin usar caducan.
        $this->invalidateActiveTokens($userId);

        $token = $this->createToken($userId, $tenantId, $context);

        try {
            $this->sendResetEmail($tenantId, (string) $user['email'], (string) ($user['nombre'] ?? ''), $token);
        } catch (\Throwable $e) {
            // El token queda creado (caduca en 15 min); el fallo SMTP se
            // audita pero no altera la respuesta genérica.
            $this->auditRequest($context, $tenantId, $userId, $email, 'FAILURE', 'Fallo de envio SMTP: ' . $e->getMessage());
            return;
        }

        $this->auditRequest($context, $tenantId, $userId, $email, 'SUCCESS', 'Token de reset generado y enviado');
    }

    /**
     * Paso 2 (POST /auth/reset-password): valida el token, re-verifica el
     * permiso y aplica la nueva contraseña.
     *
     * @param array<string, mixed> $context Contexto de auditoría
     * @return string id del usuario cuya contraseña se restableció
     * @throws UserException Si el token es inválido/caducado/ya usado, el
     *         permiso fue revocado o la contraseña incumple la política.
     */
    public function resetPassword(string $token, string $newPassword, array $context = []): string
    {
        $row = $this->findTokenByHash($this->hashToken($token));
        if ($row === null) {
            $this->auditReset($context, null, null, 'FAILURE', 'Token invalido');
            throw (new UserException(
                'El enlace de restablecimiento no es valido o ha caducado.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_INVALID'
            ))->withMessageKey('auth.reset_token_invalid');
        }

        $tenantId = (string) $row['tenant_id'];
        $userId = (string) $row['user_id'];

        if ($row['used_at'] !== null) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Token ya utilizado');
            throw (new UserException(
                'El enlace de restablecimiento ya fue utilizado.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_USED'
            ))->withMessageKey('auth.reset_token_used');
        }

        if ($row['expires_at'] < date('Y-m-d H:i:s')) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Token caducado');
            throw (new UserException(
                'El enlace de restablecimiento ha caducado.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_EXPIRED'
            ))->withMessageKey('auth.reset_token_expired');
        }

        $user = $this->findUserById($userId, $tenantId);
        if ($user === null || !$this->isUserActive($user) || !$this->isTenantActive($tenantId)) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Cuenta o tenant inactivo');
            throw (new UserException(
                'La cuenta no esta disponible.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_INVALID'
            ))->withMessageKey('auth.reset_token_invalid');
        }

        // Re-verificacion del permiso en el momento de fijar la contrasena: si
        // un administrador lo revoco despues de emitir el token, el enlace
        // deja de servir aunque el token siga vigente.
        if (!$this->authz()->hasPermission($userId, $tenantId, self::PERMISSION)) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Permiso revocado tras emitir el token');
            throw (new UserException(
                'El restablecimiento ya no esta autorizado.',
                403, null, null, UserException::CATEGORY_FORBIDDEN, 'RESET_PERMISSION_REVOKED'
            ))->withMessageKey('auth.reset_permission_revoked');
        }

        // La politica se valida ANTES de consumir el token: una contrasena
        // debil no quema el enlace (el usuario puede reintentar con la misma
        // liga). El consumo atomico de mas abajo sigue impidiendo reutilizacion.
        $this->assertPasswordPolicy($newPassword);

        // Consumo ATOMICO del token: si otra peticion concurrente ya lo uso,
        // rowCount = 0 y se rechaza (un solo uso garantizado).
        if (!$this->consumeToken((string) $row['id'])) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Token reutilizado (carrera)');
            throw (new UserException(
                'El enlace de restablecimiento ya fue utilizado.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_USED'
            ))->withMessageKey('auth.reset_token_used');
        }

        // Politica + historial + transaccion (UserService v1.16.0). Si falla
        // (p. ej. reutilizacion de una contrasena anterior) el token ya quedo
        // consumido: hay que solicitar un enlace nuevo, lo cual es correcto.
        $this->users()->changePassword($userId, $tenantId, $newPassword);

        // El reset llega desde fuera de la sesion: se revocan TODAS las
        // sesiones del usuario por seguridad.
        $this->revokeSessions($userId);

        try {
            $this->sendChangedEmail($tenantId, (string) $user['email'], (string) ($user['nombre'] ?? ''));
        } catch (\Throwable $e) {
            // El cambio ya se aplico; el fallo de notificacion no lo deshace.
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Cambio aplicado; fallo de notificacion SMTP: ' . $e->getMessage());
        }

        $this->auditReset($context, $tenantId, $userId, 'SUCCESS', 'Contrasena restablecida via SSPR');

        return $userId;
    }

    // ========================================================================
    // POLITICA (delegada, sin duplicar UserService)
    // ========================================================================

    /**
     * Valida la fortaleza ANTES de consumir el token (mismas reglas que
     * UserService / PasswordPolicy v1.16.0).
     *
     * @throws UserException Si no cumple la politica
     */
    private function assertPasswordPolicy(string $password): void
    {
        $violacion = \App\Shared\Security\PasswordPolicy::validate($password);
        if ($violacion === null) {
            return;
        }

        $mensajes = [
            'auth.password_min_length' => 'El password debe tener minimo 9 caracteres',
            'auth.password_uppercase'  => 'El password debe contener al menos una mayuscula',
            'auth.password_lowercase'  => 'El password debe contener al menos una minuscula',
            'auth.password_digit'      => 'El password debe contener al menos un numero',
            'auth.password_special'    => 'El password debe contener al menos un caracter especial',
        ];

        throw (new UserException(
            $mensajes[$violacion] ?? 'El password no cumple la politica de seguridad',
            400, null, 'password_nuevo', UserException::CATEGORY_VALIDATION
        ))->withMessageKey($violacion);
    }

    // ========================================================================
    // TOKEN
    // ========================================================================

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Genera, guarda (como hash) y devuelve el token en claro para el enlace. */
    private function createToken(string $userId, string $tenantId, array $context): string
    {
        $token = bin2hex(random_bytes(32));

        $sql = "INSERT INTO password_reset_tokens
                    (id, tenant_id, user_id, token_hash, expires_at, ip_address)
                VALUES (:id, :tenant_id, :user_id, :token_hash, :expires_at, :ip)";
        $stmt = $this->db()->getConnection()->prepare($sql);
        $stmt->execute([
            'id'         => $this->generateUuid(),
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'token_hash' => $this->hashToken($token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_TTL_MINUTES * 60),
            'ip'         => (string) ($context['ip'] ?? null),
        ]);

        return $token;
    }

    private function findTokenByHash(string $tokenHash): ?array
    {
        $stmt = $this->db()->getConnection()->prepare(
            'SELECT * FROM password_reset_tokens WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Invalida (atomicamente) el token si aún no está usado. */
    private function consumeToken(string $tokenId): bool
    {
        $stmt = $this->db()->getConnection()->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $stmt->execute(['id' => $tokenId]);
        return $stmt->rowCount() === 1;
    }

    /** Marca como usados todos los tokens activos del usuario (al emitir uno nuevo). */
    private function invalidateActiveTokens(string $userId): void
    {
        $this->db()->getConnection()->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL'
        )->execute(['uid' => $userId]);
    }

    // ========================================================================
    // USUARIO / TENANT
    // ========================================================================

    private function deduceTenantIdFromEmail(string $email): ?string
    {
        $stmt = $this->db()->getConnection()->prepare(
            'SELECT tenant_id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $tenantId = $stmt->fetchColumn();
        return $tenantId !== false ? (string) $tenantId : null;
    }

    private function findUserByEmail(string $email, string $tenantId): ?array
    {
        $stmt = $this->db()->getConnection()->prepare(
            'SELECT * FROM users WHERE email = :email AND tenant_id = :tid AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function findUserById(string $userId, string $tenantId): ?array
    {
        $stmt = $this->db()->getConnection()->prepare(
            'SELECT * FROM users WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $userId, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function isUserActive(array $user): bool
    {
        if (($user['deleted_at'] ?? null) !== null) {
            return false;
        }
        $estado = (string) ($user['estado'] ?? 'ACTIVO');
        return $estado === 'ACTIVO';
    }

    private function isTenantActive(string $tenantId): bool
    {
        $stmt = $this->db()->getConnection()->prepare(
            'SELECT estado FROM tenants WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $tenantId]);
        return $stmt->fetchColumn() === 'ACTIVO';
    }

    private function revokeSessions(string $userId): void
    {
        if (!Container::getInstance()->has(\App\Core\Authentication\Service\SessionRevokerService::class)) {
            return;
        }
        Container::getInstance()->get(SessionRevokerService::class)
            ->revokeAllForUser($userId, null, 'password_reset');
    }

    // ========================================================================
    // CORREO (sobreescribible en tests: sendMail evita el SMTP real)
    // ========================================================================

    /** @throws \Throwable Si el SMTP del tenant falla */
    protected function sendResetEmail(string $tenantId, string $destino, string $nombre, string $token): void
    {
        $url = $this->buildResetUrl($token);
        $html = '<p>Hola ' . htmlspecialchars($nombre !== '' ? $nombre : $destino, ENT_QUOTES, 'UTF-8') . ':</p>'
            . '<p>Recibimos una solicitud para restablecer tu contrasena. Haz clic en el siguiente enlace '
            . '(válido durante ' . self::TOKEN_TTL_MINUTES . ' minutos y de un solo uso):</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Restablecer mi contrasena</a></p>'
            . '<p>Si no fuiste tu, ignora este correo; tu contrasena no cambia.</p>';

        $this->sendMail($tenantId, $destino, 'Restablecer tu contrasena', $html);
    }

    /** @throws \Throwable Si el SMTP del tenant falla */
    protected function sendChangedEmail(string $tenantId, string $destino, string $nombre): void
    {
        $html = '<p>Hola ' . htmlspecialchars($nombre !== '' ? $nombre : $destino, ENT_QUOTES, 'UTF-8') . ':</p>'
            . '<p>Tu contrasena fue restablecida correctamente. Si no fuiste tu, '
            . 'contacta de inmediato con un administrador.</p>';

        $this->sendMail($tenantId, $destino, 'Tu contrasena fue restablecida', $html);
    }

    /** Punto único de envío: en tests se sustituye por un no-op. */
    protected function sendMail(string $tenantId, string $destino, string $asunto, string $html): void
    {
        $this->mailer()->sendCustom($tenantId, $destino, $asunto, $html);
    }

    private function buildResetUrl(string $token): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        return $base . '/#/reset-password?token=' . rawurlencode($token);
    }

    // ========================================================================
    // AUDITORIA (sin tokens ni contrasenas)
    // ========================================================================

    private function auditRequest(array $context, ?string $tenantId, ?string $userId, string $email, string $resultado, string $detalle): void
    {
        if ($this->auditOrNull() === null || $tenantId === null) {
            return;
        }
        $this->auditOrNull()->record([
            'tenant_id'    => $tenantId,
            'usuario_id'   => $userId,
            'operacion'    => 'auth.password.forgot',
            'modulo'       => 'auth',
            'recurso'      => 'password_reset',
            'entidad_id'   => $userId,
            'tipo_cambio'  => 'EXECUTE',
            'resultado'    => $resultado,
            'razon_fallo'  => $resultado === 'FAILURE' ? $detalle : null,
            'ip_address'   => $context['ip'] ?? null,
            'user_agent'   => $context['user_agent'] ?? null,
            'request_id'   => $context['request_id'] ?? null,
            // Nunca se almacena el token ni la contrasena.
            'detalles_adicionales' => ['email' => $email, 'detalle' => $detalle],
        ]);
    }

    private function auditReset(array $context, ?string $tenantId, ?string $userId, string $resultado, string $detalle): void
    {
        if ($this->auditOrNull() === null || $tenantId === null) {
            return;
        }
        $this->auditOrNull()->record([
            'tenant_id'    => $tenantId,
            'usuario_id'   => $userId,
            'operacion'    => 'auth.password.reset',
            'modulo'       => 'auth',
            'recurso'      => 'password_reset',
            'entidad_id'   => $userId,
            'tipo_cambio'  => 'EXECUTE',
            'resultado'    => $resultado,
            'razon_fallo'  => $resultado === 'FAILURE' ? $detalle : null,
            'ip_address'   => $context['ip'] ?? null,
            'user_agent'   => $context['user_agent'] ?? null,
            'request_id'   => $context['request_id'] ?? null,
            'detalles_adicionales' => ['detalle' => $detalle],
        ]);
    }

    // ========================================================================
    // RESOLUCION PEREZOSA + HELPERS
    // ========================================================================

    private function db(): DatabaseService
    {
        return $this->db ??= Container::getInstance()->get('database');
    }

    private function authz(): AuthorizationService
    {
        return $this->authz ??= Container::getInstance()->get(AuthorizationService::class);
    }

    private function users(): UserService
    {
        return $this->users ??= Container::getInstance()->get(UserService::class);
    }

    private function mailer(): MailerService
    {
        return $this->mailer ??= Container::getInstance()->get(MailerService::class);
    }

    private function auditOrNull(): ?AuditLogger
    {
        return $this->audit;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
