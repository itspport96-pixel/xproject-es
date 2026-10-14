<?php
declare(strict_types=1);

namespace App\Core\Security\Entity;

use App\Shared\Exceptions\SecurityException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Secret Entity - Aggregate Root del dominio SecretsVault.
 *
 * Representa un secreto (credencial) almacenado de forma encriptada
 * dentro de un tenant. Usado para API keys, tokens OAuth, passwords
 * de servicios externos, claves de encriptación, etc.
 *
 * Reglas de negocio:
 * - Nombre único por tenant (slug seguro)
 * - Valor siempre almacenado encriptado (AES-256-GCM)
 * - Rotación configurable (última_rotacion + proxima_rotacion)
 * - owner_user_id nullable (puede no tener owner explícito)
 * - No se puede modificar valor_encriptado directamente (solo via rotate)
 * - Soft delete no aplica (se marca activo=0)
 */
final class Secret
{
    // Tipos de secretos
    public const TYPE_API_KEY         = 'API_KEY';
    public const TYPE_PASSWORD        = 'PASSWORD';
    public const TYPE_ENCRYPTION_KEY  = 'ENCRYPTION_KEY';
    public const TYPE_OAUTH_TOKEN     = 'OAUTH_TOKEN';
    public const TYPE_OTHER           = 'OTRO';

    public const VALID_TYPES = [
        self::TYPE_API_KEY,
        self::TYPE_PASSWORD,
        self::TYPE_ENCRYPTION_KEY,
        self::TYPE_OAUTH_TOKEN,
        self::TYPE_OTHER,
    ];

    // Límites
    private const MAX_NOMBRE_LENGTH        = 255;
    private const MAX_DESCRIPCION_LENGTH   = 2000;
    private const MAX_VALOR_LENGTH         = 10000; // Post-encriptación (base64)

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private string $nombre,
        private string $tipo,
        private string $valorEncriptado,
        private ?string $descripcion,
        private bool $rotacionRequerida,
        private ?DateTimeImmutable $ultimaRotacion,
        private ?DateTimeImmutable $proximaRotacion,
        private bool $activo,
        private ?string $ownerUserId,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Factory: Crea un nuevo secreto.
     *
     * @param string $id UUID del secreto
     * @param string $tenantId ID del tenant propietario
     * @param string $nombre Nombre único del secreto (slug)
     * @param string $tipo Tipo de secreto (API_KEY, PASSWORD, etc.)
     * @param string $valorEncriptado Valor ya encriptado (AES-256-GCM + base64)
     * @param string|null $descripcion Descripción opcional
     * @param bool $rotacionRequerida Si requiere rotación periódica
     * @param string|null $ownerUserId ID del usuario owner (opcional)
     * @param int|null $rotationDays Días hasta próxima rotación (si rotacionRequerida=true)
     */
    public static function create(
        string $id,
        string $tenantId,
        string $nombre,
        string $tipo,
        string $valorEncriptado,
        ?string $descripcion = null,
        bool $rotacionRequerida = false,
        ?string $ownerUserId = null,
        ?int $rotationDays = null,
    ): self {
        self::validateNombre($nombre);
        self::validateTipo($tipo);
        self::validateValorEncriptado($valorEncriptado);
        self::validateDescripcion($descripcion);

        $now = new DateTimeImmutable();
        $proximaRotacion = null;

        if ($rotacionRequerida && $rotationDays !== null && $rotationDays > 0) {
            $proximaRotacion = $now->modify("+{$rotationDays} days");
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            nombre: strtolower(trim($nombre)),
            tipo: $tipo,
            valorEncriptado: $valorEncriptado,
            descripcion: $descripcion !== null ? trim($descripcion) : null,
            rotacionRequerida: $rotacionRequerida,
            ultimaRotacion: $now,
            proximaRotacion: $proximaRotacion,
            activo: true,
            ownerUserId: $ownerUserId,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Factory: Reconstruye desde persistencia (hidratación).
     */
    public static function fromArray(array $data): self
    {
        $required = ['id', 'tenant_id', 'nombre', 'tipo', 'valor_encriptado'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            nombre: (string) $data['nombre'],
            tipo: (string) $data['tipo'],
            valorEncriptado: (string) $data['valor_encriptado'],
            descripcion: isset($data['descripcion']) ? (string) $data['descripcion'] : null,
            rotacionRequerida: (bool) ($data['rotacion_requerida'] ?? false),
            ultimaRotacion: isset($data['ultima_rotacion'])
                ? new DateTimeImmutable($data['ultima_rotacion'])
                : null,
            proximaRotacion: isset($data['proxima_rotacion'])
                ? new DateTimeImmutable($data['proxima_rotacion'])
                : null,
            activo: (bool) ($data['activo'] ?? true),
            ownerUserId: isset($data['owner_user_id']) ? (string) $data['owner_user_id'] : null,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
        );
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function nombre(): string { return $this->nombre; }
    public function tipo(): string { return $this->tipo; }
    public function valorEncriptado(): string { return $this->valorEncriptado; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function rotacionRequerida(): bool { return $this->rotacionRequerida; }
    public function ultimaRotacion(): ?DateTimeImmutable { return $this->ultimaRotacion; }
    public function proximaRotacion(): ?DateTimeImmutable { return $this->proximaRotacion; }
    public function activo(): bool { return $this->activo; }
    public function ownerUserId(): ?string { return $this->ownerUserId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    // ========================================================================
    // COMPORTAMIENTO
    // ========================================================================

    /**
     * Actualiza la descripción del secreto.
     */
    public function actualizarDescripcion(?string $descripcion): void
    {
        self::validateDescripcion($descripcion);
        $this->descripcion = $descripcion !== null ? trim($descripcion) : null;
        $this->touch();
    }

    /**
     * Asigna o cambia el owner del secreto.
     */
    public function asignarOwner(?string $ownerUserId): void
    {
        $this->ownerUserId = $ownerUserId;
        $this->touch();
    }

    /**
     * Activa el secreto.
     */
    public function activar(): void
    {
        $this->activo = true;
        $this->touch();
    }

    /**
     * Desactiva el secreto (revocación inmediata).
     */
    public function desactivar(): void
    {
        $this->activo = false;
        $this->touch();
    }

    /**
     * Habilita la rotación periódica.
     *
     * @param int $days Días hasta la próxima rotación
     */
    public function habilitarRotacion(int $days = 90): void
    {
        if ($days <= 0) {
            throw (new SecurityException('Los días de rotación deben ser mayores a 0', 400))->withMessageKey('security.rotation_days_invalid');
        }
        $this->rotacionRequerida = true;
        $this->proximaRotacion = (new DateTimeImmutable())->modify("+{$days} days");
        $this->touch();
    }

    /**
     * Deshabilita la rotación periódica.
     */
    public function deshabilitarRotacion(): void
    {
        $this->rotacionRequerida = false;
        $this->proximaRotacion = null;
        $this->touch();
    }

    /**
     * Rota el secreto con un nuevo valor encriptado.
     * Actualiza última_rotacion y recalcula proxima_rotacion.
     *
     * @param string $nuevoValorEncriptado Nuevo valor ya encriptado
     * @param int|null $rotationDays Días hasta próxima rotación (si aplica)
     */
    public function rotar(string $nuevoValorEncriptado, ?int $rotationDays = null): void
    {
        self::validateValorEncriptado($nuevoValorEncriptado);

        $now = new DateTimeImmutable();
        $this->valorEncriptado = $nuevoValorEncriptado;
        $this->ultimaRotacion = $now;

        if ($this->rotacionRequerida) {
            $days = $rotationDays ?? $this->calcularDiasHastaProximaRotacion();
            if ($days > 0) {
                $this->proximaRotacion = $now->modify("+{$days} days");
            }
        }

        $this->touch();
    }

    // ========================================================================
    // QUERIES
    // ========================================================================

    /**
     * Verifica si el secreto está activo.
     */
    public function isActive(): bool
    {
        return $this->activo;
    }

    /**
     * Verifica si el secreto requiere rotación.
     */
    public function requiresRotation(): bool
    {
        return $this->rotacionRequerida;
    }

    /**
     * Verifica si la rotación está vencida (debe rotarse ya).
     */
    public function isRotationOverdue(): bool
    {
        if (!$this->rotacionRequerida || $this->proximaRotacion === null) {
            return false;
        }
        return $this->proximaRotacion < new DateTimeImmutable();
    }

    /**
     * Verifica si la rotación está próxima a vencer (en los próximos N días).
     */
    public function isRotationDueSoon(int $withinDays = 7): bool
    {
        if (!$this->rotacionRequerida || $this->proximaRotacion === null) {
            return false;
        }
        $threshold = (new DateTimeImmutable())->modify("+{$withinDays} days");
        return $this->proximaRotacion <= $threshold;
    }

    /**
     * Verifica si el usuario dado es el owner del secreto.
     */
    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerUserId !== null && $this->ownerUserId === $userId;
    }

    /**
     * Verifica si el secreto pertenece al tenant dado.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializa el secreto COMPLETO (incluye valor encriptado).
     * Usar SOLO internamente en el repositorio/service.
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'tenant_id'           => $this->tenantId,
            'nombre'              => $this->nombre,
            'tipo'                => $this->tipo,
            'valor_encriptado'    => $this->valorEncriptado,
            'descripcion'         => $this->descripcion,
            'rotacion_requerida'  => $this->rotacionRequerida,
            'ultima_rotacion'     => $this->ultimaRotacion?->format('Y-m-d H:i:s'),
            'proxima_rotacion'    => $this->proximaRotacion?->format('Y-m-d H:i:s'),
            'activo'              => $this->activo,
            'owner_user_id'       => $this->ownerUserId,
            'created_at'          => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Versión pública (SIN valor encriptado).
     * Para respuestas API.
     */
    public function toPublicArray(): array
    {
        return [
            'id'                  => $this->id,
            'tenant_id'           => $this->tenantId,
            'nombre'              => $this->nombre,
            'tipo'                => $this->tipo,
            'descripcion'         => $this->descripcion,
            'rotacion_requerida'  => $this->rotacionRequerida,
            'ultima_rotacion'     => $this->ultimaRotacion?->format('Y-m-d H:i:s'),
            'proxima_rotacion'    => $this->proximaRotacion?->format('Y-m-d H:i:s'),
            'activo'              => $this->activo,
            'owner_user_id'       => $this->ownerUserId,
            'rotation_overdue'    => $this->isRotationOverdue(),
            'created_at'          => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private static function validateNombre(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw (new SecurityException('El nombre del secreto no puede estar vacío', 400))->withMessageKey('security.secret_name_required');
        }
        if (strlen($nombre) > self::MAX_NOMBRE_LENGTH) {
            throw new SecurityException(
                "El nombre del secreto excede longitud máxima (" . self::MAX_NOMBRE_LENGTH . ")",
                400
            );
        }
        // Slug seguro: lowercase, alphanumeric + guiones + guiones bajos + puntos
        if (!preg_match('/^[a-z0-9][a-z0-9._\-]*[a-z0-9]$/', $nombre) && strlen($nombre) > 1) {
            throw new SecurityException(
                "Nombre de secreto inválido: '{$nombre}'. Solo minúsculas, números, guiones, puntos y guiones bajos.",
                400
            );
        }
    }

    private static function validateTipo(string $tipo): void
    {
        if (!in_array($tipo, self::VALID_TYPES, true)) {
            throw new SecurityException(
                "Tipo de secreto inválido: '{$tipo}'. Válidos: " . implode(', ', self::VALID_TYPES),
                400
            );
        }
    }

    private static function validateValorEncriptado(string $valor): void
    {
        if ($valor === '') {
            throw (new SecurityException('El valor encriptado no puede estar vacío', 400))->withMessageKey('security.secret_encrypted_required');
        }
        if (strlen($valor) > self::MAX_VALOR_LENGTH) {
            throw new SecurityException(
                "El valor encriptado excede longitud máxima (" . self::MAX_VALOR_LENGTH . ")",
                400
            );
        }
        // Debe ser base64 válido (resultado de EncryptionService)
        if (base64_decode($valor, true) === false) {
            throw new SecurityException(
                'El valor encriptado debe ser base64 válido',
                400
            );
        }
    }

    private static function validateDescripcion(?string $descripcion): void
    {
        if ($descripcion === null) {
            return;
        }
        if (strlen($descripcion) > self::MAX_DESCRIPCION_LENGTH) {
            throw new SecurityException(
                "La descripción excede longitud máxima (" . self::MAX_DESCRIPCION_LENGTH . ")",
                400
            );
        }
    }

    /**
     * Calcula días hasta próxima rotación basándose en el histórico.
     * Si no hay histórico, default 90 días.
     */
    private function calcularDiasHastaProximaRotacion(): int
    {
        if ($this->ultimaRotacion === null) {
            return 90;
        }
        // Si había una próxima_rotacion anterior, calcular el intervalo
        // (simplificado: usar 90 días por defecto)
        return 90;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
