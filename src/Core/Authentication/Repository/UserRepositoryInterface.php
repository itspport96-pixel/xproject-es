<?php

declare(strict_types=1);

namespace App\Core\Authentication\Repository;

interface UserRepositoryInterface
{
    public function findByEmail(string $email, string $tenantId): ?array;

    public function findById(string $id, string $tenantId): ?array;

    public function updateLastLogin(string $userId): bool;

    public function getUserRoles(string $userId, string $tenantId): array;

    public function getUserPermissions(string $userId, string $tenantId): array;

    public function isActive(string $userId, string $tenantId): bool;

    /**
     * Estado de autenticación del usuario en una sola lectura (v1.11.0):
     * estado + marcadores de cambio de contraseña (temporal y caducidad).
     *
     * @return array{estado: string, password_change_forced: ?string, fecha_expiracion_password: ?string}|null
     *         null si el usuario no existe o está eliminado
     */
    public function getAuthState(string $userId, string $tenantId): ?array;
}
