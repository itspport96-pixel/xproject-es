<?php

declare(strict_types=1);

namespace App\Core\Tenants\Repository;

use App\Core\Security\Service\BlindIndexService;
use App\Core\Security\Service\EncryptionService;
use App\Core\Tenants\Entity\Tenant;
use App\Core\Tenants\Exception\TenantException;
use App\Shared\Services\DatabaseService;
use PDO;

final class TenantRepository implements TenantRepositoryInterface
{
    private PDO $pdo;
    private ?BlindIndexService $blindIndex;
    private ?EncryptionService $encryption;

    public function __construct(
        DatabaseService $db,
        ?BlindIndexService $blindIndex = null,
        ?EncryptionService $encryption = null
    ) {
        $this->pdo = $db->getConnection();
        $this->blindIndex = $blindIndex;
        $this->encryption = $encryption;
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

        return $row ? Tenant::fromArray($this->decryptRow($row)) : null;
    }

    public function findByNombreInterno(string $nombreInterno): ?Tenant
    {
        // Blind index: busca por HMAC de nombre_interno.
        $hmac = $this->blindIndex?->compute($nombreInterno) ?? strtolower(trim($nombreInterno));
        $sql = "SELECT * FROM tenants
                WHERE nombre_interno_hmac = :hmac
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['hmac' => $hmac]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Tenant::fromArray($this->decryptRow($row)) : null;
    }

    public function findByDominioCustom(string $dominio): ?Tenant
    {
        $sql = "SELECT * FROM tenants
                WHERE LOWER(dominio_custom) = LOWER(:dominio)
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['dominio' => trim($dominio)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Tenant::fromArray($this->decryptRow($row)) : null;
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

        return array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
    }

    public function findAll(bool $soloActivos = true): array
    {
        $sql = "SELECT * FROM tenants";
        if ($soloActivos) {
            $sql .= " WHERE deleted_at IS NULL";
        }
        // ORDER BY en SQL sobre nombre_publico cifrado no funciona.
        // Se ordena en PHP tras descifrar (lista de tenants es pequeña).
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenants = array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
        usort($tenants, fn(Tenant $a, Tenant $b) => strcmp($a->nombrePublico(), $b->nombrePublico()));

        return $tenants;
    }

    public function findByEstado(string $estado): array
    {
        $sql = "SELECT * FROM tenants
                WHERE estado = :estado AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['estado' => $estado]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenants = array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
        usort($tenants, fn(Tenant $a, Tenant $b) => strcmp($a->nombrePublico(), $b->nombrePublico()));

        return $tenants;
    }

    public function findByPlan(string $plan): array
    {
        $sql = "SELECT * FROM tenants
                WHERE plan = :plan AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['plan' => $plan]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenants = array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
        usort($tenants, fn(Tenant $a, Tenant $b) => strcmp($a->nombrePublico(), $b->nombrePublico()));

        return $tenants;
    }

    public function findOperativos(): array
    {
        $sql = "SELECT * FROM tenants
                WHERE estado = 'ACTIVO'
                  AND deleted_at IS NULL
                  AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenants = array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
        usort($tenants, fn(Tenant $a, Tenant $b) => strcmp($a->nombrePublico(), $b->nombrePublico()));

        return $tenants;
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

        return array_map(fn(array $row) => Tenant::fromArray($this->decryptRow($row)), $rows);
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

        $sql = "INSERT INTO tenants
                (id, nombre_publico, nombre_interno, nombre_interno_hmac, estado, plan, owner_user_id,
                 fecha_creacion, fecha_expiracion, email_contacto, telefono, pais,
                 zona_horaria, idioma_default, logo_url, dominio_custom,
                 configuracion, metadatos, created_at, updated_at)
                VALUES
                (:id, :nombre_publico, :nombre_interno, :nombre_interno_hmac, :estado, :plan, :owner_user_id,
                 :fecha_creacion, :fecha_expiracion, :email_contacto, :telefono, :pais,
                 :zona_horaria, :idioma_default, :logo_url, :dominio_custom,
                 :configuracion, :metadatos, :created_at, :updated_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'                   => $tenant->id(),
            'nombre_publico'       => $this->encryptField($tenant->nombrePublico()),
            'nombre_interno'       => $tenant->nombreInterno(),
            'nombre_interno_hmac'  => $this->blindIndex?->compute($tenant->nombreInterno()),
            'estado'               => $tenant->estado(),
            'plan'                 => $tenant->plan(),
            'owner_user_id'        => $tenant->ownerUserId(),
            'fecha_creacion'       => $tenant->fechaCreacion()->format('Y-m-d H:i:s'),
            'fecha_expiracion'     => $tenant->fechaExpiracion()?->format('Y-m-d H:i:s'),
            'email_contacto'       => $this->encryptField($tenant->emailContacto()),
            'telefono'             => $this->encryptField($tenant->telefono()),
            'pais'                 => $tenant->pais(),
            'zona_horaria'         => $tenant->zonaHoraria(),
            'idioma_default'       => $tenant->idiomaDefault(),
            'logo_url'             => $tenant->logoUrl(),
            'dominio_custom'       => $tenant->dominioCustom(),
            'configuracion'        => $this->encodeJson($tenant->configuracion()),
            'metadatos'            => $this->encodeJson($tenant->metadatos()),
            'created_at'           => $tenant->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'           => $tenant->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
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

        $sql = "UPDATE tenants
                SET nombre_publico = :nombre_publico,
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
                    configuracion = :configuracion,
                    metadatos = :metadatos,
                    updated_at = :updated_at
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'               => $tenant->id(),
            'nombre_publico'   => $this->encryptField($tenant->nombrePublico()),
            'estado'           => $tenant->estado(),
            'plan'             => $tenant->plan(),
            'owner_user_id'    => $tenant->ownerUserId(),
            'fecha_expiracion' => $tenant->fechaExpiracion()?->format('Y-m-d H:i:s'),
            'email_contacto'   => $this->encryptField($tenant->emailContacto()),
            'telefono'         => $this->encryptField($tenant->telefono()),
            'pais'             => $tenant->pais(),
            'zona_horaria'     => $tenant->zonaHoraria(),
            'idioma_default'   => $tenant->idiomaDefault(),
            'logo_url'         => $tenant->logoUrl(),
            'dominio_custom'   => $tenant->dominioCustom(),
            'configuracion'    => $this->encodeJson($tenant->configuracion()),
            'metadatos'        => $this->encodeJson($tenant->metadatos()),
            'updated_at'       => $tenant->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
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

    public function existsByNombreInterno(string $nombreInterno): bool
    {
        $sql = "SELECT COUNT(*) FROM tenants
                WHERE nombre_interno_hmac = :hmac
                  AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['hmac' => $this->blindIndex?->compute($nombreInterno) ?? strtolower(trim($nombreInterno))]);
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

    private function encodeJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Cifra un campo nullable. Retorna null si el valor es null o vacío.
     */
    private function encryptField(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->encryption === null) {
            return $value;
        }
        return $this->encryption->encrypt($value);
    }

    /**
     * Descifra campos PII de una fila de tenants (nombre_publico, email_contacto, telefono).
     * Si el valor no está cifrado (pre-migración), lo devuelve tal cual.
     */
    private function decryptRow(array $row): array
    {
        if ($this->encryption === null) {
            return $row;
        }

        foreach (['nombre_publico', 'email_contacto', 'telefono'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                try {
                    $row[$field] = $this->encryption->decrypt($row[$field]);
                } catch (\Throwable) {
                    // Pre-migración: valor en claro, dejar tal cual.
                }
            }
        }

        return $row;
    }
}
