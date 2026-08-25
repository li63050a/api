<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            throw new RuntimeException("Container: [{$id}] not registered");
        }
        return $this->instances[$id];
    }
}
