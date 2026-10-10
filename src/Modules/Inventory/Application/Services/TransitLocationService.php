<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Shared\Services\DatabaseService;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Ubicaciones fijas por tenant: virtual TRANSITO (spec CU-006, §3.13) y
 * zona DAÑADOS (spec CU-009, §3.4).
 *
 * TRANSITO: `AuthorizeShipmentUseCase` necesita, para cada tenant, una
 * ubicación activa de tipo TRANSITO a la que mover las unidades autorizadas:
 * sin ella el alta de un tenant nuevo devolvía 404 `transitLocationMissing`
 * y ningún envío podía autorizarse (hueco v1.7.0).
 *
 * DAÑADOS (v1.8.0): `RegisterReturnUseCase` reubica aquí las unidades que
 * vuelven dañadas/ deterioradas (spec CU-009); `LocationType` sí define el
 * tipo, pero nadie la creaba (mismo hueco que TRANSITO, decisión #4 del
 * prompt).
 *
 * Esta semilla es IDEMPOTENTE y se ejecuta desde dos sitios:
 *
 *  - `TenantService::create()` / `createWithOwner()` → cada tenant nuevo
 *    nace con ambas ubicaciones (misma transacción: si falla, no se crea el
 *    tenant).
 *  - `php bin/seed-transit-locations.php` → tenants ya existentes.
 *
 * Características de la fila creada:
 *  - `activa = 1`, `capacidad = NULL` (las virtuales no tienen capacidad
 *    física; DAÑADOS queda con capacidad ilimitada y `capacidad_usada`
 *    contando los slots, decisión documentada).
 *  - `codigo`: el base (`TRANSITO` / `DANADOS`) cuando está libre y
 *    `<BASE>-<8>` (prefijo del tenant) en caso contrario, porque
 *    `locations.codigo` es UNIQUE GLOBAL en MySQL (schema.sql:183) y dos
 *    tenants no podrían compartir código.
 *  - La búsqueda es SIEMPRE por (tenant_id, tipo): los use cases resuelven
 *    la primera ubicación activa del tipo, nunca por código, de modo que el
 *    código puede variar entre tenants.
 *
 * No abre transacción propia: el llamante (alta de tenant o CLI) decide.
 */
final class TransitLocationService
{
    public const CODIGO_BASE = 'TRANSITO';
    public const TIPO = 'TRANSITO';
    public const NOMBRE = 'Transito virtual';

    public const CODIGO_BASE_DANADOS = 'DANADOS';
    public const TIPO_DANADOS = 'DAÑADOS';
    public const NOMBRE_DANADOS = 'Zona de danados';

    public function __construct(
        private readonly DatabaseService $db
    ) {
    }

    /**
     * Devuelve (creándola si hace falta) la ubicación TRANSITO del tenant.
     *
     * @return array{ubicacion_id: string, codigo: string, tipo: string, capacidad: null, capacidad_usada: int, activa: bool, creada: bool}
     *
     * @throws InvalidArgumentException si el tenant_id está vacío
     * @throws RuntimeException si la fila no se puede leer tras el INSERT
     */
    public function ensureForTenant(string $tenantId): array
    {
        return $this->ensure($tenantId, self::TIPO, self::CODIGO_BASE, self::NOMBRE);
    }

    /**
     * Devuelve (creándola si hace falta) la zona DAÑADOS del tenant
     * (spec CU-009: reubicación de devoluciones dañadas).
     *
     * @return array{ubicacion_id: string, codigo: string, tipo: string, capacidad: null, capacidad_usada: int, activa: bool, creada: bool}
     *
     * @throws InvalidArgumentException si el tenant_id está vacío
     * @throws RuntimeException si la fila no se puede leer tras el INSERT
     */
    public function ensureDamagedZoneForTenant(string $tenantId): array
    {
        return $this->ensure($tenantId, self::TIPO_DANADOS, self::CODIGO_BASE_DANADOS, self::NOMBRE_DANADOS);
    }

    /**
     * @return array{ubicacion_id: string, codigo: string, tipo: string, capacidad: null, capacidad_usada: int, activa: bool, creada: bool}
     */
    private function ensure(string $tenantId, string $tipo, string $codigoBase, string $nombre): array
    {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('El identificador de tenant es obligatorio.');
        }

        $existing = $this->find($tenantId, $tipo);

        if ($existing !== null) {
            if ((int) $existing['activa'] !== 1) {
                $this->activate((string) $existing['id']);
                $existing['activa'] = 1;
            }

            return $this->toPublic($existing, false, $tipo);
        }

        try {
            $this->insert($tenantId, $this->resolveCodigo($tenantId, $codigoBase), $tipo, $nombre);
        } catch (PDOException $e) {
            // Dos altas simultáneas del mismo tenant: gana el INSERT y el
            // perdedor reutiliza la fila (23000 = clave duplicada).
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        $created = $this->find($tenantId, $tipo);

        if ($created === null) {
            throw new RuntimeException(
                "No se pudo crear la ubicación {$tipo} del tenant " . $tenantId
            );
        }

        return $this->toPublic($created, true, $tipo);
    }

    // ====================================================================
    // INTERNOS
    // ====================================================================

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $tenantId, string $tipo): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, codigo, capacidad, capacidad_usada, activa
             FROM locations
             WHERE tenant_id = :tenant_id
               AND tipo = :tipo
               AND deleted_at IS NULL
             ORDER BY created_at ASC
             LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'tipo' => $tipo]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function activate(string $locationId): void
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE locations SET activa = 1, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['id' => $locationId, 'now' => date('Y-m-d H:i:s')]);
    }

    private function insert(string $tenantId, string $codigo, string $tipo, string $nombre): void
    {
        $now = date('Y-m-d H:i:s');

        // Placeholder distinto para cada columna: con prepares NATIVOS de
        // MySQL (sin emulación) repetir `:now` dos veces provoca
        // SQLSTATE[HY093] "Invalid parameter number" en el INSERT.
        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO locations
                (id, tenant_id, codigo, nombre, tipo, capacidad, capacidad_usada, activa, created_at, updated_at)
             VALUES
                (:id, :tenant_id, :codigo, :nombre, :tipo, NULL, 0, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $this->generateUuid(),
            'tenant_id' => $tenantId,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Código libre (comprobación GLOBAL: `locations.codigo` es UNIQUE en
     * toda la tabla, no sólo por tenant).
     */
    private function resolveCodigo(string $tenantId, string $codigoBase): string
    {
        $suffix = strtoupper(substr(str_replace('-', '', $tenantId), 0, 8));

        $candidates = [
            $codigoBase,
            $codigoBase . '-' . $suffix,
        ];

        foreach ($candidates as $candidate) {
            if (!$this->codigoTaken($candidate)) {
                return $candidate;
            }
        }

        // Extremo improbable (ambos códigos ocupados): sufijo aleatorio.
        for ($intentos = 0; $intentos < 10; $intentos++) {
            $candidato = $codigoBase . '-' . strtoupper(bin2hex(random_bytes(3)));

            if (!$this->codigoTaken($candidato)) {
                return $candidato;
            }
        }

        throw new RuntimeException("No se encontró un código libre para la ubicación {$codigoBase}.");
    }

    private function codigoTaken(string $codigo): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT COUNT(*) FROM locations WHERE codigo = :codigo'
        );
        $stmt->execute(['codigo' => $codigo]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ubicacion_id: string, codigo: string, tipo: string, capacidad: null, capacidad_usada: int, activa: bool, creada: bool}
     */
    private function toPublic(array $row, bool $creada, string $tipo): array
    {
        return [
            'ubicacion_id' => (string) $row['id'],
            'codigo' => (string) $row['codigo'],
            'tipo' => $tipo,
            'capacidad' => null,
            'capacidad_usada' => (int) ($row['capacidad_usada'] ?? 0),
            'activa' => (int) $row['activa'] === 1,
            'creada' => $creada,
        ];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
