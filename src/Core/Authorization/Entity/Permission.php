<?php

declare(strict_types=1);

namespace App\Core\Authorization\Entity;

use App\Core\Authorization\Exception\AuthorizationException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Permission Entity - Aggregate Root del dominio Authorization
 *
 * Representa un permiso granular en el sistema.
 * Formato: "modulo.recurso.accion" (ej: "inventory.item.create")
 */
final class Permission
{
    public const CRITICIDAD_LOW = 'LOW';
    public const CRITICIDAD_MEDIUM = 'MEDIUM';
    public const CRITICIDAD_HIGH = 'HIGH';
    public const CRITICIDAD_CRITICAL = 'CRITICAL';

    public const VALID_CRITICIDADES = [
        self::CRITICIDAD_LOW,
        self::CRITICIDAD_MEDIUM,
        self::CRITICIDAD_HIGH,
        self::CRITICIDAD_CRITICAL,
    ];

    private const MAX_IDENTIFICADOR_LENGTH = 255;
    private const MAX_NOMBRE_LENGTH = 100;
    private const MAX_DESCRIPCION_LENGTH = 500;

    private function __construct(
        private readonly string $id,
        private readonly string $identificador,
        private string $nombre,
        private ?string $descripcion,
        private readonly string $modulo,
        private readonly string $recurso,
        private readonly string $accion,
        private string $criticidad,
        private bool $requiereMfa,
        private bool $activo,
        private ?array $metadata,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $id,
        string $identificador,
        string $nombre,
        ?string $descripcion = null,
        string $criticidad = self::CRITICIDAD_MEDIUM,
        bool $requiereMfa = false,
        ?array $metadata = null,
    ): self {
        self::validateIdentificador($identificador);
        self::validateNombre($nombre);
        self::validateDescripcion($descripcion);
        self::validateCriticidad($criticidad);

        $parts = explode('.', $identificador);
        if (count($parts) !== 3) {
            throw new AuthorizationException("Identificador inválido: '{$identificador}' (esperado: modulo.recurso.accion)", 400);
        }

        [$modulo, $recurso, $accion] = $parts;

        return new self(
            id: $id,
            identificador: strtolower(trim($identificador)),
            nombre: trim($nombre),
            descripcion: $descripcion !== null ? trim($descripcion) : null,
            modulo: strtolower(trim($modulo)),
            recurso: strtolower(trim($recurso)),
            accion: strtolower(trim($accion)),
            criticidad: $criticidad,
            requiereMfa: $requiereMfa,
            activo: true,
            metadata: $metadata,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    public static function fromArray(array $data): self
    {
        $required = ['id', 'identificador', 'nombre', 'modulo', 'recurso', 'accion'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        $metadata = $data['metadata'] ?? null;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return new self(
            id: (string) $data['id'],
            identificador: (string) $data['identificador'],
            nombre: (string) $data['nombre'],
            descripcion: isset($data['descripcion']) ? (string) $data['descripcion'] : null,
            modulo: (string) $data['modulo'],
            recurso: (string) $data['recurso'],
            accion: (string) $data['accion'],
            criticidad: (string) ($data['criticidad'] ?? self::CRITICIDAD_MEDIUM),
            requiereMfa: (bool) ($data['requiere_mfa'] ?? false),
            activo: (bool) ($data['activo'] ?? true),
            metadata: $metadata,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
        );
    }

    public function id(): string { return $this->id; }
    public function identificador(): string { return $this->identificador; }
    public function nombre(): string { return $this->nombre; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function modulo(): string { return $this->modulo; }
    public function recurso(): string { return $this->recurso; }
    public function accion(): string { return $this->accion; }
    public function criticidad(): string { return $this->criticidad; }
    public function requiereMfa(): bool { return $this->requiereMfa; }
    public function activo(): bool { return $this->activo; }
    public function metadata(): ?array { return $this->metadata; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

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

    public function cambiarCriticidad(string $criticidad): void
    {
        self::validateCriticidad($criticidad);
        $this->criticidad = $criticidad;
        $this->touch();
    }

    public function activarMfa(): void
    {
        $this->requiereMfa = true;
        $this->touch();
    }

    public function desactivarMfa(): void
    {
        $this->requiereMfa = false;
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

    public function actualizarMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
        $this->touch();
    }

    public function isActive(): bool { return $this->activo; }
    public function isCritical(): bool { return $this->criticidad === self::CRITICIDAD_CRITICAL; }
    public function isHigh(): bool { return $this->criticidad === self::CRITICIDAD_HIGH; }
    public function requiresMfa(): bool { return $this->requiereMfa; }

    public function matchesPattern(string $pattern): bool
    {
        $regex = '/^' . str_replace('*', '[a-z0-9_]+', preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($regex, $this->identificador);
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'identificador' => $this->identificador,
            'nombre'        => $this->nombre,
            'descripcion'   => $this->descripcion,
            'modulo'        => $this->modulo,
            'recurso'       => $this->recurso,
            'accion'        => $this->accion,
            'criticidad'    => $this->criticidad,
            'requiere_mfa'  => $this->requiereMfa,
            'activo'        => $this->activo,
            'metadata'      => $this->metadata,
            'created_at'    => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'    => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    private static function validateIdentificador(string $identificador): void
    {
        $identificador = trim($identificador);
        if ($identificador === '') {
            throw (new AuthorizationException('El identificador del permiso no puede estar vacío', 400))->withMessageKey('roles.permission_identifier_required');
        }
        if (strlen($identificador) > self::MAX_IDENTIFICADOR_LENGTH) {
            throw new AuthorizationException("El identificador excede longitud máxima (" . self::MAX_IDENTIFICADOR_LENGTH . ")", 400);
        }
        if (!preg_match('/^[a-z0-9]+\.[a-z0-9_]+\.[a-z0-9_]+$/', $identificador)) {
            throw new AuthorizationException("Formato de identificador inválido: '{$identificador}' (esperado: modulo.recurso.accion)", 400);
        }
    }

    private static function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw (new AuthorizationException('El nombre del permiso no puede estar vacío', 400))->withMessageKey('roles.permission_name_required');
        }
        if (strlen($nombre) > self::MAX_NOMBRE_LENGTH) {
            throw new AuthorizationException("El nombre excede longitud máxima (" . self::MAX_NOMBRE_LENGTH . ")", 400);
        }
    }

    private static function validateDescripcion(?string $descripcion): void
    {
        if ($descripcion === null) {
            return;
        }
        if (strlen($descripcion) > self::MAX_DESCRIPCION_LENGTH) {
            throw new AuthorizationException("La descripción excede longitud máxima (" . self::MAX_DESCRIPCION_LENGTH . ")", 400);
        }
    }

    private static function validateCriticidad(string $criticidad): void
    {
        if (!in_array($criticidad, self::VALID_CRITICIDADES, true)) {
            throw new AuthorizationException("Criticidad inválida: '{$criticidad}'", 400);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
