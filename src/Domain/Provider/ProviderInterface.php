<?php
declare(strict_types=1);

namespace App\Domain\Provider;

interface ProviderInterface
{
    /** @return array{chat: string, embeddings: string} */
    public function endpoints(): array;
    /** $model 为 model_map 行（含 base_url/timeout，由 ModelAlias 中间件并入）；不含密钥 */
    public function buildUrl(array $model, string $endpoint, array $payload, string $clientFormat): string;
    public function convertRequest(array $payload, string $clientFormat): array;   // openai → 上游
    public function convertResponse(array $payload): array;  // 上游 → openai 兼容
    /** @return array{prompt_tokens:int, completion_tokens:int, total_tokens:int} */
    public function extractUsage(array $json): array;
    public function friendlyError(string $raw): string;
}
