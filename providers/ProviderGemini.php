<?php
/**
 * Gemini 上游适配器。无命名空间。
 */
class ProviderGemini extends ProviderBase
{
    protected function endpoint(array $model): string
    {
        $base = $this->baseUrl($model);
        $name = $model['upstream_model'];
        $key = $this->currentKey['key_value'] ?? '';
        if ($this->streaming) {
            return $base . '/models/' . $name . ':streamGenerateContent?alt=sse&key=' . urlencode($key);
        }
        return $base . '/models/' . $name . ':generateContent?key=' . urlencode($key);
    }

    protected function buildAuthHeaders(array $model, array $key): array
    {
        return [];
    }

    protected function mapRequest(array $openaiBody, array $model): array
    {
        $contents = [];
        $system = null;
        foreach ($openaiBody['messages'] ?? [] as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system = is_array($m['content']) ? ProviderFormatter::openaiContentToText($m['content']) : $m['content'];
                continue;
            }
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = is_array($m['content']) ? ProviderFormatter::openaiContentToText($m['content']) : $m['content'];
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        $body = ['contents' => $contents];
        if ($system !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        $gc = [];
        if (isset($openaiBody['temperature'])) {
            $gc['temperature'] = $openaiBody['temperature'];
        }
        if (isset($openaiBody['top_p'])) {
            $gc['topP'] = $openaiBody['top_p'];
        }
        if (isset($openaiBody['max_tokens'])) {
            $gc['maxOutputTokens'] = $openaiBody['max_tokens'];
        }
        if ($gc) {
            $body['generationConfig'] = $gc;
        }
        if (!empty($openaiBody['stream'])) {
            $body['generationConfig']['stream'] = true;
        }
        return $body;
    }

    protected function mapResponse($providerBody): array
    {
        $providerBody = is_array($providerBody) ? $providerBody : [];
        $text = '';
        $cand = $providerBody['candidates'][0] ?? [];
        foreach (($cand['content']['parts'] ?? []) as $p) {
            if (is_array($p)) {
                $text .= $p['text'] ?? '';
            }
        }
        $um = $providerBody['usageMetadata'] ?? [];
        $finish = ProviderBase::mapFinishBack($cand['finishReason'] ?? null);
        return [
            'id' => 'chatcmpl-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $providerBody['modelVersion'] ?? '',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $finish,
                ],
            ],
            'usage' => [
                'prompt_tokens' => (int) ($um['promptTokenCount'] ?? 0),
                'completion_tokens' => (int) ($um['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int) (($um['promptTokenCount'] ?? 0) + ($um['candidatesTokenCount'] ?? 0)),
            ],
        ];
    }

    protected function mapProviderChunkToOpenai(string $providerChunk): ?string
    {
        if (trim($providerChunk) === '') {
            return null;
        }
        $out = '';
        foreach (ProviderFormatter::parseSse($providerChunk) as $ev) {
            if (isset($ev['__done'])) {
                continue;
            }
            $text = '';
            $cand = $ev['candidates'][0] ?? [];
            foreach (($cand['content']['parts'] ?? []) as $p) {
                if (is_array($p)) {
                    $text .= $p['text'] ?? '';
                }
            }
            $frame = [
                'choices' => [
                    ['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text]],
                ],
            ];
            $um = $ev['usageMetadata'] ?? [];
            if (!empty($um)) {
                $frame['choices'][0]['finish_reason'] = ProviderBase::mapFinishBack($cand['finishReason'] ?? null);
                $frame['usage'] = [
                    'prompt_tokens' => (int) ($um['promptTokenCount'] ?? 0),
                    'completion_tokens' => (int) ($um['candidatesTokenCount'] ?? 0),
                ];
            }
            $out .= ProviderFormatter::sseFrame($frame);
            if (!empty($um)) {
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
