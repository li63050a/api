<?php
declare(strict_types=1);

namespace App\Domain\Provider;

final class Formatter
{
    /** @return array{chat: string, embeddings: string} */
    public static function endpoints(string $format): array
    {
        return match ($format) {
            'anthropic' => ['chat' => '/v1/messages', 'embeddings' => '/v1/embeddings'],
            'gemini' => ['chat' => '/v1beta/models/{model}:generateContent', 'embeddings' => '/v1beta/models/{model}:embedContent'],
            default => ['chat' => '/v1/chat/completions', 'embeddings' => '/v1/embeddings'],
        };
    }

    /** 客户端格式 → openai 格式（供内部统一处理） */
    public static function toOpenAI(array $payload, string $format): array
    {
        return match ($format) {
            'anthropic' => self::anthropicToOpenai($payload),
            'gemini' => self::geminiToOpenai($payload),
            default => $payload,
        };
    }

    /** openai 格式 → 上游格式 */
    public static function fromOpenAI(array $payload, string $format): array
    {
        return match ($format) {
            'anthropic' => self::openaiToAnthropic($payload),
            'gemini' => self::openaiToGemini($payload),
            default => $payload,
        };
    }

    public static function openaiToAnthropic(array $p): array
    {
        $out = [
            'model' => (string)($p['model'] ?? ''),
            'messages' => [],
            'max_tokens' => (int)($p['max_tokens'] ?? $p['max_completion_tokens'] ?? 4096),
        ];
        foreach (['temperature', 'top_p', 'stream', 'stop'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        // 提取 system 并去除 system 占位消息
        $system = '';
        $filtered = [];
        foreach ($p['messages'] ?? [] as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n") . (is_string($content) ? $content : '');
                continue;
            }
            $filtered[] = [
                'role' => $role,
                'content' => is_array($content) ? $content : (string)$content,
            ];
        }
        $out['messages'] = $filtered;
        if ($system !== '') {
            $out['system'] = $system;
        }
        return $out;
    }

    public static function anthropicToOpenai(array $p): array
    {
        $messages = [];
        if (!empty($p['system'])) {
            $messages[] = ['role' => 'system', 'content' => is_array($p['system']) ? '' : (string)$p['system']];
        }
        foreach ($p['messages'] ?? [] as $m) {
            $messages[] = ['role' => $m['role'] ?? 'user', 'content' => $m['content'] ?? ''];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'messages' => $messages];
        foreach (['temperature', 'top_p', 'stream', 'stop'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        if (isset($p['max_tokens'])) {
            $out['max_tokens'] = (int)$p['max_tokens'];
        }
        return $out;
    }

    public static function openaiToGemini(array $p): array
    {
        $contents = [];
        $system = '';
        foreach ($p['messages'] ?? [] as $m) {
            $role = $m['role'] ?? 'user';
            $text = is_array($m['content'] ?? null) ? '' : (string)($m['content'] ?? '');
            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n") . $text;
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'contents' => $contents];
        if ($system !== '') {
            $out['system_instruction'] = ['parts' => [['text' => $system]]];
        }
        foreach (['temperature', 'top_p', 'stream'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        if (isset($p['max_tokens'])) {
            $out['generationConfig'] = ['maxOutputTokens' => (int)$p['max_tokens']];
        }
        return $out;
    }

    public static function geminiToOpenai(array $p): array
    {
        $messages = [];
        foreach ($p['contents'] ?? [] as $c) {
            $role = ($c['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $text = '';
            foreach ($c['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'messages' => $messages];
        if (isset($p['generationConfig']['maxOutputTokens'])) {
            $out['max_tokens'] = (int)$p['generationConfig']['maxOutputTokens'];
        }
        foreach (['temperature', 'top_p', 'stream'] as $k) {
            if (isset($p[$k])) {
                $out[$k] = $p[$k];
            }
        }
        return $out;
    }
}
