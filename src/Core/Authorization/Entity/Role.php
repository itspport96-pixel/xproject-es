<?php

declare(strict_types=1);

namespace App\Core\Authorization\Entity;

use App\Core\Authorization\Exception\AuthorizationException;
use DateTimeImmutable;
use InvalidArgumentException;

final class Role
{
    public const TYPE_BUILT_IN = 'BUILT_IN';
    public const TYPE_CUSTOM = 'CUSTOM';

    public const VALID_TYPES = [self::TYPE_BUILT_IN, self::TYPE_CUSTOM];

    public const BUILT_IN_ADMIN   = 'ADMIN';
    public const BUILT_IN_MANAGER = 'MANAGER';
    public const BUILT_IN_USER    = 'USER';
    public const BUILT_IN_VIEWER  = 'VIEWER';

    private const MAX_NOMBRE_LENGTH = 100;
    private const MAX_DESCRIPCION_LENGTH = 500;

    private function __construct(
        private readonly string $id,
        private string $nombre,
        private ?string $descripcion,
        private string $tipo,
        private readonly ?string $tenantId,
        private bool $activo,
        private array $permisos,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
    ) {
    }

    public static function create(
        string $id,
        string $nombre,
        ?string $descripcion = null,
        string $tipo = self::TYPE_CUSTOM,
        array $permisos = [],
        ?string $tenantId = null,
    ): self {
        self::validateNombre($nombre);
        self::validateTipo($tipo);
        self::validateDescripcion($descripcion);
        self::validatePermisos($permisos);

        if ($tipo === self::TYPE_BUILT_IN) {
            $tenantId = null;
        } elseif ($tenantId === null || trim($tenantId) === '') {
            throw new AuthorizationException('Un rol CUSTOM requiere un tenant_id', 400);
        }

        return new self(
            id: $id,
            nombre: strtoupper(trim($nombre)),
            descripcion: $descripcion !== null ? trim($descripcion) : null,
            tipo: $tipo,
            tenantId: $tenantId,
            activo: true,
            permisos: array_values(array_unique($permisos)),
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    public static function createBuiltIn(string $id, string $nombre, array $permisos = []): self
    {
        return self::create($id, $nombre, null, self::TYPE_BUILT_IN, $permisos);
    }

    public static function fromArray(array $data): self
    {
        $required = ['id', 'nombre', 'tipo'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        $permisos = $data['permisos'] ?? [];
        if (is_string($permisos)) {
            $decoded = json_decode($permisos, true);
            $permisos = is_array($decoded) ? $decoded : [];
        }

        $tenantId = $data['tenant_id'] ?? null;
        if ($tenantId === '' || $tenantId === false) {
            $tenantId = null;
        }

        return new self(
            id: (string) $data['id'],
            nombre: (string) $data['nombre'],
            descripcion: isset($data['descripcion']) ? (string) $data['descripcion'] : null,
            tipo: (string) $data['tipo'],
            tenantId: $tenantId !== null ? (string) $tenantId : null,
            activo: (bool) ($data['activo'] ?? 1),
            permisos: $permisos,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null,
        );
    }

    // GETTERS
    public function id(): string { return $this->id; }
    public function nombre(): string { return $this->nombre; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function tipo(): string { return $this->tipo; }
    public function tenantId(): ?string { return $this->tenantId; }
    public function activo(): bool { return $this->activo; }
    public function permisos(): array { return $this->permisos; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    // COMPORTAMIENTO
    public function actualizarNombre(string $nombre): void
    {
        $this->ensureMutable();
        self::validateNombre($nombre);
        $this->nombre = strtoupper(trim($nombre));
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

    public function agregarPermiso(string $permissionId): void
    {
        $this->ensureMutable();
        self::validatePermissionId($permissionId);

        if (!in_array($permissionId, $this->permisos, true)) {
            $this->permisos[] = $permissionId;
            $this->touch();
        }
    }

    public function agregarPermisos(array $permissionIds): void
    {
        $this->ensureMutable();
        foreach ($permissionIds as $pid) {
            self::validatePermissionId($pid);
        }

        $merged = array_unique(array_merge($this->permisos, $permissionIds));
        $this->permisos = array_values($merged);
        $this->touch();
    }

    public function quitarPermiso(string $permissionId): void
    {
        $this->ensureMutable();
        $this->permisos = array_values(
            array_filter($this->permisos, fn($p) => $p !== $permissionId)
        );
        $this->touch();
    }

    public function reemplazarPermisos(array $permissionIds): void
    {
        $this->ensureMutable();
        self::validatePermisos($permissionIds);
        $this->permisos = array_values(array_unique($permissionIds));
        $this->touch();
    }

    public function softDelete(): void
    {
        if ($this->isBuiltIn()) {
            throw new AuthorizationException('No se puede eliminar un rol BUILT_IN', 403);
        }
        $this->deletedAt = new DateTimeImmutable();
        $this->activo = false;
        $this->touch();
    }

    // QUERIES
    public function hasPermission(string $permissionId): bool
    {
        return in_array($permissionId, $this->permisos, true);
    }

    public function hasAnyPermission(array $permissionIds): bool
    {
        return count(array_intersect($permissionIds, $this->permisos)) > 0;
    }

    public function hasAllPermissions(array $permissionIds): bool
    {
        return count(array_intersect($permissionIds, $this->permisos)) === count($permissionIds);
    }

    public function isBuiltIn(): bool
    {
        return $this->tipo === self::TYPE_BUILT_IN;
    }

    public function isCustom(): bool
    {
        return $this->tipo === self::TYPE_CUSTOM;
    }

    /**
     * Indica si el rol puede usarse/verse desde el tenant indicado:
     * los BUILT_IN son visibles para todos, los CUSTOM sólo para su tenant.
     */
    public function usableByTenant(string $tenantId): bool
    {
        return $this->isBuiltIn() || $this->tenantId === $tenantId;
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
            'id'          => $this->id,
            'nombre'      => $this->nombre,
            'descripcion' => $this->descripcion,
            'tipo'        => $this->tipo,
            'tenant_id'   => $this->tenantId,
            'activo'      => $this->activo,
            'permisos'    => $this->permisos,
            'created_at'  => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'  => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at'  => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    // VALIDACIONES PRIVADAS
    private static function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new AuthorizationException('El nombre del rol no puede estar vacío', 400);
        }
        if (strlen($nombre) > self::MAX_NOMBRE_LENGTH) {
            throw new AuthorizationException('El nombre del rol excede longitud máxima (100)', 400);
        }
        if (!preg_match('/^[A-Z0-9_\- ]+$/i', $nombre)) {
            throw new AuthorizationException('El nombre del rol solo permite letras, números, espacios, guiones y guiones bajos', 400);
        }
    }

    private static function validateDescripcion(?string $descripcion): void
    {
        if ($descripcion === null) {
            return;
        }
        if (strlen($descripcion) > self::MAX_DESCRIPCION_LENGTH) {
            throw new AuthorizationException('La descripción excede longitud máxima (500)', 400);
        }
    }

    private static function validateTipo(string $tipo): void
    {
        if (!in_array($tipo, self::VALID_TYPES, true)) {
            throw new AuthorizationException("Tipo de rol inválido: {$tipo}", 400);
        }
    }

    private static function validatePermisos(array $permisos): void
    {
        foreach ($permisos as $pid) {
            self::validatePermissionId($pid);
        }
    }

    private static function validatePermissionId(string $permissionId): void
    {
        $permissionId = trim($permissionId);
        if ($permissionId === '') {
            throw new AuthorizationException('El identificador de permiso no puede estar vacío', 400);
        }
        if (!preg_match('/^[a-z0-9]+\.[a-z0-9_]+\.[a-z0-9_]+$/', $permissionId)) {
            throw new AuthorizationException("Formato de permiso inválido: '{$permissionId}' (esperado: modulo.recurso.accion)", 400);
        }
    }

    private function ensureMutable(): void
    {
        if ($this->isBuiltIn()) {
            throw new AuthorizationException('Los roles BUILT_IN no pueden ser modificados', 403);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
