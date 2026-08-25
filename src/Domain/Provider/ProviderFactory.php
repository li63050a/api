<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use RuntimeException;

final class ProviderFactory
{
    /** @var array<string, string> name → class */
    private const MAP = [
        'openai' => OpenAIProvider::class,
        'anthropic' => AnthropicProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    public function __construct(
        private Config $config,
        private CryptoService $crypto,
        private KeyPool $pool,
    ) {}

    public function make(string $name): ProviderInterface
    {
        $class = self::MAP[strtolower($name)] ?? throw new RuntimeException("Unknown provider: {$name}");
        return new $class($this->config, $this->crypto, $this->pool);
    }
}
