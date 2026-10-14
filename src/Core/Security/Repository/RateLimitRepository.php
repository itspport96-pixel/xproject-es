<?php
declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Service\EncryptionService;
use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use PDO;

/**
 * RateLimitRepository - Acceso a las tablas de rate limiting (spec §6.3).
 *
 * - `rate_limit_config`: overrides de límites por endpoint (tenant específico
 *   o global cuando tenant_id es NULL). Si no hay fila, se usan los límites
 *   de código (.env + actions).
 * - `rate_limit_log`: auditoría de rechazos (429) — qué endpoint, quién,
 *   cuántas requests, límite aplicado, acción tomada y bloqueo.
 */
final class RateLimitRepository
{
    private PDO $pdo;
    private ?EncryptionService $encryption;

    public function __construct(DatabaseService $db, ?EncryptionService $encryption = null)
    {
        $this->pdo = $db->getConnection();
        if ($encryption !== null) {
            $this->encryption = $encryption;
        } else {
            try {
                $container = Container::getInstance();
                $this->encryption = $container->has(EncryptionService::class) ? $container->get(EncryptionService::class) : null;
            } catch (\Throwable) {
                $this->encryption = null;
            }
        }
    }

    /**
     * Override activo para un endpoint. Prefiere la fila del tenant sobre la
     * global (tenant_id NULL). Null si no hay configuración que aplicar.
     */
    public function findActiveOverride(string $endpoint, ?string $tenantId): ?array
    {
        $sql = "SELECT endpoint, max_requests, tiempo_ventana, tipo_limit
                FROM rate_limit_config
                WHERE endpoint = :endpoint
                  AND activo = 1
                  AND (tenant_id = :tenant_id OR tenant_id IS NULL)
                ORDER BY (tenant_id IS NULL) ASC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['endpoint' => $endpoint, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Registra un evento de rechazo por rate limiting.
     * Nunca debe propagar excepciones (no romper la respuesta 429).
     */
    public function logExceeded(
        ?string $tenantId,
        ?string $userId,
        string $ipAddress,
        string $endpoint,
        int $requestsContados,
        int $limite,
        string $accionTomada = 'REJECT',
        ?int $duracionBloqueo = null
    ): void {
        $sql = "INSERT INTO rate_limit_log
                    (id, tenant_id, usuario_id, ip_address, endpoint,
                     requests_contados, limite, accion_tomada, duracion_bloqueo)
                VALUES
                    (:id, :tenant_id, :usuario_id, :ip_address, :endpoint,
                     :requests_contados, :limite, :accion_tomada, :duracion_bloqueo)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $this->generateUuid(),
            'tenant_id' => $tenantId,
            'usuario_id' => $userId,
            'ip_address' => $this->encryption?->encrypt($ipAddress) ?? $ipAddress,
            'endpoint' => $endpoint,
            'requests_contados' => $requestsContados,
            'limite' => $limite,
            'accion_tomada' => $accionTomada,
            'duracion_bloqueo' => $duracionBloqueo,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
