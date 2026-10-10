<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Shared\Exceptions\SecurityException;

/**
 * EncryptionService - Encriptación simétrica AES-256-GCM.
 *
 * Responsabilidades:
 * - Encriptar/desencriptar datos sensibles (PII, series, lotes, tokens externos).
 * - Usar AES-256-GCM (modo autenticado: confidencialidad + integridad).
 * - Generar IV único por cada encriptación (16 bytes).
 * - Incluir Authentication Tag (16 bytes) para detectar tampering.
 *
 * Formato de salida (base64):
 *   iv (16 bytes) + tag (16 bytes) + ciphertext
 *
 * Configuración vía .env:
 *   ENCRYPTION_ALGORITHM=aes-256-gcm
 *   ENCRYPTION_KEY=<64 caracteres hex = 32 bytes>
 *   ENCRYPTION_IV_LENGTH=16
 */
final class EncryptionService
{
    private string $key;
    private string $algorithm;
    private int $ivLength;
    private int $tagLength = 16;

    public function __construct()
    {
        $this->algorithm = (string) ($_ENV['ENCRYPTION_ALGORITHM'] ?? 'aes-256-gcm');
        $this->ivLength  = (int) ($_ENV['ENCRYPTION_IV_LENGTH'] ?? 16);
        $rawKey          = (string) ($_ENV['ENCRYPTION_KEY'] ?? '');

        $this->key = $this->normalizeKey($rawKey);
        $this->validateAlgorithm();
    }

    /**
     * Encripta datos sensibles.
     *
     * @param string $plaintext Datos a encriptar
     * @return string Base64 de (iv + tag + ciphertext)
     * @throws SecurityException Si falla la encriptación
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv  = random_bytes($this->ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->algorithm,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $this->tagLength
        );

        if ($ciphertext === false) {
            throw SecurityException::encryptionFailed('openssl_encrypt returned false');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Desencripta datos previamente encriptados.
     *
     * @param string $encrypted Base64 de (iv + tag + ciphertext)
     * @return string Plaintext
     * @throws SecurityException Si los datos son inválidos o la integridad falla
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $combined = base64_decode($encrypted, true);
        if ($combined === false) {
            throw SecurityException::decryptionFailed('base64 inválido');
        }

        $minLength = $this->ivLength + $this->tagLength + 1;
        if (strlen($combined) < $minLength) {
            throw SecurityException::decryptionFailed('datos malformados (longitud insuficiente)');
        }

        $iv         = substr($combined, 0, $this->ivLength);
        $tag        = substr($combined, $this->ivLength, $this->tagLength);
        $ciphertext = substr($combined, $this->ivLength + $this->tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->algorithm,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed('integridad comprometida o clave incorrecta');
        }

        return $plaintext;
    }

    /**
     * Alias semántico para uso en repositorios (field-level encryption).
     */
    public function encryptForStorage(string $value): string
    {
        return $this->encrypt($value);
    }

    /**
     * Alias semántico para uso en repositorios (field-level encryption).
     */
    public function decryptFromStorage(string $value): string
    {
        return $this->decrypt($value);
    }

    /**
     * Genera una clave maestra aleatoria segura (32 bytes = 256 bits).
     * Retorna en formato hex (64 caracteres) para almacenar en .env.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Genera un IV aleatorio del tamaño configurado.
     */
    public function generateIv(): string
    {
        return random_bytes($this->ivLength);
    }

    /**
     * Retorna el algoritmo configurado (útil para tests/debug).
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Retorna la longitud del IV configurada.
     */
    public function getIvLength(): int
    {
        return $this->ivLength;
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    /**
     * Normaliza la clave a 32 bytes binarios.
     * Acepta:
     *  - 64 caracteres hex → se convierte a 32 bytes
     *  - 32 bytes raw      → se usa tal cual
     *
     * @throws SecurityException Si la clave es inválida
     */
    private function normalizeKey(string $rawKey): string
    {
        if ($rawKey === '') {
            throw SecurityException::invalidKey();
        }

        $length = strlen($rawKey);

        // 64 hex chars → 32 bytes
        if ($length === 64 && ctype_xdigit($rawKey)) {
            return hex2bin($rawKey);
        }

        // 32 bytes raw
        if ($length === 32) {
            return $rawKey;
        }

        throw new SecurityException(
            'ENCRYPTION_KEY debe tener 64 caracteres hex (32 bytes) o 32 bytes raw. ' .
            "Longitud recibida: {$length}.",
            500
        );
    }

    /**
     * Valida que el algoritmo esté soportado por OpenSSL.
     *
     * @throws SecurityException Si el algoritmo no está disponible
     */
    private function validateAlgorithm(): void
    {
        $supported = openssl_get_cipher_methods();
        if (!in_array($this->algorithm, $supported, true)) {
            throw new SecurityException(
                "Algoritmo no soportado: {$this->algorithm}",
                500
            );
        }
    }
}
