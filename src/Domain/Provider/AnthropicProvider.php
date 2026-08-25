<?php
declare(strict_types=1);

namespace App\Domain\Provider;

final class AnthropicProvider extends AbstractProvider
{
    public function endpoints(): array
    {
        return ['chat' => '/v1/messages', 'embeddings' => '/v1/embeddings'];
    }

    public function buildUrl(array $model, string $endpoint, array $payload, string $clientFormat): string
    {
        $this->curlTimeout = (int)($model['timeout'] ?? 0);
        return rtrim((string)($model['base_url'] ?? ''), '/') . $endpoint;
    }

    public function convertResponse(array $payload): array
    {
        $text = '';
        foreach ($payload['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        $usage = $payload['usage'] ?? [];
        $pt = (int)($usage['input_tokens'] ?? 0);
        $ct = (int)($usage['output_tokens'] ?? 0);
        return [
            'id' => $payload['id'] ?? ('chatcmpl_' . uniqid()),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['model'] ?? '',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $this->mapFinishReason($payload['stop_reason'] ?? null),
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
        $u = $json['usage'] ?? [];
        $pt = (int)($u['input_tokens'] ?? 0);
        $ct = (int)($u['output_tokens'] ?? 0);
        return ['prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => $pt + $ct];
    }

    public function friendlyError(string $raw): string
    {
        return $this->parseError($raw);
    }

    protected function nativeFormat(): string
    {
        return 'anthropic';
    }

    protected function curlOnce(string $url, string $apiKey, array $body, ?callable $onChunk): ?array
    {
        return $this->post(
            $url,
            ['Content-Type' => 'application/json', 'x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'],
            $body,
            $onChunk
        );
    }

    private function mapFinishReason(?string $reason): ?string
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            'content_filter' => 'content_filter',
            null => null,
            default => 'stop',
        };
    }
}
