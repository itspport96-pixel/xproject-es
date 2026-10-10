<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\ValueObjects\LocationType;
use PDO;

/**
 * Presencia física de unidades en las ubicaciones (CU-010/CU-011, v1.9.0).
 *
 * Dos operaciones, con el MISMO criterio que `bin/recalculate-capacity.php`:
 *
 *  - `snapshot()` instantánea de las unidades presentes (lista teórica de un
 *    conteo): unidades con ubicación física (se excluyen las de la zona
 *    virtual TRANSITO y las que no tienen ubicación, p.ej. ENTREGADO).
 *  - `cupoUsado()` = SUM(CEIL(cantidad)) de las unidades cuya `ubicacion_id`
 *    es esa ubicación (las virtuales no llevan contador).
 *
 * Sólo lectura: el tenant_id llega SIEMPRE explícito (claims o contexto).
 */
final class InventoryPresenceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lista teórica para un conteo: todas las unidades presentes del tenant
     * o sólo las de una ubicación concreta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(string $tenantId, ?string $ubicacionId = null): array
    {
        $sql = 'SELECT u.id, u.codigo_barcode, u.producto_id, u.ubicacion_id, u.cantidad, l.codigo
                  FROM stock_units u
                  JOIN locations l
                    ON l.id = u.ubicacion_id AND l.tenant_id = u.tenant_id
                 WHERE u.tenant_id = :tenant
                   AND l.deleted_at IS NULL
                   AND l.tipo <> :virtual';

        $params = [
            'tenant' => $tenantId,
            'virtual' => LocationType::TRANSITO,
        ];

        if ($ubicacionId !== null) {
            $sql .= ' AND u.ubicacion_id = :ubicacion';
            $params['ubicacion'] = $ubicacionId;
        }

        $sql .= ' ORDER BY l.codigo, u.codigo_barcode';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $lista = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lista[] = [
                'unidad_fisica_id' => (string) $row['id'],
                'codigo_barcode' => (string) $row['codigo_barcode'],
                'producto_id' => (string) $row['producto_id'],
                'ubicacion_id' => (string) $row['ubicacion_id'],
                'ubicacion_codigo' => (string) $row['codigo'],
                'cantidad_esperada' => (float) $row['cantidad'],
            ];
        }

        return $lista;
    }

    /**
     * Slots ocupados en una ubicación por presencia física de unidades
     * (SUM(CEIL(cantidad))). Las ubicaciones virtuales devuelven 0.
     */
    public function cupoUsado(string $tenantId, string $ubicacionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo FROM locations WHERE id = :ubicacion AND tenant_id = :tenant'
        );
        $stmt->execute(['ubicacion' => $ubicacionId, 'tenant' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return 0;
        }

        if ((string) $row['tipo'] === LocationType::TRANSITO) {
            return 0;
        }

        $slots = 0;

        $units = $this->pdo->prepare(
            'SELECT cantidad FROM stock_units
              WHERE tenant_id = :tenant AND ubicacion_id = :ubicacion'
        );
        $units->execute(['tenant' => $tenantId, 'ubicacion' => $ubicacionId]);

        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $slots += (int) ceil((float) $fila['cantidad']);
        }

        return $slots;
    }
}
