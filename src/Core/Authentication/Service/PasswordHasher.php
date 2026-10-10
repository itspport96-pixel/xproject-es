<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use App\Shared\Exceptions\AuthenticationException;

class PasswordHasher
{
    private int $rounds;

    public function __construct(array $config = [])
    {
        $this->rounds = (int)($config['rounds'] ?? 12);

        if ($this->rounds < 4 || $this->rounds > 31) {
            throw new AuthenticationException('Invalid bcrypt rounds value');
        }
    }

    public function hash(string $password): string
    {
        if ($password === '' || $password === null) {
            throw new AuthenticationException('Password cannot be empty');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, [
            'cost' => $this->rounds,
        ]);

        if ($hash === false) {
            throw new AuthenticationException('Failed to hash password');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        if ($password === '' || $password === null) {
            return false;
        }

        if ($hash === '' || $hash === null) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, [
            'cost' => $this->rounds,
        ]);
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }
}
