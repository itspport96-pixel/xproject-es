<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\LocationRepositoryInterface;
use App\Shared\Container\Container;

final class LocationService
{
    private LocationRepositoryInterface $repository;
    private TenantContext $tenantContext;
    private InputSanitizer $sanitizer;

    public function __construct(
        ?LocationRepositoryInterface $repository = null,
        ?TenantContext $tenantContext = null,
        ?InputSanitizer $sanitizer = null
    ) {
        $container = Container::getInstance();
        $this->repository = $repository ?? $container->get(LocationRepositoryInterface::class);
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

        if ($this->repository->existsByCodigo($clean['codigo'])) {
            throw InventoryException::locationCodeAlreadyExists($clean['codigo']);
        }

        $location = Location::create(
            id: $this->generateUuid(),
            tenantId: $tenantId,
            codigo: $clean['codigo'],
            tipo: $clean['tipo'],
            nombre: $clean['nombre'] ?? null,
            jerarquia: $clean['jerarquia'] ?? null,
            capacidad: $clean['capacidad'] ?? null,
            metadatos: $clean['metadatos'] ?? null
        );

        $this->repository->save($location);

        return $this->toPublicArray($location);
    }

    // ====================================================================
    // LECTURA
    // ====================================================================

    public function findById(string $id): ?array
    {
        $location = $this->repository->findById($id);
        return $location !== null ? $this->toPublicArray($location) : null;
    }

    public function findByCodigo(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return null;
        }
        $location = $this->repository->findByCodigo($codigo);
        return $location !== null ? $this->toPublicArray($location) : null;
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $cleanFilters = $this->sanitizeFilters($filters);
        $result = $this->repository->findAll($cleanFilters, $page, $perPage);

        $result['data'] = array_map(
            fn(Location $l) => $this->toPublicArray($l),
            $result['data']
        );

        return $result;
    }

    public function findActivas(): array
    {
        $locations = $this->repository->findActivas();
        return array_map(fn(Location $l) => $this->toPublicArray($l), $locations);
    }

    public function findByTipo(string $tipo): array
    {
        $tipo = strtoupper(trim($tipo));
        if ($tipo === '') {
            return [];
        }
        $locations = $this->repository->findByTipo($tipo);
        return array_map(fn(Location $l) => $this->toPublicArray($l), $locations);
    }

    public function findWithCapacityAvailable(): array
    {
        $locations = $this->repository->findWithCapacityAvailable();
        return array_map(fn(Location $l) => $this->toPublicArray($l), $locations);
    }

    public function findChildren(string $jerarquiaPadre): array
    {
        $jerarquiaPadre = trim($jerarquiaPadre);
        if ($jerarquiaPadre === '') {
            return [];
        }
        $locations = $this->repository->findChildren($jerarquiaPadre);
        return array_map(fn(Location $l) => $this->toPublicArray($l), $locations);
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
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        $clean = $this->sanitizeUpdateData($data);
        $this->validateUpdateData($clean);

        if (isset($clean['codigo']) && $clean['codigo'] !== $location->codigo()->value()) {
            if ($this->repository->existsByCodigo($clean['codigo'])) {
                throw InventoryException::locationCodeAlreadyExists($clean['codigo']);
            }
        }

        $location->update($clean);
        $this->repository->update($location);

        return $this->toPublicArray($location);
    }

    public function activate(string $id): array
    {
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        $location->activate();
        $this->repository->update($location);

        return $this->toPublicArray($location);
    }

    public function deactivate(string $id): array
    {
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        $location->deactivate();
        $this->repository->update($location);

        return $this->toPublicArray($location);
    }

    // ====================================================================
    // ELIMINACIÓN
    // ====================================================================

    public function delete(string $id): bool
    {
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        if ($this->repository->hasStockUnits($id)) {
            throw InventoryException::locationHasStockUnits($id);
        }

        if ($this->repository->hasMovements($id)) {
            throw InventoryException::locationHasMovements($id);
        }

        $this->repository->softDelete($id);
        return true;
    }

    /**
     * Fila cruda incluyendo el borrado lógico, para auditoría del delete.
     */
    public function findIncludingDeleted(string $id): ?array
    {
        return $this->repository->findRawIncludingDeleted($id);
    }

    // ====================================================================
    // CAPACIDAD
    // ====================================================================

    public function incrementarCapacidadUsada(string $id, int $cantidad = 1): array
    {
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        $location->incrementarCapacidadUsada($cantidad);
        $this->repository->update($location);

        return $this->toPublicArray($location);
    }

    public function decrementarCapacidadUsada(string $id, int $cantidad = 1): array
    {
        $location = $this->repository->findById($id);
        if ($location === null) {
            throw InventoryException::locationNotFound($id);
        }

        $location->decrementarCapacidadUsada($cantidad);
        $this->repository->update($location);

        return $this->toPublicArray($location);
    }

    // ====================================================================
    // SANITIZACIÓN Y VALIDACIÓN
    // ====================================================================

    private function sanitizeCreateData(array $data): array
    {
        return [
            'codigo'    => $this->sanitizer->sanitizeString((string) ($data['codigo'] ?? '')),
            'tipo'      => strtoupper(trim((string) ($data['tipo'] ?? ''))),
            'nombre'    => isset($data['nombre']) ? $this->sanitizer->sanitizeString((string) $data['nombre']) : null,
            'jerarquia' => isset($data['jerarquia']) ? $this->sanitizer->sanitizeString((string) $data['jerarquia']) : null,
            'capacidad' => $this->sanitizer->sanitizeInt($data['capacidad'] ?? null),
            'metadatos' => isset($data['metadatos']) && is_array($data['metadatos']) ? $data['metadatos'] : null,
        ];
    }

    private function sanitizeUpdateData(array $data): array
    {
        $clean = [];

        if (array_key_exists('codigo', $data)) {
            $clean['codigo'] = $this->sanitizer->sanitizeString((string) $data['codigo']);
        }
        if (array_key_exists('tipo', $data)) {
            $clean['tipo'] = strtoupper(trim((string) $data['tipo']));
        }
        if (array_key_exists('nombre', $data)) {
            $clean['nombre'] = $data['nombre'] !== null
                ? $this->sanitizer->sanitizeString((string) $data['nombre'])
                : null;
        }
        if (array_key_exists('jerarquia', $data)) {
            $clean['jerarquia'] = $data['jerarquia'] !== null
                ? $this->sanitizer->sanitizeString((string) $data['jerarquia'])
                : null;
        }
        if (array_key_exists('capacidad', $data)) {
            $clean['capacidad'] = $this->sanitizer->sanitizeInt($data['capacidad']);
        }
        if (array_key_exists('metadatos', $data)) {
            $clean['metadatos'] = is_array($data['metadatos']) ? $data['metadatos'] : null;
        }

        return $clean;
    }

    private function validateCreateData(array $data): void
    {
        if (empty($data['codigo'])) {
            throw InventoryException::requiredField('codigo');
        }
        if (empty($data['tipo'])) {
            throw InventoryException::requiredField('tipo');
        }
    }

    private function validateUpdateData(array $data): void
    {
        if (isset($data['codigo']) && $data['codigo'] === '') {
            throw InventoryException::requiredField('codigo');
        }
        if (isset($data['tipo']) && $data['tipo'] === '') {
            throw InventoryException::requiredField('tipo');
        }
    }

    private function sanitizeFilters(array $filters): array
    {
        $clean = [];

        if (!empty($filters['tipo'])) {
            $clean['tipo'] = strtoupper(trim($filters['tipo']));
        }
        if (isset($filters['activa'])) {
            $clean['activa'] = filter_var($filters['activa'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($filters['search'])) {
            $clean['search'] = $this->sanitizer->sanitizeSearch((string) $filters['search']);
        }
        if (!empty($filters['jerarquia'])) {
            $clean['jerarquia'] = trim($filters['jerarquia']);
        }

        return $clean;
    }

    // ====================================================================
    // SERIALIZACIÓN
    // ====================================================================

    private function toPublicArray(Location $location): array
    {
        $data = [
            'id'              => $location->id(),
            'codigo'          => $location->codigo()->value(),
            'nombre'          => $location->nombre(),
            'tipo'            => $location->tipo()->value(),
            'jerarquia'       => $location->jerarquia(),
            'capacidad'       => $location->capacidad(),
            'capacidad_usada' => $location->capacidadUsada(),
            'activa'          => $location->activa(),
            'metadatos'       => $location->metadatos(),
            'created_at'      => $location->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'      => $location->updatedAt()->format('Y-m-d H:i:s'),
        ];

        if ($location->capacidad() !== null) {
            $data['capacidad_restante'] = $location->capacityRemaining();
            $data['tiene_disponibilidad'] = $location->hasCapacityAvailable();
        }

        return $data;
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
