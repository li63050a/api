<?php
/**
 * 请求封装（扁平、无命名空间）
 */
class AppRequest
{
    public string $method;
    public string $path;
    public string $rawBody = '';
    public ?array $json = null;
    public array $headers = [];
    public array $query = [];
    public array $attributes = [];

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script !== '' && strpos($uri, $script) === 0) {
            $path = substr($uri, strlen($script));
        } elseif (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
            $path = $_SERVER['PATH_INFO'];
        } else {
            $path = $uri;
        }
        $this->path = '/' . ltrim($path, '/');
        $this->query = $_GET;
        $this->headers = $this->collectHeaders();
        $this->rawBody = (string) file_get_contents('php://input');
        if ($this->rawBody !== '' && strpos($this->headers['content-type'] ?? '', 'application/json') !== false) {
            $this->json = json_decode($this->rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->json = null;
            }
        }
    }

    private function collectHeaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $out['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['AUTHORIZATION'])) {
            $out['authorization'] = $_SERVER['AUTHORIZATION'];
        }
        return $out;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBearerToken(): ?string
    {
        $h = $this->getHeader('authorization');
        if ($h && preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
            return $m[1];
        }
        return $this->query['api_key'] ?? null;
    }

    public function input(string $key, $default = null)
    {
        return $this->json[$key] ?? $this->query[$key] ?? $default;
    }

    public function setAttribute(string $k, $v): void
    {
        $this->attributes[$k] = $v;
    }

    public function getAttribute(string $k, $default = null)
    {
        return $this->attributes[$k] ?? $default;
    }
}
