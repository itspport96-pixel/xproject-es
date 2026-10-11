<?php

declare(strict_types=1);

namespace App\Core\Users\Entity;

use App\Core\Users\Exception\UserException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * User Entity - Aggregate Root del dominio Users
 *
 * Representa la identidad individual de un usuario dentro de un tenant.
 * Inmutable en su identidad (id, tenant_id), mutable en sus atributos.
 *
 * Reglas de negocio:
 * - Email único por tenant
 * - Password debe estar hasheado (bcrypt)
 * - Estado controla el ciclo de vida
 * - MFA es opcional por tenant
 */
final class User
{
    private const STATUS_ACTIVE = 'ACTIVO';
    private const STATUS_INACTIVE = 'INACTIVO';
    private const STATUS_SUSPENDED = 'SUSPENDIDO';
    private const STATUS_PENDING = 'PENDIENTE_CONFIRMACION';

    private const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_PENDING,
    ];

    private const MFA_TOTP = 'TOTP';
    private const MFA_EMAIL = 'EMAIL';
    private const MFA_SMS = 'SMS';

    private const VALID_MFA_METHODS = [
        self::MFA_TOTP,
        self::MFA_EMAIL,
        self::MFA_SMS,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private string $email,
        private string $nombre,
        private string $apellido,
        private string $password,
        private string $estado,
        private bool $emailVerificado,
        private ?string $telefono,
        private ?string $avatarUrl,
        private ?DateTimeImmutable $ultimoLogin,
        private ?DateTimeImmutable $fechaExpiracionPassword,
        private bool $mfaHabilitado,
        private ?string $mfaMetodo,
        private ?array $preferencias,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
    ) {
    }

    /**
     * Factory: Crea un nuevo usuario (para registro/creación)
     */
    public static function create(
        string $id,
        string $tenantId,
        string $email,
        string $nombre,
        string $apellido,
        string $passwordHashed,
        ?string $telefono = null,
        bool $mfaHabilitado = false,
        ?string $mfaMetodo = null,
        ?array $preferencias = null,
    ): self {
        self::validateEmail($email);
        self::validateNombre($nombre);
        self::validateApellido($apellido);
        self::validatePasswordHash($passwordHashed);

        if ($mfaHabilitado && $mfaMetodo !== null) {
            self::validateMfaMethod($mfaMetodo);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            email: strtolower(trim($email)),
            nombre: trim($nombre),
            apellido: trim($apellido),
            password: $passwordHashed,
            estado: self::STATUS_ACTIVE,
            emailVerificado: false,
            telefono: $telefono ? trim($telefono) : null,
            avatarUrl: null,
            ultimoLogin: null,
            fechaExpiracionPassword: null,
            mfaHabilitado: $mfaHabilitado,
            mfaMetodo: $mfaMetodo,
            preferencias: $preferencias,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    /**
     * Factory: Reconstruye desde persistencia (hidratación)
     */
    public static function fromArray(array $data): self
    {
        $required = ['id', 'tenant_id', 'email', 'nombre', 'apellido', 'password', 'estado'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            email: (string) $data['email'],
            nombre: (string) $data['nombre'],
            apellido: (string) $data['apellido'],
            password: (string) $data['password'],
            estado: (string) $data['estado'],
            emailVerificado: (bool) ($data['email_verificado'] ?? false),
            telefono: isset($data['telefono']) ? (string) $data['telefono'] : null,
            avatarUrl: isset($data['avatar_url']) ? (string) $data['avatar_url'] : null,
            ultimoLogin: isset($data['ultimo_login']) ? new DateTimeImmutable($data['ultimo_login']) : null,
            fechaExpiracionPassword: isset($data['fecha_expiracion_password']) ? new DateTimeImmutable($data['fecha_expiracion_password']) : null,
            mfaHabilitado: (bool) ($data['mfa_habilitado'] ?? false),
            mfaMetodo: isset($data['mfa_metodo']) ? (string) $data['mfa_metodo'] : null,
            preferencias: isset($data['preferencias']) ? (is_string($data['preferencias']) ? json_decode($data['preferencias'], true) : $data['preferencias']) : null,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null,
        );
    }

    // ========================================================================
    // GETTERS (Inmutables para identidad, mutables para atributos)
    // ========================================================================

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function apellido(): string
    {
        return $this->apellido;
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellido}");
    }

    public function password(): string
    {
        return $this->password;
    }

    public function estado(): string
    {
        return $this->estado;
    }

    public function emailVerificado(): bool
    {
        return $this->emailVerificado;
    }

    public function telefono(): ?string
    {
        return $this->telefono;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function ultimoLogin(): ?DateTimeImmutable
    {
        return $this->ultimoLogin;
    }

    public function fechaExpiracionPassword(): ?DateTimeImmutable
    {
        return $this->fechaExpiracionPassword;
    }

    public function mfaHabilitado(): bool
    {
        return $this->mfaHabilitado;
    }

    public function mfaMetodo(): ?string
    {
        return $this->mfaMetodo;
    }

    public function preferencias(): ?array
    {
        return $this->preferencias;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    // ========================================================================
    // MÉTODOS DE COMPORTAMIENTO (Cambian estado interno)
    // ========================================================================

    public function actualizarEmail(string $nuevoEmail): void
    {
        self::validateEmail($nuevoEmail);
        $this->email = strtolower(trim($nuevoEmail));
        $this->emailVerificado = false;
        $this->touch();
    }

    public function actualizarNombre(string $nombre, string $apellido): void
    {
        self::validateNombre($nombre);
        self::validateApellido($apellido);
        $this->nombre = trim($nombre);
        $this->apellido = trim($apellido);
        $this->touch();
    }

    public function cambiarPassword(string $nuevoPasswordHashed): void
    {
        self::validatePasswordHash($nuevoPasswordHashed);
        $this->password = $nuevoPasswordHashed;
        $this->touch();
    }

    public function cambiarEstado(string $nuevoEstado): void
    {
        if (!in_array($nuevoEstado, self::VALID_STATUSES, true)) {
            throw new UserException("Estado inválido: {$nuevoEstado}");
        }
        $this->estado = $nuevoEstado;
        $this->touch();
    }

    public function activar(): void
    {
        $this->cambiarEstado(self::STATUS_ACTIVE);
    }

    public function desactivar(): void
    {
        $this->cambiarEstado(self::STATUS_INACTIVE);
    }

    public function suspender(): void
    {
        $this->cambiarEstado(self::STATUS_SUSPENDED);
    }

    public function confirmarEmail(): void
    {
        $this->emailVerificado = true;
        $this->touch();
    }

    public function registrarLogin(): void
    {
        $this->ultimoLogin = new DateTimeImmutable();
        $this->touch();
    }

    public function habilitarMfa(string $metodo): void
    {
        self::validateMfaMethod($metodo);
        $this->mfaHabilitado = true;
        $this->mfaMetodo = $metodo;
        $this->touch();
    }

    public function deshabilitarMfa(): void
    {
        $this->mfaHabilitado = false;
        $this->mfaMetodo = null;
        $this->touch();
    }

    public function actualizarPreferencias(array $preferencias): void
    {
        $this->preferencias = $preferencias;
        $this->touch();
    }

    public function actualizarAvatar(string $url): void
    {
        $this->avatarUrl = $url;
        $this->touch();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->touch();
    }

    // ========================================================================
    // QUERIES (Preguntas sobre el estado, no modifican)
    // ========================================================================

    public function isActive(): bool
    {
        return $this->estado === self::STATUS_ACTIVE && $this->deletedAt === null;
    }

    public function isSuspended(): bool
    {
        return $this->estado === self::STATUS_SUSPENDED;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function requiereMfa(): bool
    {
        return $this->mfaHabilitado && $this->mfaMetodo !== null;
    }

    public function passwordExpirado(): bool
    {
        if ($this->fechaExpiracionPassword === null) {
            return false;
        }
        return $this->fechaExpiracionPassword < new DateTimeImmutable();
    }

    public function perteneceATenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'email' => $this->email,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'estado' => $this->estado,
            'email_verificado' => $this->emailVerificado,
            'telefono' => $this->telefono,
            'avatar_url' => $this->avatarUrl,
            'ultimo_login' => $this->ultimoLogin?->format('Y-m-d H:i:s'),
            'fecha_expiracion_password' => $this->fechaExpiracionPassword?->format('Y-m-d H:i:s'),
            'mfa_habilitado' => $this->mfaHabilitado,
            'mfa_metodo' => $this->mfaMetodo,
            'preferencias' => $this->preferencias,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at' => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Versión segura para API (sin password ni datos sensibles)
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'email' => $this->email,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'nombre_completo' => $this->nombreCompleto(),
            'estado' => $this->estado,
            'email_verificado' => $this->emailVerificado,
            'telefono' => $this->telefono,
            'avatar_url' => $this->avatarUrl,
            'ultimo_login' => $this->ultimoLogin?->format('Y-m-d H:i:s'),
            'mfa_habilitado' => $this->mfaHabilitado,
            'preferencias' => $this->preferencias,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private static function validateEmail(string $email): void
    {
        $email = trim($email);
        if ($email === '') {
            throw new UserException('Email no puede estar vacío');
        }
        if (strlen($email) > 255) {
            throw new UserException('Email excede longitud máxima (255)');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserException('Email inválido');
        }
    }

    private static function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new UserException('Nombre no puede estar vacío');
        }
        if (strlen($nombre) > 100) {
            throw new UserException('Nombre excede longitud máxima (100)');
        }
    }

    private static function validateApellido(string $apellido): void
    {
        $apellido = trim($apellido);
        if ($apellido === '') {
            throw new UserException('Apellido no puede estar vacío');
        }
        if (strlen($apellido) > 100) {
            throw new UserException('Apellido excede longitud máxima (100)');
        }
    }

    private static function validatePasswordHash(string $hash): void
    {
        if ($hash === '') {
            throw new UserException('Password hash no puede estar vacío');
        }
        // Acepta bcrypt ($2y$) o argon2 ($argon2*)
        if (!str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$argon2')) {
            throw new UserException('Password hash inválido (debe ser bcrypt o argon2)');
        }
    }

    private static function validateMfaMethod(string $metodo): void
    {
        if (!in_array($metodo, self::VALID_MFA_METHODS, true)) {
            throw new UserException("Método MFA inválido: {$metodo}");
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
