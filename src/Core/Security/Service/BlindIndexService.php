<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Shared\Exceptions\SecurityException;

/**
 * BlindIndexService - Índice ciego determinista para búsqueda de datos cifrados.
 *
 * Permite buscar por campos cifrados (email, nombre_interno) sin descifrar:
 * se almacena un HMAC-SHA256 del valor normalizado en una columna separada.
 *
 * Diferencia con EncryptionService:
 * - EncryptionService: AES-256-GCM, IV aleatorio → mismo plaintext da distinto ciphertext.
 *   Sirve para CONFIDENCIALIDAD (no se puede buscar).
 * - BlindIndexService: HMAC-SHA256, determinista → mismo valor da siempre el mismo hash.
 *   Sirve para BÚSQUEDA e UNICIDAD (se puede comparar con =).
 *
 * Configuración vía .env:
 *   BLIND_INDEX_KEY=<64 caracteres hex = 32 bytes>
 *
 * Key separation: usa una clave DIFERENTE de ENCRYPTION_KEY. Si un atacante
 * obtiene una clave, la otra sigue protegida.
 */
final class BlindIndexService
{
    private string $key;

    public function __construct()
    {
        $rawKey = (string) ($_ENV['BLIND_INDEX_KEY'] ?? '');

        if ($rawKey === '') {
            throw new SecurityException('BLIND_INDEX_KEY no configurada en .env', 500);
        }

        $this->key = $this->normalizeKey($rawKey);
    }

    /**
     * Calcula el índice ciego (HMAC-SHA256 hex = 64 chars) de un valor.
     *
     * El valor se normaliza (trim + lowercase UTF-8) antes del HMAC para que
     * "Admin@Acme.local" y "admin@acme.local" produzcan el mismo índice.
     *
     * @param string $value Valor en claro (email, nombre_interno, etc.)
     * @return string HMAC-SHA256 en hex (64 caracteres)
     */
    public function compute(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return hash_hmac('sha256', $normalized, $this->key);
    }

    /**
     * Calcula el índice ciego de un valor nullable.
     * Retorna null si el valor es null o vacío (para columnas NULL).
     */
    public function computeNullable(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->compute($value);
    }

    /**
     * Normaliza la clave a 32 bytes binarios.
     * Acepta 64 hex chars o 32 bytes raw.
     *
     * @throws SecurityException Si la clave es inválida
     */
    private function normalizeKey(string $rawKey): string
    {
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
            'BLIND_INDEX_KEY debe tener 64 caracteres hex (32 bytes) o 32 bytes raw. ' .
            "Longitud recibida: {$length}.",
            500
        );
    }
}
