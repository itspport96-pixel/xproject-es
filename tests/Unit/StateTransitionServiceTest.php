<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Application\Services\StateTransitionService;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 6.1: máquina de estados de la unidad física (spec §4).
 *
 * La matriz §4 es la única fuente de verdad: las transiciones listadas se
 * aceptan y CUALQUIERA otra (incluida X → X) se rechaza con 422 y
 * `field: estado`.
 */
final class StateTransitionServiceTest extends TestCase
{
    private StateTransitionService $states;

    protected function setUp(): void
    {
        $this->states = new StateTransitionService();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function transicionesValidas(): array
    {
        return [
            [StockState::DISPONIBLE, StockState::RESERVADO],
            [StockState::DISPONIBLE, StockState::EN_PREPARACION],
            [StockState::DISPONIBLE, StockState::DANADO],
            [StockState::DISPONIBLE, StockState::BLOQUEADO],
            // DESVIACIÓN v1.9.1 (aprobada): spec §4 dice NO, pero CU-010 y
            // CU-011 marcan PERDIDO desde DISPONIBLE.
            [StockState::DISPONIBLE, StockState::PERDIDO],
            [StockState::RESERVADO, StockState::DISPONIBLE],
            [StockState::RESERVADO, StockState::EN_PREPARACION],
            [StockState::RESERVADO, StockState::BLOQUEADO],
            [StockState::EN_PREPARACION, StockState::CARGADO],
            [StockState::CARGADO, StockState::EN_TRANSITO],
            [StockState::EN_TRANSITO, StockState::ENTREGADO],
            [StockState::EN_TRANSITO, StockState::DEVUELTO],
            [StockState::ENTREGADO, StockState::DEVUELTO],
            [StockState::DEVUELTO, StockState::DISPONIBLE],
            [StockState::DEVUELTO, StockState::PERDIDO],
            [StockState::DANADO, StockState::BLOQUEADO],
            [StockState::BLOQUEADO, StockState::DISPONIBLE],
            [StockState::BLOQUEADO, StockState::PERDIDO],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function transicionesInvalidas(): array
    {
        return [
            [StockState::DISPONIBLE, StockState::EN_TRANSITO],
            [StockState::DISPONIBLE, StockState::CARGADO],
            [StockState::RESERVADO, StockState::DANADO],
            [StockState::RESERVADO, StockState::PERDIDO],
            [StockState::RESERVADO, StockState::EN_TRANSITO],
            [StockState::EN_PREPARACION, StockState::DISPONIBLE],
            [StockState::EN_PREPARACION, StockState::RESERVADO],
            [StockState::EN_PREPARACION, StockState::PERDIDO],
            [StockState::CARGADO, StockState::DISPONIBLE],
            [StockState::CARGADO, StockState::PERDIDO],
            [StockState::EN_TRANSITO, StockState::DISPONIBLE],
            [StockState::EN_TRANSITO, StockState::PERDIDO],
            [StockState::ENTREGADO, StockState::DISPONIBLE],
            [StockState::ENTREGADO, StockState::PERDIDO],
            [StockState::DEVUELTO, StockState::RESERVADO],
            [StockState::DANADO, StockState::DISPONIBLE],
            [StockState::BLOQUEADO, StockState::RESERVADO],
            [StockState::PERDIDO, StockState::DISPONIBLE],
            [StockState::PERDIDO, StockState::BLOQUEADO],
        ];
    }

    #[DataProvider('transicionesValidas')]
    public function testTransicionesValidasDeLaMatrizSeAceptan(string $from, string $to): void
    {
        $this->assertTrue($this->states->canTransition($from, $to), "{$from} → {$to} debe ser válida");

        try {
            $this->states->assertCanTransition($from, $to);
            $this->addToAssertionCount(1);
        } catch (InventoryException $e) {
            $this->fail("assertCanTransition rechazó una transición válida: {$from} → {$to}");
        }

        $this->assertContains($to, $this->states->allowedTargets($from));
    }

    #[DataProvider('transicionesInvalidas')]
    public function testTransicionesInvalidasSeRechazanCon422(string $from, string $to): void
    {
        $this->assertFalse($this->states->canTransition($from, $to), "{$from} → {$to} no debe ser válida");

        try {
            $this->states->assertCanTransition($from, $to);
            $this->fail("Se esperaba 422 para {$from} → {$to}");
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(InventoryException::CATEGORY_BUSINESS, $e->getCategory());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString($from, $e->getMessage());
            $this->assertStringContainsString($to, $e->getMessage());
        }
    }

    public function testLaMismaTransicionNoEsValida(): void
    {
        $this->assertFalse($this->states->canTransition(StockState::DISPONIBLE, StockState::DISPONIBLE));
        $this->assertFalse($this->states->canTransition(StockState::RESERVADO, StockState::RESERVADO));
        $this->assertFalse($this->states->canTransition(StockState::PERDIDO, StockState::PERDIDO));
    }

    public function testPerdidoEsEstadoTerminalSinDestinos(): void
    {
        // PERDIDO es terminal: sin destinos posibles y sin forma de salir.
        $this->assertSame([], $this->states->allowedTargets(StockState::PERDIDO));
        $this->assertSame([], StateTransitionService::TRANSITIONS[StockState::PERDIDO]);

        // Sólo puede llegar a PERDIDO desde estados "de pérdida" (matriz §4)
        // más la desviación aprobada v1.9.1 DISPONIBLE → PERDIDO.
        $this->assertContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::DEVUELTO));
        $this->assertContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::DANADO));
        $this->assertContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::BLOQUEADO));
        $this->assertContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::DISPONIBLE));

        // El resto de orígenes NO contemplados en la spec siguen rechazados.
        $this->assertNotContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::RESERVADO));
        $this->assertNotContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::EN_PREPARACION));
        $this->assertNotContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::CARGADO));
        $this->assertNotContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::EN_TRANSITO));
        $this->assertNotContains(StockState::PERDIDO, $this->states->allowedTargets(StockState::ENTREGADO));
    }

    public function testEstadosDesconocidosDevuelven400(): void
    {
        try {
            $this->states->canTransition('NO_EXISTE', StockState::DISPONIBLE);
            $this->fail('Un estado desconocido debe rechazarse');
        } catch (InventoryException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
        }
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    public static function estadosMovibles(): array
    {
        return [
            [StockState::DISPONIBLE, true],
            [StockState::RESERVADO, true],
            [StockState::EN_PREPARACION, true],
            [StockState::CARGADO, true],
            [StockState::DEVUELTO, true],
            [StockState::DANADO, true],
            [StockState::BLOQUEADO, true],
            [StockState::EN_TRANSITO, false],
            [StockState::ENTREGADO, false],
            [StockState::PERDIDO, false],
        ];
    }

    #[DataProvider('estadosMovibles')]
    public function testMovimientoInternoSegunEstado(string $estado, bool $esperado): void
    {
        $this->assertSame($esperado, $this->states->canMove($estado));
    }

    public function testAssertCanMoveDevuelve422ConElIdDeLaUnidad(): void
    {
        try {
            $this->states->assertCanMove('unidad-1', StockState::EN_TRANSITO);
            $this->fail('Unidad EN_TRANSITO no debe poder moverse');
        } catch (InventoryException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('estado', $e->getField());
            $this->assertStringContainsString('unidad-1', $e->getMessage());
            $this->assertStringContainsString(StockState::EN_TRANSITO, $e->getMessage());
        }
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    public static function estadosReservables(): array
    {
        return [
            [StockState::DISPONIBLE, true],
            [StockState::RESERVADO, false],
            [StockState::DEVUELTO, false],
            [StockState::EN_PREPARACION, false],
            [StockState::EN_TRANSITO, false],
            [StockState::PERDIDO, false],
        ];
    }

    #[DataProvider('estadosReservables')]
    public function testReservarSoloDesdeEstadosPermitidos(string $estado, bool $esperado): void
    {
        $this->assertSame($esperado, $this->states->canReserve($estado));
    }
}
