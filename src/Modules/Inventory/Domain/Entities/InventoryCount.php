<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Conteo físico de inventario (inventory_counts) - CU-011.
 *
 * Ciclo: PENDIENTE → EN_CURSO → COMPLETADO → RECONCILIADO.
 *
 *  - `lista_teorica` es la instantánea tomada al iniciar el conteo: unidades
 *    presentes en la ubicación (o en todo el tenant) con su cantidad
 *    registrada. Es inmutable: el conteo se compara SIEMPRE contra ella.
 *  - `resultados` guarda lo encontrado por unidad (cantidad_encontrada y
 *    notas), en llamadas parciales.
 *  - `discrepancias` se calcula al completar: cantidad esperada, encontrada
 *    y diferencia por unidad.
 *  - `ajustes_aplicados` registra quién autoriza cada acción (AJUSTAR o
 *    JUSTIFICAR) con su justificación.
 */
final class InventoryCount
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_EN_CURSO = 'EN_CURSO';
    public const ESTADO_COMPLETADO = 'COMPLETADO';
    public const ESTADO_RECONCILIADO = 'RECONCILIADO';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_EN_CURSO,
        self::ESTADO_COMPLETADO,
        self::ESTADO_RECONCILIADO,
    ];

    public const ACCION_AJUSTAR = 'AJUSTAR';
    public const ACCION_JUSTIFICAR = 'JUSTIFICAR';

    public const ACCIONES_VALIDAS = [
        self::ACCION_AJUSTAR,
        self::ACCION_JUSTIFICAR,
    ];

    /**
     * @param array<int, array<string, mixed>> $listaTeorica
     * @param array<int, array<string, mixed>> $resultados
     * @param array<int, array<string, mixed>> $discrepancias
     * @param array<int, array<string, mixed>> $ajustesAplicados
     */
    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly ?string $ubicacionId,
        private string $estado,
        private readonly ?string $usuarioResponsableId,
        private readonly DateTimeImmutable $fechaConteo,
        private array $listaTeorica,
        private array $resultados,
        private array $discrepancias,
        private bool $reconciliado,
        private array $ajustesAplicados,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $data Fila de `inventory_counts`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            tenantId: (string) $data['tenant_id'],
            ubicacionId: $data['ubicacion_id'] ?? null,
            estado: (string) ($data['estado'] ?? self::ESTADO_PENDIENTE),
            usuarioResponsableId: $data['usuario_responsable_id'] ?? null,
            fechaConteo: new DateTimeImmutable((string) ($data['fecha_conteo'] ?? 'now')),
            listaTeorica: self::decodeList($data['lista_teorica'] ?? null),
            resultados: self::decodeList($data['resultados'] ?? null),
            discrepancias: self::decodeList($data['discrepancias'] ?? null),
            reconciliado: (bool) ($data['reconciliado'] ?? false),
            ajustesAplicados: self::decodeList($data['ajustes_aplicados'] ?? null),
            createdAt: new DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            updatedAt: new DateTimeImmutable((string) ($data['updated_at'] ?? 'now'))
        );
    }

    /**
     * Crea un conteo PENDIENTE con su instantánea `lista_teorica`.
     *
     * @param array<int, array<string, mixed>> $listaTeorica
     */
    public static function crear(
        string $id,
        string $tenantId,
        ?string $ubicacionId,
        ?string $usuarioResponsableId,
        DateTimeImmutable $fechaConteo,
        array $listaTeorica
    ): self {
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }

        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            ubicacionId: $ubicacionId !== null ? trim($ubicacionId) : null,
            estado: self::ESTADO_PENDIENTE,
            usuarioResponsableId: $usuarioResponsableId !== null ? trim($usuarioResponsableId) : null,
            fechaConteo: $fechaConteo,
            listaTeorica: $listaTeorica,
            resultados: [],
            discrepancias: [],
            reconciliado: false,
            ajustesAplicados: [],
            createdAt: $now,
            updatedAt: $now
        );
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenantId === $tenantId;
    }

    // ====================================================================
    // CICLO DE VIDA
    // ====================================================================

    /**
     * Guarda (fusionando) los resultados parciales y pasa PENDIENTE → EN_CURSO.
     *
     * @param array<int, array<string, mixed>> $resultados Ya validados contra
     *        la lista teórica por el use case.
     */
    public function registrarResultados(array $resultados): void
    {
        $porUnidad = [];

        foreach ($this->resultados as $fila) {
            $porUnidad[(string) $fila['unidad_fisica_id']] = $fila;
        }

        foreach ($resultados as $fila) {
            $porUnidad[(string) $fila['unidad_fisica_id']] = $fila + ($porUnidad[(string) $fila['unidad_fisica_id']] ?? []);
        }

        $this->resultados = array_values($porUnidad);

        if ($this->estado === self::ESTADO_PENDIENTE) {
            $this->estado = self::ESTADO_EN_CURSO;
        }

        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Unidades de la lista teórica sin resultado registrado.
     *
     * @return array<int, string>
     */
    public function unidadesSinResultado(): array
    {
        $contadas = [];

        foreach ($this->resultados as $fila) {
            $contadas[(string) $fila['unidad_fisica_id']] = true;
        }

        $faltantes = [];

        foreach ($this->listaTeorica as $fila) {
            $unidadId = (string) $fila['unidad_fisica_id'];

            if (!isset($contadas[$unidadId])) {
                $faltantes[] = $unidadId;
            }
        }

        return $faltantes;
    }

    /**
     * Cierra el conteo con sus discrepancias (PENDIENTE/EN_CURSO → COMPLETADO).
     *
     * @param array<int, array<string, mixed>> $discrepancias
     */
    public function completar(array $discrepancias): void
    {
        if ($this->estado !== self::ESTADO_PENDIENTE && $this->estado !== self::ESTADO_EN_CURSO) {
            throw InventoryException::countInvalidState($this->id, $this->estado, self::ESTADO_EN_CURSO);
        }

        $this->discrepancias = $discrepancias;
        $this->estado = self::ESTADO_COMPLETADO;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Discrepancia (si aún no tiene acción registrada) de una unidad.
     *
     * @return array<string, mixed>|null
     */
    public function discrepanciaPendiente(string $unidadFisicaId): ?array
    {
        foreach ($this->discrepancias as $fila) {
            if ((string) $fila['unidad_fisica_id'] !== $unidadFisicaId) {
                continue;
            }

            foreach ($this->ajustesAplicados as $aplicada) {
                if ((string) $aplicada['unidad_fisica_id'] === $unidadFisicaId) {
                    return null;
                }
            }

            return $fila;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>> discrepancias sin acción
     */
    public function discrepanciasPendientes(): array
    {
        $pendientes = [];

        foreach ($this->discrepancias as $fila) {
            if ($this->discrepanciaPendiente((string) $fila['unidad_fisica_id']) !== null) {
                $pendientes[] = $fila;
            }
        }

        return $pendientes;
    }

    /**
     * Registra una acción aplicada a una discrepancia y, si ya no queda ninguna
     * pendiente, pasa el conteo a RECONCILIADO.
     *
     * @param array<string, mixed> $accion unidad_fisica_id, accion,
     *        justificacion, actor_autoriza, fecha y el resto del detalle
     */
    public function aplicarAccion(array $accion): void
    {
        if ($this->estado !== self::ESTADO_COMPLETADO && $this->estado !== self::ESTADO_RECONCILIADO) {
            throw InventoryException::countInvalidState($this->id, $this->estado, self::ESTADO_COMPLETADO);
        }

        $this->ajustesAplicados[] = $accion;

        if ($this->discrepanciasPendientes() === []) {
            $this->estado = self::ESTADO_RECONCILIADO;
            $this->reconciliado = true;
        }

        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array    {
        return [
            'conteo_id' => $this->id,
            'ubicacion_id' => $this->ubicacionId,
            'estado' => $this->estado,
            'usuario_responsable_id' => $this->usuarioResponsableId,
            'fecha_conteo' => $this->fechaConteo->format('Y-m-d H:i:s'),
            'unidades_lista' => count($this->listaTeorica),
            'unidades_contadas' => count($this->resultados),
            'discrepancias' => count($this->discrepancias),
            'discrepancias_pendientes' => count($this->discrepanciasPendientes()),
            'reconciliado' => $this->reconciliado,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Detalle completo (GET /counts/{id}).
     */
    public function toDetailArray(): array
    {
        return $this->toPublicArray() + [
            'lista_teorica' => $this->listaTeorica,
            'resultados' => $this->resultados,
            'detalle_discrepancias' => $this->discrepancias,
            'ajustes_aplicados' => $this->ajustesAplicados,
        ];
    }

    /**
     * @return array<string, mixed> Fila para `inventory_counts`
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'ubicacion_id' => $this->ubicacionId,
            'estado' => $this->estado,
            'usuario_responsable_id' => $this->usuarioResponsableId,
            'fecha_conteo' => $this->fechaConteo->format('Y-m-d H:i:s'),
            'lista_teorica' => json_encode($this->listaTeorica, JSON_UNESCAPED_UNICODE),
            'resultados' => json_encode($this->resultados, JSON_UNESCAPED_UNICODE),
            'discrepancias' => json_encode($this->discrepancias, JSON_UNESCAPED_UNICODE),
            'reconciliado' => $this->reconciliado ? 1 : 0,
            'ajustes_aplicados' => json_encode($this->ajustesAplicados, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // Getters
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function ubicacionId(): ?string { return $this->ubicacionId; }
    public function estado(): string { return $this->estado; }
    public function usuarioResponsableId(): ?string { return $this->usuarioResponsableId; }
    public function fechaConteo(): DateTimeImmutable { return $this->fechaConteo; }
    public function listaTeorica(): array { return $this->listaTeorica; }
    public function resultados(): array { return $this->resultados; }
    public function discrepancias(): array { return $this->discrepancias; }
    public function reconciliado(): bool { return $this->reconciliado; }
    public function ajustesAplicados(): array { return $this->ajustesAplicados; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function decodeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
