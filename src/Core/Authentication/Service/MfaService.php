<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Services\DatabaseService;
use OTPHP\TOTP;
use RuntimeException;

/**
 * MFA TOTP (v1.15.0): enrolamiento, verificación y códigos de respaldo.
 *
 * El secreto TOTP se guarda CIFRADO en `users.mfa_secret` (EncryptionService),
 * igual que la password SMTP; jamás sale por la API salvo en el momento del
 * enrolamiento (setup). Los códigos de respaldo se guardan hasheados en
 * `users.mfa_backup_codes` (JSON de hashes) y son de un solo uso.
 *
 * Opt-in por usuario: MFA se activa desde la seguridad del propio usuario y
 * sólo se exige en el login una vez habilitado (o a permisos con
 * `requiere_mfa`, ya existente).
 */
final class MfaService
{
    private const ISSUER = 'XP Platform';
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const SECRET_BYTES = 20;      // 160 bits (SHA1) por RFC 4226/6238
    private const BACKUP_CODES = 10;

    public function __construct(
        private DatabaseService $db,
        private EncryptionService $encryption,
    ) {
    }

    // ========================================================================
    // NÚCLEO TOTP
    // ========================================================================

    /** Genera un secreto TOTP en base32 (RFC 4648, sin padding). */
    public function generateSecret(): string
    {
        return $this->base32(random_bytes(self::SECRET_BYTES));
    }

    /** otpauth:// URL para que la app autenticadora lo escanee/enter manual. */
    public function buildProvisionUri(string $secret, string $email): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($email);
        $totp->setIssuer(self::ISSUER);
        return $totp->getProvisioningUri();
    }

    /** Verifica un código TOTP (6 dígitos) contra el secreto base32. */
    public function verifyTotp(string $secretBase32, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        try {
            return $this->totp($secretBase32)->verify($code);
        } catch (\Throwable) {
            return false;
        }
    }

    // ========================================================================
    // ENROLAMIENTO / ESTADO
    // ========================================================================

    /**
     * Paso 1 del enrolamiento: genera y guarda (cifrado) un secreto pendiente
     * SIN habilitar MFA todavía. Devuelve el secreto y la otpauth:// URL para
     * el QR / entrada manual. El MFA sólo se activa en enable() con un código.
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function setup(string $userId, string $email): array
    {
        $secret = $this->generateSecret();
        $encrypted = $this->encryption->encryptForStorage($secret);

        $this->db->query(
            "UPDATE users SET mfa_secret = :secret, updated_at = NOW() WHERE id = :id",
            ['secret' => $encrypted, 'id' => $userId]
        );

        return [
            'secret'       => $secret,
            'otpauth_url'  => $this->buildProvisionUri($secret, $email),
        ];
    }

    /**
     * Paso 2: verifica un código contra el secreto pendiente y, si es válido,
     * habilita MFA (metodo TOTP) y genera códigos de respaldo (devueltos una
     * única vez).
     *
     * @return string[] Códigos de respaldo en claro (guardar ya).
     * @throws RuntimeException Si el código no valida o no hay secreto.
     */
    public function enable(string $userId, string $code): array
    {
        $user = $this->fetchUser($userId);
        $secret = $user['mfa_secret'] ?? null;
        if ($secret === null || $secret === '') {
            throw new RuntimeException('No hay secreto MFA pendiente; ejecuta setup primero.');
        }
        $secretPlain = $this->encryption->decryptFromStorage((string) $secret);
        if (!$this->verifyTotp($secretPlain, $code)) {
            throw new RuntimeException('Código MFA inválido.');
        }

        $backupCodes = $this->generateBackupCodes();
        // Se hashea SIN guion para que la verificación sea insensible al guion.
        $hashed = array_map(
            static fn(string $c) => password_hash(str_replace('-', '', $c), PASSWORD_BCRYPT),
            $backupCodes
        );

        $this->db->query(
            "UPDATE users
             SET mfa_habilitado = 1, mfa_metodo = 'TOTP', mfa_backup_codes = :codes, updated_at = NOW()
             WHERE id = :id",
            [
                'codes' => json_encode($hashed, JSON_UNESCAPED_SLASHES),
                'id'    => $userId,
            ]
        );

        return $backupCodes;
    }

    /** Deshabilita MFA y borra secreto y códigos de respaldo. */
    public function disable(string $userId, string $code): void
    {
        if (!$this->verify($userId, $code)) {
            throw new RuntimeException('Código MFA inválido.');
        }
        $this->db->query(
            "UPDATE users
             SET mfa_habilitado = 0, mfa_metodo = NULL, mfa_secret = NULL, mfa_backup_codes = NULL, updated_at = NOW()
             WHERE id = :id",
            ['id' => $userId]
        );
    }

    /**
     * Verifica un código (TOTP o de respaldo) contra el usuario. Es el que usa
     * el login. Un código de respaldo válido se consume (un solo uso).
     */
    public function verify(string $userId, string $code): bool
    {
        $user = $this->fetchUser($userId);
        $secret = $user['mfa_secret'] ?? null;
        if ($secret === null || $secret === '') {
            return false;
        }
        $secretPlain = $this->encryption->decryptFromStorage((string) $secret);
        if ($this->verifyTotp($secretPlain, $code)) {
            return true;
        }
        return $this->consumeBackupCode($userId, (string) ($user['mfa_backup_codes'] ?? ''), $code);
    }

    // ========================================================================
    // ESTADO
    // ========================================================================

    /**
     * Estado MFA del usuario para la UI de seguridad (nunca expone el secreto).
     *
     * @return array{habilitado: bool, metodo: ?string, pending_setup: bool}
     */
    public function status(string $userId): array
    {
        $user = $this->db->fetch(
            "SELECT mfa_habilitado, mfa_metodo, mfa_secret
             FROM users WHERE id = :id AND deleted_at IS NULL",
            ['id' => $userId]
        );
        if ($user === null) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        $secret = $user['mfa_secret'] ?? null;
        return [
            'habilitado'   => !empty($user['mfa_habilitado']),
            'metodo'       => $user['mfa_metodo'] ?? null,
            'pending_setup'=> !$user['mfa_habilitado'] && $secret !== null && $secret !== '',
        ];
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function totp(string $secret): TOTP
    {
        return TOTP::create($secret, self::PERIOD, 'sha1', self::DIGITS);
    }

    private function fetchUser(string $userId): array
    {
        $user = $this->db->fetch(
            "SELECT id, mfa_habilitado, mfa_metodo, mfa_secret, mfa_backup_codes
             FROM users WHERE id = :id AND deleted_at IS NULL",
            ['id' => $userId]
        );
        if ($user === null) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        return $user;
    }

    /** Genera códigos de respaldo legibles (XXXXX-XXXXX). */
    private function generateBackupCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O/1/I
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    /** Consume un código de respaldo válido (lo elimina de la lista). */
    private function consumeBackupCode(string $userId, string $json, string $code): bool
    {
        $hashes = json_decode($json, true);
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }
        // Normaliza a sin guion/espacios: así se guardaron hasheados.
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        if ($code === '') {
            return false;
        }
        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($hashes[$i]);
                $this->db->query(
                    "UPDATE users SET mfa_backup_codes = :codes, updated_at = NOW() WHERE id = :id",
                    ['codes' => json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES), 'id' => $userId]
                );
                return true;
            }
        }
        return false;
    }

    /** Base32 RFC 4648 (sin padding) sobre bytes aleatorios. */
    private function base32(string $bin): string
    {
        $A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $A[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }
}
