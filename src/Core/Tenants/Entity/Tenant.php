<?php

declare(strict_types=1);

namespace App\Core\Tenants\Entity;

use App\Core\Tenants\Exception\TenantException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tenant Entity - Aggregate Root del dominio Tenants.
 *
 * Representa una organización/cliente dentro de la plataforma SaaS.
 * Aísla completamente los datos entre organizaciones.
 *
 * Reglas de negocio:
 * - nombre_interno único global (slug; editable por el admin del tenant en
 *   settings, unicidad garantizada por nombre_interno_hmac)
 * - dominio_custom único (si se asigna)
 * - Transiciones de estado controladas (CANCELED es terminal)
 * - owner_user_id nullable (puede no haber owner al crear)
 */
final class Tenant
{
    // Estados
    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_SUSPENDIDO = 'SUSPENDIDO';
    public const ESTADO_PENDIENTE = 'PENDIENTE_ACTIVACION';
    public const ESTADO_CANCELED = 'CANCELED';

    public const VALID_ESTADOS = [
        self::ESTADO_ACTIVO,
        self::ESTADO_SUSPENDIDO,
        self::ESTADO_PENDIENTE,
        self::ESTADO_CANCELED,
    ];

    // Planes
    public const PLAN_FREE = 'FREE';
    public const PLAN_STARTER = 'STARTER';
    public const PLAN_PRO = 'PRO';
    public const PLAN_ENTERPRISE = 'ENTERPRISE';

    public const VALID_PLANES = [
        self::PLAN_FREE,
        self::PLAN_STARTER,
        self::PLAN_PRO,
        self::PLAN_ENTERPRISE,
    ];

    // Límites
    private const MAX_NOMBRE_PUBLICO_LENGTH = 255;
    private const MAX_NOMBRE_INTERNO_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_TELEFONO_LENGTH = 20;
    private const MAX_PAIS_LENGTH = 100;
    private const MAX_ZONA_HORARIA_LENGTH = 50;
    private const MAX_IDIOMA_LENGTH = 10;
    private const MAX_DOMINIO_LENGTH = 255;

    private function __construct(
        private readonly string $id,
        private string $nombrePublico,
        // Editable por el admin del tenant en settings (unicidad vía HMAC)
        private string $nombreInterno,
        private string $estado,
        private string $plan,
        private ?string $ownerUserId,
        private readonly DateTimeImmutable $fechaCreacion,
        private ?DateTimeImmutable $fechaExpiracion,
        private ?string $emailContacto,
        private ?string $telefono,
        private ?string $pais,
        private ?string $zonaHoraria,
        private ?string $idiomaDefault,
        private ?string $logoUrl,
        private ?string $dominioCustom,
        private ?array $configuracion,
        private ?array $metadatos,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt,
    ) {
    }

    /**
     * Factory: Crea un nuevo tenant.
     *
     * Por defecto inicia en PENDIENTE_ACTIVACION hasta que sea activado explícitamente.
     */
    public static function create(
        string $id,
        string $nombrePublico,
        string $nombreInterno,
        string $plan = self::PLAN_FREE,
        ?string $ownerUserId = null,
        ?string $emailContacto = null,
        ?string $telefono = null,
        ?string $pais = null,
        ?string $zonaHoraria = null,
        ?string $idiomaDefault = null,
        ?string $dominioCustom = null,
        ?array $configuracion = null,
        ?array $metadatos = null,
    ): self {
        self::validateNombrePublico($nombrePublico);
        self::validateNombreInterno($nombreInterno);
        self::validatePlan($plan);
        self::validateEmailContacto($emailContacto);
        self::validateDominioCustom($dominioCustom);

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            nombrePublico: trim($nombrePublico),
            nombreInterno: strtolower(trim($nombreInterno)),
            estado: self::ESTADO_PENDIENTE,
            plan: $plan,
            ownerUserId: $ownerUserId,
            fechaCreacion: $now,
            fechaExpiracion: null,
            emailContacto: $emailContacto !== null ? strtolower(trim($emailContacto)) : null,
            telefono: $telefono !== null ? trim($telefono) : null,
            pais: $pais !== null ? trim($pais) : null,
            zonaHoraria: $zonaHoraria !== null ? trim($zonaHoraria) : null,
            idiomaDefault: $idiomaDefault !== null ? strtolower(trim($idiomaDefault)) : null,
            logoUrl: null,
            dominioCustom: $dominioCustom !== null ? strtolower(trim($dominioCustom)) : null,
            configuracion: $configuracion,
            metadatos: $metadatos,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    /**
     * Factory: Reconstruye desde persistencia (hidratación).
     */
    public static function fromArray(array $data): self
    {
        $required = ['id', 'nombre_publico', 'nombre_interno', 'estado', 'plan'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        $configuracion = $data['configuracion'] ?? null;
        if (is_string($configuracion)) {
            $decoded = json_decode($configuracion, true);
            $configuracion = is_array($decoded) ? $decoded : null;
        }

        $metadatos = $data['metadatos'] ?? null;
        if (is_string($metadatos)) {
            $decoded = json_decode($metadatos, true);
            $metadatos = is_array($decoded) ? $decoded : null;
        }

        return new self(
            id: (string) $data['id'],
            nombrePublico: (string) $data['nombre_publico'],
            nombreInterno: (string) $data['nombre_interno'],
            estado: (string) $data['estado'],
            plan: (string) $data['plan'],
            ownerUserId: isset($data['owner_user_id']) ? (string) $data['owner_user_id'] : null,
            fechaCreacion: new DateTimeImmutable($data['fecha_creacion'] ?? $data['created_at']),
            fechaExpiracion: isset($data['fecha_expiracion']) ? new DateTimeImmutable($data['fecha_expiracion']) : null,
            emailContacto: isset($data['email_contacto']) ? (string) $data['email_contacto'] : null,
            telefono: isset($data['telefono']) ? (string) $data['telefono'] : null,
            pais: isset($data['pais']) ? (string) $data['pais'] : null,
            zonaHoraria: isset($data['zona_horaria']) ? (string) $data['zona_horaria'] : null,
            idiomaDefault: isset($data['idioma_default']) ? (string) $data['idioma_default'] : null,
            logoUrl: isset($data['logo_url']) ? (string) $data['logo_url'] : null,
            dominioCustom: isset($data['dominio_custom']) ? (string) $data['dominio_custom'] : null,
            configuracion: $configuracion,
            metadatos: $metadatos,
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null,
        );
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

    public function id(): string { return $this->id; }
    public function nombrePublico(): string { return $this->nombrePublico; }
    public function nombreInterno(): string { return $this->nombreInterno; }
    public function estado(): string { return $this->estado; }
    public function plan(): string { return $this->plan; }
    public function ownerUserId(): ?string { return $this->ownerUserId; }
    public function fechaCreacion(): DateTimeImmutable { return $this->fechaCreacion; }
    public function fechaExpiracion(): ?DateTimeImmutable { return $this->fechaExpiracion; }
    public function emailContacto(): ?string { return $this->emailContacto; }
    public function telefono(): ?string { return $this->telefono; }
    public function pais(): ?string { return $this->pais; }
    public function zonaHoraria(): ?string { return $this->zonaHoraria; }
    public function idiomaDefault(): ?string { return $this->idiomaDefault; }
    public function logoUrl(): ?string { return $this->logoUrl; }
    public function dominioCustom(): ?string { return $this->dominioCustom; }
    public function configuracion(): ?array { return $this->configuracion; }
    public function metadatos(): ?array { return $this->metadatos; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    // ========================================================================
    // COMPORTAMIENTO
    // ========================================================================

    public function actualizarNombrePublico(string $nombrePublico): void
    {
        self::validateNombrePublico($nombrePublico);
        $this->nombrePublico = trim($nombrePublico);
        $this->touch();
    }

    /**
     * Edita el nombre interno (slug único global vía nombre_interno_hmac).
     * La unicidad la valida el repositorio antes de escribir.
     */
    public function actualizarNombreInterno(string $nombreInterno): void
    {
        self::validateNombreInterno($nombreInterno);
        $this->nombreInterno = strtolower(trim($nombreInterno));
        $this->touch();
    }

    public function cambiarPlan(string $plan): void
    {
        self::validatePlan($plan);
        $this->plan = $plan;
        $this->touch();
    }

    public function actualizarContacto(
        ?string $email,
        ?string $telefono,
        ?string $pais,
        ?string $zonaHoraria,
        ?string $idioma
    ): void {
        self::validateEmailContacto($email);
        $this->emailContacto = $email !== null ? strtolower(trim($email)) : null;
        $this->telefono = $telefono !== null ? trim($telefono) : null;
        $this->pais = $pais !== null ? trim($pais) : null;
        $this->zonaHoraria = $zonaHoraria !== null ? trim($zonaHoraria) : null;
        $this->idiomaDefault = $idioma !== null ? strtolower(trim($idioma)) : null;
        $this->touch();
    }

    public function asignarDominioCustom(string $dominio): void
    {
        self::validateDominioCustom($dominio);
        $this->dominioCustom = strtolower(trim($dominio));
        $this->touch();
    }

    public function removerDominioCustom(): void
    {
        $this->dominioCustom = null;
        $this->touch();
    }

    public function actualizarLogo(?string $url): void
    {
        $url = $url !== null ? trim($url) : null;
        $this->logoUrl = ($url === '' ? null : $url);
        $this->touch();
    }

    public function actualizarConfiguracion(?array $configuracion): void
    {
        $this->configuracion = $configuracion;
        $this->touch();
    }

    public function actualizarMetadatos(?array $metadatos): void
    {
        $this->metadatos = $metadatos;
        $this->touch();
    }

    public function asignarOwner(string $ownerUserId): void
    {
        $this->ownerUserId = $ownerUserId;
        $this->touch();
    }

    public function establecerFechaExpiracion(?DateTimeImmutable $fecha): void
    {
        $this->fechaExpiracion = $fecha;
        $this->touch();
    }

    // ========================================================================
    // TRANSICIONES DE ESTADO
    // ========================================================================

    /**
     * Activa el tenant (desde PENDIENTE_ACTIVACION o SUSPENDIDO).
     */
    public function activar(): void
    {
        if (!in_array($this->estado, [self::ESTADO_PENDIENTE, self::ESTADO_SUSPENDIDO], true)) {
            throw new TenantException(
                "No se puede activar un tenant en estado '{$this->estado}'.",
                400
            );
        }
        $this->estado = self::ESTADO_ACTIVO;
        $this->touch();
    }

    /**
     * Suspende el tenant (solo desde ACTIVO).
     */
    public function suspender(): void
    {
        if ($this->estado !== self::ESTADO_ACTIVO) {
            throw new TenantException(
                "Solo se pueden suspender tenants en estado ACTIVO. Estado actual: '{$this->estado}'.",
                400
            );
        }
        $this->estado = self::ESTADO_SUSPENDIDO;
        $this->touch();
    }

    /**
     * Cancela el tenant (estado terminal, irreversible).
     */
    public function cancelar(): void
    {
        if ($this->estado === self::ESTADO_CANCELED) {
            throw new TenantException('El tenant ya se encuentra cancelado.', 400);
        }
        $this->estado = self::ESTADO_CANCELED;
        $this->touch();
    }

    /**
     * Soft delete del tenant.
     */
    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->estado = self::ESTADO_CANCELED;
        $this->touch();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->estado = self::ESTADO_PENDIENTE;
        $this->touch();
    }

    // ========================================================================
    // QUERIES
    // ========================================================================

    public function isActive(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO && $this->deletedAt === null;
    }

    public function isSuspended(): bool
    {
        return $this->estado === self::ESTADO_SUSPENDIDO;
    }

    public function isPending(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function isCanceled(): bool
    {
        return $this->estado === self::ESTADO_CANCELED;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function hasExpired(): bool
    {
        if ($this->fechaExpiracion === null) {
            return false;
        }
        return $this->fechaExpiracion < new DateTimeImmutable();
    }

    public function canOperate(): bool
    {
        return $this->isActive() && !$this->hasExpired();
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'nombre_publico'    => $this->nombrePublico,
            'nombre_interno'    => $this->nombreInterno,
            'estado'            => $this->estado,
            'plan'              => $this->plan,
            'owner_user_id'     => $this->ownerUserId,
            'fecha_creacion'    => $this->fechaCreacion->format('Y-m-d H:i:s'),
            'fecha_expiracion'  => $this->fechaExpiracion?->format('Y-m-d H:i:s'),
            'email_contacto'    => $this->emailContacto,
            'telefono'          => $this->telefono,
            'pais'              => $this->pais,
            'zona_horaria'      => $this->zonaHoraria,
            'idioma_default'    => $this->idiomaDefault,
            'logo_url'          => $this->logoUrl,
            'dominio_custom'    => $this->dominioCustom,
            'configuracion'     => $this->configuracion,
            'metadatos'         => $this->metadatos,
            'created_at'        => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'        => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at'        => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Versión pública (sin metadatos internos).
     * Incluye nombre_interno (identificador editable en settings) y
     * configuracion (ajustes del tenant: SMTP, política de contraseñas...).
     * La password SMTP jamás sale en claro: se sustituye por null y se
     * expone solo el flag password_configurada (v1.14.1).
     */
    public function toPublicArray(): array
    {
        return [
            'id'                => $this->id,
            'nombre_publico'    => $this->nombrePublico,
            'nombre_interno'    => $this->nombreInterno,
            'estado'            => $this->estado,
            'plan'              => $this->plan,
            'email_contacto'    => $this->emailContacto,
            'telefono'          => $this->telefono,
            'pais'              => $this->pais,
            'zona_horaria'      => $this->zonaHoraria,
            'idioma_default'    => $this->idiomaDefault,
            'logo_url'          => $this->logoUrl,
            'dominio_custom'    => $this->dominioCustom,
            'configuracion'     => $this->maskSmtpPassword($this->configuracion),
        ];
    }

    /** Enmascara configuracion.smtp.password (nunca se expone el cifrado). */
    private static function maskSmtpPassword(?array $config): ?array
    {
        if ($config === null || !isset($config['smtp']) || !is_array($config['smtp'])) {
            return $config;
        }
        $config['smtp']['password_configurada'] = !empty($config['smtp']['password']);
        $config['smtp']['password'] = null;
        return $config;
    }

    /**
     * Datos de marca visibles para cualquier usuario del tenant (v1.14.0):
     * sin plan, estado ni contacto.
     */
    public function toBrandingArray(): array
    {
        $config = $this->configuracion ?? [];

        return [
            'nombre_publico' => $this->nombrePublico,
            'logo_url'       => $this->logoUrl,
            'zona_horaria'   => $this->zonaHoraria,
            'idioma_default' => $this->idiomaDefault,
            'apariencia'     => is_array($config['apariencia'] ?? null) ? $config['apariencia'] : null,
            'regional'       => is_array($config['regional'] ?? null) ? $config['regional'] : null,
        ];
    }

    // ========================================================================
    // VALIDACIONES PRIVADAS
    // ========================================================================

    private static function validateNombrePublico(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw TenantException::invalidNombrePublico($nombre);
        }
        if (strlen($nombre) > self::MAX_NOMBRE_PUBLICO_LENGTH) {
            throw new TenantException(
                "El nombre público excede longitud máxima (" . self::MAX_NOMBRE_PUBLICO_LENGTH . ").",
                400
            );
        }
    }

    private static function validateNombreInterno(string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw TenantException::invalidNombreInterno($nombre);
        }
        if (strlen($nombre) > self::MAX_NOMBRE_INTERNO_LENGTH) {
            throw (new TenantException(
                "El nombre interno excede longitud máxima (" . self::MAX_NOMBRE_INTERNO_LENGTH . ").",
                400
            ))->withMessageKey('tenants.nombre_interno_max_length');
        }
        // Slug: lowercase, alphanumeric + guiones + guiones bajos
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]*[a-z0-9]$/', $nombre) && strlen($nombre) > 1) {
            throw TenantException::invalidNombreInterno($nombre);
        }
    }

    private static function validatePlan(string $plan): void
    {
        if (!in_array($plan, self::VALID_PLANES, true)) {
            throw TenantException::invalidPlan($plan);
        }
    }

    private static function validateEmailContacto(?string $email): void
    {
        if ($email === null) {
            return;
        }
        $email = trim($email);
        if ($email === '') {
            return;
        }
        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw TenantException::invalidEmailContacto($email);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw TenantException::invalidEmailContacto($email);
        }
    }

    private static function validateDominioCustom(?string $dominio): void
    {
        if ($dominio === null) {
            return;
        }
        $dominio = trim($dominio);
        if ($dominio === '') {
            return;
        }
        if (strlen($dominio) > self::MAX_DOMINIO_LENGTH) {
            throw new TenantException(
                "El dominio custom excede longitud máxima (" . self::MAX_DOMINIO_LENGTH . ").",
                400
            );
        }
        // Dominio válido: ej. "app.acme.com" o "acme.local"
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $dominio)) {
            throw new TenantException("Dominio custom inválido: '{$dominio}'.", 400);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
