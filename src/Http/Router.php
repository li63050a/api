<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int, array{prefix: string, handler: string, middleware: array<int,string>}> */
    private array $routes = [];

    /** @param array<int, string> $middleware */
    public function add(string $prefix, string $handler, array $middleware = []): void
    {
        $this->routes[] = ['prefix' => $prefix, 'handler' => $handler, 'middleware' => $middleware];
    }

    /** @return array{handler: string, middleware: array<int,string>}|null */
    public function match(string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($path === $route['prefix']) {
                return ['handler' => $route['handler'], 'middleware' => $route['middleware']];
            }
        }
        return null;
    }
}
