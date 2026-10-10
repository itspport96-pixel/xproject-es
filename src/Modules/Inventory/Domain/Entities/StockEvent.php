<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Entities;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use DateTimeImmutable;

/**
 * Entidad: Evento de Stock (stock_events) - spec §3.9
 *
 * Bitácora append-only e inmutable de cada transición: no expone setters de
 * negocio; sólo se crea. Cada fila lleva `hash_integridad` = SHA256 de los
 * campos canónicos del evento, de modo que cualquier alteración posterior se
 * puede detectar (verificación en tests y auditoría).
 *
 * Evento de recepción (CU-001): RECIBIDO.
 */
final class StockEvent
{
    public const TIPO_RECIBIDO = 'RECIBIDO';

    // Nombres de evento del ciclo de vida picking/transporte (CU-004..CU-006).
    // 'PREPARADO' y 'DESPACHADO' se conservan por compatibilidad con eventos
    // históricos; los nuevos flujos escriben EN_PREPARACION / CARGADO /
    // EN_TRANSITO.
    public const TIPO_EN_PREPARACION = 'EN_PREPARACION';
    public const TIPO_CARGADO = 'CARGADO';
    public const TIPO_EN_TRANSITO = 'EN_TRANSITO';

    // División de una unidad física en dos (v1.8.2): se escribe UN evento
    // por cada mitad (madre e hija) con su propio hash de integridad.
    public const TIPO_DIVIDIDO = 'DIVIDIDO';

    // Ciclo de vida de entrega/devolución (CU-007..CU-009, Sprint 8).
    public const TIPO_ENTREGADO = 'ENTREGADO';
    public const TIPO_DEVUELTO = 'DEVUELTO';
    public const TIPO_DISPONIBLE = 'DISPONIBLE';
    public const TIPO_DANADO = 'DAÑADO';
    public const TIPO_PERDIDO = 'PERDIDO';

    // Ajuste de cantidad por reconciliación de un conteo físico (CU-011,
    // v1.9.0). Nombre de la spec: AJUSTE_CANTIDAD (la BD admite cualquier
    // `tipo_evento`, es varchar(50)).
    public const TIPO_AJUSTE_CANTIDAD = 'AJUSTE_CANTIDAD';

    // Bloqueo/desbloqueo manual de una unidad (v1.10.0, E4). No cambian la
    // ubicación ni el cupo: sólo el estado.
    public const TIPO_BLOQUEADO = 'BLOQUEADO';
    public const TIPO_DESBLOQUEADO = 'DESBLOQUEADO';

    public const TIPOS_VALIDOS = [
        self::TIPO_RECIBIDO,
        'RESERVADO',
        'LIBERADO',
        self::TIPO_EN_PREPARACION,
        'PREPARADO',
        self::TIPO_CARGADO,
        'DESPACHADO',
        self::TIPO_EN_TRANSITO,
        self::TIPO_DIVIDIDO,
        self::TIPO_ENTREGADO,
        self::TIPO_DEVUELTO,
        self::TIPO_DISPONIBLE,
        self::TIPO_DANADO,
        self::TIPO_PERDIDO,
        self::TIPO_AJUSTE_CANTIDAD,
        'AJUSTE_POSITIVO',
        'AJUSTE_NEGATIVO',
        'INCIDENCIA',
        'MOVIDO',
        self::TIPO_BLOQUEADO,
        self::TIPO_DESBLOQUEADO,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $tenantId,
        private readonly string $unidadFisicaId,
        private readonly string $tipoEvento,
        private readonly ?string $descripcion,
        private readonly array $datosOperacion,
        private readonly ?float $cantidadAnterior,
        private readonly ?float $cantidadPosterior,
        private readonly ?string $estadoAnterior,
        private readonly ?string $estadoPosterior,
        private readonly DateTimeImmutable $createdAt,
        private readonly string $hashIntegridad
    ) {
    }

    public static function fromArray(array $data): self
    {
        $datos = $data['datos_operacion'] ?? null;
        if (is_string($datos)) {
            $datos = json_decode($datos, true);
        }

        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            unidadFisicaId: $data['unidad_fisica_id'],
            tipoEvento: (string) $data['tipo_evento'],
            descripcion: $data['descripcion'] ?? null,
            datosOperacion: is_array($datos) ? $datos : [],
            cantidadAnterior: isset($data['cantidad_anterior']) && $data['cantidad_anterior'] !== null
                ? (float) $data['cantidad_anterior']
                : null,
            cantidadPosterior: isset($data['cantidad_posterior']) && $data['cantidad_posterior'] !== null
                ? (float) $data['cantidad_posterior']
                : null,
            estadoAnterior: $data['estado_anterior'] ?? null,
            estadoPosterior: $data['estado_posterior'] ?? null,
            createdAt: new DateTimeImmutable($data['created_at']),
            hashIntegridad: (string) ($data['hash_integridad'] ?? '')
        );
    }

    public static function create(
        string $id,
        string $tenantId,
        string $unidadFisicaId,
        string $tipoEvento,
        ?string $descripcion = null,
        array $datosOperacion = [],
        ?float $cantidadAnterior = null,
        ?float $cantidadPosterior = null,
        ?string $estadoAnterior = null,
        ?string $estadoPosterior = null,
        ?DateTimeImmutable $createdAt = null
    ): self {
        if (trim($tenantId) === '') {
            throw InventoryException::requiredField('tenant_id');
        }
        if (trim($unidadFisicaId) === '') {
            throw InventoryException::requiredField('unidad_fisica_id');
        }
        if (!in_array($tipoEvento, self::TIPOS_VALIDOS, true)) {
            throw InventoryException::invalidField('tipo_evento', "Tipo de evento desconocido: '{$tipoEvento}'.");
        }

        $fecha = $createdAt ?? new DateTimeImmutable();

        $hash = self::computeHash([
            'id'                 => $id,
            'tenant_id'          => $tenantId,
            'unidad_fisica_id'   => $unidadFisicaId,
            'tipo_evento'        => $tipoEvento,
            'descripcion'        => $descripcion,
            'datos_operacion'    => $datosOperacion,
            'cantidad_anterior'  => $cantidadAnterior,
            'cantidad_posterior' => $cantidadPosterior,
            'estado_anterior'    => $estadoAnterior,
            'estado_posterior'   => $estadoPosterior,
            'created_at'         => $fecha->format('Y-m-d H:i:s'),
        ]);

        return new self(
            id: $id,
            tenantId: $tenantId,
            unidadFisicaId: $unidadFisicaId,
            tipoEvento: $tipoEvento,
            descripcion: $descripcion,
            datosOperacion: $datosOperacion,
            cantidadAnterior: $cantidadAnterior,
            cantidadPosterior: $cantidadPosterior,
            estadoAnterior: $estadoAnterior,
            estadoPosterior: $estadoPosterior,
            createdAt: $fecha,
            hashIntegridad: $hash
        );
    }

    /**
     * SHA256 canónico de los campos del evento. Público y estático para que
     * auditoría/tests puedan recalcular y comparar con `hash_integridad`.
     *
     * @param array<string, mixed> $campos
     */
    public static function computeHash(array $campos): string
    {
        return hash('sha256', self::canonicalize($campos));
    }

    /**
     * ¿El hash guardado sigue siendo válido? (detección de alteración)
     */
    public function esIntegro(): bool
    {
        return hash_equals(
            $this->hashIntegridad,
            self::computeHash([
                'id'                 => $this->id,
                'tenant_id'          => $this->tenantId,
                'unidad_fisica_id'   => $this->unidadFisicaId,
                'tipo_evento'        => $this->tipoEvento,
                'descripcion'        => $this->descripcion,
                'datos_operacion'    => $this->datosOperacion,
                'cantidad_anterior'  => $this->cantidadAnterior,
                'cantidad_posterior' => $this->cantidadPosterior,
                'estado_anterior'    => $this->estadoAnterior,
                'estado_posterior'   => $this->estadoPosterior,
                'created_at'         => $this->createdAt->format('Y-m-d H:i:s'),
            ])
        );
    }

    // Getters (sólo lectura: no hay métodos de mutación)
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function unidadFisicaId(): string { return $this->unidadFisicaId; }
    public function tipoEvento(): string { return $this->tipoEvento; }
    public function descripcion(): ?string { return $this->descripcion; }
    public function datosOperacion(): array { return $this->datosOperacion; }
    public function cantidadAnterior(): ?float { return $this->cantidadAnterior; }
    public function cantidadPosterior(): ?float { return $this->cantidadPosterior; }
    public function estadoAnterior(): ?string { return $this->estadoAnterior; }
    public function estadoPosterior(): ?string { return $this->estadoPosterior; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function hashIntegridad(): string { return $this->hashIntegridad; }

    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'tenant_id'          => $this->tenantId,
            'unidad_fisica_id'   => $this->unidadFisicaId,
            'tipo_evento'        => $this->tipoEvento,
            'descripcion'        => $this->descripcion,
            'datos_operacion'    => $this->datosOperacion !== []
                ? json_encode($this->datosOperacion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'cantidad_anterior'  => $this->cantidadAnterior,
            'cantidad_posterior' => $this->cantidadPosterior,
            'estado_anterior'    => $this->estadoAnterior,
            'estado_posterior'   => $this->estadoPosterior,
            'created_at'         => $this->createdAt->format('Y-m-d H:i:s'),
            'hash_integridad'    => $this->hashIntegridad,
        ];
    }

    // ====================================================================
    // CANONICALIZACIÓN (misma estrategia que IdempotencyService::requestHash)
    // ====================================================================

    private static function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(
                    static fn(mixed $item): string => self::canonicalize($item),
                    $value
                )) . ']';
            }

            ksort($value);

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (json_encode((string) $key, JSON_UNESCAPED_UNICODE) ?: '""')
                    . ':' . self::canonicalize($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
        }

        return (string) json_encode($value);
    }
}
