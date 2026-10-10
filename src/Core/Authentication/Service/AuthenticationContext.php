<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use App\Shared\Exceptions\AuthenticationException;

class AuthenticationContext
{
    private static ?array $context = null;

    public static function set(array $context): void
    {
        self::$context = $context;
    }

    public static function get(): array
    {
        if (self::$context === null) {
            throw new AuthenticationException('Authentication context not initialized');
        }

        return self::$context;
    }

    public static function has(): bool
    {
        return self::$context !== null;
    }

    public static function userId(): string
    {
        $userId = self::get()['user_id'] ?? null;

        if ($userId === null || $userId === '') {
            throw new AuthenticationException('User ID not available in context');
        }

        return (string)$userId;
    }

    public static function email(): ?string
    {
        return self::get()['email'] ?? null;
    }

    public static function tenantId(): ?string
    {
        return self::get()['tenant_id'] ?? null;
    }

    public static function roles(): array
    {
        return self::get()['roles'] ?? [];
    }

    public static function tokenJti(): ?string
    {
        return self::get()['token_jti'] ?? null;
    }

    public static function tokenExp(): int
    {
        return (int)(self::get()['token_exp'] ?? 0);
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        return count(array_intersect($roles, self::roles())) > 0;
    }

    public static function clear(): void
    {
        self::$context = null;
    }

    public static function toArray(): array
    {
        return self::$context ?? [];
    }
}
