<?php
/**
 * 多格式转换：客户端格式 <-> 内部规范(OpenAI)
 * 静态工具类，无命名空间。
 */
class ProviderFormatter
{
    public static function detectFormat(AppRequest $req): string
    {
        $fmt = $req->getHeader('x-client-format');
        $allowed = config('client_formats', ['openai']);
        if (is_string($fmt) && in_array($fmt, $allowed, true)) {
            return $fmt;
        }
        return config('default_client_format', 'openai');
    }

    public static function clientToOpenai(array $body, string $format): array
    {
        if ($format === 'openai') {
            return $body;
        }
        if ($format === 'anthropic') {
            return self::anthropicToOpenai($body);
        }
        if ($format === 'gemini') {
            return self::geminiToOpenai($body);
        }
        return $body;
    }

    public static function openaiToClient($body, string $format)
    {
        if ($format === 'openai') {
            return $body;
        }
        if (is_string($body)) {
            if ($format === 'anthropic') {
                return self::openaiSseToAnthropic($body);
            }
            if ($format === 'gemini') {
                return self::openaiSseToGemini($body);
            }
            return $body;
        }
        if ($format === 'anthropic') {
            return self::openaiArrayToAnthropic($body);
        }
        if ($format === 'gemini') {
            return self::openaiArrayToGemini($body);
        }
        return $body;
    }

    private static function anthropicToOpenai(array $body): array
    {
        $messages = [];
        if (array_key_exists('system', $body)) {
            $sys = $body['system'];
            if (is_array($sys)) {
                $parts = [];
                foreach ($sys as $s) {
                    $parts[] = is_array($s) ? ($s['text'] ?? '') : $s;
                }
                $sys = implode("\n", $parts);
            }
            $messages[] = ['role' => 'system', 'content' => $sys];
        }
        foreach ($body['messages'] ?? [] as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => self::anthropicContentToText($m['content'] ?? '')];
        }
        $out = [];
        if (isset($body['model'])) {
            $out['model'] = $body['model'];
        }
        $out['messages'] = $messages;
        foreach (['max_tokens', 'temperature', 'top_p', 'stream', 'stop', 'tools'] as $k) {
            if (array_key_exists($k, $body)) {
                $out[$k] = $body[$k];
            }
        }
        return $out;
    }

    private static function geminiToOpenai(array $body): array
    {
        $messages = [];
        if (isset($body['systemInstruction'])) {
            $si = $body['systemInstruction'];
            $text = is_array($si) ? self::geminiPartsToText($si['parts'] ?? []) : $si;
            $messages[] = ['role' => 'system', 'content' => $text];
        }
        foreach ($body['contents'] ?? [] as $c) {
            $role = ($c['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => self::geminiPartsToText($c['parts'] ?? [])];
        }
        $out = [];
        if (isset($body['model'])) {
            $out['model'] = $body['model'];
        }
        $out['messages'] = $messages;
        $gc = $body['generationConfig'] ?? [];
        if (isset($gc['temperature'])) {
            $out['temperature'] = $gc['temperature'];
        }
        if (isset($gc['topP'])) {
            $out['top_p'] = $gc['topP'];
        }
        if (isset($gc['maxOutputTokens'])) {
            $out['max_tokens'] = $gc['maxOutputTokens'];
        }
        if (isset($body['stream'])) {
            $out['stream'] = $body['stream'];
        }
        return $out;
    }

    private static function openaiArrayToAnthropic(array $o): array
    {
        $text = '';
        $choice = $o['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        if (isset($msg['content'])) {
            $text = is_array($msg['content']) ? self::openaiContentToText($msg['content']) : $msg['content'];
        }
        $usage = $o['usage'] ?? [];
        return [
            'id' => $o['id'] ?? ('msg_' . uniqid()),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $o['model'] ?? '',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => ProviderBase::mapFinishBack($choice['finish_reason'] ?? null),
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    private static function openaiArrayToGemini(array $o): array
    {
        $text = '';
        $choice = $o['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        if (isset($msg['content'])) {
            $text = is_array($msg['content']) ? self::openaiContentToText($msg['content']) : $msg['content'];
        }
        $usage = $o['usage'] ?? [];
        return [
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                    'finishReason' => ProviderBase::mapFinishBack($choice['finish_reason'] ?? null),
                    'index' => 0,
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => (int) ($usage['prompt_tokens'] ?? 0),
                'candidatesTokenCount' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    private static function openaiSseToAnthropic(string $s): string
    {
        $out = '';
        foreach (self::parseSse($s) as $ev) {
            if (isset($ev['__done'])) {
                continue;
            }
            $choice = $ev['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            if (isset($delta['content']) && $delta['content'] !== '') {
                $out .= self::anthropicEvent('content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => 0,
                    'delta' => ['type' => 'text_delta', 'text' => $delta['content']],
                ]);
            }
            if (isset($ev['usage']) || isset($choice['finish_reason'])) {
                $u = $ev['usage'] ?? [];
                $out .= self::anthropicEvent('message_delta', [
                    'type' => 'message_delta',
                    'delta' => ['stop_reason' => ProviderBase::mapFinishBack($choice['finish_reason'] ?? null), 'stop_sequence' => null],
                    'usage' => ['output_tokens' => (int) ($u['completion_tokens'] ?? 0)],
                ]);
                $out .= self::anthropicEvent('message_stop', ['type' => 'message_stop']);
            }
        }
        return $out;
    }

    private static function openaiSseToGemini(string $s): string
    {
        $out = '';
        foreach (self::parseSse($s) as $ev) {
            if (isset($ev['__done'])) {
                continue;
            }
            $choice = $ev['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            $text = $delta['content'] ?? '';
            $cand = ['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'index' => 0];
            if (isset($choice['finish_reason'])) {
                $cand['finishReason'] = ProviderBase::mapFinishBack($choice['finish_reason'] ?? null);
            }
            $frame = ['candidates' => [$cand]];
            $u = $ev['usage'] ?? [];
            if (!empty($u)) {
                $frame['usageMetadata'] = [
                    'promptTokenCount' => (int) ($u['prompt_tokens'] ?? 0),
                    'candidatesTokenCount' => (int) ($u['completion_tokens'] ?? 0),
                ];
            }
            $out .= "data: " . json_encode($frame, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        return $out;
    }

    private static function anthropicEvent(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    public static function sseFrame(array $data): string
    {
        return "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    public static function parseSse(string $s): array
    {
        $out = [];
        foreach (explode("\n", $s) as $line) {
            $line = rtrim($line, "\r");
            if (strpos($line, 'data:') !== 0) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') {
                $out[] = ['__done' => true];
                continue;
            }
            $dec = json_decode($data, true);
            if (is_array($dec)) {
                $out[] = $dec;
            }
        }
        return $out;
    }

    public static function parseAnthropicSse(string $s): array
    {
        $events = [];
        $buf = '';
        foreach (explode("\n", $s) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                if ($buf !== '') {
                    $d = json_decode($buf, true);
                    if (is_array($d)) {
                        $events[] = $d;
                    }
                    $buf = '';
                }
                continue;
            }
            if (strpos($line, 'data:') === 0) {
                $buf .= trim(substr($line, 5));
            }
        }
        if ($buf !== '') {
            $d = json_decode($buf, true);
            if (is_array($d)) {
                $events[] = $d;
            }
        }
        return $events;
    }

    public static function anthropicContentToText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            } elseif (isset($block['text'])) {
                $text .= $block['text'];
            }
        }
        return $text;
    }

    public static function geminiPartsToText(array $parts): string
    {
        $text = '';
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['text'])) {
                $text .= $p['text'];
            } elseif (is_string($p)) {
                $text .= $p;
            }
        }
        return $text;
    }

    public static function openaiContentToText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? '') === 'text') {
                $text .= $part['text'] ?? '';
            } elseif (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        return $text;
    }
}
