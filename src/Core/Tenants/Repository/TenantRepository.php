<?php

declare(strict_types=1);

namespace App\Core\Tenants\Repository;

use App\Core\Security\Service\BlindIndexService;
use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Shared\Services\DatabaseService;
use PDO;
use PDOException;

final class TenantRepository implements TenantRepositoryInterface
{
    private PDO $pdo;
    private ?BlindIndexService $blindIndex;

    public function __construct(DatabaseService $db, ?BlindIndexService $blindIndex = null)
    {
        $this->pdo = $db->getConnection();
        $this->blindIndex = $blindIndex;
    }

    /** Lazy: sólo se necesita al escribir (HMAC de nombre_interno). */
    private function blindIndexService(): BlindIndexService
    {
        return $this->blindIndex ??= new BlindIndexService();
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    public function findById(string $id): ?Tenant
    {
        $sql = "SELECT * FROM tenants WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByNombreInterno(string $nombreInterno): ?Tenant
    {
        $sql = "SELECT * FROM tenants
                WHERE LOWER(nombre_interno) = LOWER(:nombre_interno)
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nombre_interno' => trim($nombreInterno)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByDominioCustom(string $dominio): ?Tenant
    {
        $sql = "SELECT * FROM tenants
                WHERE LOWER(dominio_custom) = LOWER(:dominio)
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['dominio' => trim($dominio)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByOwnerUserId(string $ownerUserId): array
    {
        $sql = "SELECT * FROM tenants
                WHERE owner_user_id = :owner
                  AND deleted_at IS NULL
                ORDER BY fecha_creacion ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['owner' => $ownerUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findAll(bool $soloActivos = true): array
    {
        $sql = "SELECT * FROM tenants";
        if ($soloActivos) {
            $sql .= " WHERE deleted_at IS NULL";
        }
        $sql .= " ORDER BY nombre_publico ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findByEstado(string $estado): array
    {
        $sql = "SELECT * FROM tenants
                WHERE estado = :estado AND deleted_at IS NULL
                ORDER BY nombre_publico ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['estado' => $estado]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findByPlan(string $plan): array
    {
        $sql = "SELECT * FROM tenants
                WHERE plan = :plan AND deleted_at IS NULL
                ORDER BY nombre_publico ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['plan' => $plan]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findOperativos(): array
    {
        $sql = "SELECT * FROM tenants
                WHERE estado = 'ACTIVO'
                  AND deleted_at IS NULL
                  AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
                ORDER BY nombre_publico ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findPorExpirar(int $dias = 30): array
    {
        $sql = "SELECT * FROM tenants
                WHERE estado = 'ACTIVO'
                  AND deleted_at IS NULL
                  AND fecha_expiracion IS NOT NULL
                  AND fecha_expiracion BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :dias DAY)
                ORDER BY fecha_expiracion ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    public function save(Tenant $tenant): void
    {
        if ($this->existsByNombreInterno($tenant->nombreInterno())) {
            throw TenantException::nombreInternoAlreadyExists($tenant->nombreInterno());
        }

        if ($tenant->dominioCustom() !== null && $this->existsByDominioCustom($tenant->dominioCustom())) {
            throw TenantException::dominioCustomAlreadyExists($tenant->dominioCustom());
        }

        $settings = $this->decomposeConfig($tenant->configuracion());

        $sql = "INSERT INTO tenants
                (id, nombre_publico, nombre_interno, estado, plan, owner_user_id,
                 fecha_creacion, fecha_expiracion, email_contacto, telefono, pais,
                 zona_horaria, idioma_default, logo_url, dominio_custom,
                 direccion, color_primario, moneda, formato_fecha, caducidad_password_dias,
                 smtp_host, smtp_puerto, smtp_encryption, smtp_username, smtp_password,
                 smtp_from_email, smtp_from_name,
                 metadatos, created_at, updated_at)
                VALUES
                (:id, :nombre_publico, :nombre_interno, :estado, :plan, :owner_user_id,
                 :fecha_creacion, :fecha_expiracion, :email_contacto, :telefono, :pais,
                 :zona_horaria, :idioma_default, :logo_url, :dominio_custom,
                 :direccion, :color_primario, :moneda, :formato_fecha, :caducidad_password_dias,
                 :smtp_host, :smtp_puerto, :smtp_encryption, :smtp_username, :smtp_password,
                 :smtp_from_email, :smtp_from_name,
                 :metadatos, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'               => $tenant->id(),
            'nombre_publico'   => $tenant->nombrePublico(),
            'nombre_interno'   => $tenant->nombreInterno(),
            'estado'           => $tenant->estado(),
            'plan'             => $tenant->plan(),
            'owner_user_id'    => $tenant->ownerUserId(),
            'fecha_creacion'   => $tenant->fechaCreacion()->format('Y-m-d H:i:s'),
            'fecha_expiracion' => $tenant->fechaExpiracion()?->format('Y-m-d H:i:s'),
            'email_contacto'   => $tenant->emailContacto(),
            'telefono'         => $tenant->telefono(),
            'pais'             => $tenant->pais(),
            'zona_horaria'     => $tenant->zonaHoraria(),
            'idioma_default'   => $tenant->idiomaDefault(),
            'logo_url'         => $tenant->logoUrl(),
            'dominio_custom'   => $tenant->dominioCustom(),
            'metadatos'        => $this->encodeJson($tenant->metadatos()),
            'created_at'       => $tenant->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'       => $tenant->updatedAt()?->format('Y-m-d H:i:s'),
        ] + $settings);
    }

    public function update(Tenant $tenant): void
    {
        if (!$this->existsById($tenant->id())) {
            throw TenantException::tenantNotFound($tenant->id());
        }

        // Validar unicidad de dominio_custom si cambió
        if ($tenant->dominioCustom() !== null) {
            $sql = "SELECT id FROM tenants
                    WHERE LOWER(dominio_custom) = LOWER(:dominio)
                      AND id != :id
                      AND deleted_at IS NULL";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['dominio' => $tenant->dominioCustom(), 'id' => $tenant->id()]);
            if ($stmt->fetchColumn() !== false) {
                throw TenantException::dominioCustomAlreadyExists($tenant->dominioCustom());
            }
        }

        // Validar unicidad de nombre_interno (el propio tenant no cuenta)
        if ($this->existsByNombreInterno($tenant->nombreInterno(), $tenant->id())) {
            throw TenantException::nombreInternoAlreadyExists($tenant->nombreInterno());
        }

        $settings = $this->decomposeConfig($tenant->configuracion());

        $sql = "UPDATE tenants
                SET nombre_publico = :nombre_publico,
                    nombre_interno = :nombre_interno,
                    nombre_interno_hmac = :nombre_interno_hmac,
                    estado = :estado,
                    plan = :plan,
                    owner_user_id = :owner_user_id,
                    fecha_expiracion = :fecha_expiracion,
                    email_contacto = :email_contacto,
                    telefono = :telefono,
                    pais = :pais,
                    zona_horaria = :zona_horaria,
                    idioma_default = :idioma_default,
                    logo_url = :logo_url,
                    dominio_custom = :dominio_custom,
                    direccion = :direccion,
                    color_primario = :color_primario,
                    moneda = :moneda,
                    formato_fecha = :formato_fecha,
                    caducidad_password_dias = :caducidad_password_dias,
                    smtp_host = :smtp_host,
                    smtp_puerto = :smtp_puerto,
                    smtp_encryption = :smtp_encryption,
                    smtp_username = :smtp_username,
                    smtp_password = :smtp_password,
                    smtp_from_email = :smtp_from_email,
                    smtp_from_name = :smtp_from_name,
                    metadatos = :metadatos,
                    updated_at = :updated_at
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute([
                'id'                 => $tenant->id(),
                'nombre_publico'     => $tenant->nombrePublico(),
                'nombre_interno'     => $tenant->nombreInterno(),
                'nombre_interno_hmac' => $this->blindIndexService()->compute($tenant->nombreInterno()),
                'estado'             => $tenant->estado(),
                'plan'               => $tenant->plan(),
                'owner_user_id'      => $tenant->ownerUserId(),
                'fecha_expiracion'   => $tenant->fechaExpiracion()?->format('Y-m-d H:i:s'),
                'email_contacto'     => $tenant->emailContacto(),
                'telefono'           => $tenant->telefono(),
                'pais'               => $tenant->pais(),
                'zona_horaria'       => $tenant->zonaHoraria(),
                'idioma_default'     => $tenant->idiomaDefault(),
                'logo_url'           => $tenant->logoUrl(),
                'dominio_custom'     => $tenant->dominioCustom(),
                'metadatos'          => $this->encodeJson($tenant->metadatos()),
                'updated_at'         => $tenant->updatedAt()?->format('Y-m-d H:i:s'),
            ] + $settings);
        } catch (PDOException $e) {
            // Carrera sobre el índice único uq_tenants_nombre_interno_hmac
            if ((string) $e->getCode() === '23000'
                && str_contains($e->getMessage(), 'uq_tenants_nombre_interno_hmac')) {
                throw TenantException::nombreInternoAlreadyExists($tenant->nombreInterno());
            }
            throw $e;
        }
    }

    public function softDelete(string $id): void
    {
        $tenant = $this->findById($id);
        if ($tenant === null) {
            throw TenantException::tenantNotFound($id);
        }

        $sql = "UPDATE tenants
                SET estado = 'CANCELED',
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
    }

    public function restore(string $id): void
    {
        $sql = "UPDATE tenants
                SET estado = 'PENDIENTE_ACTIVACION',
                    deleted_at = NULL,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NOT NULL";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            throw TenantException::tenantNotFound($id);
        }
    }

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    public function existsByNombreInterno(string $nombreInterno, ?string $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM tenants
                WHERE LOWER(nombre_interno) = LOWER(:nombre_interno)
                  AND deleted_at IS NULL";
        $params = ['nombre_interno' => trim($nombreInterno)];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsByDominioCustom(string $dominio): bool
    {
        $sql = "SELECT COUNT(*) FROM tenants
                WHERE LOWER(dominio_custom) = LOWER(:dominio)
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['dominio' => trim($dominio)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id): bool
    {
        $sql = "SELECT COUNT(*) FROM tenants WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(bool $soloActivos = true): int
    {
        $sql = "SELECT COUNT(*) FROM tenants";
        if ($soloActivos) {
            $sql .= " WHERE deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function countByEstado(string $estado): int
    {
        $sql = "SELECT COUNT(*) FROM tenants
                WHERE estado = :estado AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['estado' => $estado]);
        return (int) $stmt->fetchColumn();
    }

    public function countByPlan(string $plan): int
    {
        $sql = "SELECT COUNT(*) FROM tenants
                WHERE plan = :plan AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['plan' => $plan]);
        return (int) $stmt->fetchColumn();
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * Hidrata un tenant componiendo `configuracion` desde las columnas
     * individuales (v1.14.2). La columna JSON `configuracion` ya no se usa
     * para los ajustes: viven en columnas propias y aquí se reconstruye el
     * array anidado que consumen servicio, marca y frontend.
     */
    private function hydrate(array $row): Tenant
    {
        $row['configuracion'] = $this->composeConfig($row);
        return Tenant::fromArray($row);
    }

    /** Reconstruye el array `configuracion` (perfil/apariencia/regional/seguridad/smtp) desde las columnas. */
    private function composeConfig(array $row): ?array
    {
        $config = [];

        if (($row['direccion'] ?? null) !== null) {
            $config['perfil']['direccion'] = $row['direccion'];
        }
        if (($row['color_primario'] ?? null) !== null) {
            $config['apariencia']['color_primario'] = $row['color_primario'];
        }
        if (($row['moneda'] ?? null) !== null) {
            $config['regional']['moneda'] = $row['moneda'];
        }
        if (($row['formato_fecha'] ?? null) !== null) {
            $config['regional']['formato_fecha'] = $row['formato_fecha'];
        }
        if (($row['caducidad_password_dias'] ?? null) !== null) {
            $config['seguridad']['caducidad_password_dias'] = (int) $row['caducidad_password_dias'];
        }
        if (($row['smtp_host'] ?? null) !== null) {
            $config['smtp'] = [
                'host'       => $row['smtp_host'],
                'puerto'     => isset($row['smtp_puerto']) && $row['smtp_puerto'] !== null ? (int) $row['smtp_puerto'] : null,
                'encryption' => $row['smtp_encryption'] ?? null,
                'username'   => $row['smtp_username'] ?? null,
                'password'   => $row['smtp_password'] ?? null,
                'from_email' => $row['smtp_from_email'] ?? null,
                'from_name'  => $row['smtp_from_name'] ?? null,
            ];
        }

        return $config === [] ? null : $config;
    }

    /** Descompone el array `configuracion` en las columnas individuales que se persisten. */
    private function decomposeConfig(?array $config): array
    {
        $config    = $config ?? [];
        $perfil    = $config['perfil'] ?? [];
        $apariencia = $config['apariencia'] ?? [];
        $regional  = $config['regional'] ?? [];
        $seguridad = $config['seguridad'] ?? [];
        $smtp      = $config['smtp'] ?? [];

        return [
            'direccion'               => $perfil['direccion'] ?? null,
            'color_primario'          => $apariencia['color_primario'] ?? null,
            'moneda'                  => $regional['moneda'] ?? null,
            'formato_fecha'           => $regional['formato_fecha'] ?? null,
            'caducidad_password_dias' => isset($seguridad['caducidad_password_dias']) && $seguridad['caducidad_password_dias'] !== null
                ? (int) $seguridad['caducidad_password_dias'] : null,
            'smtp_host'       => $smtp['host'] ?? null,
            'smtp_puerto'     => isset($smtp['puerto']) && $smtp['puerto'] !== null ? (int) $smtp['puerto'] : null,
            'smtp_encryption' => $smtp['encryption'] ?? null,
            'smtp_username'   => $smtp['username'] ?? null,
            'smtp_password'   => $smtp['password'] ?? null,
            'smtp_from_email' => $smtp['from_email'] ?? null,
            'smtp_from_name'  => $smtp['from_name'] ?? null,
        ];
    }

    private function encodeJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
