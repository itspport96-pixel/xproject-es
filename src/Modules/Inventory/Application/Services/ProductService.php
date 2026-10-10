<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Product;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\ProductRepositoryInterface;
use App\Shared\Container\Container;

final class ProductService
{
    private ProductRepositoryInterface $repository;
    private TenantContext $tenantContext;
    private InputSanitizer $sanitizer;

    public function __construct(
        ?ProductRepositoryInterface $repository = null,
        ?TenantContext $tenantContext = null,
        ?InputSanitizer $sanitizer = null
    ) {
        $container = Container::getInstance();
        $this->repository = $repository ?? $container->get(ProductRepositoryInterface::class);
        $this->tenantContext = $tenantContext ?? $container->get(TenantContext::class);
        $this->sanitizer = $sanitizer ?? $container->get(InputSanitizer::class);
    }

    // ====================================================================
    // CREACIÓN
    // ====================================================================

    public function create(array $data): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $clean = $this->sanitizeCreateData($data);
        $this->validateCreateData($clean);

        if ($this->repository->existsBySku($clean['sku'])) {
            throw InventoryException::skuAlreadyExists($clean['sku']);
        }

        $product = Product::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            sku: $clean['sku'],
            nombre: $clean['nombre'],
            unidadMedida: $clean['unidad_medida'],
            descripcion: $clean['descripcion'] ?? null,
            categoria: $clean['categoria'] ?? null,
            peso: $clean['peso'] ?? null,
            volumen: $clean['volumen'] ?? null,
            dimensiones: $clean['dimensiones'] ?? null,
            metadatos: $clean['metadatos'] ?? null
        );

        $this->repository->save($product);

        return $this->toPublicArray($product);
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    public function findById(string $id): ?array
    {
        $product = $this->repository->findById($id);
        return $product !== null ? $this->toPublicArray($product) : null;
    }

    public function findBySku(string $sku): ?array
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') {
            return null;
        }
        $product = $this->repository->findBySku($sku);
        return $product !== null ? $this->toPublicArray($product) : null;
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $cleanFilters = $this->sanitizeFilters($filters);
        $result = $this->repository->findAll($cleanFilters, $page, $perPage);

        $result['data'] = array_map(
            fn(Product $p) => $this->toPublicArray($p),
            $result['data']
        );

        return $result;
    }

    public function findActivos(): array
    {
        $products = $this->repository->findActivos();
        return array_map(fn(Product $p) => $this->toPublicArray($p), $products);
    }

    public function findByCategoria(string $categoria): array
    {
        $categoria = trim($categoria);
        if ($categoria === '') {
            return [];
        }
        $products = $this->repository->findByCategoria($categoria);
        return array_map(fn(Product $p) => $this->toPublicArray($p), $products);
    }

    public function findByUnidadMedida(string $unidadMedida): array
    {
        $unidadMedida = strtoupper(trim($unidadMedida));
        if ($unidadMedida === '') {
            return [];
        }
        $products = $this->repository->findByUnidadMedida($unidadMedida);
        return array_map(fn(Product $p) => $this->toPublicArray($p), $products);
    }

    public function count(array $filters = []): int
    {
        return $this->repository->count($this->sanitizeFilters($filters));
    }

    // ====================================================================
    // ACTUALIZACIÓN
    // ====================================================================

    public function update(string $id, array $data): array
    {
        $product = $this->repository->findById($id);
        if ($product === null) {
            throw InventoryException::productNotFound($id);
        }

        $clean = $this->sanitizeUpdateData($data);
        $this->validateUpdateData($clean);

        if (isset($clean['sku']) && $clean['sku'] !== $product->sku()->value()) {
            if ($this->repository->existsBySku($clean['sku'])) {
                throw InventoryException::skuAlreadyExists($clean['sku']);
            }
        }

        $product->update($clean);
        $this->repository->update($product);

        return $this->toPublicArray($product);
    }

    public function activate(string $id): array
    {
        $product = $this->repository->findById($id);
        if ($product === null) {
            throw InventoryException::productNotFound($id);
        }

        $product->activate();
        $this->repository->update($product);

        return $this->toPublicArray($product);
    }

    public function deactivate(string $id): array
    {
        $product = $this->repository->findById($id);
        if ($product === null) {
            throw InventoryException::productNotFound($id);
        }

        $product->deactivate();
        $this->repository->update($product);

        return $this->toPublicArray($product);
    }

    // ====================================================================
    // ELIMINACIÓN
    // ====================================================================

    public function delete(string $id): bool
    {
        $product = $this->repository->findById($id);
        if ($product === null) {
            throw InventoryException::productNotFound($id);
        }

        if ($this->repository->hasStockUnits($id)) {
            throw InventoryException::productHasStockUnits($id);
        }

        $this->repository->softDelete($id);
        return true;
    }

    public function restore(string $id): bool
    {
        if (!$this->repository->existsById($id)) {
            throw InventoryException::productNotFound($id);
        }

        $this->repository->restore($id);
        return true;
    }

    // ====================================================================
    // SANITIZACIÓN Y VALIDACIÓN
    // ====================================================================

    private function sanitizeCreateData(array $data): array
    {
        return [
            'sku'           => $this->sanitizer->sanitizeString((string) ($data['sku'] ?? '')),
            'nombre'        => $this->sanitizer->sanitizeString((string) ($data['nombre'] ?? '')),
            'descripcion'   => isset($data['descripcion']) ? $this->sanitizer->sanitizeString((string) $data['descripcion']) : null,
            'categoria'     => isset($data['categoria']) ? $this->sanitizer->sanitizeString((string) $data['categoria']) : null,
            'unidad_medida' => strtoupper(trim((string) ($data['unidad_medida'] ?? ''))),
            'peso'          => $this->sanitizer->sanitizeFloat($data['peso'] ?? null),
            'volumen'       => $this->sanitizer->sanitizeFloat($data['volumen'] ?? null),
            'dimensiones'   => isset($data['dimensiones']) ? $this->sanitizer->sanitizeString((string) $data['dimensiones']) : null,
            'metadatos'     => isset($data['metadatos']) && is_array($data['metadatos']) ? $data['metadatos'] : null,
        ];
    }

    private function sanitizeUpdateData(array $data): array
    {
        $clean = [];

        if (array_key_exists('sku', $data)) {
            $clean['sku'] = $this->sanitizer->sanitizeString((string) $data['sku']);
        }
        if (array_key_exists('nombre', $data)) {
            $clean['nombre'] = $this->sanitizer->sanitizeString((string) $data['nombre']);
        }
        if (array_key_exists('descripcion', $data)) {
            $clean['descripcion'] = $data['descripcion'] !== null
                ? $this->sanitizer->sanitizeString((string) $data['descripcion'])
                : null;
        }
        if (array_key_exists('categoria', $data)) {
            $clean['categoria'] = $data['categoria'] !== null
                ? $this->sanitizer->sanitizeString((string) $data['categoria'])
                : null;
        }
        if (array_key_exists('unidad_medida', $data)) {
            $clean['unidad_medida'] = strtoupper(trim((string) $data['unidad_medida']));
        }
        if (array_key_exists('peso', $data)) {
            $clean['peso'] = $this->sanitizer->sanitizeFloat($data['peso']);
        }
        if (array_key_exists('volumen', $data)) {
            $clean['volumen'] = $this->sanitizer->sanitizeFloat($data['volumen']);
        }
        if (array_key_exists('dimensiones', $data)) {
            $clean['dimensiones'] = $data['dimensiones'] !== null
                ? $this->sanitizer->sanitizeString((string) $data['dimensiones'])
                : null;
        }
        if (array_key_exists('metadatos', $data)) {
            $clean['metadatos'] = is_array($data['metadatos']) ? $data['metadatos'] : null;
        }

        return $clean;
    }

    private function validateCreateData(array $data): void
    {
        if (empty($data['sku'])) {
            throw InventoryException::requiredField('sku');
        }
        if (empty($data['nombre'])) {
            throw InventoryException::requiredField('nombre');
        }
        if (empty($data['unidad_medida'])) {
            throw InventoryException::requiredField('unidad_medida');
        }
    }

    private function validateUpdateData(array $data): void
    {
        if (isset($data['sku']) && $data['sku'] === '') {
            throw InventoryException::requiredField('sku');
        }
        if (isset($data['nombre']) && $data['nombre'] === '') {
            throw InventoryException::requiredField('nombre');
        }
    }

    private function sanitizeFilters(array $filters): array
    {
        $clean = [];

        if (isset($filters['activo'])) {
            $clean['activo'] = filter_var($filters['activo'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($filters['unidad_medida'])) {
            $clean['unidad_medida'] = strtoupper(trim($filters['unidad_medida']));
        }
        if (!empty($filters['categoria'])) {
            $clean['categoria'] = trim($filters['categoria']);
        }
        if (isset($filters['es_serializable'])) {
            $clean['es_serializable'] = filter_var($filters['es_serializable'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($filters['es_loteable'])) {
            $clean['es_loteable'] = filter_var($filters['es_loteable'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($filters['search'])) {
            $clean['search'] = $this->sanitizer->sanitizeSearch((string) $filters['search']);
        }

        return $clean;
    }

    // ====================================================================
    // SERIALIZACIÓN
    // ====================================================================

    private function toPublicArray(Product $product): array
    {
        return [
            'id'            => $product->id(),
            'sku'           => $product->sku()->value(),
            'nombre'        => $product->nombre(),
            'descripcion'   => $product->descripcion(),
            'categoria'     => $product->categoria(),
            'unidad_medida' => $product->unidadMedida()->value(),
            'peso'          => $product->peso(),
            'volumen'       => $product->volumen(),
            'dimensiones'   => $product->dimensiones(),
            'activo'        => $product->activo(),
            'metadatos'     => $product->metadatos(),
            'created_at'    => $product->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'    => $product->updatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
