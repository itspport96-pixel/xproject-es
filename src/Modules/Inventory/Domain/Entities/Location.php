<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\LocationCode;
use App\Modules\Inventory\Domain\ValueObjects\LocationType;
use DateTimeImmutable;

final class Location
{
    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private LocationCode $codigo,
        private ?string $nombre,
        private LocationType $tipo,
        private ?string $jerarquia,
        private ?int $capacidad,
        private int $capacidadUsada,
        private bool $activa,
        private ?array $metadatos,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            codigo: LocationCode::fromString($data['codigo']),
            nombre: $data['nombre'] ?? null,
            tipo: LocationType::fromString($data['tipo']),
            jerarquia: $data['jerarquia'] ?? null,
            capacidad: isset($data['capacidad']) ? (int) $data['capacidad'] : null,
            capacidadUsada: (int) ($data['capacidad_usada'] ?? 0),
            activa: (bool) ($data['activa'] ?? true),
            metadatos: isset($data['metadatos']) ? (is_string($data['metadatos']) ? json_decode($data['metadatos'], true) : $data['metadatos']) : null,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $codigo,
        string $tipo,
        ?string $nombre = null,
        ?string $jerarquia = null,
        ?int $capacidad = null,
        ?array $metadatos = null
    ): self {
        self::validateNombre($nombre);
        self::validateJerarquia($jerarquia);
        self::validateCapacidad($capacidad);

        $tipoInstance = LocationType::fromString($tipo);
        self::validateReglasNegocio($id, $tipoInstance, $capacidad, 0);

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            codigo: LocationCode::fromString($codigo),
            nombre: $nombre !== null ? trim($nombre) : null,
            tipo: $tipoInstance,
            jerarquia: $jerarquia !== null ? trim($jerarquia) : null,
            capacidad: $capacidad,
            capacidadUsada: 0,
            activa: true,
            metadatos: $metadatos,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null
        );
    }

    public function update(array $data): void
    {
        if ($this->isDeleted()) {
            throw InventoryException::locationNotFound($this->id);
        }

        if (isset($data['codigo'])) {
            $this->codigo = LocationCode::fromString($data['codigo']);
        }

        if (isset($data['tipo'])) {
            $this->tipo = LocationType::fromString($data['tipo']);
        }

        if (array_key_exists('nombre', $data)) {
            self::validateNombre($data['nombre']);
            $this->nombre = $data['nombre'] !== null ? trim($data['nombre']) : null;
        }

        if (array_key_exists('jerarquia', $data)) {
            self::validateJerarquia($data['jerarquia']);
            $this->jerarquia = $data['jerarquia'] !== null ? trim($data['jerarquia']) : null;
        }

        if (array_key_exists('capacidad', $data)) {
            self::validateCapacidad($data['capacidad']);
            $this->capacidad = $data['capacidad'] !== null ? (int) $data['capacidad'] : null;
        }

        if (array_key_exists('capacidad_usada', $data)) {
            $this->capacidadUsada = (int) $data['capacidad_usada'];
        }

        if (isset($data['metadatos'])) {
            $this->metadatos = $data['metadatos'];
        }

        self::validateReglasNegocio($this->id, $this->tipo, $this->capacidad, $this->capacidadUsada);

        $this->updatedAt = new DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->activa = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->activa = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->activa = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function incrementarCapacidadUsada(int $cantidad = 1): void
    {
        if ($this->capacidad !== null && ($this->capacidadUsada + $cantidad) > $this->capacidad) {
            throw InventoryException::capacityExceeded($this->id, $this->capacidad, $this->capacidadUsada + $cantidad);
        }
        $this->capacidadUsada += $cantidad;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function decrementarCapacidadUsada(int $cantidad = 1): void
    {
        $this->capacidadUsada = max(0, $this->capacidadUsada - $cantidad);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setCapacidadUsada(int $cantidad): void
    {
        if ($cantidad < 0) {
            throw InventoryException::invalidCapacity($this->capacidad, $cantidad);
        }
        if ($this->capacidad !== null && $cantidad > $this->capacidad) {
            throw InventoryException::capacityExceeded($this->id, $this->capacidad, $cantidad);
        }
        $this->capacidadUsada = $cantidad;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isActive(): bool
    {
        return $this->activa && !$this->isDeleted();
    }

    public function hasCapacityAvailable(): bool
    {
        if ($this->capacidad === null) {
            return true;
        }
        return $this->capacidadUsada < $this->capacidad;
    }

    public function capacityRemaining(): ?int
    {
        if ($this->capacidad === null) {
            return null;
        }
        return max(0, $this->capacidad - $this->capacidadUsada);
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function codigo(): LocationCode { return $this->codigo; }
    public function nombre(): ?string { return $this->nombre; }
    public function tipo(): LocationType { return $this->tipo; }
    public function jerarquia(): ?string { return $this->jerarquia; }
    public function capacidad(): ?int { return $this->capacidad; }
    public function capacidadUsada(): int { return $this->capacidadUsada; }
    public function activa(): bool { return $this->activa; }
    public function metadatos(): ?array { return $this->metadatos; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'tenant_id'       => $this->tenantId,
            'codigo'          => $this->codigo->value(),
            'nombre'          => $this->nombre,
            'tipo'            => $this->tipo->value(),
            'jerarquia'       => $this->jerarquia,
            'capacidad'       => $this->capacidad,
            'capacidad_usada' => $this->capacidadUsada,
            'activa'          => $this->activa ? 1 : 0,
            'metadatos'       => $this->metadatos !== null ? json_encode($this->metadatos, JSON_UNESCAPED_UNICODE) : null,
            'created_at'      => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'      => $this->updatedAt->format('Y-m-d H:i:s'),
            'deleted_at'      => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    // ====================================================================
    // VALIDACIONES PRIVADAS
    // ====================================================================

    /**
     * Reglas de coherencia: impiden guardar ubicaciones con datos incoherentes.
     *  - TRANSITO (virtual): no admite capacidad física
     *  - capacidad: nunca menor que la capacidad ya usada
     */
    private static function validateReglasNegocio(string $id, LocationType $tipo, ?int $capacidad, int $capacidadUsada): void
    {
        if ($tipo->isVirtual() && $capacidad !== null) {
            throw InventoryException::invalidField('capacidad', 'Las ubicaciones virtuales (TRANSITO) no tienen capacidad física.');
        }

        if ($capacidad !== null && $capacidadUsada > $capacidad) {
            throw InventoryException::capacityExceeded($id, $capacidad, $capacidadUsada);
        }
    }

    private static function validateNombre(?string $nombre): void
    {
        if ($nombre !== null && mb_strlen(trim($nombre)) > 255) {
            throw InventoryException::fieldMaxLength('nombre', 255);
        }
    }

    private static function validateJerarquia(?string $jerarquia): void
    {
        if ($jerarquia !== null && mb_strlen(trim($jerarquia)) > 255) {
            throw InventoryException::fieldMaxLength('jerarquia', 255);
        }
    }

    private static function validateCapacidad(?int $capacidad): void
    {
        if ($capacidad !== null && $capacidad <= 0) {
            throw InventoryException::invalidField('capacidad', 'Debe ser un entero positivo.');
        }
    }
}
