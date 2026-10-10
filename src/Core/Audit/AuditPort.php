<?php

declare(strict_types=1);

namespace App\Core\Audit;

/**
 * AuditPort - puerta de persistencia del registro de auditoría (spec §7.1).
 *
 * Contrato append-only: el único camino de escritura es insert().
 * La interfaz NO declara update() ni delete(): los registros de auditoría
 * son inmutables y sólo se pueden añadir a la cadena.
 */
interface AuditPort
{
    /**
     * Ejecuta $callback dentro de una transacción atómica serializada por tenant.
     *
     * La serialización garantiza que dos escrituras simultáneas del mismo tenant
     * no puedan leer el mismo hash anterior (evita bifurcar la cadena de integridad).
     *
     * @param callable():mixed $callback
     * @return mixed Valor retornado por $callback
     * @throws \Throwable Si la transacción falla (nada queda escrito)
     */
    public function transaction(string $tenantId, callable $callback): mixed;

    /**
     * Hash SHA256 del último registro de auditoría del tenant.
     *
     * @return string|null null cuando el tenant todavía no tiene registros
     */
    public function previousHash(string $tenantId): ?string;

    /**
     * INSERT de un registro ya construido (incluye hash_integridad).
     *
     * @param array<string, mixed> $row
     * @throws \Throwable Si la fila no se puede insertar
     */
    public function insert(array $row): void;
}
