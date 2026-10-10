<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Middleware que inyecta claims finales en la request (como harían Auth/Tenant),
 * para comprobar que el Router preserva la request por referencia hasta el hook.
 */
final class TestClaimsMiddleware
{
    public function handle(array $request): array
    {
        $request['user'] = [
            'user_id' => 'user-1',
            'tenant_id' => 'tenant-1',
            'email' => 'demo@acme.local',
        ];
        $request['tenant_id'] = 'tenant-1';

        return $request;
    }
}
