<?php
declare(strict_types=1);

namespace App\Domain\Provider;

final class GeminiProvider extends AbstractProvider
{
    public function endpoints(): array
    {
        return ['chat' => '/v1beta/models/{model}:generateContent', 'embeddings' => '/v1beta/models/{model}:embedContent'];
    }

    public function buildUrl(array $model, string $endpoint, array $payload, string $clientFormat): string
    {
        $this->curlTimeout = (int)($model['timeout'] ?? 0);
        $base = rtrim((string)($model['base_url'] ?? ''), '/');
        $path = str_replace('{model}', (string)($model['upstream_model'] ?? ''), $endpoint);
        return $base . $path;
    }

    public function convertResponse(array $payload): array
    {
        $text = '';
        $cand = $payload['candidates'][0] ?? [];
        foreach (($cand['content']['parts'] ?? []) as $p) {
            if (is_array($p)) {
                $text .= $p['text'] ?? '';
            }
        }
        $um = $payload['usageMetadata'] ?? [];
        $pt = (int)($um['promptTokenCount'] ?? 0);
        $ct = (int)($um['candidatesTokenCount'] ?? 0);
        return [
            'id' => 'chatcmpl-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['modelVersion'] ?? '',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $this->mapFinishReason($cand['finishReason'] ?? null),
                ],
            ],
            'usage' => [
                'prompt_tokens' => $pt,
                'completion_tokens' => $ct,
                'total_tokens' => $pt + $ct,
            ],
        ];
    }

    public function extractUsage(array $json): array
    {
        $u = $json['usageMetadata'] ?? [];
        $pt = (int)($u['promptTokenCount'] ?? 0);
        $ct = (int)($u['candidatesTokenCount'] ?? 0);
        return ['prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => $pt + $ct];
    }

    public function friendlyError(string $raw): string
    {
        return $this->parseError($raw);
    }

    protected function nativeFormat(): string
    {
        return 'gemini';
    }

    protected function curlOnce(string $url, string $apiKey, array $body, ?callable $onChunk): ?array
    {
        // Gemini 用 ?key= 查询参数鉴权（buildUrl 不接收密钥，故在此拼接）
        $sep = str_contains($url, '?') ? '&' : '?';
        return $this->post(
            $url . $sep . 'key=' . urlencode($apiKey),
            ['Content-Type' => 'application/json'],
            $body,
            $onChunk
        );
    }

    private function mapFinishReason(?string $reason): ?string
    {
        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY', 'BLOCKLIST', 'RECITATION', 'PROHIBITED_CONTENT' => 'content_filter',
            null => null,
            default => 'stop',
        };
    }
}
