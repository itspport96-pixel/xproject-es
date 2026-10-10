<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;
use App\Shared\Services\RedisService;

/**
 * Revocación de sesiones del usuario (v1.11.0).
 *
 * Cambiar o resetear la contraseña invalida todas las familias de refresh
 * tokens del usuario (refresh_tokens.revoked=1) y planta la denylist
 * Redis session:act:{family}=0, de modo que los access tokens emitidos
 * dejen de ser válidos en la siguiente petición.
 */
final class SessionRevokerService
{
    private DatabaseService $db;
    private RedisService $redis;

    public function __construct(?DatabaseService $db = null, ?RedisService $redis = null)
    {
        $container = Container::getInstance();
        $this->db = $db ?? $container->get('database');
        $this->redis = $redis ?? $container->get(RedisService::class);
    }

    /**
     * Revoca todas las sesiones activas del usuario.
     *
     * @param string      $userId     Usuario afectado
     * @param string|null $keepFamily Familia que se conserva (la sesión actual
     *                                en un cambio de contraseña propio); null
     *                                revoca todas
     * @param string      $reason     Motivo grabado en revoked_reason
     *
     * @return int Número de familias revocadas
     */
    public function revokeAllForUser(string $userId, ?string $keepFamily = null, string $reason = 'password_change'): int
    {
        $sql = "SELECT DISTINCT family_id FROM refresh_tokens
                WHERE user_id = :uid AND revoked = 0";
        $params = ['uid' => $userId];

        if ($keepFamily !== null && $keepFamily !== '') {
            $sql .= " AND family_id <> :keep";
            $params['keep'] = $keepFamily;
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $families = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'family_id');

        if ($families === []) {
            return 0;
        }

        $updateSql = "UPDATE refresh_tokens
                      SET revoked = 1, revoked_reason = :reason, updated_at = NOW()
                      WHERE user_id = :uid AND revoked = 0";
        $updateParams = ['reason' => $reason, 'uid' => $userId];

        if ($keepFamily !== null && $keepFamily !== '') {
            $updateSql .= " AND family_id <> :keep";
            $updateParams['keep'] = $keepFamily;
        }

        $this->db->getConnection()->prepare($updateSql)->execute($updateParams);

        // Denylist Redis: la ventana de actividad de cada familia queda en 0,
        // igual que un logout, para que el AuthenticationMiddleware rechace
        // los access tokens aún no vencidos.
        $ttl = (int) ($_ENV['JWT_EXPIRATION'] ?? 3600);
        if ($ttl > 0) {
            foreach ($families as $familyId) {
                $this->redis->set('session:act:' . $familyId, 0, $ttl);
            }
        }

        return count($families);
    }
}
