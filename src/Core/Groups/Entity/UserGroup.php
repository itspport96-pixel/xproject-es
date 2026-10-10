<?php

declare(strict_types=1);

namespace App\Core\Groups\Entity;

use App\Core\Groups\Exception\GroupException;
use DateTimeImmutable;

/**
 * Grupo de usuarios dentro de un tenant (spec §4.2).
 *
 * Tabla user_groups (BD viva): id, tenant_id, nombre, descripcion,
 * propietario_id, activo, created_at, updated_at, deleted_at.
 */
final class UserGroup
{
    private const MAX_NOMBRE_LENGTH = 100;
    private const MAX_DESCRIPCION_LENGTH = 500;

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private string $nombre,
        private ?string $descripcion,
        private ?string $propietarioId,
        private bool $activo,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
    ) {
    }

    public static function create(
        string $id,
        string $tenantId,
        string $nombre,
        ?string $descripcion = null,
        ?string $propietarioId = null,
    ): self {
        self::validateNombre($nombre);
        self::validateDescripcion($descripcion);

        return new self(
            id: $id,
            tenantId: $tenantId,
            nombre: trim($nombre),
            descripcion: $descripcion !== null ? trim($descripcion) : null,
            propietarioId: $propietarioId,
            activo: true,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    public static function fromArray(array $data): self
    {
        $required = ['id', 'tenant_id', 'nombre'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw GroupException::invalidField($field, "Campo requerido faltante: {$field}");
            }
        }

        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            nombre: (string) $data['nombre'],
            descripcion: isset($data['descripcion']) ? (string) $data['descripcion'] : null,
            propietarioId: isset($data['propietario_id']) ? (string) $data['propietario_id'] : null,
            activo: (bool) ($data['activo'] ?? 1),
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) && $data['updated_at'] !== null
                ? new DateTimeImmutable($data['updated_at'])
                : null,
            deletedAt: isset($data['deleted_at']) && $data['deleted_at'] !== null
                ? new DateTimeImmutable($data['deleted_at'])
                : null,
        );
    }

    // GETTERS
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function nombre(): string { return $this->nombre; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function propietarioId(): ?string { return $this->propietarioId; }
    public function activo(): bool { return $this->activo; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    // COMPORTAMIENTO
    public function actualizarNombre(string $nombre): void
    {
        self::validateNombre($nombre);
        $this->nombre = trim($nombre);
        $this->touch();
    }

    public function actualizarDescripcion(?string $descripcion): void
    {
        self::validateDescripcion($descripcion);
        $this->descripcion = $descripcion !== null ? trim($descripcion) : null;
        $this->touch();
    }

    public function activar(): void
    {
        $this->activo = true;
        $this->touch();
    }

    public function desactivar(): void
    {
        $this->activo = false;
        $this->touch();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->activo = false;
        $this->touch();
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isActive(): bool
    {
        return $this->activo && $this->deletedAt === null;
    }

    // SERIALIZACIÓN
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'tenant_id'      => $this->tenantId,
            'nombre'         => $this->nombre,
            'descripcion'    => $this->descripcion,
            'propietario_id' => $this->propietarioId,
            'activo'         => $this->activo,
            'created_at'     => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'     => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at'     => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    // VALIDACIONES
    private static function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw GroupException::requiredField('nombre');
        }
        if (strlen($nombre) > self::MAX_NOMBRE_LENGTH) {
            throw GroupException::invalidField('nombre', 'El nombre del grupo excede longitud máxima (100).');
        }
    }

    private static function validateDescripcion(?string $descripcion): void
    {
        if ($descripcion === null) {
            return;
        }
        if (strlen($descripcion) > self::MAX_DESCRIPCION_LENGTH) {
            throw GroupException::invalidField('descripcion', 'La descripción excede longitud máxima (500).');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
