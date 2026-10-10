<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\StockCalculationService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use PHPUnit\Framework\TestCase;

/**
 * R1 (v1.8.3): cantidad_disponible sólo cuenta unidades en estado DISPONIBLE.
 *
 *   DISPONIBLE  = física - reservada - en tránsito (>= 0)
 *   cualquier otro estado = 0
 */
final class StockCalculationServiceTest extends TestCase
{
    private StockCalculationService $service;

    protected function setUp(): void
    {
        $this->service = new StockCalculationService();
    }

    public function testDisponibleRestaReservadaYEnTransito(): void
    {
        $this->assertSame(70.0, $this->service->disponible(100.0, 25.0, 5.0, StockState::DISPONIBLE));
        $this->assertSame(0.0, $this->service->disponible(30.0, 30.0, 0.0, StockState::DISPONIBLE));
        $this->assertSame(10.5, $this->service->disponible(10.5, 0.0, 0.0, StockState::DISPONIBLE));
    }

    public function testDisponibleSinEstadoMantieneLaFormulaDeLaSpec(): void
    {
        $this->assertSame(70.0, $this->service->disponible(100.0, 25.0, 5.0));
        $this->assertSame(0.0, $this->service->disponible(30.0, 30.0, 0.0));
    }

    /**
     * @dataProvider estadosQueNoSumanDisponible
     */
    public function testDisponibleEsCeroParaCualquierEstadoDistintoDeDisponible(string $estado): void
    {
        $this->assertSame(
            0.0,
            $this->service->disponible(50.0, 0.0, 0.0, $estado),
            "El estado {$estado} no debe sumar disponible"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function estadosQueNoSumanDisponible(): array
    {
        $datos = [];

        foreach (StockState::VALID_VALUES as $estado) {
            if ($estado === StockState::DISPONIBLE) {
                continue;
            }
            $datos[$estado] = [$estado];
        }

        return $datos;
    }

    public function testDisponibleConEstadoInvalidoDevuelve400(): void
    {
        try {
            $this->service->disponible(10.0, 0.0, 0.0, 'EN_VUELO');
            $this->fail('Debía rechazar un estado inexistente');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('validation', $e->getCategory());
        }
    }

    public function testDisponibleNegativoDevuelve422(): void
    {
        try {
            $this->service->disponible(10.0, 8.0, 3.0, StockState::DISPONIBLE);
            $this->fail('El disponible no puede ser negativo');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('business_rule', $e->getCategory());
            $this->assertSame('cantidad_disponible', $e->getField());
        }
    }

    public function testCantidadesNegativasDeEntradaDevuelven400(): void
    {
        foreach ([[-1.0, 0.0, 0.0], [5.0, -1.0, 0.0], [5.0, 0.0, -1.0]] as [$f, $r, $t]) {
            try {
                $this->service->disponible($f, $r, $t);
                $this->fail("Debía rechazar ($f, $r, $t)");
            } catch (InventoryException $e) {
                $this->assertSame(400, $e->getStatusCode());
                $this->assertSame('validation', $e->getCategory());
            }
        }
    }

    public function testCrearLedgerInicializaCeroReservadoYTransito(): void
    {
        $ledger = $this->service->crearLedger(
            'ledger-1',
            'tenant-1',
            'unidad-1',
            42.5,
            StockState::DISPONIBLE
        );

        $this->assertSame(42.5, $ledger->cantidadFisica());
        $this->assertSame(0.0, $ledger->cantidadReservada());
        $this->assertSame(0.0, $ledger->cantidadEnTransito());
        $this->assertSame(42.5, $ledger->cantidadDisponible());
        $this->assertSame('DISPONIBLE', $ledger->estadoActual());
        $this->assertSame('tenant-1', $ledger->tenantId());
        $this->assertSame('unidad-1', $ledger->unidadFisicaId());
    }

    public function testCrearLedgerConEstadoInvalidoDevuelve400(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionCode(400);

        $this->service->crearLedger('ledger-2', 'tenant-1', 'unidad-1', 10.0, 'EN_VUELO');
    }

    public function testLedgerActualizaYRecalculaElDisponibleSegunElEstado(): void
    {
        $ledger = $this->service->crearLedger('ledger-3', 'tenant-1', 'unidad-1', 50.0, StockState::DISPONIBLE);

        // RESERVADO: no suma disponible, aunque la fórmula daría 20
        $ledger->actualizar([
            'cantidad_reservada' => 20.0,
            'cantidad_en_transito' => 10.0,
            'estado_actual' => StockState::RESERVADO,
        ]);

        $this->assertSame(0.0, $ledger->cantidadDisponible());
        $this->assertSame('RESERVADO', $ledger->estadoActual());

        // Vuelve a DISPONIBLE: manda la fórmula (50 - 20 - 10)
        $ledger->actualizar(['estado_actual' => StockState::DISPONIBLE]);
        $this->assertSame(20.0, $ledger->cantidadDisponible());

        try {
            $ledger->actualizar(['cantidad_reservada' => 40.0, 'cantidad_en_transito' => 15.0]);
            $this->fail('No se puede quedar el disponible en negativo');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * @dataProvider estadosQueNoSumanDisponible
     */
    public function testLedgerPoneElDisponibleACeroEnEstadosQueNoSuman(string $estado): void
    {
        $ledger = $this->service->crearLedger('ledger-' . $estado, 'tenant-1', 'unidad-1', 50.0, StockState::DISPONIBLE);

        $ledger->actualizar(['estado_actual' => $estado]);

        $this->assertSame(0.0, $ledger->cantidadDisponible(), "Ledger en {$estado} debe quedar a 0");
        $this->assertSame(50.0, $ledger->cantidadFisica(), 'La cantidad física no cambia');
        $this->assertSame($estado, $ledger->estadoActual());
    }

    public function testCrearLedgerInicializaElDisponibleACeroSiLaUnidadNoEstaDisponible(): void
    {
        $ledger = $this->service->crearLedger(
            'ledger-4',
            'tenant-1',
            'unidad-1',
            42.5,
            StockState::ENTREGADO
        );

        $this->assertSame(42.5, $ledger->cantidadFisica());
        $this->assertSame(0.0, $ledger->cantidadDisponible());
        $this->assertSame('ENTREGADO', $ledger->estadoActual());
    }
}
