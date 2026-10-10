<?php
declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Entity\Secret;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Implementación MariaDB del repositorio de secretos.
 *
 * La tabla `secrets_vault` tiene aislamiento multi-tenant (tenant_id).
 * El nombre es único POR TENANT (no global).
 * El valor_encriptado NUNCA se expone en listados (solo en getDecryptable).
 */
final class SecretRepository implements SecretRepositoryInterface
{
    private PDO $pdo;

    public function __construct(DatabaseService $db)
    {
        $this->pdo = $db->getConnection();
    }

    // ========================================================================
    // LECTURA
    // ========================================================================

    public function findById(string $id, string $tenantId): ?Secret
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND activo = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Secret::fromArray($row) : null;
    }

    public function findByNombre(string $nombre, string $tenantId): ?Secret
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE LOWER(nombre) = LOWER(:nombre)
                  AND tenant_id = :tenant_id
                  AND activo = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nombre' => trim($nombre), 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Secret::fromArray($row) : null;
    }

    public function findAll(
        string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 20
    ): array {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        // Filtros opcionales
        if (isset($filters['tipo']) && $filters['tipo'] !== '') {
            $where[] = 'tipo = :tipo';
            $params['tipo'] = $filters['tipo'];
        }

        if (array_key_exists('activo', $filters)) {
            $where[] = 'activo = :activo';
            $params['activo'] = $filters['activo'] ? 1 : 0;
        } else {
            // Por defecto solo activos
            $where[] = 'activo = 1';
        }

        if (isset($filters['owner_user_id']) && $filters['owner_user_id'] !== '') {
            $where[] = 'owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $filters['owner_user_id'];
        }

        if (array_key_exists('rotacion_requerida', $filters)) {
            $where[] = 'rotacion_requerida = :rotacion_requerida';
            $params['rotacion_requerida'] = $filters['rotacion_requerida'] ? 1 : 0;
        }

        $whereClause = implode(' AND ', $where);

        // Contar total
        $countSql = "SELECT COUNT(*) FROM secrets_vault WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Obtener datos (SIN valor_encriptado en listado)
        $sql = "SELECT id, tenant_id, nombre, tipo, descripcion,
                       rotacion_requerida, ultima_rotacion, proxima_rotacion,
                       activo, owner_user_id, created_at, updated_at
                FROM secrets_vault
                WHERE {$whereClause}
                ORDER BY nombre ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hidratar entidades (valor_encriptado estará vacío en listado)
        $secrets = [];
        foreach ($rows as $row) {
            $row['valor_encriptado'] = ''; // Listado no expone valor
            $secrets[] = Secret::fromArray($row);
        }

        return [
            'data' => array_map(fn(Secret $s) => $s->toPublicArray(), $secrets),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    public function findByTipo(string $tenantId, string $tipo): array
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND tipo = :tipo
                  AND activo = 1
                ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'tipo' => $tipo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Secret::fromArray($row), $rows);
    }

    public function findByOwner(string $tenantId, string $ownerUserId): array
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND owner_user_id = :owner
                  AND activo = 1
                ORDER BY nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'owner' => $ownerUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Secret::fromArray($row), $rows);
    }

    public function findRotationOverdue(string $tenantId): array
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND activo = 1
                  AND rotacion_requerida = 1
                  AND proxima_rotacion IS NOT NULL
                  AND proxima_rotacion < NOW()
                ORDER BY proxima_rotacion ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Secret::fromArray($row), $rows);
    }

    public function findRotationDueSoon(string $tenantId, int $withinDays = 7): array
    {
        $sql = "SELECT * FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND activo = 1
                  AND rotacion_requerida = 1
                  AND proxima_rotacion IS NOT NULL
                  AND proxima_rotacion BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)
                ORDER BY proxima_rotacion ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':days', $withinDays, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => Secret::fromArray($row), $rows);
    }

    public function getDecryptable(string $id, string $tenantId): ?Secret
    {
        // Este método SÍ retorna el valor_encriptado
        $sql = "SELECT * FROM secrets_vault
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND activo = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Secret::fromArray($row) : null;
    }

    // ========================================================================
    // ESCRITURA
    // ========================================================================

    public function save(Secret $secret): void
    {
        if ($this->existsByNombre($secret->nombre(), $secret->tenantId())) {
            throw new \RuntimeException(
                "El secreto '{$secret->nombre()}' ya existe en el tenant '{$secret->tenantId()}'"
            );
        }

        $sql = "INSERT INTO secrets_vault
                (id, tenant_id, nombre, tipo, valor_encriptado, descripcion,
                 rotacion_requerida, ultima_rotacion, proxima_rotacion,
                 activo, owner_user_id, created_at, updated_at)
                VALUES
                (:id, :tenant_id, :nombre, :tipo, :valor_encriptado, :descripcion,
                 :rotacion_requerida, :ultima_rotacion, :proxima_rotacion,
                 :activo, :owner_user_id, :created_at, :updated_at)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'                  => $secret->id(),
            'tenant_id'           => $secret->tenantId(),
            'nombre'              => $secret->nombre(),
            'tipo'                => $secret->tipo(),
            'valor_encriptado'    => $secret->valorEncriptado(),
            'descripcion'         => $secret->descripcion(),
            'rotacion_requerida'  => $secret->rotacionRequerida() ? 1 : 0,
            'ultima_rotacion'     => $secret->ultimaRotacion()?->format('Y-m-d H:i:s'),
            'proxima_rotacion'    => $secret->proximaRotacion()?->format('Y-m-d H:i:s'),
            'activo'              => $secret->activo() ? 1 : 0,
            'owner_user_id'       => $secret->ownerUserId(),
            'created_at'          => $secret->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'          => $secret->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(Secret $secret): void
    {
        if (!$this->existsById($secret->id(), $secret->tenantId())) {
            throw new \RuntimeException("El secreto '{$secret->id()}' no existe");
        }

        $sql = "UPDATE secrets_vault
                SET nombre = :nombre,
                    tipo = :tipo,
                    descripcion = :descripcion,
                    rotacion_requerida = :rotacion_requerida,
                    ultima_rotacion = :ultima_rotacion,
                    proxima_rotacion = :proxima_rotacion,
                    activo = :activo,
                    owner_user_id = :owner_user_id,
                    updated_at = :updated_at
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'                  => $secret->id(),
            'tenant_id'           => $secret->tenantId(),
            'nombre'              => $secret->nombre(),
            'tipo'                => $secret->tipo(),
            'descripcion'         => $secret->descripcion(),
            'rotacion_requerida'  => $secret->rotacionRequerida() ? 1 : 0,
            'ultima_rotacion'     => $secret->ultimaRotacion()?->format('Y-m-d H:i:s'),
            'proxima_rotacion'    => $secret->proximaRotacion()?->format('Y-m-d H:i:s'),
            'activo'              => $secret->activo() ? 1 : 0,
            'owner_user_id'       => $secret->ownerUserId(),
            'updated_at'          => $secret->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function rotate(
        string $id,
        string $tenantId,
        string $nuevoValorEncriptado,
        ?int $rotationDays = null
    ): bool {
        $now = date('Y-m-d H:i:s');
        $proximaRotacion = null;

        // Si requiere rotación, calcular próxima fecha
        $sqlCheck = "SELECT rotacion_requerida FROM secrets_vault
                     WHERE id = :id AND tenant_id = :tenant_id AND activo = 1";
        $stmtCheck = $this->pdo->prepare($sqlCheck);
        $stmtCheck->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($row && (bool) $row['rotacion_requerida'] && $rotationDays !== null && $rotationDays > 0) {
            $proximaRotacion = date('Y-m-d H:i:s', strtotime("+{$rotationDays} days"));
        }

        $sql = "UPDATE secrets_vault
                SET valor_encriptado = :valor,
                    ultima_rotacion = :ultima,
                    proxima_rotacion = :proxima,
                    updated_at = :updated
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'valor'   => $nuevoValorEncriptado,
            'ultima'  => $now,
            'proxima' => $proximaRotacion,
            'updated' => $now,
            'id'      => $id,
            'tenant_id' => $tenantId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deactivate(string $id, string $tenantId): bool
    {
        $sql = "UPDATE secrets_vault
                SET activo = 0, updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function activate(string $id, string $tenantId): bool
    {
        $sql = "UPDATE secrets_vault
                SET activo = 1, updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function hardDelete(string $id, string $tenantId): bool
    {
        $sql = "DELETE FROM secrets_vault
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $stmt->rowCount() > 0;
    }

    // ========================================================================
    // CONSULTAS
    // ========================================================================

    public function existsByNombre(string $nombre, string $tenantId): bool
    {
        $sql = "SELECT COUNT(*) FROM secrets_vault
                WHERE LOWER(nombre) = LOWER(:nombre)
                  AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nombre' => trim($nombre), 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsById(string $id, string $tenantId): bool
    {
        $sql = "SELECT COUNT(*) FROM secrets_vault
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function count(string $tenantId, array $filters = []): int
    {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (isset($filters['tipo']) && $filters['tipo'] !== '') {
            $where[] = 'tipo = :tipo';
            $params['tipo'] = $filters['tipo'];
        }

        if (array_key_exists('activo', $filters)) {
            $where[] = 'activo = :activo';
            $params['activo'] = $filters['activo'] ? 1 : 0;
        } else {
            $where[] = 'activo = 1';
        }

        if (isset($filters['owner_user_id']) && $filters['owner_user_id'] !== '') {
            $where[] = 'owner_user_id = :owner';
            $params['owner'] = $filters['owner_user_id'];
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM secrets_vault WHERE {$whereClause}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countRotationOverdue(string $tenantId): int
    {
        $sql = "SELECT COUNT(*) FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND activo = 1
                  AND rotacion_requerida = 1
                  AND proxima_rotacion IS NOT NULL
                  AND proxima_rotacion < NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    public function countRotationDueSoon(string $tenantId, int $withinDays = 7): int
    {
        $sql = "SELECT COUNT(*) FROM secrets_vault
                WHERE tenant_id = :tenant_id
                  AND activo = 1
                  AND rotacion_requerida = 1
                  AND proxima_rotacion IS NOT NULL
                  AND proxima_rotacion BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':days', $withinDays, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
