<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Audit\AuditLogger;

/**
 * AuditLogger que sólo captura las entradas en memoria.
 *
 * No toca la persistencia (ni siquiera construye el AuditPort), por lo que
 * sirve para verificar las llamadas de dominio de los controllers y el hook
 * del Router sin depender de la BD.
 */
final class RecordingAuditLogger extends AuditLogger
{
    /** @var array<int, array<string, mixed>> */
    public array $entries = [];

    public function __construct()
    {
    }

    public function record(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }

    /** @return array<string, mixed>|null */
    public function byOperacion(string $operacion): ?array
    {
        foreach (array_reverse($this->entries) as $entry) {
            if (($entry['operacion'] ?? null) === $operacion) {
                return $entry;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
