<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use DateTimeImmutable;

/**
 * Entidad: Unidad Física (stock_units) - spec §3.6
 *
 * Representa un lote/serie concreto de producto dentro de la cadena de
 * suministro. Sólo se crean; los cambios de estado/ubicación los refleja el
 * flujo de stock (ledger + eventos).
 */
final class StockUnit
{
    private const BARCODE_MAX_LENGTH = 64;

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $productoId,
        private ?string $propietarioId,
        private ?string $custodioId,
        private ?string $numeroSerie,
        private ?string $lote,
        private readonly string $codigoBarcode,
        private float $cantidad,
        private ?float $peso,
        private ?float $volumen,
        private StockState $estado,
        private ?string $ubicacionId,
        private readonly DateTimeImmutable $fechaIngreso,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            productoId: $data['producto_id'],
            propietarioId: $data['propietario_id'] ?? null,
            custodioId: $data['custodio_id'] ?? null,
            numeroSerie: $data['numero_serie'] ?? null,
            lote: $data['lote'] ?? null,
            codigoBarcode: (string) $data['codigo_barcode'],
            cantidad: (float) $data['cantidad'],
            peso: isset($data['peso']) && $data['peso'] !== null ? (float) $data['peso'] : null,
            volumen: isset($data['volumen']) && $data['volumen'] !== null ? (float) $data['volumen'] : null,
            estado: StockState::fromString($data['estado'] ?? StockState::DISPONIBLE),
            ubicacionId: $data['ubicacion_id'] ?? null,
            fechaIngreso: isset($data['fecha_ingreso'])
                ? new DateTimeImmutable($data['fecha_ingreso'])
                : new DateTimeImmutable(),
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at'])
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $productoId,
        string $codigoBarcode,
        float $cantidad,
        ?string $propietarioId = null,
        ?string $custodioId = null,
        ?string $numeroSerie = null,
        ?string $lote = null,
        ?float $peso = null,
        ?float $volumen = null,
        string $estado = StockState::DISPONIBLE,
        ?string $ubicacionId = null
    ): self {
        self::validateIds($id, $tenantId, $productoId);
        self::validateBarcode($codigoBarcode);
        self::validateCantidad($cantidad);
        self::validateOptativos($numeroSerie, $lote, $peso, $volumen);

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            productoId: $productoId,
            propietarioId: self::trimOrNull($propietarioId),
            custodioId: self::trimOrNull($custodioId),
            numeroSerie: self::trimOrNull($numeroSerie),
            lote: self::trimOrNull($lote),
            codigoBarcode: $codigoBarcode,
            cantidad: $cantidad,
            peso: $peso,
            volumen: $volumen,
            estado: StockState::fromString($estado),
            ubicacionId: $ubicacionId !== null ? trim($ubicacionId) : null,
            fechaIngreso: $now,
            createdAt: $now,
            updatedAt: $now
        );
    }

    /**
     * Asigna ubicación y/o estado a la unidad (manteniendo sus invariantes).
     *
     * @param array{ubicacion_id?: string|null, estado?: string} $cambios
     */
    public function actualizar(array $cambios): void
    {
        if (array_key_exists('ubicacion_id', $cambios)) {
            $ubicacionId = $cambios['ubicacion_id'];
            $this->ubicacionId = $ubicacionId !== null ? trim((string) $ubicacionId) : null;
        }

        if (isset($cambios['estado'])) {
            $this->estado = StockState::fromString((string) $cambios['estado']);
        }

        if (isset($cambios['cantidad'])) {
            $cantidad = (float) $cambios['cantidad'];
            self::validateCantidad($cantidad);
            $this->cantidad = $cantidad;
        }

        if (array_key_exists('propietario_id', $cambios)) {
            $this->propietarioId = self::trimOrNull($cambios['propietario_id']);
        }

        if (array_key_exists('custodio_id', $cambios)) {
            $this->custodioId = self::trimOrNull($cambios['custodio_id']);
        }

        $this->updatedAt = new DateTimeImmutable();
    }

    public function moverA(?string $ubicacionId, StockState $estado): void
    {
        $this->ubicacionId = $ubicacionId !== null ? trim($ubicacionId) : null;
        $this->estado = $estado;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function productoId(): string { return $this->productoId; }
    public function propietarioId(): ?string { return $this->propietarioId; }
    public function custodioId(): ?string { return $this->custodioId; }
    public function numeroSerie(): ?string { return $this->numeroSerie; }
    public function lote(): ?string { return $this->lote; }
    public function codigoBarcode(): string { return $this->codigoBarcode; }
    public function cantidad(): float { return $this->cantidad; }
    public function peso(): ?float { return $this->peso; }
    public function volumen(): ?float { return $this->volumen; }
    public function estado(): StockState { return $this->estado; }
    public function ubicacionId(): ?string { return $this->ubicacionId; }
    public function fechaIngreso(): DateTimeImmutable { return $this->fechaIngreso; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'tenant_id'       => $this->tenantId,
            'producto_id'     => $this->productoId,
            'propietario_id'  => $this->propietarioId,
            'custodio_id'     => $this->custodioId,
            'numero_serie'    => $this->numeroSerie,
            'lote'            => $this->lote,
            'codigo_barcode'  => $this->codigoBarcode,
            'cantidad'        => $this->cantidad,
            'peso'            => $this->peso,
            'volumen'         => $this->volumen,
            'estado'          => $this->estado->value(),
            'ubicacion_id'    => $this->ubicacionId,
            'fecha_ingreso'   => $this->fechaIngreso->format('Y-m-d H:i:s'),
            'created_at'      => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'      => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Representación pública para respuestas de API.
     */
    public function toPublicArray(): array
    {
        return [
            'unidad_fisica_id' => $this->id,
            'producto_id'      => $this->productoId,
            'codigo_barcode'   => $this->codigoBarcode,
            'cantidad'         => $this->cantidad,
            'estado'           => $this->estado->value(),
            'ubicacion_id'     => $this->ubicacionId,
            'lote'             => $this->lote,
            'numero_serie'     => $this->numeroSerie,
            'fecha_ingreso'    => $this->fechaIngreso->format('Y-m-d H:i:s'),
        ];
    }

    // ====================================================================
    // VALIDACIONES PRIVADAS
    // ====================================================================

    private static function validateIds(string $id, string $tenantId, string $productoId): void
    {
        if (trim($id) === '') {
            throw InventoryException::requiredField('id');
        }
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (trim($productoId) === '') {
            throw InventoryException::requiredField('producto_id');
        }
    }

    private static function validateBarcode(string $codigoBarcode): void
    {
        if (trim($codigoBarcode) === '') {
            throw InventoryException::requiredField('codigo_barcode');
        }
        if (strlen($codigoBarcode) > self::BARCODE_MAX_LENGTH) {
            throw InventoryException::fieldMaxLength('codigo_barcode', self::BARCODE_MAX_LENGTH);
        }
    }

    private static function validateCantidad(float $cantidad): void
    {
        if (!is_finite($cantidad) || $cantidad <= 0) {
            throw InventoryException::invalidQuantity('cantidad', 'Debe ser mayor que cero.');
        }
    }

    private static function validateOptativos(?string $numeroSerie, ?string $lote, ?float $peso, ?float $volumen): void
    {
        if ($numeroSerie !== null && mb_strlen(trim($numeroSerie)) > 255) {
            throw InventoryException::fieldMaxLength('numero_serie', 255);
        }
        if ($lote !== null && mb_strlen(trim($lote)) > 255) {
            throw InventoryException::fieldMaxLength('lote', 255);
        }
        if ($peso !== null && $peso < 0) {
            throw InventoryException::invalidField('peso', 'No puede ser negativo.');
        }
        if ($volumen !== null && $volumen < 0) {
            throw InventoryException::invalidField('volumen', 'No puede ser negativo.');
        }
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
