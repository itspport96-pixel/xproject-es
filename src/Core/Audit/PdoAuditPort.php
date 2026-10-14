<?php

declare(strict_types=1);

namespace App\Core\Audit;

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

    public function __construct(private readonly DatabaseService $db)
    {
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
