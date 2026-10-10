<?php

declare(strict_types=1);

namespace App\Core\Authorization\Http;

use App\Core\Authorization\Entity\Permission;
use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Catálogo de permisos (spec §5.2).
 *
 * Los permisos son globales (no pertenecen a un tenant) y sólo se leen por
 * HTTP: el alta/baja la hacen los seeders. Requiere permissions.permission.view
 * (sembrado por PlatformControlPermissionSeeder: ADMIN, MANAGER, USER, VIEWER).
 */
final class PermissionController
{
    private PermissionRepositoryInterface $permissions;

    public function __construct(?PermissionRepositoryInterface $permissions = null)
    {
        $container = Container::getInstance();
        $this->permissions = $permissions ?? $container->get(PermissionRepositoryInterface::class);
    }

    /**
     * GET /api/permissions
     * Lista el catálogo. Filtros opcionales (mutuamente excluyentes):
     * ?modulo=inventory | ?criticidad=CRITICAL | ?include_inactive=true
     */
    public function index(array $request): array
    {
        $this->requireAuthenticated($request);
        $query = (array) ($request['query'] ?? []);

        $modulo     = isset($query['modulo']) ? trim((string) $query['modulo']) : '';
        $criticidad = isset($query['criticidad']) ? trim((string) $query['criticidad']) : '';
        $soloActivos = (($query['include_inactive'] ?? 'false') !== 'true');

        if ($modulo !== '' && $criticidad !== '') {
            throw new HttpException('Los filtros modulo y criticidad son excluyentes', 400);
        }
        if ($criticidad !== '' && !in_array($criticidad, Permission::VALID_CRITICIDADES, true)) {
            throw new HttpException('Criticidad inválida', 400);
        }

        $permisos = $this->permissions->findAll($soloActivos);

        if ($modulo !== '') {
            $permisos = array_values(array_filter(
                $permisos,
                fn(Permission $p): bool => strtolower($p->modulo()) === strtolower($modulo)
            ));
        }
        if ($criticidad !== '') {
            $permisos = array_values(array_filter(
                $permisos,
                fn(Permission $p): bool => $p->criticidad() === $criticidad
            ));
        }

        return $this->json(200, [
            'permissions' => array_map(fn(Permission $p): array => $p->toArray(), $permisos),
            'total'       => count($permisos),
        ]);
    }

    /**
     * GET /api/permissions/{id}
     * Detalle de un permiso del catálogo.
     */
    public function show(array $request, array $params): array
    {
        $this->requireAuthenticated($request);
        $id = $params['id'] ?? null;

        if (!$id) {
            throw new HttpException('ID de permiso requerido', 400);
        }

        $permission = $this->permissions->findById((string) $id);
        if ($permission === null) {
            throw new HttpException('Permiso no encontrado', 404);
        }

        return $this->json(200, ['permission' => $permission->toArray()]);
    }

    /**
     * @param array<string, mixed> $request
     * @throws HttpException 401 si no está autenticado.
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, data: array<string, mixed>}
     */
    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
