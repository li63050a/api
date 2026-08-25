<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Provider\Formatter;
use Tests\Framework;

Framework::test('Formatter: openai chat -> anthropic -> back', function (): void {
    $openai = [
        'model' => 'claude-3-5-sonnet',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'temperature' => 0.7,
        'max_tokens' => 100,
    ];
    $anthropic = Formatter::openaiToAnthropic($openai);
    Framework::assertSame($openai['model'], $anthropic['model']);
    Framework::assertSame([['role' => 'user', 'content' => 'hi']], $anthropic['messages']);
    Framework::assertSame(0.7, $anthropic['temperature']);
    Framework::assertSame(100, $anthropic['max_tokens']);
    $back = Formatter::anthropicToOpenai($anthropic);
    Framework::assertSame($openai['messages'], $back['messages']);
    Framework::assertSame(100, $back['max_tokens']);
});

Framework::test('Formatter: openai chat -> gemini -> back', function (): void {
    $openai = ['model' => 'gemini-1.5-pro', 'messages' => [['role' => 'user', 'content' => 'hi']]];
    $gemini = Formatter::openaiToGemini($openai);
    Framework::assertSame($openai['model'], $gemini['model']);
    Framework::assertTrue(isset($gemini['contents'][0]['parts'][0]['text']));
    $back = Formatter::geminiToOpenai($gemini);
    Framework::assertSame('user', $back['messages'][0]['role']);
    Framework::assertSame('hi', $back['messages'][0]['content']);
});
