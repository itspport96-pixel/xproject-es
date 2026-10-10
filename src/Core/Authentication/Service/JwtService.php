<?php

declare(strict_types=1);

namespace App\Core\Authentication\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\UnexpectedValueException;
use DomainException;
use RuntimeException;

class JwtService
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    private int $refreshExpiration;

    public function __construct()
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET environment variable is not configured. Set it in your .env file.');
        }
        $this->secret = $secret;
        $this->algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
        $this->expiration = (int) ($_ENV['JWT_EXPIRATION'] ?? 3600);
        $this->refreshExpiration = (int) ($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800);
    }

    public function generateToken(array $payload): string
    {
        $issuedAt = time();
        $tokenPayload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->expiration,
            'type' => 'access',
        ]);

        return JWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }

    public function generateRefreshToken(array $payload): string
    {
        $issuedAt = time();
        $tokenPayload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->refreshExpiration,
            'type' => 'refresh',
        ]);

        return JWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }

    public function decodeToken(string $token): object
    {
        return JWT::decode($token, new Key($this->secret, $this->algorithm));
    }

    public function getExpiration(): int
    {
        return $this->expiration;
    }

    public function getRefreshExpiration(): int
    {
        return $this->refreshExpiration;
    }
}
