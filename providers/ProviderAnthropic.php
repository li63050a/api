<?php
/**
 * Anthropic 上游适配器。无命名空间。
 */
class ProviderAnthropic extends ProviderBase
{
    protected function endpoint(array $model): string
    {
        $base = $this->baseUrl($model);
        return $base . '/messages';
    }

    protected function buildAuthHeaders(array $model, array $key): array
    {
        return [
            'x-api-key' => $key['key_value'] ?? '',
            'anthropic-version' => '2023-06-01',
        ];
    }

    protected function mapRequest(array $openaiBody, array $model): array
    {
        $system = [];
        $messages = [];
        foreach ($openaiBody['messages'] ?? [] as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system[] = is_array($m['content']) ? ProviderFormatter::openaiContentToText($m['content']) : $m['content'];
                continue;
            }
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $content = is_array($m['content']) ? ProviderFormatter::openaiContentToText($m['content']) : $m['content'];
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $body = [
            'model' => $model['upstream_model'],
            'messages' => $messages,
        ];
        if ($system) {
            $body['system'] = implode("\n", $system);
        }
        $body['max_tokens'] = $openaiBody['max_tokens'] ?? 4096;
        if (isset($openaiBody['temperature'])) {
            $body['temperature'] = $openaiBody['temperature'];
        }
        if (isset($openaiBody['top_p'])) {
            $body['top_p'] = $openaiBody['top_p'];
        }
        if (isset($openaiBody['stream'])) {
            $body['stream'] = $openaiBody['stream'];
        }
        if (isset($openaiBody['stop'])) {
            $body['stop_sequences'] = is_array($openaiBody['stop']) ? $openaiBody['stop'] : [$openaiBody['stop']];
        }
        return $body;
    }

    protected function mapResponse($providerBody): array
    {
        $providerBody = is_array($providerBody) ? $providerBody : [];
        $text = '';
        foreach ($providerBody['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        $usage = $providerBody['usage'] ?? [];
        $finish = ProviderBase::mapFinishBack($providerBody['stop_reason'] ?? null);
        return [
            'id' => $providerBody['id'] ?? ('chatcmpl_' . uniqid()),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $providerBody['model'] ?? '',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $finish,
                ],
            ],
            'usage' => [
                'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens' => (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
            ],
        ];
    }

    protected function mapProviderChunkToOpenai(string $providerChunk): ?string
    {
        if (trim($providerChunk) === '') {
            return null;
        }
        $out = '';
        foreach (ProviderFormatter::parseAnthropicSse($providerChunk) as $ev) {
            $type = $ev['type'] ?? '';
            if ($type === 'content_block_delta' && ($ev['delta']['type'] ?? '') === 'text_delta') {
                $out .= ProviderFormatter::sseFrame([
                    'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $ev['delta']['text'] ?? '']]],
                ]);
            } elseif ($type === 'message_start') {
                $u = $ev['message']['usage'] ?? [];
                if (!empty($u)) {
                    $out .= ProviderFormatter::sseFrame([
                        'choices' => [['index' => 0, 'delta' => []]],
                        'usage' => ['prompt_tokens' => (int) ($u['input_tokens'] ?? 0), 'completion_tokens' => 0],
                    ]);
                }
            } elseif ($type === 'message_delta') {
                $u = $ev['usage'] ?? [];
                $fr = ProviderBase::mapFinishBack($ev['delta']['stop_reason'] ?? null);
                $out .= ProviderFormatter::sseFrame([
                    'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $fr]],
                    'usage' => ['prompt_tokens' => 0, 'completion_tokens' => (int) ($u['output_tokens'] ?? 0)],
                ]);
                $out .= "data: [DONE]\n\n";
            }
        }
        return $out === '' ? null : $out;
    }

    public function extractUsage($openai, bool $streaming): array
    {
        return $this->extractOpenaiUsage($openai);
    }
}
