<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private array $query,
        private ?string $body,
        private string $clientIp,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string)parse_url($uri, PHP_URL_PATH);
        // 去掉入口脚本前缀（含子目录部署，如 /api/index.php/v1/... -> /v1/...）
        $path = preg_replace('#^(?:.*/)?index\.php#', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string)$v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        $auth = $headers['authorization'] ?? '';
        $rawBody = file_get_contents('php://input');
        $req = new self(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $headers,
            $_GET,
            $rawBody === false ? null : $rawBody,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        );
        // version 取路径首段（如 /v1/chat/completions -> v1）
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if (isset($segments[0])) {
            $req->setAttribute('version', $segments[0]);
        }
        return $req;
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }
    public function json(): array
    {
        $data = json_decode((string)$this->body, true);
        return is_array($data) ? $data : [];
    }
    public function body(): ?string { return $this->body; }
    public function clientIp(): string { return $this->clientIp; }
    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization');
        if ($h === null || !preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return null;
        }
        return $m[1];
    }
    public function setAttribute(string $key, mixed $value): void { $this->attributes[$key] = $value; }
    public function attribute(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }
}
