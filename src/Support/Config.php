<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
