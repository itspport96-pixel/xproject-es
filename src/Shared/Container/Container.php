<?php

declare(strict_types=1);

namespace App\Shared\Container;

use Closure;
use App\Shared\Exceptions\ContainerException;

class Container
{
    private static ?self $instance = null;

    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $singletons = [];

    public static function setInstance(self $container): void
    {
        self::$instance = $container;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new ContainerException('Container not initialized');
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract], $this->singletons[$abstract]);
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        $this->singletons[$abstract] = true;
        unset($this->instances[$abstract]);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * @throws ContainerException
     */
    public function get(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            throw new ContainerException("No binding found for: {$abstract}");
        }

        $resolved = ($this->bindings[$abstract])($this);

        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $resolved;
        }

        return $resolved;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function forget(string $abstract): void
    {
        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->singletons[$abstract]
        );
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->singletons = [];
    }
}
