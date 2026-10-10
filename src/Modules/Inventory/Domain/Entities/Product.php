<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\SKU;
use App\Modules\Inventory\Domain\ValueObjects\UnitOfMeasure;
use DateTimeImmutable;

final class Product
{
    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private SKU $sku,
        private string $nombre,
        private ?string $descripcion,
        private ?string $categoria,
        private UnitOfMeasure $unidadMedida,
        private ?float $peso,
        private ?float $volumen,
        private ?string $dimensiones,
        private bool $activo,
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
            sku: SKU::fromString($data['sku']),
            nombre: $data['nombre'],
            descripcion: $data['descripcion'] ?? null,
            categoria: $data['categoria'] ?? null,
            unidadMedida: UnitOfMeasure::fromString($data['unidad_medida']),
            peso: isset($data['peso']) ? (float) $data['peso'] : null,
            volumen: isset($data['volumen']) ? (float) $data['volumen'] : null,
            dimensiones: $data['dimensiones'] ?? null,
            activo: (bool) ($data['activo'] ?? true),
            metadatos: isset($data['metadatos']) ? (is_string($data['metadatos']) ? json_decode($data['metadatos'], true) : $data['metadatos']) : null,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $sku,
        string $nombre,
        string $unidadMedida,
        ?string $descripcion = null,
        ?string $categoria = null,
        ?float $peso = null,
        ?float $volumen = null,
        ?string $dimensiones = null,
        ?array $metadatos = null
    ): self {
        self::validateNombre($nombre);
        self::validatePeso($peso);
        self::validateVolumen($volumen);
        self::validateDimensiones($dimensiones);
        self::validateCategoria($categoria);

        $unit = UnitOfMeasure::fromString($unidadMedida);
        self::validateReglasNegocio($unit, $peso, $volumen, $categoria);

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            sku: SKU::fromString($sku),
            nombre: trim($nombre),
            descripcion: $descripcion !== null ? trim($descripcion) : null,
            categoria: $categoria !== null ? trim($categoria) : null,
            unidadMedida: $unit,
            peso: $peso,
            volumen: $volumen,
            dimensiones: $dimensiones !== null ? trim($dimensiones) : null,
            activo: true,
            metadatos: $metadatos,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null
        );
    }

    public function update(array $data): void
    {
        if ($this->isDeleted()) {
            throw InventoryException::productNotFound($this->id);
        }

        if (isset($data['sku'])) {
            $this->sku = SKU::fromString($data['sku']);
        }

        if (isset($data['unidad_medida'])) {
            $this->unidadMedida = UnitOfMeasure::fromString($data['unidad_medida']);
        }

        if (isset($data['nombre'])) {
            self::validateNombre($data['nombre']);
            $this->nombre = trim($data['nombre']);
        }

        if (array_key_exists('descripcion', $data)) {
            $this->descripcion = $data['descripcion'] !== null ? trim($data['descripcion']) : null;
        }

        if (array_key_exists('categoria', $data)) {
            self::validateCategoria($data['categoria']);
            $this->categoria = $data['categoria'] !== null ? trim($data['categoria']) : null;
        }

        if (isset($data['peso'])) {
            self::validatePeso((float) $data['peso']);
            $this->peso = (float) $data['peso'];
        }

        if (isset($data['volumen'])) {
            self::validateVolumen((float) $data['volumen']);
            $this->volumen = (float) $data['volumen'];
        }

        if (array_key_exists('dimensiones', $data)) {
            self::validateDimensiones($data['dimensiones']);
            $this->dimensiones = $data['dimensiones'] !== null ? trim($data['dimensiones']) : null;
        }

        if (isset($data['metadatos'])) {
            $this->metadatos = $data['metadatos'];
        }

        self::validateReglasNegocio($this->unidadMedida, $this->peso, $this->volumen, $this->categoria);

        $this->updatedAt = new DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->activo = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->activo = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->activo = false;
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
        return $this->activo && !$this->isDeleted();
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function sku(): SKU { return $this->sku; }
    public function nombre(): string { return $this->nombre; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function categoria(): ?string { return $this->categoria; }
    public function unidadMedida(): UnitOfMeasure { return $this->unidadMedida; }
    public function peso(): ?float { return $this->peso; }
    public function volumen(): ?float { return $this->volumen; }
    public function dimensiones(): ?string { return $this->dimensiones; }
    public function activo(): bool { return $this->activo; }
    public function metadatos(): ?array { return $this->metadatos; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'tenant_id'     => $this->tenantId,
            'sku'           => $this->sku->value(),
            'nombre'        => $this->nombre,
            'descripcion'   => $this->descripcion,
            'categoria'     => $this->categoria,
            'unidad_medida' => $this->unidadMedida->value(),
            'peso'          => $this->peso,
            'volumen'       => $this->volumen,
            'dimensiones'   => $this->dimensiones,
            'activo'        => $this->activo ? 1 : 0,
            'metadatos'     => $this->metadatos !== null ? json_encode($this->metadatos, JSON_UNESCAPED_UNICODE) : null,
            'created_at'    => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'    => $this->updatedAt->format('Y-m-d H:i:s'),
            'deleted_at'    => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    // ====================================================================
    // VALIDACIONES PRIVADAS
    // ====================================================================

    /**
     * Reglas de coherencia: impiden guardar productos con datos obligatorios en null.
     *  - categoria: siempre obligatoria
     *  - KG (peso): exige peso
     *  - M3 / LT (volumen): exigen volumen
     */
    private static function validateReglasNegocio(UnitOfMeasure $unidad, ?float $peso, ?float $volumen, ?string $categoria): void
    {
        if ($categoria === null || trim($categoria) === '') {
            throw InventoryException::requiredField('categoria');
        }

        if ($unidad->isWeight() && $peso === null) {
            throw InventoryException::requiredField('peso');
        }

        if ($unidad->isVolume() && $volumen === null) {
            throw InventoryException::requiredField('volumen');
        }
    }

    private static function validateNombre(string $nombre): void
    {
        $trimmed = trim($nombre);
        if ($trimmed === '') {
            throw InventoryException::requiredField('nombre');
        }
        if (mb_strlen($trimmed) > 255) {
            throw InventoryException::fieldMaxLength('nombre', 255);
        }
    }

    private static function validateCategoria(?string $categoria): void
    {
        if ($categoria !== null && mb_strlen(trim($categoria)) > 100) {
            throw InventoryException::fieldMaxLength('categoria', 100);
        }
    }

    private static function validatePeso(?float $peso): void
    {
        if ($peso !== null && $peso < 0) {
            throw InventoryException::invalidField('peso', 'Debe ser un valor positivo.');
        }
    }

    private static function validateVolumen(?float $volumen): void
    {
        if ($volumen !== null && $volumen < 0) {
            throw InventoryException::invalidField('volumen', 'Debe ser un valor positivo.');
        }
    }

    private static function validateDimensiones(?string $dimensiones): void
    {
        if ($dimensiones !== null && mb_strlen(trim($dimensiones)) > 100) {
            throw InventoryException::fieldMaxLength('dimensiones', 100);
        }
    }
}
