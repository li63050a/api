<?php
/**
 * 供应商工厂。无命名空间。
 */
class ProviderFactory
{
    public static function make(string $providerName): ProviderBase
    {
        return match ($providerName) {
            'openai' => new ProviderOpenAI(),
            'anthropic' => new ProviderAnthropic(),
            'gemini' => new ProviderGemini(),
            default => AppResponse::error('unknown provider', 400),
        };
    }
}
