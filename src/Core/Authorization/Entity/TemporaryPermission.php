<?php

declare(strict_types=1);

namespace App\Core\Authorization\Entity;

use DateTimeImmutable;

/**
 * Permiso temporal (spec §5.3, v1.11.0).
 *
 * Permiso limitado en tiempo sobre un usuario, con optionalmente un recurso
 * concreto (recurso_id, ej: "unidad_fisica:12345"). La tabla
 * temporary_permissions no tiene tenant_id: el tenant se determina a través
 * del usuario beneficiario (users.tenant_id).
 */
final class TemporaryPermission
{
    private string $id;
    private string $userId;
    private string $permissionId;
    private ?string $recursoId;
    private ?string $grantedByUserId;
    private ?string $motivo;
    private DateTimeImmutable $fechaInicio;
    private DateTimeImmutable $fechaExpiracion;
    private bool $activo;
    private ?DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;

    private function __construct(
        string $id,
        string $userId,
        string $permissionId,
        ?string $recursoId,
        ?string $grantedByUserId,
        ?string $motivo,
        DateTimeImmutable $fechaInicio,
        DateTimeImmutable $fechaExpiracion,
        bool $activo,
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->permissionId = $permissionId;
        $this->recursoId = $recursoId;
        $this->grantedByUserId = $grantedByUserId;
        $this->motivo = $motivo;
        $this->fechaInicio = $fechaInicio;
        $this->fechaExpiracion = $fechaExpiracion;
        $this->activo = $activo;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public static function create(
        string $id,
        string $userId,
        string $permissionId,
        ?string $recursoId,
        ?string $grantedByUserId,
        ?string $motivo,
        DateTimeImmutable $fechaInicio,
        DateTimeImmutable $fechaExpiracion
    ): self {
        return new self(
            $id,
            $userId,
            $permissionId,
            $recursoId,
            $grantedByUserId,
            $motivo,
            $fechaInicio,
            $fechaExpiracion,
            true,
            null,
            null
        );
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['user_id'],
            (string) $row['permission_id'],
            $row['recurso_id'] !== null ? (string) $row['recurso_id'] : null,
            $row['granted_by_user_id'] !== null ? (string) $row['granted_by_user_id'] : null,
            $row['motivo'] !== null ? (string) $row['motivo'] : null,
            new DateTimeImmutable((string) $row['fecha_inicio']),
            new DateTimeImmutable((string) $row['fecha_expiracion']),
            (bool) $row['activo'],
            isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function permissionId(): string
    {
        return $this->permissionId;
    }

    public function recursoId(): ?string
    {
        return $this->recursoId;
    }

    public function grantedByUserId(): ?string
    {
        return $this->grantedByUserId;
    }

    public function motivo(): ?string
    {
        return $this->motivo;
    }

    public function fechaInicio(): DateTimeImmutable
    {
        return $this->fechaInicio;
    }

    public function fechaExpiracion(): DateTimeImmutable
    {
        return $this->fechaExpiracion;
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable('now');
        return $this->fechaExpiracion <= $now;
    }

    /**
     * Vigente: activo, no expirado y con fecha de inicio alcanzada
     * (mismos criterios que AuthorizationService::getTemporaryPermissions).
     */
    public function isVigente(?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable('now');
        return $this->activo
            && $this->fechaInicio <= $now
            && $this->fechaExpiracion > $now;
    }

    public function revoke(): void
    {
        $this->activo = false;
        $this->updatedAt = new DateTimeImmutable('now');
    }

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'user_id'             => $this->userId,
            'permission_id'       => $this->permissionId,
            'recurso_id'          => $this->recursoId,
            'granted_by_user_id'  => $this->grantedByUserId,
            'motivo'              => $this->motivo,
            'fecha_inicio'        => $this->fechaInicio->format('Y-m-d H:i:s'),
            'fecha_expiracion'    => $this->fechaExpiracion->format('Y-m-d H:i:s'),
            'activo'              => $this->activo,
            'expirado'            => $this->isExpired(),
            'vigente'             => $this->isVigente(),
            'created_at'          => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
