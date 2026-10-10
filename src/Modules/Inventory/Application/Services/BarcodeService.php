<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

/**
 * Generador de códigos de barras de unidad física.
 *
 * Formato (decisión de diseño 1.5.1): prefijo fijo + parte aleatoria.
 *
 *   SU-7K2M4Q-X9A805F7
 *   ^^  ^^^^^^  ^^^^^^^^
 *    |   6 chars  8 chars
 *  fijo
 *
 * El prefijo `SU` (Stock Unit) es constante: no deriva de tenant, producto
 * ni fecha. La parte aleatoria usa un alfabeto sin caracteres ambiguos
 * (0/O, 1/I, U) generada con `random_int`.
 *
 * La unicidad la garantiza `UNIQUE (tenant_id, codigo_barcode)` en BD; el
 * use case reintenta la generación hasta `MAX_ATTEMPTS` veces si el código
 * ya existe en el tenant actual.
 */
final class BarcodeService
{
    public const MAX_ATTEMPTS = 5;

    public const PREFIX = 'SU';

    private const PREFIX_LENGTH = 2;
    private const RANDOM_LENGTH_1 = 6;
    private const RANDOM_LENGTH_2 = 8;

    /** Sin 0, O, 1, I, U (evita confusiones al escanear / leer a simple vista). */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTVWXYZ23456789';

    /**
     * Genera un código candidato (puede colisionar dentro del tenant: el
     * caller verifica unicidad y reintenta).
     */
    public function generate(): string
    {
        return sprintf(
            '%s-%s-%s',
            self::PREFIX,
            $this->randomPart(self::RANDOM_LENGTH_1),
            $this->randomPart(self::RANDOM_LENGTH_2)
        );
    }

    /**
     * ¿Respeta el formato del servicio? (tests/auditoría)
     *
     * ^SU-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{6}-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{8}$
     */
    public function matches(string $barcode): bool
    {
        $charset = preg_quote(self::CHARSET, '/');

        return (bool) preg_match(
            '/^' . self::PREFIX . '-[' . $charset . ']{6}-[' . $charset . ']{8}$/',
            $barcode
        );
    }

    private function randomPart(int $length): string
    {
        $max = strlen(self::CHARSET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::CHARSET[random_int(0, $max)];
        }

        return $out;
    }
}
