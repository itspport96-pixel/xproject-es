<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ReceiveInventoryUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: Recepción de inventario (CU-001).
 *
 * Endpoints:
 *  - POST /api/inventory/receive → ejecuta la recepción transaccional
 *
 * Permisos: inventory.receiving.create (aplicado en routes)
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO.
 * Con la misma key y el mismo cuerpo se devuelve la respuesta original
 * (HTTP 201 de la primera ejecución) sin repetir la operación; con la misma
 * key y un cuerpo distinto → 409.
 */
final class ReceivingController
{
    use ReturnsUseCaseResult;

    private const MAX_KEY_LENGTH = 64;

    private ReceiveInventoryUseCase $useCase;
    private ?AuditLogger $audit;

    public function __construct(?ReceiveInventoryUseCase $useCase = null, ?AuditLogger $audit = null)
    {
        $this->useCase = $useCase ?? Container::getInstance()->get(ReceiveInventoryUseCase::class);
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

    /**
     * El logger se resuelve sin romper si el contenedor no está inicializado
     * (tests unitarios que inyectan el use case directamente).
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

    /**
     * POST /api/inventory/receive
     */
    public function receive(array $request): array
    {
        $claims = $this->requireAuthenticated($request);

        $key = $this->idempotencyKey($request);

        $response = $this->useCase->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditReceiving($request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * Auditoría de dominio de la recepción (CU-001, spec §7.1).
     *
     * Sólo en éxito: los errores (400/404/409/422) ya los registra el hook
     * del Router (AuditMiddleware) con su causa.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $response
     */
    private function auditReceiving(array $request, array $claims, string $key, array $response): void
    {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => 'receiving.create',
            'modulo' => 'inventory',
            'recurso' => 'receiving',
            'entidad_id' => is_string($data['unidad_fisica_id'] ?? null) ? $data['unidad_fisica_id'] : null,
            'tipo_cambio' => 'CREATE',
            'resultado' => 'SUCCESS',
            'valores_nuevos' => $data,
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_id' => $request['request_id'] ?? null,
            'detalles_adicionales' => [
                'idempotency_key' => $key,
                'codigo_respuesta' => $response['status'] ?? null,
            ],
        ]);
    }

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        return $claims;
    }

    /**
     * Extrae y valida el header Idempotency-Key (case-insensitive).
     *
     * @throws InventoryException 400 si falta o es inválido
     */
    private function idempotencyKey(array $request): string
    {
        $headers = $request['headers'] ?? [];
        $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];

        $key = $headers['idempotency-key'] ?? null;
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            throw InventoryException::idempotencyKeyRequired();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InventoryException::idempotencyKeyInvalid(
                'Máximo ' . self::MAX_KEY_LENGTH . ' caracteres.'
            );
        }

        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw InventoryException::idempotencyKeyInvalid(
                'Sólo se permiten letras, números y los caracteres . _ : -.'
            );
        }

        return $key;
    }
}
