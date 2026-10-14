<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Incidencia (incidents)
 *
 * Se registra junto con el hecho que la provoca (misma transacción), p.ej.
 * una recepción parcial con DISCREPANCIA_CANTIDAD (CU-001).
 */
final class Incident
{
    public const TIPO_DANO = 'DAÑO';
    public const TIPO_PERDIDA = 'PERDIDA';
    public const TIPO_DISCREPANCIA_CANTIDAD = 'DISCREPANCIA_CANTIDAD';
    public const TIPO_UBICACION_DESCONOCIDA = 'UBICACION_DESCONOCIDA';
    public const TIPO_ENTREGA_RECHAZADA = 'ENTREGA_RECHAZADA';
    public const TIPO_TRANSPORTE_FALLIDO = 'TRANSPORTE_FALLIDO';
    public const TIPO_INVENTARIO_DESAPARECIDO = 'INVENTARIO_DESAPARECIDO';
    public const TIPO_OTRO = 'OTRO';

    public const TIPOS_VALIDOS = [
        self::TIPO_DANO,
        self::TIPO_PERDIDA,
        self::TIPO_DISCREPANCIA_CANTIDAD,
        self::TIPO_UBICACION_DESCONOCIDA,
        self::TIPO_ENTREGA_RECHAZADA,
        self::TIPO_TRANSPORTE_FALLIDO,
        self::TIPO_INVENTARIO_DESAPARECIDO,
        self::TIPO_OTRO,
    ];

    public const ESTADO_ABIERTA = 'ABIERTA';
    public const ESTADO_INVESTIGACION = 'INVESTIGACION';
    public const ESTADO_RESUELTA = 'RESUELTA';
    public const ESTADO_NO_RESUELTO = 'NO_RESUELTO';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_ABIERTA,
        self::ESTADO_INVESTIGACION,
        self::ESTADO_RESUELTA,
        self::ESTADO_NO_RESUELTO,
    ];

    // v1.10.0 (E1): prioridad de la incidencia. ALTA la marca la recepción
    // cuando la discrepancia supera el umbral configurable
    // (RECEPTION_DISCREPANCY_THRESHOLD, en %).
    public const PRIORIDAD_NORMAL = 'NORMAL';
    public const PRIORIDAD_ALTA = 'ALTA';

    public const PRIORIDADES_VALIDAS = [
        self::PRIORIDAD_NORMAL,
        self::PRIORIDAD_ALTA,
    ];

    // v1.10.0 (E4): tipos cuyo reporte bloquea la unidad y cuya resolución
    // admite `resultado` (ENCONTRADA / NO_ENCONTRADA).
    public const TIPOS_CON_RESOLUCION_UNIDAD = [
        self::TIPO_UBICACION_DESCONOCIDA,
        self::TIPO_INVENTARIO_DESAPARECIDO,
    ];

    public const RESULTADO_ENCONTRADA = 'ENCONTRADA';
    public const RESULTADO_NO_ENCONTRADA = 'NO_ENCONTRADA';

    public const RESULTADOS_VALIDOS = [
        self::RESULTADO_ENCONTRADA,
        self::RESULTADO_NO_ENCONTRADA,
    ];

    private DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $tipo,
        private readonly ?string $unidadFisicaId,
        private readonly string $descripcion,
        private readonly ?string $observaciones,
        private readonly ?string $ubicacionId,
        private string $estado,
        private ?string $inventoryCountId,
        private readonly ?string $actorIdReporta,
        private readonly DateTimeImmutable $timestampDeteccion,
        private ?string $resolucion,
        private readonly string $prioridad
    ) {
        $this->updatedAt = $timestampDeteccion;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            tipo: (string) $data['tipo'],
            unidadFisicaId: $data['unidad_fisica_id'] ?? null,
            descripcion: (string) $data['descripcion'],
            observaciones: $data['observaciones'] ?? null,
            ubicacionId: $data['ubicacion_id'] ?? null,
            estado: (string) ($data['estado'] ?? self::ESTADO_ABIERTA),
            inventoryCountId: $data['inventory_count_id'] ?? null,
            actorIdReporta: $data['actor_id_reporta'] ?? null,
            timestampDeteccion: new DateTimeImmutable($data['timestamp_deteccion']),
            resolucion: $data['resolucion'] ?? null,
            prioridad: (string) ($data['prioridad'] ?? self::PRIORIDAD_NORMAL)
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $tipo,
        string $descripcion,
        ?string $unidadFisicaId = null,
        ?string $ubicacionId = null,
        ?string $actorIdReporta = null,
        ?string $observaciones = null,
        string $prioridad = self::PRIORIDAD_NORMAL
    ): self {
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw InventoryException::invalidField('tipo', "Tipo de incidencia desconocido: '{$tipo}'.");
        }
        if (trim($descripcion) === '') {
            throw InventoryException::requiredField('descripcion');
        }
        if (!in_array($prioridad, self::PRIORIDADES_VALIDAS, true)) {
            throw InventoryException::invalidField('prioridad', "Prioridad desconocida: '{$prioridad}'.");
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            tipo: $tipo,
            unidadFisicaId: $unidadFisicaId !== null ? trim($unidadFisicaId) : null,
            descripcion: trim($descripcion),
            observaciones: $observaciones !== null && trim($observaciones) !== '' ? trim($observaciones) : null,
            ubicacionId: $ubicacionId !== null ? trim($ubicacionId) : null,
            estado: self::ESTADO_ABIERTA,
            inventoryCountId: null,
            actorIdReporta: $actorIdReporta !== null ? trim($actorIdReporta) : null,
            timestampDeteccion: new DateTimeImmutable(),
            resolucion: null,
            prioridad: $prioridad
        );
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    /**
     * ABIERTA → INVESTIGACION (CU-010).
     */
    public function iniciarInvestigacion(): void
    {
        if ($this->estado !== self::ESTADO_ABIERTA) {
            throw InventoryException::incidentInvalidState($this->id, $this->estado, self::ESTADO_ABIERTA);
        }

        $this->estado = self::ESTADO_INVESTIGACION;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Cierra la incidencia en RESUELTA o NO_RESUELTO (CU-010) guardando el
     * detalle en el JSON `resolucion` junto con el actor y la fecha.
     *
     * @param array<string, mixed> $detalle descripcion_resolucion,
     *        acciones_tomadas y compensacion (opcional)
     */
    public function resolver(string $estadoFinal, array $detalle, string $actorId): void
    {
        if (!in_array($estadoFinal, [self::ESTADO_RESUELTA, self::ESTADO_NO_RESUELTO], true)) {
            throw InventoryException::invalidField(
                'estado',
                "Estado final desconocido: '{$estadoFinal}'. Debe ser RESUELTA o NO_RESUELTO."
            );
        }

        if (!in_array($this->estado, [self::ESTADO_ABIERTA, self::ESTADO_INVESTIGACION], true)) {
            throw InventoryException::incidentInvalidState($this->id, $this->estado, self::ESTADO_INVESTIGACION);
        }

        $this->estado = $estadoFinal;
        $this->resolucion = json_encode(
            $detalle + [
                'estado_final' => $estadoFinal,
                'actor_resuelve' => $actorId,
                'fecha_resolucion' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            JSON_UNESCAPED_UNICODE
        );
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Vincula la incidencia con el conteo físico generado (DISCREPANCIA_CANTIDAD).
     */
    public function vincularConteo(string $inventoryCountId): void
    {
        $this->inventoryCountId = trim($inventoryCountId);
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function tipo(): string { return $this->tipo; }
    public function unidadFisicaId(): ?string { return $this->unidadFisicaId; }
    public function descripcion(): string { return $this->descripcion; }
    public function observaciones(): ?string { return $this->observaciones; }
    public function ubicacionId(): ?string { return $this->ubicacionId; }
    public function estado(): string { return $this->estado; }
    public function inventoryCountId(): ?string { return $this->inventoryCountId; }
    public function actorIdReporta(): ?string { return $this->actorIdReporta; }
    public function timestampDeteccion(): DateTimeImmutable { return $this->timestampDeteccion; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function resolucion(): ?string { return $this->resolucion; }
    public function prioridad(): string { return $this->prioridad; }

    /** @return array<string, mixed>|null detalle JSON de la resolución */
    public function resolucionDetalle(): ?array
    {
        if ($this->resolucion === null || $this->resolucion === '') {
            return null;
        }

        $decoded = json_decode($this->resolucion, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'tenant_id'           => $this->tenantId,
            'tipo'                => $this->tipo,
            'unidad_fisica_id'    => $this->unidadFisicaId,
            'descripcion'         => $this->descripcion,
            'observaciones'       => $this->observaciones,
            'ubicacion_id'        => $this->ubicacionId,
            'estado'              => $this->estado,
            'inventory_count_id'  => $this->inventoryCountId,
            'actor_id_reporta'    => $this->actorIdReporta,
            'timestamp_deteccion' => $this->timestampDeteccion->format('Y-m-d H:i:s'),
            'resolucion'          => $this->resolucion,
            'prioridad'           => $this->prioridad,
            'created_at'          => $this->timestampDeteccion->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'incidencia_id'       => $this->id,
            'tipo'                => $this->tipo,
            'descripcion'         => $this->descripcion,
            'observaciones'       => $this->observaciones,
            'estado'              => $this->estado,
            'prioridad'           => $this->prioridad,
            'unidad_fisica_id'    => $this->unidadFisicaId,
            'ubicacion_id'        => $this->ubicacionId,
            'inventory_count_id'  => $this->inventoryCountId,
            'actor_id_reporta'    => $this->actorIdReporta,
            'fecha_deteccion'     => $this->timestampDeteccion->format('Y-m-d H:i:s'),
            'resolucion'          => $this->resolucionDetalle(),
        ];
    }
}
