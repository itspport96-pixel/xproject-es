<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Registro del DI Container de Inventory.
 *
 * Regresión v1.6.2: `ReservationQueryService` llegó a registrarse con un
 * `::class` sin el `use` correspondiente, con lo que el binding quedaba en
 * el namespace del propio provider y `Container::get()` fallaba en vivo
 * (GET /api/inventory/reservations devolvía 500). Este test compara las
 * FQCN registradas con las esperadas.
 */
final class InventoryServiceProviderTest extends TestCase
{
    private const EXPECTED = [
        'App\Modules\Inventory\Application\Services\ReservationQueryService',
        'App\Modules\Inventory\Application\Services\DashboardService',
        'App\Modules\Inventory\Application\Services\StockQueryService',
        'App\Modules\Inventory\Application\Services\ReserveStockUseCase',
        'App\Modules\Inventory\Application\Services\ReleaseReservationUseCase',
        'App\Modules\Inventory\Application\Services\ReceiveInventoryUseCase',
        'App\Modules\Inventory\Domain\Repositories\ReservationRepositoryInterface',
        'App\Shared\Services\IdempotencyService',
        // Sprint 7 (v1.7.0): picking y transporte
        'App\Modules\Inventory\Application\Services\PickingQueryService',
        'App\Modules\Inventory\Application\Services\ShipmentQueryService',
        'App\Modules\Inventory\Application\Services\StartPickingUseCase',
        'App\Modules\Inventory\Application\Services\CompletePickingUseCase',
        'App\Modules\Inventory\Application\Services\VerifyPickingUseCase',
        'App\Modules\Inventory\Application\Services\LoadShipmentUseCase',
        'App\Modules\Inventory\Application\Services\AuthorizeShipmentUseCase',
        'App\Modules\Inventory\Domain\Repositories\PickingOperationRepositoryInterface',
        'App\Modules\Inventory\Domain\Repositories\ShipmentRepositoryInterface',
        'App\Modules\Inventory\Domain\Repositories\ShipmentItemRepositoryInterface',
        // Mini-sprint v1.7.1: ubicación virtual TRANSITO por tenant (A1)
        'App\Modules\Inventory\Application\Services\TransitLocationService',
    ];

    /**
     * @return array<int, string>
     */
    private function registeredKeys(Container $container): array
    {
        $prop = new \ReflectionProperty(Container::class, 'bindings');
        $prop->setAccessible(true);

        return array_keys($prop->getValue($container));
    }

    public function testRegistraLosServiciosBajoSuFqcnEsperada(): void
    {
        $container = new Container();
        (new InventoryServiceProvider())->register($container);

        $keys = $this->registeredKeys($container);

        foreach (self::EXPECTED as $fqcn) {
            $this->assertContains($fqcn, $keys, "Falta el binding {$fqcn}");
        }
    }

    public function testNoHayBindingsEnElNamespaceDelProvider(): void
    {
        $container = new Container();
        (new InventoryServiceProvider())->register($container);

        foreach ($this->registeredKeys($container) as $key) {
            $this->assertStringNotContainsString(
                'Providers\\ReservationQueryService',
                $key,
                'Binding resuelto en el namespace del provider: ' . $key
            );
            $this->assertStringNotContainsString('Providers\\DashboardService', $key);
            $this->assertStringNotContainsString('Providers\\StartPickingUseCase', $key);
            $this->assertStringNotContainsString('Providers\\LoadShipmentUseCase', $key);
            $this->assertStringNotContainsString('Providers\\PickingQueryService', $key);
        }
    }
}
