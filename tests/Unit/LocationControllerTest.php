<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Modules\Inventory\Application\Services\LocationService;
use App\Modules\Inventory\Domain\Entities\Location;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\LocationController;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryLocationRepository;

/**
 * HTTP de ubicaciones: guardia de claims, envoltorios {status,data},
 * parseo de query string y propagación de errores de negocio.
 */
final class LocationControllerTest extends TestCase
{
    private const TENANT = 'tenant-1';

    private InMemoryLocationRepository $repository;
    private LocationController $controller;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->repository = new InMemoryLocationRepository(self::TENANT);

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        $service = new LocationService($this->repository, $tenantContext, new InputSanitizer());
        $this->controller = new LocationController($service);
    }

    protected function tearDown(): void
    {
        Container::resetInstance();
    }

    // ==================================================================
    // LECTURA
    // ==================================================================

    public function testIndexRejectsRequestWithoutClaims(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->controller->index([]);
    }

    public function testIndexReturnsEnvelopeWithPagination(): void
    {
        $this->seedLocation(['codigo' => 'A-01']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'B-01', 'activa' => 0]);

        $result = $this->controller->index($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['data']['pagination']['total']);
        $this->assertSame(['A-01', 'B-01'], array_column($result['data']['data'], 'codigo'));
    }

    public function testIndexMapsQueryFiltersAndPagination(): void
    {
        $this->seedLocation(['codigo' => 'A-01', 'tipo' => 'ALMACENAMIENTO', 'jerarquia' => 'RAIZ/1']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'B-01', 'tipo' => 'PICKING', 'jerarquia' => 'OTRA/1']);
        $this->seedLocation(['id' => 'loc-3', 'codigo' => 'C-01', 'tipo' => 'ALMACENAMIENTO', 'activa' => 0, 'jerarquia' => 'RAIZ/2']);

        $result = $this->controller->index($this->request([
            'query' => [
                'tipo' => 'almacenamiento',
                'activa' => 'true',
                'jerarquia' => 'RAIZ/',
                'per_page' => '1',
            ],
        ]));

        $this->assertSame(1, $result['data']['pagination']['total']);
        $this->assertSame(1, $result['data']['pagination']['per_page']);
        $this->assertSame(['A-01'], array_column($result['data']['data'], 'codigo'));
    }

    public function testFindByCodigoRejectsMissingCodigo(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->findByCodigo($this->request(), []);
    }

    public function testFindByCodigoReturnsNotFoundForUnknownCodigo(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller->findByCodigo($this->request(), ['codigo' => 'NO-EXISTE']);
    }

    public function testFindByCodigoReturnsLocationEnvelope(): void
    {
        $this->seedLocation(['codigo' => 'A-01']);

        $result = $this->controller->findByCodigo($this->request(), ['codigo' => 'a-01']);

        $this->assertSame(200, $result['status']);
        $this->assertSame('A-01', $result['data']['location']['codigo']);
    }

    public function testWithCapacityReturnsOnlyAvailableLocations(): void
    {
        $this->seedLocation(['codigo' => 'DISP-1', 'capacidad' => 10, 'capacidad_usada' => 1]);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'LLENA-1', 'capacidad' => 1, 'capacidad_usada' => 1]);

        $result = $this->controller->withCapacity($this->request());

        $this->assertSame(200, $result['status']);
        $this->assertSame(['DISP-1'], array_column($result['data']['locations'], 'codigo'));
    }

    public function testChildrenRejectsMissingJerarquia(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->children($this->request(), []);
    }

    public function testChildrenReturnsHierarchyMatches(): void
    {
        $this->seedLocation(['codigo' => 'H-1', 'jerarquia' => 'RAIZ/1']);
        $this->seedLocation(['id' => 'loc-2', 'codigo' => 'H-2', 'jerarquia' => 'OTRA/1']);

        $result = $this->controller->children($this->request(), ['jerarquia' => 'RAIZ/']);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['H-1'], array_column($result['data']['locations'], 'codigo'));
    }

    public function testShowRejectsMissingId(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->controller->show($this->request(), []);
    }

    public function testShowReturnsNotFoundForUnknownId(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller->show($this->request(), ['id' => 'no-existe']);
    }

    // ==================================================================
    // ESCRITURA
    // ==================================================================

    public function testStoreCreatesLocationWithCreatedStatus(): void
    {
        $result = $this->controller->store($this->request([
            'body' => [
                'codigo' => 'NUEVA-1',
                'tipo' => 'PICKING',
                'nombre' => 'Puesto nuevo',
                'capacidad' => 5,
            ],
        ]));

        $this->assertSame(201, $result['status']);
        $this->assertSame('NUEVA-1', $result['data']['location']['codigo']);
        $this->assertSame(1, $this->repository->findAll()['pagination']['total']);
    }

    public function testStorePropagatesBusinessValidation(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->controller->store($this->request(['body' => ['codigo' => '', 'tipo' => 'PICKING']]));
    }

    public function testUpdateReturnsUpdatedLocation(): void
    {
        $id = $this->seedLocation();

        $result = $this->controller->update($this->request(['body' => ['nombre' => 'Renombrada']]), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('Renombrada', $result['data']['location']['nombre']);
    }

    public function testUpdatePropagatesNotFound(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(404);

        $this->controller->update($this->request(['body' => ['nombre' => 'X']]), ['id' => 'no-existe']);
    }

    public function testDestroyPropagatesConflictWhenLocationHasMovements(): void
    {
        $id = $this->seedLocation();
        $this->repository->withMovements($id);

        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(409);

        $this->controller->destroy($this->request(), ['id' => $id]);
    }

    public function testDestroySoftDeletesLocationAndHidesIt(): void
    {
        $id = $this->seedLocation();

        $result = $this->controller->destroy($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('message', $result['data']);
        $this->assertTrue($this->repository->isSoftDeleted($id));

        try {
            $this->controller->show($this->request(), ['id' => $id]);
            $this->fail('La ubicación eliminada no debe ser visible');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    // ==================================================================
    // ACCIONES DE ESTADO
    // ==================================================================

    public function testActivateAndDeactivateReturnUpdatedLocation(): void
    {
        $id = $this->seedLocation();

        $desactivada = $this->controller->deactivate($this->request(), ['id' => $id]);
        $this->assertFalse($desactivada['data']['location']['activa']);

        $activada = $this->controller->activate($this->request(), ['id' => $id]);
        $this->assertTrue($activada['data']['location']['activa']);
    }

    public function testStateActionsRejectMissingId(): void
    {
        foreach (['activate', 'deactivate', 'restore'] as $accion) {
            try {
                $this->controller->{$accion}($this->request(), []);
                $this->fail("{$accion} debió exigir id");
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
    }

    public function testRestoreBringsDeletedLocationBack(): void
    {
        $id = $this->seedLocation(['codigo' => 'LIFE-1']);
        $this->controller->destroy($this->request(), ['id' => $id]);
        $this->assertTrue($this->repository->isSoftDeleted($id));

        $result = $this->controller->restore($this->request(), ['id' => $id]);

        $this->assertSame(200, $result['status']);
        $this->assertTrue(
            $this->controller->show($this->request(), ['id' => $id])['data']['location']['activa']
        );
    }

    // ==================================================================
    // HELPERS
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'user' => ['user_id' => 'u-1', 'tenant_id' => self::TENANT],
        ], $overrides);
    }

    private function seedLocation(array $overrides = []): string
    {
        $location = Location::fromArray(array_merge([
            'id' => 'loc-1',
            'tenant_id' => self::TENANT,
            'codigo' => 'LOC-001',
            'tipo' => 'ALMACENAMIENTO',
            'nombre' => 'Estanteria 1',
            'jerarquia' => 'RAIZ/1',
            'capacidad' => 10,
            'capacidad_usada' => 0,
            'activa' => 1,
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ], $overrides));

        $this->repository->seed($location);

        return $location->id();
    }
}
