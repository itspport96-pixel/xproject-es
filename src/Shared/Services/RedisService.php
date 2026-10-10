<?php

declare(strict_types=1);

namespace App\Shared\Services;

use Predis\Client;
use Predis\Connection\ConnectionException;
use App\Shared\Exceptions\RedisException;

class RedisService
{
    private ?Client $client = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->connect();
        }

        return $this->client;
    }

    public function get(string $key): mixed
    {
        try {
            $value = $this->getClient()->get($key);
            return $value === null ? null : json_decode($value, true);
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis get failed (fail-open): {$e->getMessage()}");
            return null;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $serialized = json_encode($value);

            if ($ttl !== null) {
                $this->getClient()->setex($key, $ttl, $serialized);
            } else {
                $this->getClient()->set($key, $serialized);
            }

            return true;
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis set failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->getClient()->del([$key]);
            return true;
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis delete failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    public function exists(string $key): bool
    {
        try {
            return (bool)$this->getClient()->exists($key);
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis exists failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    public function expire(string $key, int $seconds): bool
    {
        try {
            return (bool)$this->getClient()->expire($key, $seconds);
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis expire failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            $this->getClient()->flushdb();
            return true;
        } catch (ConnectionException|RedisException $e) {
            error_log("Redis flush failed (fail-open): {$e->getMessage()}");
            return false;
        }
    }

    public function ping(): bool
    {
        try {
            // Predis devuelve 'PONG' (string), no true: basta con que no lance excepción
            $this->getClient()->ping();
            return true;
        } catch (ConnectionException|RedisException $e) {
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }
    }

    private function connect(): void
    {
        try {
            $parameters = [
                'scheme' => 'tcp',
                'host' => $this->config['host'],
                'port' => $this->config['port'],
            ];

            if (!empty($this->config['password'])) {
                $parameters['password'] = $this->config['password'];
            }

            $this->client = new Client($parameters, [
                'exceptions' => true,
            ]);

            $this->client->ping();
        } catch (ConnectionException $e) {
            throw new RedisException("Redis connection failed: {$e->getMessage()}", (int)$e->getCode(), $e);
        }
    }
}
