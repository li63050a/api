<?php
declare(strict_types=1);

namespace App\Http;

use App\Support\Exception\HttpException;

final class Response
{
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
    ) {}

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers + ['Content-Type' => 'application/json'], json_encode($data));
    }

    public static function error(HttpException $e): self
    {
        return self::json(
            ['error' => ['message' => $e->getMessage(), 'type' => $e->type()]],
            $e->status(),
        );
    }

    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    public function body(): string { return $this->body; }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
