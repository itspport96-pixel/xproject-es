<?php

declare(strict_types=1);

namespace App\Core\Authorization\Seeder;

use PDO;

/**
 * Siembra los permisos del módulo Inventory y su asignación a los roles.
 *
 * Regla (spec Port 2 / Port 3):
 *  - Los permisos son GLOBALES (tabla `permissions`, sin tenant_id).
 *  - Las asignaciones viven en `roles.permisos` (JSON array de identificadores).
 *  - Un tenant nuevo queda funcional sin SQL manual: basta con ejecutar
 *    PlatformSeeder, que llama a este seeder.
 *
 * La fuente de verdad es la BD viva (24 permisos `inventory.*` y sus listas
 * por rol); cualquier desviación (permiso modificado, permiso ausente o rol
 * sin el permiso) se corrige al ejecutar. Idempotente: repetirlo no duplica
 * ni reescribe lo que ya coincide.
 *
 * El Sprint 7 (v1.7.0) añade 3 permisos nuevos (`inventory.picking.verify`,
 * `inventory.picking.view`, `inventory.shipping.view`): total 27.
 * El Sprint 8 (v1.8.0) añade `inventory.delivery.view` e
 * `inventory.return.view` (criterio de roles: `*.view` → los 4 BUILT_IN,
 * como `inventory.shipping.view`): total 29.
 * v1.8.3: `inventory.delivery.confirm` e `inventory.return.create` se
 * retiran del rol USER (escritura: sólo ADMIN y MANAGER); el seeder sigue
 * siendo aditivo, así que la corrección en la BD viva se hace una vez y no
 * se revierte al re-ejecutar.
 * El Sprint 9 (v1.9.0, CU-010/CU-011) añade `inventory.incident.view`,
 * `inventory.count.create`, `inventory.count.view` e
 * `inventory.count.reconcile`: total 33. Además MANAGER gana
 * `inventory.incident.resolve` (antes sólo ADMIN) para poder resolver
 * incidencias sin escalar.
 * v1.10.0 (E4) añade `inventory.stock.block` (bloquear/desbloquear unidades;
 * escritura → sólo ADMIN y MANAGER): total 34.
 * v1.13.28 (BLOQUE R1) añade `inventory.receiving.plan` (planificar recepción:
 * documentos de recepción; escritura → sólo ADMIN y MANAGER): total 35.
 * v1.13.30 (BLOQUE R3) añade `inventory.receiving.adhoc` (recepción sin
 * documento planificado; escritura → ADMIN y MANAGER): total 36.
 */
final class InventoryPermissionSeeder
{
    /**
     * Permisos `inventory.*` del módulo (BD viva + `inventory.reservation.view`,
     * siembra nueva del v1.6.2 para GET /api/inventory/reservations).
     *
     * @var array<int, array{
     *     identificador: string, nombre: string, modulo: string,
     *     recurso: string, accion: string, criticidad: string,
     *     requiere_mfa: int, activo: int
     * }>
     */
    public const PERMISSIONS = [
        ['inventory.count.create', 'Crear Conteo', 'inventory', 'count', 'create', 'MEDIUM', 0, 1],
        ['inventory.count.reconcile', 'Reconciliar Conteo', 'inventory', 'count', 'edit', 'HIGH', 0, 1],
        ['inventory.count.view', 'Ver Conteos', 'inventory', 'count', 'view', 'MEDIUM', 0, 1],
        ['inventory.delivery.confirm', 'Confirmar Entrega', 'inventory', 'delivery', 'edit', 'HIGH', 0, 1],
        ['inventory.delivery.reject', 'Rechazar Entrega', 'inventory', 'delivery', 'edit', 'HIGH', 0, 1],
        ['inventory.delivery.view', 'Ver Entregas', 'inventory', 'delivery', 'view', 'MEDIUM', 0, 1],
        ['inventory.incident.report', 'Reportar Incidencia', 'inventory', 'incident', 'create', 'MEDIUM', 0, 1],
        ['inventory.incident.resolve', 'Resolver Incidencia', 'inventory', 'incident', 'edit', 'HIGH', 0, 1],
        ['inventory.incident.view', 'Ver Incidencias', 'inventory', 'incident', 'view', 'MEDIUM', 0, 1],
        ['inventory.item.create', 'Crear Items', 'inventory', 'item', 'create', 'HIGH', 0, 1],
        ['inventory.item.delete', 'Eliminar Items', 'inventory', 'item', 'delete', 'CRITICAL', 0, 1],
        ['inventory.item.edit', 'Editar Items', 'inventory', 'item', 'edit', 'HIGH', 0, 1],
        ['inventory.item.view', 'Ver Items', 'inventory', 'item', 'view', 'MEDIUM', 0, 1],
        ['inventory.location.manage', 'Gestionar Ubicaciones', 'inventory', 'location', 'edit', 'HIGH', 0, 1],
        ['inventory.location.view', 'Ver Ubicaciones', 'inventory', 'location', 'view', 'MEDIUM', 0, 1],
        ['inventory.movement.create', 'Mover Inventario', 'inventory', 'movement', 'create', 'MEDIUM', 0, 1],
        ['inventory.picking.confirm', 'Confirmar Picking', 'inventory', 'picking', 'edit', 'MEDIUM', 0, 1],
        ['inventory.picking.create', 'Crear Picking', 'inventory', 'picking', 'create', 'MEDIUM', 0, 1],
        ['inventory.picking.verify', 'Verificar Picking', 'inventory', 'picking', 'edit', 'HIGH', 0, 1],
        ['inventory.picking.view', 'Ver Picking', 'inventory', 'picking', 'view', 'MEDIUM', 0, 1],
        ['inventory.receiving.adhoc', 'Recepción Adhoc', 'inventory', 'receiving', 'create', 'HIGH', 0, 1],
        ['inventory.receiving.confirm', 'Confirmar Recepción', 'inventory', 'receiving', 'edit', 'HIGH', 0, 1],
        ['inventory.receiving.create', 'Recibir Inventario', 'inventory', 'receiving', 'create', 'HIGH', 0, 1],
        ['inventory.receiving.plan', 'Planificar Recepción', 'inventory', 'receiving', 'create', 'HIGH', 0, 1],
        ['inventory.reservation.cancel', 'Cancelar Reserva', 'inventory', 'reservation', 'edit', 'MEDIUM', 0, 1],
        ['inventory.reservation.create', 'Crear Reserva', 'inventory', 'reservation', 'create', 'MEDIUM', 0, 1],
        ['inventory.reservation.view', 'Ver Reservas', 'inventory', 'reservation', 'view', 'MEDIUM', 0, 1],
        ['inventory.return.confirm', 'Confirmar Devolución', 'inventory', 'return', 'edit', 'MEDIUM', 0, 1],
        ['inventory.return.create', 'Crear Devolución', 'inventory', 'return', 'create', 'MEDIUM', 0, 1],
        ['inventory.return.view', 'Ver Devoluciones', 'inventory', 'return', 'view', 'MEDIUM', 0, 1],
        ['inventory.shipping.confirm', 'Confirmar Envío', 'inventory', 'shipping', 'edit', 'HIGH', 0, 1],
        ['inventory.shipping.create', 'Crear Envío', 'inventory', 'shipping', 'create', 'HIGH', 0, 1],
        ['inventory.shipping.view', 'Ver Envíos', 'inventory', 'shipping', 'view', 'MEDIUM', 0, 1],
        ['inventory.stock.adjust', 'Ajustar Stock', 'inventory', 'stock', 'edit', 'HIGH', 0, 1],
        ['inventory.stock.block', 'Bloquear Stock', 'inventory', 'stock', 'edit', 'HIGH', 0, 1],
        ['inventory.stock.view', 'Ver Stock', 'inventory', 'stock', 'view', 'MEDIUM', 0, 1],
    ];

    /**
     * Permisos `inventory.*` que debe tener cada rol BUILT_IN.
     * `inventory.reservation.view` va a los roles que ya tienen
     * `inventory.stock.view` (los cuatro BUILT_IN). Los permisos ajenos a
     * Inventory que ya tengan los roles se conservan.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'ADMIN' => [
            'inventory.item.view',
            'inventory.item.create',
            'inventory.item.edit',
            'inventory.item.delete',
            'inventory.stock.view',
            'inventory.stock.adjust',
            'inventory.stock.block',
            'inventory.location.view',
            'inventory.location.manage',
            'inventory.receiving.create',
            'inventory.receiving.adhoc',
            'inventory.receiving.confirm',
            'inventory.receiving.plan',
            'inventory.movement.create',
            'inventory.reservation.view',
            'inventory.reservation.create',
            'inventory.reservation.cancel',
            'inventory.picking.create',
            'inventory.picking.confirm',
            'inventory.picking.verify',
            'inventory.picking.view',
            'inventory.shipping.create',
            'inventory.shipping.confirm',
            'inventory.shipping.view',
            'inventory.delivery.confirm',
            'inventory.delivery.view',
            'inventory.return.create',
            'inventory.return.confirm',
            'inventory.return.view',
            'inventory.incident.report',
            'inventory.incident.resolve',
            'inventory.incident.view',
            'inventory.count.create',
            'inventory.count.view',
            'inventory.count.reconcile',
        ],
        'MANAGER' => [
            'inventory.item.view',
            'inventory.item.create',
            'inventory.item.edit',
            'inventory.stock.view',
            'inventory.stock.adjust',
            'inventory.stock.block',
            'inventory.location.view',
            'inventory.receiving.create',
            'inventory.receiving.adhoc',
            'inventory.receiving.confirm',
            'inventory.receiving.plan',
            'inventory.movement.create',
            'inventory.reservation.view',
            'inventory.reservation.create',
            'inventory.reservation.cancel',
            'inventory.picking.create',
            'inventory.picking.confirm',
            'inventory.picking.verify',
            'inventory.picking.view',
            'inventory.shipping.create',
            'inventory.shipping.confirm',
            'inventory.shipping.view',
            'inventory.delivery.confirm',
            'inventory.delivery.view',
            'inventory.return.create',
            'inventory.return.confirm',
            'inventory.return.view',
            'inventory.incident.report',
            'inventory.incident.resolve',
            'inventory.incident.view',
            'inventory.count.create',
            'inventory.count.view',
            'inventory.count.reconcile',
        ],
        'USER' => [
            'inventory.item.view',
            'inventory.stock.view',
            'inventory.location.view',
            'inventory.receiving.create',
            'inventory.movement.create',
            'inventory.reservation.view',
            'inventory.reservation.create',
            'inventory.picking.create',
            'inventory.picking.view',
            'inventory.shipping.create',
            'inventory.shipping.view',
            'inventory.delivery.view',
            'inventory.return.view',
            'inventory.incident.report',
            'inventory.incident.view',
            'inventory.count.create',
            'inventory.count.view',
        ],
        'VIEWER' => [
            'inventory.item.view',
            'inventory.stock.view',
            'inventory.location.view',
            'inventory.reservation.view',
            'inventory.picking.view',
            'inventory.shipping.view',
            'inventory.delivery.view',
            'inventory.return.view',
            'inventory.incident.view',
            'inventory.count.view',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Crea/reescribe los permisos `inventory.*` y añade a cada rol BUILT_IN
     * los permisos de inventory que le falten.
     *
     * @return array{permissions_created: int, permissions_updated: int, roles_updated: int}
     */
    public function seed(): array
    {
        $created = 0;
        $updated = 0;

        foreach (self::PERMISSIONS as $definition) {
            [$identificador, $nombre, $modulo, $recurso, $accion, $criticidad, $requiereMfa, $activo] = $definition;

            $stmt = $this->pdo->prepare(
                'SELECT id, nombre, modulo, recurso, accion, criticidad, requiere_mfa, activo
                   FROM permissions
                  WHERE identificador = :identificador'
            );
            $stmt->execute(['identificador' => $identificador]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $expected = [
                'nombre' => $nombre,
                'modulo' => $modulo,
                'recurso' => $recurso,
                'accion' => $accion,
                'criticidad' => $criticidad,
                'requiere_mfa' => $requiereMfa,
                'activo' => $activo,
            ];

            if ($row === false) {
                $this->insertPermission($identificador, $expected);
                $created++;
                continue;
            }

            $drift = false;
            foreach ($expected as $column => $value) {
                if ((string) $row[$column] !== (string) $value) {
                    $drift = true;
                    break;
                }
            }

            if ($drift) {
                $this->updatePermission((string) $row['id'], $expected);
                $updated++;
            }
        }

        $rolesUpdated = 0;
        foreach (self::ROLE_PERMISSIONS as $nombre => $required) {
            if ($this->syncRolePermissions($nombre, $required)) {
                $rolesUpdated++;
            }
        }

        return [
            'permissions_created' => $created,
            'permissions_updated' => $updated,
            'roles_updated' => $rolesUpdated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertPermission(string $identificador, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO permissions
                (id, identificador, nombre, descripcion, modulo, recurso, accion,
                 criticidad, requiere_mfa, activo, metadata, created_at)
             VALUES
                (:id, :identificador, :nombre, NULL, :modulo, :recurso, :accion,
                 :criticidad, :requiere_mfa, :activo, NULL, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            'id' => $this->generateUuid(),
            'identificador' => $identificador,
            'nombre' => $data['nombre'],
            'modulo' => $data['modulo'],
            'recurso' => $data['recurso'],
            'accion' => $data['accion'],
            'criticidad' => $data['criticidad'],
            'requiere_mfa' => $data['requiere_mfa'],
            'activo' => $data['activo'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updatePermission(string $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE permissions
                SET nombre = :nombre,
                    modulo = :modulo,
                    recurso = :recurso,
                    accion = :accion,
                    criticidad = :criticidad,
                    requiere_mfa = :requiere_mfa,
                    activo = :activo
              WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'nombre' => $data['nombre'],
            'modulo' => $data['modulo'],
            'recurso' => $data['recurso'],
            'accion' => $data['accion'],
            'criticidad' => $data['criticidad'],
            'requiere_mfa' => $data['requiere_mfa'],
            'activo' => $data['activo'],
        ]);
    }

    /**
     * Añade al rol (si existe) los permisos `inventory.*` que le falten.
     *
     * @param array<int, string> $required
     * @return bool true si el rol se modificó
     */
    private function syncRolePermissions(string $nombre, array $required): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, permisos FROM roles WHERE nombre = :nombre AND tipo = ' . "'BUILT_IN'" . ' AND deleted_at IS NULL'
        );
        $stmt->execute(['nombre' => $nombre]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $current = $row['permisos'] ?? null;
        $decoded = is_string($current) ? json_decode($current, true) : $current;

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded = array_values(array_map('strval', $decoded));
        $missing = array_values(array_diff($required, $decoded));

        if ($missing === []) {
            return false;
        }

        $merged = array_values(array_unique(array_merge($decoded, $missing)));
        sort($merged);

        $update = $this->pdo->prepare(
            'UPDATE roles SET permisos = :permisos, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $update->execute([
            'permisos' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'id' => $row['id'],
        ]);

        return true;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
