<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Shared\Exceptions\HttpException;

/**
 * Controlador HTTP: Recepción de inventario (CU-001) — ENDPOINT CERRADO.
 *
 * Endpoints:
 *  - POST /api/inventory/receive → 422 receivingWithoutDocumentNotAllowed
 *
 * Permisos: inventory.receiving.create (aplicado en routes)
 *
 * v1.13.32: no hay recepción sin documento planificado. El endpoint sigue
 * montado (dependencias históricas de e2e y tests antiguos) pero siempre
 * responde 422. Una entrega imprevista se planifica primero y se recibe
 * contra el documento (POST /receiving/documents/{id}/receive).
 */
final class ReceivingController
{
    /**
     * POST /api/inventory/receive — CERRADO (v1.13.32).
     *
     * No hay recepción sin documento planificado: el endpoint responde 422
     * `receivingWithoutDocumentNotAllowed`. Se conserva montado por
     * dependencias históricas (e2e y tests antiguos); una entrega imprevista
     * se planifica primero y se recibe contra el documento
     * (POST /receiving/documents/{id}/receive).
     */
    public function receive(array $request): array
    {
        $claims = $request['user'] ?? null;

        if (!is_array($claims) || empty($claims['user_id']) || empty($claims['tenant_id'])) {
            throw new HttpException('No autorizado', 401);
        }

        throw InventoryException::receivingWithoutDocumentNotAllowed();
    }
}
