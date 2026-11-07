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

    /**
     * Constructor de JwtService.
     * 
     * M1: Solo se admiten algoritmos simétricos HS* (HS256, HS384, HS512) porque
     * la aplicación no implementa gestión de claves públicas/privadas para JWT
     * asimétricos (RS*, ES*, etc). Esto evita ataques de confusión de algoritmo
     * (ej: 'none' o RS256 con clave pública expuesta).
     *
     * @throws RuntimeException Si JWT_SECRET o JWT_ALGORITHM son inválidos
     */
    public function __construct()
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET environment variable is not configured. Set it in your .env file.');
        }
        $this->secret = $secret;
        
        // M1: whitelist de algoritmos simétricos admitidos
        $this->algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
        if (!in_array($this->algorithm, ['HS256', 'HS384', 'HS512'], true)) {
            throw new RuntimeException(
                "JWT_ALGORITHM inválido: '{$this->algorithm}'. " .
                "Admitidos: HS256, HS384, HS512 (algoritmos simétricos HMAC-SHA)."
            );
        }
        
        $this->expiration = (int) ($_ENV['JWT_EXPIRATION'] ?? 3600);
        $this->refreshExpiration = (int) ($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800);
        
        if ($this->expiration <= 0) {
            throw new RuntimeException('JWT_EXPIRATION must be a positive integer.');
        }
        if ($this->refreshExpiration <= 0) {
            throw new RuntimeException('JWT_REFRESH_EXPIRATION must be a positive integer.');
        }
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
