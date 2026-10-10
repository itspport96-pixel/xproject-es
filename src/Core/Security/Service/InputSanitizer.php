<?php
declare(strict_types=1);

namespace App\Core\Security\Service;

/**
 * InputSanitizer - Sanitización de entrada de usuario.
 *
 * Responsabilidades:
 * - Prevenir XSS (HTML/JS injection)
 * - Normalizar datos (trim, lowercase, espacios)
 * - Validar formatos básicos (email, UUID, URL)
 * - Sanitizar arrays recursivamente
 *
 * NO reemplaza validación de negocio, solo sanitiza entrada cruda.
 * Stateless: no mantiene estado entre llamadas.
 */
final class InputSanitizer
{
    /**
     * Sanitiza un string: elimina HTML/JS, trim, normaliza espacios.
     */
    public function sanitizeString(string $input): string
    {
        if ($input === '') {
            return '';
        }

        // Eliminar tags HTML/JS
        $input = strip_tags($input);

        // Decodificar entidades HTML (evita bypasses)
        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Eliminar caracteres de control no imprimibles (excepto saltos de línea básicos)
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input) ?? $input;

        // Trim
        $input = trim($input);

        // Normalizar espacios múltiples a uno solo
        $input = preg_replace('/\s+/', ' ', $input) ?? $input;

        return $input;
    }

    /**
     * Sanitiza un email: trim + lowercase.
     * NO valida formato (eso corresponde al validador de negocio).
     */
    public function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        // Normalizar: lowercase + eliminar espacios internos
        $email = preg_replace('/\s+/', '', $email) ?? $email;

        return strtolower($email);
    }

    /**
     * Sanitiza un array recursivamente.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Sanitizar la key también
            $cleanKey = $this->sanitizeString((string) $key);
            if ($cleanKey === '') {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$cleanKey] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$cleanKey] = $this->sanitizeString($value);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$cleanKey] = $value;
            }
            // Otros tipos (objetos, recursos) se descartan por seguridad
        }
        return $sanitized;
    }

    /**
     * Sanitiza un campo que puede contener HTML permitido (ej: descripciones ricas).
     * Permite solo tags seguros (p, br, strong, em, ul, ol, li, a, h1-h6).
     */
    public function sanitizeHtml(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $allowedTags = '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><code><pre>';

        $input = strip_tags($input, $allowedTags);

        // Eliminar atributos peligrosos (on*, javascript:, etc.)
        $input = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $input) ?? $input;
        $input = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $input) ?? $input;

        // Eliminar javascript: en hrefs
        $input = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $input) ?? $input;

        return trim($input);
    }

    /**
     * Sanitiza un UUID: trim + lowercase + validación de formato.
     * Retorna string vacío si el formato es inválido.
     */
    public function sanitizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if ($uuid === '') {
            return '';
        }

        // Formato UUID v4: 8-4-4-4-12 hex
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            return '';
        }

        return $uuid;
    }

    /**
     * Sanitiza una URL: trim + validación básica de esquema.
     * Retorna string vacío si es inválida.
     */
    public function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $filtered = filter_var($url, FILTER_VALIDATE_URL);
        if ($filtered === false) {
            return '';
        }

        // Solo permitir esquemas seguros
        $scheme = parse_url($filtered, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return '';
        }

        return $filtered;
    }

    /**
     * Sanitiza un número entero.
     * Retorna null si la conversión falla.
     */
    public function sanitizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) || is_float($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            return $filtered !== false ? (int) $filtered : null;
        }

        return null;
    }

    /**
     * Sanitiza un número decimal.
     * Retorna null si la conversión falla.
     */
    public function sanitizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
            return $filtered !== false ? (float) $filtered : null;
        }

        return null;
    }

    /**
     * Sanitiza un booleano desde valor mixto.
     */
    public function sanitizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return false;
    }

    /**
     * Sanitiza un nombre de archivo (elimina caracteres peligrosos).
     * Útil para uploads y referencias a archivos.
     */
    public function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }

        // Eliminar path traversal
        $filename = basename($filename);

        // Eliminar caracteres no alfanuméricos (excepto ., -, _)
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename;

        // Eliminar puntos múltiples y guiones múltiples
        $filename = preg_replace('/\.{2,}/', '.', $filename) ?? $filename;
        $filename = preg_replace('/_{2,}/', '_', $filename) ?? $filename;

        // Limitar longitud
        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 250 - strlen($ext)) . '.' . $ext;
        }

        return $filename;
    }

    /**
     * Sanitiza un campo de búsqueda (permite más caracteres pero elimina tags).
     */
    public function sanitizeSearch(string $input): string
    {
        $input = strip_tags($input);
        $input = trim($input);

        // Limitar longitud para búsquedas
        if (strlen($input) > 200) {
            $input = substr($input, 0, 200);
        }

        return $input;
    }
}
