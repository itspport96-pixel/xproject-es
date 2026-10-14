<?php

declare(strict_types=1);

namespace App\Core\Authorization\Repository;

use App\Core\Authorization\Entity\TemporaryPermission;
use App\Core\Security\Service\EncryptionService;
use App\Shared\Services\DatabaseService;

/**
 * Repositorio de permisos temporales (spec §5.3, v1.11.0).
 */
final class TemporaryPermissionRepository implements TemporaryPermissionRepositoryInterface
{
    private DatabaseService $db;
    private ?EncryptionService $encryption;

    public function __construct(DatabaseService $db, ?EncryptionService $encryption = null)
    {
        $this->db = $db;
        $this->encryption = $encryption;
    }

    public function findAll(
        string $tenantId,
        ?string $userId = null,
        bool $soloVigentes = true,
        ?int $page = null,
        ?int $perPage = null
    ): array {
        $sql = "SELECT tp.id, tp.user_id, u.email AS user_email,
                       tp.permission_id, p.identificador AS permission_identificador,
                       p.criticidad AS permission_criticidad,
                       tp.recurso_id, tp.granted_by_user_id, g.email AS granted_by_email,
                       tp.motivo, tp.fecha_inicio, tp.fecha_expiracion, tp.activo,
                       tp.created_at, tp.updated_at
                FROM temporary_permissions tp
                INNER JOIN users u ON u.id = tp.user_id
                INNER JOIN permissions p ON p.id = tp.permission_id
                LEFT JOIN users g ON g.id = tp.granted_by_user_id";

        [$where, $params] = $this->buildWhere($tenantId, $userId, $soloVigentes);
        $sql .= $where . ' ORDER BY tp.fecha_expiracion DESC, tp.created_at DESC';

        if ($page !== null) {
            $limit = max(1, $perPage ?? 20);
            $offset = (max(1, $page) - 1) * $limit;
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params['limit'] = $limit;
            $params['offset'] = $offset;
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                ":{$key}",
                $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->decryptEmails($row);
        }
        unset($row);

        return $rows;
    }

    public function count(string $tenantId, ?string $userId = null, bool $soloVigentes = true): int
    {
        [$where, $params] = $this->buildWhere($tenantId, $userId, $soloVigentes);

        $sql = "SELECT COUNT(*)
                FROM temporary_permissions tp
                INNER JOIN users u ON u.id = tp.user_id
                INNER JOIN permissions p ON p.id = tp.permission_id
                {$where}";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * WHERE compartido por findAll() y count() (mismos filtros => mismo total).
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(string $tenantId, ?string $userId, bool $soloVigentes): array
    {
        $where = ' WHERE u.tenant_id = :tid';
        $params = ['tid' => $tenantId];

        if ($userId !== null) {
            $where .= ' AND tp.user_id = :uid';
            $params['uid'] = $userId;
        }

        if ($soloVigentes) {
            $where .= " AND tp.activo = 1
                      AND tp.fecha_inicio <= NOW()
                      AND tp.fecha_expiracion > NOW()";
        }

        return [$where, $params];
    }

    public function findById(string $id, string $tenantId): ?array
    {
        $sql = "SELECT tp.id, tp.user_id, u.email AS user_email,
                       tp.permission_id, p.identificador AS permission_identificador,
                       p.criticidad AS permission_criticidad,
                       tp.recurso_id, tp.granted_by_user_id, g.email AS granted_by_email,
                       tp.motivo, tp.fecha_inicio, tp.fecha_expiracion, tp.activo,
                       tp.created_at, tp.updated_at
                FROM temporary_permissions tp
                INNER JOIN users u ON u.id = tp.user_id
                INNER JOIN permissions p ON p.id = tp.permission_id
                LEFT JOIN users g ON g.id = tp.granted_by_user_id
                WHERE tp.id = :id AND u.tenant_id = :tid";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $this->decryptEmails($row);
        return $row;
    }

    public function save(TemporaryPermission $temporaryPermission): void
    {
        $sql = "INSERT INTO temporary_permissions
                    (id, user_id, permission_id, recurso_id, granted_by_user_id,
                     motivo, fecha_inicio, fecha_expiracion, activo, created_at, updated_at)
                VALUES
                    (:id, :user_id, :permission_id, :recurso_id, :granted_by_user_id,
                     :motivo, :fecha_inicio, :fecha_expiracion, :activo, :created_at, :updated_at)";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'id'                  => $temporaryPermission->id(),
            'user_id'             => $temporaryPermission->userId(),
            'permission_id'       => $temporaryPermission->permissionId(),
            'recurso_id'          => $temporaryPermission->recursoId(),
            'granted_by_user_id'  => $temporaryPermission->grantedByUserId(),
            'motivo'              => $temporaryPermission->motivo(),
            'fecha_inicio'        => $temporaryPermission->fechaInicio()->format('Y-m-d H:i:s'),
            'fecha_expiracion'    => $temporaryPermission->fechaExpiracion()->format('Y-m-d H:i:s'),
            'activo'              => $temporaryPermission->activo() ? 1 : 0,
            'created_at'          => ($temporaryPermission->createdAt() ?? new \DateTimeImmutable('now'))
                ->format('Y-m-d H:i:s'),
            'updated_at'          => $temporaryPermission->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function deactivate(string $id): bool
    {
        $sql = "UPDATE temporary_permissions
                SET activo = 0, updated_at = NOW()
                WHERE id = :id AND activo = 1";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsVigente(string $userId, string $permissionId, ?string $recursoId, string $tenantId): bool
    {
        // Ojo: PDO no acepta parámetros nombrados repetidos -> dos consultas
        // según recurso_id (NULL se compara con IS NULL).
        if ($recursoId === null) {
            $sql = "SELECT COUNT(*)
                    FROM temporary_permissions tp
                    INNER JOIN users u ON u.id = tp.user_id
                    WHERE tp.user_id = :uid
                      AND tp.permission_id = :pid
                      AND tp.recurso_id IS NULL
                      AND tp.activo = 1
                      AND tp.fecha_expiracion > NOW()
                      AND u.tenant_id = :tid";
            $params = ['uid' => $userId, 'pid' => $permissionId, 'tid' => $tenantId];
        } else {
            $sql = "SELECT COUNT(*)
                    FROM temporary_permissions tp
                    INNER JOIN users u ON u.id = tp.user_id
                    WHERE tp.user_id = :uid
                      AND tp.permission_id = :pid
                      AND tp.recurso_id = :rid
                      AND tp.activo = 1
                      AND tp.fecha_expiracion > NOW()
                      AND u.tenant_id = :tid";
            $params = ['uid' => $userId, 'pid' => $permissionId, 'rid' => $recursoId, 'tid' => $tenantId];
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Descifra los emails cifrados (AES-256-GCM) en la fila.
     * users.email y granted_by_email pueden venir cifrados desde la BD.
     */
    private function decryptEmails(array &$row): void
    {
        if ($this->encryption === null) {
            return;
        }

        foreach (['user_email', 'granted_by_email'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                try {
                    $row[$field] = $this->encryption->decrypt($row[$field]);
                } catch (\Throwable) {
                    // Pre-migración: valor en claro, dejar tal cual.
                }
            }
        }
    }
}
