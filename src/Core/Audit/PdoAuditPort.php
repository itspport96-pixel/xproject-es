<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Adaptador PDO del AuditPort sobre la tabla `audit_logs`.
 *
 * Sólo emite SELECT (lectura de la cadena), SELECT ... FOR UPDATE (candado
 * por tenant) e INSERT. No existen UPDATE ni DELETE: la auditoría es
 * inmutable (spec §7.1).
 */
final class PdoAuditPort implements AuditPort
{
    /** Columnas escribibles (coinciden con schema.sql y create_tables.ps1). */
    private const COLUMNS = [
        'id',
        'tenant_id',
        'usuario_id',
        'operacion',
        'modulo',
        'recurso',
        'entidad_id',
        'tipo_cambio',
        'valores_anteriores',
        'valores_nuevos',
        'resultado',
        'razon_fallo',
        'ip_address',
        'user_agent',
        'request_id',
        'timestamp',
        'duracion_operacion',
        'detalles_adicionales',
        'hash_integridad',
    ];

    public function __construct(
        private readonly DatabaseService $db,
        private readonly ?EncryptionService $encryption = null
    ) {
    }

    public function transaction(string $tenantId, callable $callback): mixed
    {
        return $this->db->transaction(function () use ($tenantId, $callback) {
            $this->lockTenant($tenantId);
            return $callback();
        });
    }

    public function previousHash(string $tenantId): ?string
    {
        $sql = 'SELECT hash_integridad
                  FROM audit_logs
                 WHERE tenant_id = ?
                   AND hash_integridad IS NOT NULL
                 ORDER BY timestamp DESC, id DESC
                 LIMIT 1' . $this->forUpdate();

        $stmt = $this->db->query($sql, [$tenantId]);
        $hash = $stmt->fetchColumn();

        if ($hash === false || $hash === null || $hash === '') {
            return null;
        }

        return (string) $hash;
    }

    public function insert(array $row): void
    {
        $row = $this->encryptSensitiveFields($row);

        $columns = array_values(array_intersect(self::COLUMNS, array_keys($row)));
        if ($columns === []) {
            throw new \InvalidArgumentException('PdoAuditPort::insert requiere al menos una columna válida');
        }

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns));

        $sql = "INSERT INTO audit_logs ({$columnList}) VALUES ({$placeholders})";

        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $row[$column];
        }

        $this->db->query($sql, $params);
    }

    /**
     * Cifra campos sensibles (PII) antes de persistir.
     *
     * Se cifra DESPUÉS de que AuditLogger calcula hash_integridad, de modo que
     * el hash protege el contenido lógico y el cifrado protege el almacenamiento.
     * Al leer, AuditLogQueryService descifra y la verificación de hash opera
     * sobre el contenido descifrado.
     */
    private function encryptSensitiveFields(array $row): array
    {
        if ($this->encryption === null) {
            return $row;
        }

        foreach (['ip_address', 'user_agent'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = $this->encryption->encrypt($row[$field]);
            }
        }

        // JSON con PII: se envuelve el ciphertext en un objeto JSON válido
        // {"__encrypted":"base64"} para cumplir el CHECK json_valid de la
        // columna. El hash ya se calculó sobre el contenido en claro; al
        // leer, AuditLogQueryService detecta __encrypted y descifra.
        foreach (['valores_anteriores', 'valores_nuevos', 'detalles_adicionales'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = json_encode(
                    ['__encrypted' => $this->encryption->encrypt($row[$field])],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        return $row;
    }

    /**
     * Candado de fila sobre `tenants` para serializar los escritores del tenant.
     * En SQLite (tests unitarios, un solo hilo) no hace falta: se omite.
     */
    private function lockTenant(string $tenantId): void
    {
        if (!$this->supportsForUpdate()) {
            return;
        }

        $this->db->query('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
    }

    private function supportsForUpdate(): bool
    {
        return $this->db->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function forUpdate(): string
    {
        return $this->supportsForUpdate() ? ' FOR UPDATE' : '';
    }
}
