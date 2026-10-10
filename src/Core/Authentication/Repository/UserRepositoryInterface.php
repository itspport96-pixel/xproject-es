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
     * estado + marcador de cambio de contraseña obligatorio.
     *
     * @return array{estado: string, fecha_expiracion_password: ?string}|null
     *         null si el usuario no existe o está eliminado
     */
    public function getAuthState(string $userId, string $tenantId): ?array;

    /**
     * Actualiza el hash del password y, opcionalmente, limpia el marcador
     * fecha_expiracion_password (cambio de contraseña completado).
     */
    public function updatePassword(string $userId, string $tenantId, string $passwordHash, bool $clearPasswordExpiry): bool;
}
