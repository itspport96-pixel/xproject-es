<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Core\Audit\AuditLogger;
use App\Modules\Inventory\Application\Services\ReceiveAdhocUseCase;
use App\Modules\Inventory\Application\Services\ConfirmAdhocUseCase;
use App\Modules\Inventory\Application\Services\RejectAdhocUseCase;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Inventory\Http\Concerns\ReturnsUseCaseResult;
use App\Shared\Container\Container;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: Recepción adhoc (BLOQUE R3, v1.13.30).
 *
 * Endpoints:
 *  - POST /api/inventory/receiving/adhoc              → store  (registra, unidades BLOQUEADO)
 *  - POST /api/inventory/receiving/adhoc/{id}/confirm → confirm (BLOQUEADO → DISPONIBLE)
 *  - POST /api/inventory/receiving/adhoc/{id}/reject  → reject  (queda BLOQUEADO + incidencia ALTA)
 *
 * Permisos (aplicados en routes):
 *  - inventory.receiving.adhoc   → store
 *  - inventory.receiving.confirm → confirm y reject (separación de funciones)
 *
 * Idempotencia (spec R7): el header `Idempotency-Key` es OBLIGATORIO en los
 * 3 endpoints. Misma clave + mismo cuerpo → replay de la respuesta original;
 * misma clave + cuerpo distinto → 409.
 */
final class ReceivingAdhocController
{
    use ReturnsUseCaseResult;

    private const MAX_KEY_LENGTH = 64;

    private ReceiveAdhocUseCase $receiveAdhoc;
    private ConfirmAdhocUseCase $confirmAdhoc;
    private RejectAdhocUseCase $rejectAdhoc;
    private ?AuditLogger $audit;

    public function __construct(
        ?ReceiveAdhocUseCase $receiveAdhoc = null,
        ?ConfirmAdhocUseCase $confirmAdhoc = null,
        ?RejectAdhocUseCase $rejectAdhoc = null,
        ?AuditLogger $audit = null
    ) {
        $container = Container::getInstance();
        $this->receiveAdhoc = $receiveAdhoc ?? $container->get(ReceiveAdhocUseCase::class);
        $this->confirmAdhoc = $confirmAdhoc ?? $container->get(ConfirmAdhocUseCase::class);
        $this->rejectAdhoc = $rejectAdhoc ?? $container->get(RejectAdhocUseCase::class);
        $this->audit = $audit ?? $this->resolveAuditLogger();
    }

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
     * POST /api/inventory/receiving/adhoc
     */
    public function store(array $request): array
    {
        $claims = $this->requireAuthenticated($request);
        $key = $this->idempotencyKey($request);

        $response = $this->receiveAdhoc->execute(
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditEvent('receiving.adhoc.create', 'CREATE', $request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/receiving/adhoc/{id}/confirm
     */
    public function confirm(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $key = $this->idempotencyKey($request);
        $adhocId = $this->requireAdhocId($params);

        $response = $this->confirmAdhoc->execute(
            $adhocId,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditEvent('receiving.adhoc.confirm', 'UPDATE', $request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    /**
     * POST /api/inventory/receiving/adhoc/{id}/reject
     */
    public function reject(array $request, array $params): array
    {
        $claims = $this->requireAuthenticated($request);
        $key = $this->idempotencyKey($request);
        $adhocId = $this->requireAdhocId($params);

        $response = $this->rejectAdhoc->execute(
            $adhocId,
            $request['body'] ?? [],
            $key,
            (string) $claims['user_id']
        );

        if (!$this->isReplay($response)) {
            $this->auditEvent('receiving.adhoc.reject', 'UPDATE', $request, $claims, $key, $response);
        }

        return $this->useCaseResponse($response);
    }

    // ====================================================================
    // HELPERS PRIVADOS
    // ====================================================================

    /**
     * Auditoría de dominio (spec §7.1). Sólo en éxito (errores ya los
     * registra el AuditMiddleware del Router).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $response
     */
    private function auditEvent(
        string $operacion,
        string $tipoCambio,
        array $request,
        array $claims,
        string $key,
        array $response
    ): void {
        if ($this->audit === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $this->audit->record([
            'tenant_id' => $claims['tenant_id'] ?? null,
            'usuario_id' => $claims['user_id'] ?? null,
            'operacion' => $operacion,
            'modulo' => 'inventory',
            'recurso' => 'receiving_adhoc',
            'entidad_id' => is_string($data['adhoc_id'] ?? null) ? $data['adhoc_id'] : null,
            'tipo_cambio' => $tipoCambio,
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

    private function requireAuthenticated(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        return $claims;
    }

    private function requireAdhocId(array $params): string
    {
        $id = trim((string) ($params['id'] ?? ''));

        if ($id === '') {
            throw InventoryException::requiredField('id');
        }

        return $id;
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
