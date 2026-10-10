<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Shipment / Transporte (shipments) - spec §3.13
 *
 * Envío que agrupa las unidades cargadas para su despacho (CU-005/CU-006):
 *
 *  PREPARACION → EN_RUTA      al autorizar la salida (supervisor ≠ quien carga)
 *  EN_RUTA     → ENTREGADO | FALLO_ENTREGA | DEVUELTO   (CU-006+)
 *
 * `hora_salida` y `actor_id_autoriza_salida` se rellenan al autorizar; los
 * timestamps de la tabla son UTC (configuración del sprint v1.7.0).
 *
 * @property-read string $estado PREPARACION | EN_RUTA | ENTREGADO | FALLO_ENTREGA | DEVUELTO
 */
final class Shipment
{
    public const ESTADO_PREPARACION  = 'PREPARACION';
    public const ESTADO_EN_RUTA      = 'EN_RUTA';
    public const ESTADO_ENTREGADO    = 'ENTREGADO';
    public const ESTADO_FALLO        = 'FALLO_ENTREGA';
    public const ESTADO_DEVUELTO     = 'DEVUELTO';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PREPARACION,
        self::ESTADO_EN_RUTA,
        self::ESTADO_ENTREGADO,
        self::ESTADO_FALLO,
        self::ESTADO_DEVUELTO,
    ];

    public const TIPOS_VALIDOS = ['VEHICULO', 'MENSAJERIA', 'PROPIO', 'TERCERO'];
    public const TIPO_DEFECTO = 'PROPIO';

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private ?string $referenciaExterna,
        private string $tipo,
        private ?string $origenUbicacionId,
        private ?string $destinoDireccion,
        private ?string $destinatarioId,
        private ?string $destinatarioNombre,
        private ?string $destinatarioContacto,
        private ?string $conductorNombre,
        private ?string $conductorContacto,
        private ?string $vehiculoReferencia,
        private string $estado,
        private ?DateTimeImmutable $horaSalida,
        private ?DateTimeImmutable $horaEntregaEsperada,
        private ?DateTimeImmutable $horaEntregaReal,
        private ?string $observaciones,
        private ?string $actorIdAutorizaSalida,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        $estado = strtoupper(trim((string) ($data['estado'] ?? self::ESTADO_PREPARACION)));

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw InventoryException::invalidField('estado', "Estado de envío desconocido: '{$estado}'.");
        }

        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            referenciaExterna: $data['referencia_externa'] ?? null,
            tipo: strtoupper(trim((string) ($data['tipo'] ?? self::TIPO_DEFECTO))),
            origenUbicacionId: $data['origen_ubicacion_id'] ?? null,
            destinoDireccion: $data['destino_direccion'] ?? null,
            destinatarioId: $data['destinatario_id'] ?? null,
            destinatarioNombre: $data['destinatario_nombre'] ?? null,
            destinatarioContacto: $data['destinatario_contacto'] ?? null,
            conductorNombre: $data['conductor_nombre'] ?? null,
            conductorContacto: $data['conductor_contacto'] ?? null,
            vehiculoReferencia: $data['vehiculo_referencia'] ?? null,
            estado: $estado,
            horaSalida: !empty($data['hora_salida']) ? new DateTimeImmutable($data['hora_salida']) : null,
            horaEntregaEsperada: !empty($data['hora_entrega_esperada'])
                ? new DateTimeImmutable($data['hora_entrega_esperada'])
                : null,
            horaEntregaReal: !empty($data['hora_entrega_real'])
                ? new DateTimeImmutable($data['hora_entrega_real'])
                : null,
            observaciones: $data['observaciones'] ?? null,
            actorIdAutorizaSalida: $data['actor_id_autoriza_salida'] ?? null,
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            updatedAt: new DateTimeImmutable($data['updated_at'] ?? 'now')
        );
    }

    /**
     * @param array<string, mixed> $datos Datos opcionales del envío (spec §3.13)
     */
    public static function create(
        string $id,
        string $tenantId,
        array $datos = [],
        ?DateTimeImmutable $now = null
    ): self {
        if (trim($id) === '') {
            throw InventoryException::requiredField('id');
        }
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }

        $tipo = strtoupper(trim((string) ($datos['tipo'] ?? self::TIPO_DEFECTO)));
        if ($tipo === '') {
            $tipo = self::TIPO_DEFECTO;
        }
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw InventoryException::invalidField(
                'tipo',
                'Tipo de envío desconocido: \'' . $tipo . '\'. Válidos: ' . implode(', ', self::TIPOS_VALIDOS) . '.'
            );
        }

        $fecha = $now ?? new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            referenciaExterna: self::trimOrNull($datos['referencia_externa'] ?? null),
            tipo: $tipo,
            origenUbicacionId: self::trimOrNull($datos['origen_ubicacion_id'] ?? null),
            destinoDireccion: self::trimOrNull($datos['destino_direccion'] ?? null),
            destinatarioId: self::trimOrNull($datos['destinatario_id'] ?? null),
            destinatarioNombre: self::trimOrNull($datos['destinatario_nombre'] ?? null),
            destinatarioContacto: self::trimOrNull($datos['destinatario_contacto'] ?? null),
            conductorNombre: self::trimOrNull($datos['conductor_nombre'] ?? null),
            conductorContacto: self::trimOrNull($datos['conductor_contacto'] ?? null),
            vehiculoReferencia: self::trimOrNull($datos['vehiculo_referencia'] ?? null),
            estado: self::ESTADO_PREPARACION,
            horaSalida: null,
            horaEntregaEsperada: self::parseFecha($datos['hora_entrega_esperada'] ?? null, 'hora_entrega_esperada'),
            horaEntregaReal: null,
            observaciones: self::trimOrNull($datos['observaciones'] ?? null),
            actorIdAutorizaSalida: null,
            createdAt: $fecha,
            updatedAt: $fecha
        );
    }

    /**
     * Autoriza la salida (CU-006): PREPARACION → EN_RUTA con `hora_salida`
     * (UTC) y el supervisor que autoriza. El use case valida el estado antes.
     */
    public function autorizarSalida(string $actorIdAutorizaSalida, ?DateTimeImmutable $now = null): void
    {
        if ($this->estado !== self::ESTADO_PREPARACION) {
            throw InventoryException::shipmentNotInPreparation($this->id, $this->estado);
        }
        if (trim($actorIdAutorizaSalida) === '') {
            throw InventoryException::requiredField('actor_id_autoriza_salida');
        }

        $this->estado = self::ESTADO_EN_RUTA;
        $this->horaSalida = $now ?? new DateTimeImmutable();
        $this->actorIdAutorizaSalida = trim($actorIdAutorizaSalida);
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * ¿Tiene destinatario válido para autorizar la salida? (spec CU-006)
     */
    public function tieneDestinatarioValido(): bool
    {
        return trim((string) $this->destinatarioNombre) !== ''
            || trim((string) $this->destinatarioId) !== '';
    }

    /**
     * Marca la salida como entregada (CU-007): EN_RUTA → ENTREGADO con
     * `hora_entrega_real` (UTC). El use case valida el estado antes.
     */
    public function marcarEntregado(?DateTimeImmutable $now = null): void
    {
        if ($this->estado !== self::ESTADO_EN_RUTA && $this->estado !== self::ESTADO_ENTREGADO) {
            throw InventoryException::shipmentNotOnRoute($this->id, $this->estado);
        }

        $this->estado = self::ESTADO_ENTREGADO;
        $this->horaEntregaReal ??= $now ?? new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Marca la salida como fallida (CU-008 / E3): EN_RUTA → FALLO_ENTREGA.
     */
    public function marcarFalloEntrega(): void
    {
        if ($this->estado !== self::ESTADO_EN_RUTA && $this->estado !== self::ESTADO_FALLO) {
            throw InventoryException::shipmentNotOnRoute($this->id, $this->estado);
        }

        $this->estado = self::ESTADO_FALLO;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Fija la ubicación de origen del envío (v1.8.3, spec CU-005): el
     * use case la deriva de la zona de preparación de las unidades cuando el
     * cliente no envía `origen_ubicacion_id`.
     */
    public function definirOrigen(string $ubicacionId): void
    {
        $ubicacionId = trim($ubicacionId);

        if ($ubicacionId === '') {
            throw InventoryException::requiredField('origen_ubicacion_id');
        }

        $this->origenUbicacionId = $ubicacionId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function referenciaExterna(): ?string { return $this->referenciaExterna; }
    public function tipo(): string { return $this->tipo; }
    public function origenUbicacionId(): ?string { return $this->origenUbicacionId; }
    public function destinoDireccion(): ?string { return $this->destinoDireccion; }
    public function destinatarioId(): ?string { return $this->destinatarioId; }
    public function destinatarioNombre(): ?string { return $this->destinatarioNombre; }
    public function destinatarioContacto(): ?string { return $this->destinatarioContacto; }
    public function conductorNombre(): ?string { return $this->conductorNombre; }
    public function conductorContacto(): ?string { return $this->conductorContacto; }
    public function vehiculoReferencia(): ?string { return $this->vehiculoReferencia; }
    public function estado(): string { return $this->estado; }
    public function horaSalida(): ?DateTimeImmutable { return $this->horaSalida; }
    public function horaEntregaEsperada(): ?DateTimeImmutable { return $this->horaEntregaEsperada; }
    public function horaEntregaReal(): ?DateTimeImmutable { return $this->horaEntregaReal; }
    public function observaciones(): ?string { return $this->observaciones; }
    public function actorIdAutorizaSalida(): ?string { return $this->actorIdAutorizaSalida; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'referencia_externa' => $this->referenciaExterna,
            'tipo' => $this->tipo,
            'origen_ubicacion_id' => $this->origenUbicacionId,
            'destino_direccion' => $this->destinoDireccion,
            'destinatario_id' => $this->destinatarioId,
            'destinatario_nombre' => $this->destinatarioNombre,
            'destinatario_contacto' => $this->destinatarioContacto,
            'conductor_nombre' => $this->conductorNombre,
            'conductor_contacto' => $this->conductorContacto,
            'vehiculo_referencia' => $this->vehiculoReferencia,
            'estado' => $this->estado,
            'hora_salida' => $this->horaSalida?->format('Y-m-d H:i:s'),
            'hora_entrega_esperada' => $this->horaEntregaEsperada?->format('Y-m-d H:i:s'),
            'hora_entrega_real' => $this->horaEntregaReal?->format('Y-m-d H:i:s'),
            'observaciones' => $this->observaciones,
            'actor_id_autoriza_salida' => $this->actorIdAutorizaSalida,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'shipment_id' => $this->id,
            'referencia_externa' => $this->referenciaExterna,
            'tipo' => $this->tipo,
            'origen_ubicacion_id' => $this->origenUbicacionId,
            'destino_direccion' => $this->destinoDireccion,
            'destinatario_id' => $this->destinatarioId,
            'destinatario_nombre' => $this->destinatarioNombre,
            'destinatario_contacto' => $this->destinatarioContacto,
            'conductor_nombre' => $this->conductorNombre,
            'conductor_contacto' => $this->conductorContacto,
            'vehiculo_referencia' => $this->vehiculoReferencia,
            'estado' => $this->estado,
            'hora_salida' => $this->horaSalida?->format('Y-m-d H:i:s'),
            'hora_entrega_esperada' => $this->horaEntregaEsperada?->format('Y-m-d H:i:s'),
            'hora_entrega_real' => $this->horaEntregaReal?->format('Y-m-d H:i:s'),
            'observaciones' => $this->observaciones,
            'actor_id_autoriza_salida' => $this->actorIdAutorizaSalida,
            'fecha_creacion' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function parseFecha(mixed $valor, string $campo): ?DateTimeImmutable
    {
        $texto = self::trimOrNull(is_scalar($valor) ? (string) $valor : null);

        if ($texto === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($texto);
        } catch (\Throwable) {
            throw InventoryException::invalidField($campo, 'Debe ser una fecha y hora válidas (ej. 2026-10-01T12:00:00).');
        }
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
