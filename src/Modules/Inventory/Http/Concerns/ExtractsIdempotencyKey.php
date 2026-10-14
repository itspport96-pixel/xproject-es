<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Concerns;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;

/**
 * Capa HTTP compartida: claims de autenticación + header Idempotency-Key.
 *
 * Mismo comportamiento que ReceivingController (CU-001): la clave se lee de
 * forma case-insensitive, se valida longitud y charset y se rechaza vacía con
 * 400 (`field: idempotency-key`).
 */
trait ExtractsIdempotencyKey
{
    private const MAX_KEY_LENGTH = 64;

    /**
     * @return array<string, mixed> claims del usuario
     *
     * @throws HttpException 401 si no hay claims
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        return $claims;
    }

    /**
     * Extrae y valida el header Idempotency-Key (case-insensitive).
     *
     * @param bool $required false → devuelve null si no está presente
     *
     * @return string|null
     *
     * @throws InventoryException 400 si falta o es inválido
     */
    private function idempotencyKey(array $request, bool $required = true): ?string
    {
        $headers = $request['headers'] ?? [];
        $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];

        $key = $headers['idempotency-key'] ?? null;
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            if (!$required) {
                return null;
            }

            throw InventoryException::idempotencyKeyRequired();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InventoryException::idempotencyKeyInvalid(
                'Máximo ' . self::MAX_KEY_LENGTH . ' caracteres.'
            );
        }

        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw InventoryException::idempotencyKeyInvalid(
                'Sólo se permiten letras, números y los caracteres . _ : -.'
            );
        }

        return $key;
    }
}
