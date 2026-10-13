<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * Documentos de recepción planificada (v1.13.28, BLOQUE R1).
 *
 * Separa la PLANIFICACIÓN de la RECEPCIÓN (principios del prompt):
 *  - El planificador introduce `cantidad_esperada` (nunca el receptor).
 *  - Quien crea el documento no puede recibirlo (R2 lo aplicará en el
 *    endpoint de recepción) y, además, al crear debe existir OTRO usuario
 *    activo del tenant con `inventory.receiving.create`: si el planificador
 *    fuera el único receptor posible, la separación de funciones sería
 *    ficticia → 409 `receivingSecondReceiverRequired`.
 *  - La edición (`PATCH`) sólo se permite en estado PLANIFICADA y sin
 *    líneas recibidas; la anulación, sólo en PLANIFICADA (política de
 *    borrado: no se borra, se anula; regla 11).
 *
 * Tablas: `receiving_documents` + `receiving_document_lines` (sin
 * soft delete: el documento mal creado se ANULA vía estado).
 */
final class ReceivingDocumentService
{
    private const PER_PAGE_MAX = 100;

    private DatabaseService $db;
    private TenantContext $tenantContext;
    private AuthorizationService $auth;

    public function __construct(
        ?DatabaseService $db = null,
        ?TenantContext $tenantContext = null,
        ?AuthorizationService $auth = null
    ) {
        $container = Container::getInstance();
        $this->db = $db ?? $container->get('database');
        $this->tenantContext = $tenantContext ?? $container->get(TenantContext::class);
        $this->auth = $auth ?? $container->get(AuthorizationService::class);
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    /**
     * Listado paginado de documentos del tenant.
     *
     * Filtros: estado (enum), search (numero_documento o proveedor).
     *
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $page = max(1, $page);
        $perPage = min(self::PER_PAGE_MAX, max(1, $perPage));

        $where = ['d.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        if ($estado !== '') {
            $where[] = 'd.estado = :estado';
            $params['estado'] = $estado;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.numero_documento LIKE :search OR d.proveedor LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $totalStmt = $this->db->getConnection()->prepare(
            "SELECT COUNT(*) FROM receiving_documents d WHERE {$whereSql}"
        );
        foreach ($params as $key => $value) {
            $totalStmt->bindValue(":{$key}", $value);
        }
        $totalStmt->execute();
        $total = (int) $totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT d.id, d.numero_documento, d.proveedor, d.referencia,
                       d.estado, d.creado_por, d.created_at, d.updated_at,
                       COUNT(l.id) AS lineas_totales,
                       COALESCE(SUM(CASE WHEN l.estado = 'RECIBIDA' THEN 1 ELSE 0 END), 0) AS lineas_recibidas
                  FROM receiving_documents d
                  LEFT JOIN receiving_document_lines l ON l.document_id = d.id
                 WHERE {$whereSql}
                 GROUP BY d.id
                 ORDER BY d.created_at DESC, d.id ASC
                 LIMIT :limit OFFSET :offset";

        $stmt = $this->db->getConnection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(
                fn(array $row): array => $this->mapSummaryRow($row),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            ),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ],
        ];
    }

    /**
     * Detalle de UNO documento del tenant con sus líneas (null si no existe).
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $tenantId = $this->tenantContext->getTenantId();

        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, numero_documento, proveedor, referencia, estado,
                    creado_por, created_at, updated_at
               FROM receiving_documents
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $doc = $this->mapSummaryRow($row);
        $doc['lineas'] = $this->findLines($id);

        return $doc;
    }

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    /**
     * Crea un documento PLANIFICADA con sus líneas.
     *
     * @param array<string, mixed> $data numero_documento, proveedor?, referencia?, lineas[]
     * @param string $actorId usuario autenticado (claims del request)
     * @return array<string, mixed> el documento creado con sus líneas
     */
    public function create(array $data, string $actorId): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (trim($actorId) === '') {
            throw new \RuntimeException('No hay usuario autenticado para planificar la recepción.');
        }

        $numero = trim((string) ($data['numero_documento'] ?? ''));
        if ($numero === '' || mb_strlen($numero) > 100) {
            throw InventoryException::requiredField('numero_documento');
        }

        $proveedor = trim((string) ($data['proveedor'] ?? ''));
        if (mb_strlen($proveedor) > 255) {
            throw InventoryException::invalidField('proveedor', 'Máximo 255 caracteres.');
        }

        $referencia = trim((string) ($data['referencia'] ?? ''));
        if (mb_strlen($referencia) > 255) {
            throw InventoryException::invalidField('referencia', 'Máximo 255 caracteres.');
        }

        $lineas = $this->normalizeLines($data['lineas'] ?? []);

        $this->assertNumeroLibre($tenantId, $numero);
        $this->assertAnotherReceiverExists($tenantId, $actorId);

        $resolved = array_map(
            fn(array $line): array => $this->resolveProductLine($tenantId, $line),
            $lineas
        );

        $pdo = $this->db->getConnection();
        $this->db->transaction(function () use ($pdo, $tenantId, $actorId, $numero, $proveedor, $referencia, $resolved): void {
            $now = date('Y-m-d H:i:s');
            $docId = $this->generateUuid();

            $stmt = $pdo->prepare(
                'INSERT INTO receiving_documents
                    (id, tenant_id, numero_documento, proveedor, referencia, estado, creado_por, created_at, updated_at)
                 VALUES
                    (:id, :tenant_id, :numero_documento, :proveedor, :referencia, :estado, :creado_por, :created_at, :updated_at)'
            );
            $stmt->execute([
                'id' => $docId,
                'tenant_id' => $tenantId,
                'numero_documento' => $numero,
                'proveedor' => $proveedor === '' ? null : $proveedor,
                'referencia' => $referencia === '' ? null : $referencia,
                'estado' => 'PLANIFICADA',
                'creado_por' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->insertLines($pdo, $docId, $resolved, $now);
        });

        $created = $this->findByNumero($tenantId, $numero);

        return $created ?? throw new \RuntimeException(
            "No se pudo leer el documento de recepción recién creado '{$numero}'."
        );
    }

    /**
     * Actualiza un documento PLANIFICADA y sin líneas recibidas.
     *
     * Campos editables: numero_documento, proveedor, referencia y (opcional)
     * la lista completa de líneas (se sustituyen; sólo existen PENDIENTE
     * mientras el estado sea PLANIFICADA).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> el documento actualizado con sus líneas
     */
    public function update(string $id, array $data): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        $before = $this->findById($id);
        if ($before === null) {
            throw InventoryException::receivingDocumentNotFound($id);
        }

        if ($before['estado'] !== 'PLANIFICADA') {
            throw InventoryException::receivingDocumentNotEditable((string) $before['estado']);
        }

        if ($this->countReceivedLines($id) > 0) {
            throw InventoryException::receivingDocumentNotEditable((string) $before['estado']);
        }

        $numero = array_key_exists('numero_documento', $data)
            ? trim((string) $data['numero_documento'])
            : (string) $before['numero_documento'];
        if ($numero === '' || mb_strlen($numero) > 100) {
            throw InventoryException::requiredField('numero_documento');
        }

        $proveedor = array_key_exists('proveedor', $data)
            ? trim((string) $data['proveedor'])
            : (string) ($before['proveedor'] ?? '');
        if (mb_strlen($proveedor) > 255) {
            throw InventoryException::invalidField('proveedor', 'Máximo 255 caracteres.');
        }

        $referencia = array_key_exists('referencia', $data)
            ? trim((string) $data['referencia'])
            : (string) ($before['referencia'] ?? '');
        if (mb_strlen($referencia) > 255) {
            throw InventoryException::invalidField('referencia', 'Máximo 255 caracteres.');
        }

        if ($numero !== (string) $before['numero_documento']) {
            $this->assertNumeroLibre($tenantId, $numero);
        }

        $pdo = $this->db->getConnection();
        $this->db->transaction(function () use ($pdo, $id, $tenantId, $numero, $proveedor, $referencia, $data): void {
            $now = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare(
                'UPDATE receiving_documents
                    SET numero_documento = :numero_documento,
                        proveedor = :proveedor,
                        referencia = :referencia,
                        updated_at = :updated_at
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([
                'numero_documento' => $numero,
                'proveedor' => $proveedor === '' ? null : $proveedor,
                'referencia' => $referencia === '' ? null : $referencia,
                'updated_at' => $now,
                'id' => $id,
                'tenant_id' => $tenantId,
            ]);

            if (array_key_exists('lineas', $data)) {
                $lineas = $this->normalizeLines($data['lineas']);
                $resolved = array_map(
                    fn(array $line): array => $this->resolveProductLine($tenantId, $line),
                    $lineas
                );

                $del = $pdo->prepare(
                    'DELETE FROM receiving_document_lines WHERE document_id = :document_id AND estado = :estado'
                );
                $del->execute(['document_id' => $id, 'estado' => 'PENDIENTE']);

                $this->insertLines($pdo, $id, $resolved, $now);
            }
        });

        $after = $this->findById($id);

        return $after ?? throw new \RuntimeException(
            "No se pudo leer el documento de recepción '{$id}' tras la actualización."
        );
    }

    /**
     * Anula un documento PLANIFICADA (política de borrado: estado ANULADA).
     *
     * @return array<string, mixed> el documento anulado
     */
    public function cancel(string $id): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        $before = $this->findById($id);
        if ($before === null) {
            throw InventoryException::receivingDocumentNotFound($id);
        }

        if ($before['estado'] !== 'PLANIFICADA') {
            throw InventoryException::receivingDocumentCannotCancel((string) $before['estado']);
        }

        $stmt = $this->db->getConnection()->prepare(
            'UPDATE receiving_documents
                SET estado = :estado, updated_at = :updated_at
              WHERE id = :id AND tenant_id = :tenant_id AND estado = :esperado'
        );
        $stmt->execute([
            'estado' => 'ANULADA',
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $id,
            'tenant_id' => $tenantId,
            'esperado' => 'PLANIFICADA',
        ]);

        return $this->findById($id) ?? throw new \RuntimeException(
            "No se pudo leer el documento de recepción '{$id}' tras anularlo."
        );
    }

    // ====================================================================
    // INTERNOS
    // ====================================================================

    /**
     * @return array<int, array{sku: string, cantidad_esperada: float}>
     */
    private function normalizeLines(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw InventoryException::receivingDocumentLinesRequired();
        }

        $lines = [];
        $seen = [];

        foreach (array_values($raw) as $index => $item) {
            $numeroLinea = $index + 1;
            if (!is_array($item)) {
                throw InventoryException::invalidField('lineas', "La línea #{$numeroLinea} no es un objeto válido.");
            }

            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                throw InventoryException::invalidField('lineas', "La línea #{$numeroLinea} no tiene SKU.");
            }

            if (isset($seen[$sku])) {
                throw InventoryException::receivingDocumentDuplicateSku($sku);
            }
            $seen[$sku] = true;

            $cantidad = $item['cantidad_esperada'] ?? null;
            if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
                throw InventoryException::invalidField(
                    'lineas',
                    "La línea del SKU '{$sku}' debe tener cantidad_esperada > 0."
                );
            }

            $lines[] = [
                'sku' => $sku,
                'cantidad_esperada' => round((float) $cantidad, 4),
            ];
        }

        return $lines;
    }

    /**
     * Resuelve el SKU contra los productos activos del tenant.
     *
     * @param array{sku: string, cantidad_esperada: float} $line
     * @return array{producto_id: string, sku: string, cantidad_esperada: float}
     */
    private function resolveProductLine(string $tenantId, array $line): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id FROM products
              WHERE tenant_id = :tenant_id AND sku = :sku AND activo = 1 AND deleted_at IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'sku' => $line['sku']]);
        $productoId = $stmt->fetchColumn();

        if ($productoId === false) {
            throw InventoryException::receivingDocumentProductNotFound($line['sku']);
        }

        return [
            'producto_id' => (string) $productoId,
            'sku' => $line['sku'],
            'cantidad_esperada' => $line['cantidad_esperada'],
        ];
    }

    /**
     * @param PDO $pdo
     * @param array<int, array{producto_id: string, sku: string, cantidad_esperada: float}> $lines
     */
    private function insertLines(PDO $pdo, string $documentId, array $lines, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO receiving_document_lines
                (id, document_id, producto_id, sku, cantidad_esperada, estado, unidad_recepcion_id, created_at, updated_at)
             VALUES
                (:id, :document_id, :producto_id, :sku, :cantidad_esperada, :estado, NULL, :created_at, :updated_at)'
        );

        foreach ($lines as $line) {
            $stmt->execute([
                'id' => $this->generateUuid(),
                'document_id' => $documentId,
                'producto_id' => $line['producto_id'],
                'sku' => $line['sku'],
                'cantidad_esperada' => $line['cantidad_esperada'],
                'estado' => 'PENDIENTE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findLines(string $documentId): array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, producto_id, sku, cantidad_esperada, estado, unidad_recepcion_id
               FROM receiving_document_lines
              WHERE document_id = :document_id
              ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['document_id' => $documentId]);

        return array_map(
            fn(array $row): array => [
                'id' => (string) $row['id'],
                'producto_id' => (string) $row['producto_id'],
                'sku' => (string) $row['sku'],
                'cantidad_esperada' => (float) $row['cantidad_esperada'],
                'estado' => (string) $row['estado'],
                'unidad_recepcion_id' => $row['unidad_recepcion_id'] !== null
                    ? (string) $row['unidad_recepcion_id']
                    : null,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByNumero(string $tenantId, string $numero): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id FROM receiving_documents
              WHERE tenant_id = :tenant_id AND numero_documento = :numero_documento'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'numero_documento' => $numero]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->findById((string) $id);
    }

    private function assertNumeroLibre(string $tenantId, string $numero): void
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT COUNT(*) FROM receiving_documents
              WHERE tenant_id = :tenant_id AND numero_documento = :numero_documento'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'numero_documento' => $numero]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw InventoryException::receivingDocumentNumberAlreadyExists($numero);
        }
    }

    /**
     * Debe existir al menos otro usuario activo del tenant con
     * `inventory.receiving.create` (separación de funciones).
     */
    private function assertAnotherReceiverExists(string $tenantId, string $excludeUserId): void
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT id FROM users
              WHERE tenant_id = :tenant_id
                AND estado = 'ACTIVO'
                AND deleted_at IS NULL
                AND id <> :exclude"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'exclude' => $excludeUserId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $candidateId) {
            if ($this->auth->hasPermission(
                (string) $candidateId,
                $tenantId,
                'inventory.receiving.create',
                false
            )) {
                return;
            }
        }

        throw InventoryException::receivingSecondReceiverRequired();
    }

    private function countReceivedLines(string $documentId): int
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT COUNT(*) FROM receiving_document_lines
              WHERE document_id = :document_id AND estado = 'RECIBIDA'"
        );
        $stmt->execute(['document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSummaryRow(array $row): array
    {
        $mapped = [
            'id' => (string) $row['id'],
            'numero_documento' => (string) $row['numero_documento'],
            'proveedor' => $row['proveedor'] !== null ? (string) $row['proveedor'] : null,
            'referencia' => $row['referencia'] !== null ? (string) $row['referencia'] : null,
            'estado' => (string) $row['estado'],
            'creado_por' => (string) $row['creado_por'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];

        if (array_key_exists('lineas_totales', $row)) {
            $mapped['lineas_totales'] = (int) $row['lineas_totales'];
            $mapped['lineas_recibidas'] = (int) $row['lineas_recibidas'];
        }

        return $mapped;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
