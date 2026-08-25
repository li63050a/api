<?php
declare(strict_types=1);

namespace App\Domain\Provider;

final class OpenAIProvider extends AbstractProvider
{
    public function endpoints(): array
    {
        return ['chat' => '/v1/chat/completions', 'embeddings' => '/v1/embeddings'];
    }

    public function buildUrl(array $model, string $endpoint, array $payload, string $clientFormat): string
    {
        $this->curlTimeout = (int)($model['timeout'] ?? 0);
        return rtrim((string)($model['base_url'] ?? ''), '/') . $endpoint;
    }

    public function convertResponse(array $payload): array
    {
        return $payload;
    }

    public function extractUsage(array $json): array
    {
        $u = $json['usage'] ?? [];
        $pt = (int)($u['prompt_tokens'] ?? 0);
        $ct = (int)($u['completion_tokens'] ?? 0);
        return ['prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => (int)($u['total_tokens'] ?? $pt + $ct)];
    }

    public function friendlyError(string $raw): string
    {
        return $this->parseError($raw);
    }

    protected function nativeFormat(): string
    {
        return 'openai';
    }

    protected function curlOnce(string $url, string $apiKey, array $body, ?callable $onChunk): ?array
    {
        return $this->post(
            $url,
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $apiKey],
            $body,
            $onChunk
        );
    }
}
