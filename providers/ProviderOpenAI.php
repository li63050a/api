<?php
/**
 * OpenAI 上游适配器（基本透传）。无命名空间。
 */
class ProviderOpenAI extends ProviderBase
{
    protected function endpoint(array $model): string
    {
        $base = $this->baseUrl($model);
        if (strpos($this->reqPath, '/embeddings') !== false) {
            return $base . '/embeddings';
        }
        return $base . '/chat/completions';
    }

    protected function buildAuthHeaders(array $model, array $key): array
    {
        return ['Authorization' => 'Bearer ' . ($key['key_value'] ?? '')];
    }

    protected function mapRequest(array $openaiBody, array $model): array
    {
        $body = $openaiBody;
        $body['model'] = $model['upstream_model'];
        return $body;
    }

    protected function mapResponse($providerBody): array
    {
        return is_array($providerBody) ? $providerBody : ['raw' => $providerBody];
    }

    protected function mapProviderChunkToOpenai(string $providerChunk): ?string
    {
        if (trim($providerChunk) === '') {
            return null;
        }
        return $providerChunk;
    }

    public function extractUsage($openai, bool $streaming): array
    {
        return $this->extractOpenaiUsage($openai);
    }
}
