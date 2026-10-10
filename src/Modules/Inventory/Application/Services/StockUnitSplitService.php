<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Entities\StockEvent;
use App\Modules\Inventory\Domain\Entities\StockLedger;
use App\Modules\Inventory\Domain\Entities\StockUnit;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Domain\Repositories\StockEventRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockLedgerRepositoryInterface;
use App\Modules\Inventory\Domain\Repositories\StockUnitRepositoryInterface;
use App\Modules\Inventory\Domain\ValueObjects\StockState;
use App\Shared\Services\DatabaseService;
use Throwable;

/**
 * División de una unidad física en dos (v1.8.2).
 *
 * Reglas:
 *  - Sólo divide si la cantidad pedida es MENOR que la de la unidad
 *    (>= se rechaza con 422 `unitQuantityMismatch`).
 *  - "madre": la fila ORIGINAL conserva id y barcode y queda con la resta.
 *  - "hija": fila NUEVA con barcode propio único en el tenant (mismo
 *    producto/lote/serie/ubicación por defecto) y la cantidad pedida.
 *  - La capacidad usada de la ubicación NO cambia (la presencia física no
 *    varía): si el caller cambia ubicaciones, la capacidad es su problema.
 *  - Transaccional: si ya hay transacción abierta se reutiliza; si no, la
 *    abre y la cierra él (DatabaseService::transaction NO es anidable).
 *  - Se escribe UN evento DIVIDIDO por cada mitad, con `hash_integridad`,
 *    ledger de ambas y los valores de negocio en `datos_operacion`.
 *
 * No escribe `audit_logs`: en flujos HTTP lo hace AuditMiddleware y en CLI
 * (bin/fix-unit-limbo.php) se registra tras confirmar la transacción, porque
 * AuditLogger abriría una transacción anidada.
 */
final class StockUnitSplitService
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly StockUnitRepositoryInterface $stockUnits,
        private readonly StockLedgerRepositoryInterface $ledgers,
        private readonly StockEventRepositoryInterface $events,
        private readonly StockCalculationService $calculation,
        private readonly StateTransitionService $states,
        private readonly BarcodeService $barcodeService,
    ) {
    }

    /**
     * Divide $unidad en dos.
     *
     * @param float               $cantidadHija  cantidad de la fila nueva (> 0 y < unidad)
     * @param string              $estadoHija    estado en que nace la hija (p. ej. DISPONIBLE)
     * @param string|null         $ubicacionHija ubicación de la hija (por defecto: la de la madre)
     * @param string|null         $estadoMadre   estado de la madre tras dividir (por defecto: se mantiene)
     * @param string|null         $ubicacionMadre ubicación de la madre (por defecto: la de la unidad)
     * @param array<string, mixed> $datosOperacion contexto extra para los dos eventos
     *
     * @return array{
     *     madre: StockUnit, hija: StockUnit,
     *     evento_madre: StockEvent, evento_hija: StockEvent,
     *     ledger_madre: \App\Modules\Inventory\Domain\Entities\StockLedger,
     *     ledger_hija: \App\Modules\Inventory\Domain\Entities\StockLedger
     * }
     *
     * @throws InventoryException 400/422 si la división no es posible
     */
    public function dividir(
        StockUnit $unidad,
        float $cantidadHija,
        string $estadoHija,
        ?string $ubicacionHija = null,
        ?string $estadoMadre = null,
        ?string $ubicacionMadre = null,
        string $descripcion = 'Division de unidad fisica (v1.8.2)',
        array $datosOperacion = []
    ): array {
        if (!is_finite($cantidadHija) || $cantidadHija <= 0) {
            throw InventoryException::invalidQuantity('cantidad', 'La cantidad de la unidad nueva debe ser mayor que cero.');
        }
        if ($cantidadHija >= $unidad->cantidad()) {
            throw InventoryException::unitQuantityMismatch($unidad->id(), $unidad->cantidad(), $cantidadHija);
        }

        $estadoHija = StockState::fromString($estadoHija)->value();
        $estadoMadreFinal = StockState::fromString($estadoMadre ?? $unidad->estado()->value())->value();

        // La madre sólo puede cambiar de estado si la transición está en la
        // matriz (la hija es una fila nueva: nace ya en su estado).
        if ($estadoMadreFinal !== $unidad->estado()->value()) {
            $this->states->assertCanTransition($unidad->estado()->value(), $estadoMadreFinal);
        }

        $cantidadMadre = $unidad->cantidad() - $cantidadHija;
        $cantidadAnteriorMadre = $unidad->cantidad();
        $estadoAnteriorMadre = $unidad->estado()->value();
        $ubicacionMadreFinal = $ubicacionMadre ?? $unidad->ubicacionId();
        $ubicacionHijaFinal = $ubicacionHija ?? $unidad->ubicacionId();

        $pdo = $this->db->getConnection();
        $propia = !$pdo->inTransaction();

        if ($propia) {
            $pdo->beginTransaction();
        }

        try {
            $resultado = $this->dividirInterno(
                $unidad,
                $cantidadHija,
                $cantidadMadre,
                $cantidadAnteriorMadre,
                $estadoHija,
                $estadoMadreFinal,
                $estadoAnteriorMadre,
                $ubicacionHijaFinal,
                $ubicacionMadreFinal,
                $descripcion,
                $datosOperacion
            );

            if ($propia) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $resultado;
    }

    /**
     * @param array<string, mixed> $datosOperacion
     * @return array{
     *     madre: StockUnit, hija: StockUnit,
     *     evento_madre: StockEvent, evento_hija: StockEvent,
     *     ledger_madre: \App\Modules\Inventory\Domain\Entities\StockLedger,
     *     ledger_hija: \App\Modules\Inventory\Domain\Entities\StockLedger
     * }
     */
    private function dividirInterno(
        StockUnit $unidad,
        float $cantidadHija,
        float $cantidadMadre,
        float $cantidadAnteriorMadre,
        string $estadoHija,
        string $estadoMadre,
        string $estadoAnteriorMadre,
        ?string $ubicacionHija,
        ?string $ubicacionMadre,
        string $descripcion,
        array $datosOperacion
    ): array {
        $tenantId = $unidad->tenantId();

        // 1. Ledger ANTES (para datos_operacion)
        $ledgerAntes = $this->ledgers->findByUnidadFisica($unidad->id());
        $antes = $ledgerAntes?->valoresOperacion() ?? [];

        // 2. Madre: cantidad, estado y ubicación
        $unidad->actualizar([
            'cantidad'    => $cantidadMadre,
            'estado'      => $estadoMadre,
            'ubicacion_id' => $ubicacionMadre,
        ]);
        $this->stockUnits->update($unidad);

        // 3. Hija: fila nueva con barcode propio
        $hija = StockUnit::create(
            id: uuid(),
            tenantId: $tenantId,
            productoId: $unidad->productoId(),
            codigoBarcode: $this->generateUniqueBarcode(),
            cantidad: $cantidadHija,
            propietarioId: $unidad->propietarioId(),
            custodioId: $unidad->custodioId(),
            numeroSerie: $unidad->numeroSerie(),
            lote: $unidad->lote(),
            peso: $unidad->peso(),
            volumen: $unidad->volumen(),
            estado: $estadoHija,
            ubicacionId: $ubicacionHija
        );
        $this->stockUnits->save($hija);

        // 4. Ledger de la madre: conserva como máximo su nueva cantidad en
        //    reservada/en tránsito (si no, disponible quedaría negativo); la
        //    hija nace a cero en ambas. Los totales siguen cuadrando.
        if ($ledgerAntes === null) {
            $ledgerMadre = $this->calculation->crearLedger(
                uuid(),
                $tenantId,
                $unidad->id(),
                $cantidadMadre,
                $estadoMadre
            );
            $this->ledgers->save($ledgerMadre);
        } else {
            $ledgerMadre = $ledgerAntes;
            $ledgerMadre->actualizar([
                'cantidad_fisica'      => $cantidadMadre,
                'cantidad_reservada'   => min($ledgerMadre->cantidadReservada(), $cantidadMadre),
                'cantidad_en_transito' => min($ledgerMadre->cantidadEnTransito(), $cantidadMadre),
                'estado_actual'        => $estadoMadre,
            ]);
            $this->ledgers->update($ledgerMadre);
        }

        // 5. Ledger de la hija
        $ledgerHija = $this->calculation->crearLedger(
            uuid(),
            $tenantId,
            $hija->id(),
            $cantidadHija,
            $estadoHija
        );
        $this->ledgers->save($ledgerHija);

        // 6. Eventos DIVIDIDO (uno por mitad, con hash de integridad)
        $comun = $datosOperacion + [
            'unidad_madre_id' => $unidad->id(),
            'unidad_hija_id'  => $hija->id(),
            'cantidad_madre'  => $cantidadMadre,
            'cantidad_hija'   => $cantidadHija,
        ];

        $eventoMadre = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $unidad->id(),
            tipoEvento: StockEvent::TIPO_DIVIDIDO,
            descripcion: $descripcion,
            datosOperacion: StockLedger::contextoOperacion($antes, $ledgerMadre->valoresOperacion()) + $comun,
            cantidadAnterior: $cantidadAnteriorMadre,
            cantidadPosterior: $cantidadMadre,
            estadoAnterior: $estadoAnteriorMadre,
            estadoPosterior: $estadoMadre
        );
        $this->events->save($eventoMadre);

        $eventoHija = StockEvent::create(
            id: uuid(),
            tenantId: $tenantId,
            unidadFisicaId: $hija->id(),
            tipoEvento: StockEvent::TIPO_DIVIDIDO,
            descripcion: $descripcion,
            datosOperacion: StockLedger::contextoOperacion([], $ledgerHija->valoresOperacion()) + $comun,
            cantidadAnterior: 0.0,
            cantidadPosterior: $cantidadHija,
            estadoAnterior: null,
            estadoPosterior: $estadoHija
        );
        $this->events->save($eventoHija);

        return [
            'madre'         => $unidad,
            'hija'          => $hija,
            'evento_madre'  => $eventoMadre,
            'evento_hija'   => $eventoHija,
            'ledger_madre'  => $ledgerMadre,
            'ledger_hija'   => $ledgerHija,
        ];
    }

    private function generateUniqueBarcode(): string
    {
        for ($attempt = 1; $attempt <= BarcodeService::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->barcodeService->generate();

            if (!$this->stockUnits->existsByBarcode($candidate)) {
                return $candidate;
            }
        }

        throw InventoryException::barcodeGenerationFailed();
    }
}
