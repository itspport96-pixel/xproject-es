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
use App\Shared\Services\MailCatalog;
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
 *
 * v1.18.0 (cumplimiento): se amplía con la emisión ADMINISTRATIVA (kind
 * 'admin', desde POST /users/{id}/password/reset) y de BIENVENIDA (kind
 * 'welcome', desde el alta de usuario). Ambas comparten token/liga con el
 * SSPR pero NO exigen el permiso opt-in `password_reset_self_service` al
 * consumirse: la autorización se verificó al emitir (`users.password.reset`
 * / alta de usuario). El TTL también difiere por kind (ver constantes).
 * El admin ya no ve ninguna contraseña: en el reset administrativo la
 * contraseña anterior se invalida al instante (UserService::invalidatePassword)
 * y la única vía de recuperación es la liga enviada por correo.
 */
class PasswordResetService
{
    /** Caducidad del token de reset SSPR (minutos). */
    public const TOKEN_TTL_MINUTES = 15;

    /** Caducidad del token emitido por un administrador (minutos). */
    public const ADMIN_TTL_MINUTES = 60;

    /** Caducidad del token de bienvenida (horas). */
    public const WELCOME_TTL_HOURS = 24;

    /** Permiso que debe tener el usuario para usar el SSPR. */
    public const PERMISSION = 'password_reset_self_service';

    /** Kind por defecto: solicitud propia del usuario (exige el permiso). */
    public const KIND_SSPR = 'sspr';

    /** Kind del reset ordenado por un administrador (sin permiso SSPR). */
    public const KIND_ADMIN = 'admin';

    /** Kind del enlace de bienvenida del alta (sin permiso SSPR). */
    public const KIND_WELCOME = 'welcome';

    private ?DatabaseService $db;
    private ?AuthorizationService $authz;
    private ?UserService $users;
    private ?MailCatalog $catalog;
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

        $issued = $this->createToken($userId, $tenantId, $context, self::KIND_SSPR);
        $token = $issued['token'];

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

    // ========================================================================
    // EMISION ADMINISTRATIVA Y DE BIENVENIDA (v1.18.0, cumplimiento)
    // ========================================================================

    /**
     * Emite el reset ordenado por un administrador (kind 'admin').
     *
     * Validaciones ANTES de mutar nada: usuario existe, email destino
     * valido (400) y SMTP del tenant configurado (400). Después:
     *  1. La contraseña se invalida AL INSTANTE (UserService::
     *     invalidatePassword: hash aleatorio desconocido para todos +
     *     historial + marcador de cambio forzado). El admin ya no ve ninguna
     *     clave: la recuperación es exclusivamente la liga del correo.
     *  2. Tokens anteriores muertos; token nuevo kind 'admin' con TTL
     *     ADMIN_TTL_MINUTES y trazabilidad requested_by.
     *  3. Envío; si falla se borra el token (reintento limpio tras arreglar
     *     el SMTP) pero la contraseña YA está invalidada: es la intención
     *     del administrador. El error se propaga (400/502).
     *
     * La auditoría de este flujo la emite el controller (user.password.reset);
     * aquí no se registra para no duplicar el evento.
     *
     * @return string email destino (para la respuesta al administrador)
     * @throws UserException 404 si el usuario no existe / 400 sin email válido
     * @throws \Throwable Fallo de envío (ya con el token borrado)
     */
    public function issueAdminReset(string $userId, string $tenantId, string $requestedBy, array $context = []): string
    {
        $user = $this->findUserById($userId, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($userId);
        }

        $email = $this->assertUsableEmail($user);
        $this->mailer()->assertSendable($tenantId, $email);

        $this->users()->invalidatePassword($userId, $tenantId);
        // Acceso matado al instante tambien en sesiones vivas: si luego falla
        // el envio (502) la cuenta sigue bloqueada, que es la intencion.
        $this->revokeSessions($userId, 'admin_reset');
        $this->invalidateActiveTokens($userId);

        $issued = $this->createToken(
            $userId, $tenantId, $context, self::KIND_ADMIN, $requestedBy, self::ADMIN_TTL_MINUTES
        );

        try {
            $this->sendAdminResetEmail(
                $tenantId, $email, (string) ($user['nombre'] ?? ''), $issued['token'], self::ADMIN_TTL_MINUTES
            );
        } catch (\Throwable $e) {
            $this->deleteToken($issued['id']);
            throw $e;
        }

        return $email;
    }

    /**
     * Emite el enlace de bienvenida del alta de usuario (kind 'welcome').
     *
     * El alta ya dejó un hash aleatorio desconocido (UserService::create), de
     * modo que el enlace es la única vía para que el nuevo usuario fije su
     * contraseña. Si el envío falla se borra el token y se propaga el error:
     * el usuario queda creado y recuperable con "Restablecer contraseña"
     * cuando el SMTP esté resuelto (el controller responde email_enviado=false).
     *
     * @return string email destino
     * @throws UserException 404 si el usuario no existe / 400 sin email válido
     * @throws \Throwable Fallo de envío (ya con el token borrado)
     */
    public function issueWelcome(string $userId, string $tenantId, string $requestedBy, array $context = []): string
    {
        $user = $this->findUserById($userId, $tenantId);
        if ($user === null) {
            throw UserException::userNotFound($userId);
        }

        $email = $this->assertUsableEmail($user);
        $this->mailer()->assertSendable($tenantId, $email);

        $this->invalidateActiveTokens($userId);

        $issued = $this->createToken(
            $userId, $tenantId, $context, self::KIND_WELCOME, $requestedBy, self::WELCOME_TTL_HOURS * 60
        );

        try {
            $this->sendWelcomeEmail(
                $tenantId, $email, (string) ($user['nombre'] ?? ''), $issued['token'], self::WELCOME_TTL_HOURS
            );
        } catch (\Throwable $e) {
            $this->deleteToken($issued['id']);
            throw $e;
        }

        return $email;
    }

    /** Email de destino utilizable o 400 (users.reset_email_missing). */
    private function assertUsableEmail(array $user): string
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw (new UserException(
                'El usuario no tiene un correo electronico valido; configuralo antes de enviar el enlace.',
                400,
                null,
                'email',
                UserException::CATEGORY_VALIDATION
            ))->withMessageKey('users.reset_email_missing');
        }

        return $email;
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

        // Re-verificacion del permiso SOLO para tokens SSPR (v1.18.0): los
        // kinds 'admin' y 'welcome' se autorizaron al emitirlos (permiso
        // users.password.reset / alta de usuario) y el target puede no tener
        // el opt-in password_reset_self_service. Para SSPR mantiene la
        // garantia: si un administrador revoca el permiso despues de emitir
        // el token, el enlace deja de servir aunque el token siga vigente.
        $kind = (string) ($row['kind'] ?? self::KIND_SSPR);
        if ($kind === self::KIND_SSPR && !$this->authz()->hasPermission($userId, $tenantId, self::PERMISSION)) {
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

        // El reuso (contrasena actual o del historial) tambien se valida ANTES
        // de consumir el token: no debe quemar el enlace, para que el usuario
        // pueda reintentar con la misma liga y una contrasena distinta.
        $this->users()->assertPasswordNotReused($userId, $tenantId, $newPassword);

        // Consumo ATOMICO del token: si otra peticion concurrente ya lo uso,
        // rowCount = 0 y se rechaza (un solo uso garantizado).
        if (!$this->consumeToken((string) $row['id'])) {
            $this->auditReset($context, $tenantId, $userId, 'FAILURE', 'Token reutilizado (carrera)');
            throw (new UserException(
                'El enlace de restablecimiento ya fue utilizado.',
                400, null, null, UserException::CATEGORY_VALIDATION, 'RESET_TOKEN_USED'
            ))->withMessageKey('auth.reset_token_used');
        }

        // Politica + historial + transaccion (UserService v1.16.0). La
        // politica y el reuso ya se validaron arriba (sin consumir el enlace);
        // el consumo del token es atomico. Si el UPDATE falla por una carrera
        // concurrente, el enlace ya quedo consumido: hay que pedir uno nuevo.
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

    /**
     * Genera y guarda (como hash) un token; devuelve id de fila y token en
     * claro para el enlace (v1.18.0: parametrizado por kind/TTL/autor).
     *
     * @return array{id: string, token: string}
     */
    private function createToken(
        string $userId,
        string $tenantId,
        array $context,
        string $kind = self::KIND_SSPR,
        ?string $requestedBy = null,
        ?int $ttlMinutes = null
    ): array {
        $token = bin2hex(random_bytes(32));
        $id = $this->generateUuid();
        $ttl = $ttlMinutes ?? self::TOKEN_TTL_MINUTES;

        $sql = "INSERT INTO password_reset_tokens
                    (id, tenant_id, user_id, token_hash, kind, requested_by, expires_at, ip_address)
                VALUES (:id, :tenant_id, :user_id, :token_hash, :kind, :requested_by, :expires_at, :ip)";
        $stmt = $this->db()->getConnection()->prepare($sql);
        $stmt->execute([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'user_id'      => $userId,
            'token_hash'   => $this->hashToken($token),
            'kind'         => $kind,
            'requested_by' => $requestedBy !== null && $requestedBy !== '' ? $requestedBy : null,
            'expires_at'   => date('Y-m-d H:i:s', time() + $ttl * 60),
            'ip'           => (string) ($context['ip'] ?? null),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** Borra un token recién emitido si el envío falló (v1.18.0). */
    private function deleteToken(string $tokenId): void
    {
        $this->db()->getConnection()->prepare(
            'DELETE FROM password_reset_tokens WHERE id = :id AND used_at IS NULL'
        )->execute(['id' => $tokenId]);
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

    private function revokeSessions(string $userId, string $reason = 'password_reset'): void
    {
        if (!Container::getInstance()->has(\App\Core\Authentication\Service\SessionRevokerService::class)) {
            throw new \RuntimeException('SessionRevokerService no registrado: la revocación de sesiones es obligatoria en este flujo.');
        }
        Container::getInstance()->get(SessionRevokerService::class)
            ->revokeAllForUser($userId, null, $reason);
    }

    // ========================================================================
    // CORREO (sobreescribible en tests: sendMail evita el SMTP real)
    // ========================================================================

    /** @throws \Throwable Si el SMTP del tenant falla */
    protected function sendResetEmail(string $tenantId, string $destino, string $nombre, string $token): void
    {
        $mail = $this->catalog()->compose('reset', $destino, $nombre !== '' ? $nombre : $destino, [
            'ttl' => (string) self::TOKEN_TTL_MINUTES,
            'url' => $this->buildResetUrl($token),
        ]);

        $this->sendMail($tenantId, $destino, $mail['asunto'], $mail['html'], 'reset');
    }

    /**
     * Enlace del reset administrativo (v1.18.0): NUNCA incluye ninguna
     * contraseña; avisa de que la anterior dejó de ser válida.
     *
     * @throws \Throwable Si el SMTP del tenant falla
     */
    protected function sendAdminResetEmail(string $tenantId, string $destino, string $nombre, string $token, int $ttlMinutes): void
    {
        $mail = $this->catalog()->compose('admin', $destino, $nombre !== '' ? $nombre : $destino, [
            'ttl' => (string) $ttlMinutes,
            'url' => $this->buildResetUrl($token),
        ]);

        $this->sendMail($tenantId, $destino, $mail['asunto'], $mail['html'], 'admin');
    }

    /**
     * Enlace de bienvenida del alta (v1.18.0): el usuario nuevo establece su
     * propia contraseña; ningún dato de credencial viaja en el correo.
     *
     * @throws \Throwable Si el SMTP del tenant falla
     */
    protected function sendWelcomeEmail(string $tenantId, string $destino, string $nombre, string $token, int $ttlHours): void
    {
        $mail = $this->catalog()->compose('welcome', $destino, $nombre !== '' ? $nombre : $destino, [
            'ttl' => (string) $ttlHours,
            'url' => $this->buildResetUrl($token),
        ]);

        $this->sendMail($tenantId, $destino, $mail['asunto'], $mail['html'], 'welcome');
    }

    /** @throws \Throwable Si el SMTP del tenant falla */
    protected function sendChangedEmail(string $tenantId, string $destino, string $nombre): void
    {
        $mail = $this->catalog()->compose('changed', $destino, $nombre !== '' ? $nombre : $destino);

        $this->sendMail($tenantId, $destino, $mail['asunto'], $mail['html'], 'changed');
    }

    /**
     * Punto único de envío: en tests se sustituye por un no-op.
     *
     * @param string $kind Etiqueta de contexto para mail_outbox
     */
    protected function sendMail(string $tenantId, string $destino, string $asunto, string $html, string $kind = 'general'): void
    {
        $this->mailer()->sendCustom($tenantId, $destino, $asunto, $html, $kind);
    }

    private function buildResetUrl(string $token): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $valid = filter_var($base, FILTER_VALIDATE_URL);
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        if ($valid === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('APP_URL inválida: se requiere http(s) absoluta. Ejemplo: https://app.dominio.com');
        }
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

    private function catalog(): MailCatalog
    {
        if (! isset($this->catalog)) {
            $container = Container::getInstance();
            $this->catalog = $container->has(MailCatalog::class)
                ? $container->get(MailCatalog::class)
                : new MailCatalog();
        }

        return $this->catalog;
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
