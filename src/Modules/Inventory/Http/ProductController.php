<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP para el agregado Product.
 *
 * Endpoints:
 *  - GET    /api/inventory/products              → index (listar)
 *  - GET    /api/inventory/products/by-sku/{sku} → findBySku
 *  - GET    /api/inventory/products/{id}         → show
 *  - POST   /api/inventory/products              → store
 *  - PATCH  /api/inventory/products/{id}         → update
 *  - DELETE /api/inventory/products/{id}         → destroy
 *  - POST   /api/inventory/products/{id}/activate   → activate
 *  - POST   /api/inventory/products/{id}/deactivate → deactivate
 *
 * Permisos requeridos (aplicados via PermissionMiddleware en routes):
 *  - inventory.item.view    → index, show, findBySku
 *  - inventory.item.create  → store
 *  - inventory.item.edit    → update, activate, deactivate
 *  - inventory.item.delete  → destroy
 */
final class ProductController
{
    private ProductService $service;
    private ?AuditLogger $audit;

    public function __construct(?ProductService $service = null, ?AuditLogger $audit = null)
    {
        $this->service = $service ?? Container::getInstance()->get(ProductService::class);
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * El logger se resuelve sin romper si el contenedor no está inicializado
     * (tests unitarios que inyectan el service directamente).
     */
    private function resolveAuditLogger(): ?AuditLogger
    {
        try {
            $container = Container::getInstance();

            return $container->has(AuditLogger::class) ? $container->get(AuditLogger::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    /**
     * GET /api/inventory/products
     * Lista productos con filtros y paginación.
     */
    public function index(array $request): array
    {
        $this->requireAuthenticated($request);

        $query = $request['query'] ?? [];
        $filters = [];

        if (isset($query['activo'])) {
            $filters['activo'] = filter_var($query['activo'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($query['unidad_medida'])) {
            $filters['unidad_medida'] = $query['unidad_medida'];
        }
        if (!empty($query['categoria'])) {
            $filters['categoria'] = $query['categoria'];
        }
        if (isset($query['es_serializable'])) {
            $filters['es_serializable'] = filter_var($query['es_serializable'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($query['es_loteable'])) {
            $filters['es_loteable'] = filter_var($query['es_loteable'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($query['search'])) {
            $filters['search'] = $query['search'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        $result = $this->service->findAll($filters, $page, $perPage);

        return $this->json(200, $result);
    }

    /**
     * GET /api/inventory/products/by-sku/{sku}
     * Busca un producto por SKU.
     */
    public function findBySku(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $sku = $params['sku'] ?? '';
        if ($sku === '') {
            throw (new HttpException('SKU requerido', 400))->withMessageKey('common.sku_required');
        }

        $product = $this->service->findBySku($sku);
        if ($product === null) {
            throw (new HttpException('Producto no encontrado', 404))->withMessageKey('common.product_not_found');
        }

        return $this->json(200, ['product' => $product]);
    }

    /**
     * GET /api/inventory/products/{id}
     * Detalle de un producto.
     */
    public function show(array $request, array $params): array
    {
        $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw (new HttpException('ID de producto requerido', 400))->withMessageKey('common.id_producto_requerido');
        }

        $product = $this->service->findById($id);
        if ($product === null) {
            throw (new HttpException('Producto no encontrado', 404))->withMessageKey('common.product_not_found');
        }

        return $this->json(200, ['product' => $product]);
    }

    // ====================================================================
    // ESCRITURA
    // ====================================================================

    /**
     * POST /api/inventory/products
     * Crea un nuevo producto.
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $data = $request['body'] ?? [];

        $product = $this->service->create($data);

        $this->auditEvent('product.create', 'CREATE', $request, $claims, [
            'entidad_id' => is_array($product) ? ($product['id'] ?? null) : null,
            'valores_nuevos' => $product,
        ]);

        return $this->json(201, ['product' => $product]);
    }

    /**
     * PATCH /api/inventory/products/{id}
     * Actualiza un producto existente.
     */
    public function update(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw (new HttpException('ID de producto requerido', 400))->withMessageKey('common.id_producto_requerido');
        }

        $before = $this->service->findById($id);
        $data = $request['body'] ?? [];

        $product = $this->service->update($id, $data);

        $this->auditEvent('product.update', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'valores_nuevos' => $product,
        ]);

        return $this->json(200, ['product' => $product]);
    }

    /**
     * DELETE /api/inventory/products/{id}
     * Eliminación lógica de un producto.
     */
    public function destroy(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw (new HttpException('ID de producto requerido', 400))->withMessageKey('common.id_producto_requerido');
        }

        $before = $this->service->findById($id);
        $this->service->delete($id);
        $after = $this->service->findIncludingDeleted($id);

        $this->auditEvent('product.delete', 'DELETE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_anteriores' => $before,
            'detalles_adicionales' => ['valores_despues' => $after],
        ]);

        return $this->json(200, ['message' => 'Producto eliminado correctamente']);
    }

    // ====================================================================
    // ACCIONES DE ESTADO
    // ====================================================================

    /**
     * POST /api/inventory/products/{id}/activate
     * Activa un producto.
     */
    public function activate(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw (new HttpException('ID de producto requerido', 400))->withMessageKey('common.id_producto_requerido');
        }

        $product = $this->service->activate($id);

        $this->auditEvent('product.activate', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_nuevos' => $product,
        ]);

        return $this->json(200, ['product' => $product]);
    }

    /**
     * POST /api/inventory/products/{id}/deactivate
     * Desactiva un producto.
     */
    public function deactivate(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);

        $id = $params['id'] ?? '';
        if ($id === '') {
            throw (new HttpException('ID de producto requerido', 400))->withMessageKey('common.id_producto_requerido');
        }

        $product = $this->service->deactivate($id);

        $this->auditEvent('product.deactivate', 'UPDATE', $request, $claims, [
            'entidad_id' => (string) $id,
            'valores_nuevos' => $product,
        ]);

        return $this->json(200, ['product' => $product]);
    }

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    /**
     * Escribe un registro de auditoría de dominio (spec §7.1).
     *
     * Sólo los éxitos: los fallos (401/403/404/409/422...) ya quedan
     * registrados con su causa por el hook del Router (AuditMiddleware).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $entry
     */
    private function auditEvent(
        string $operacion,
        string $tipoCambio,
        array $request,
        array $claims,
        array $entry = []
    ): void {
        if ($this->audit === null) {
            return;
        }

        $this->audit->record(array_merge([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'product',
            'tipo_cambio' => $tipoCambio,
            'resultado' => 'SUCCESS',
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
        ], $entry));
    }

    /**
     * Valida que el request tenga claims válidos de usuario autenticado.
     *
     * @throws HttpException 401 si no está autenticado
     */
    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw (new HttpException('No autorizado', 401))->withMessageKey('common.unauthorized');
        }

        return $claims;
    }

    private function json(int $status, array $data): array
    {
        return ['status' => $status, 'data' => $data];
    }
}
