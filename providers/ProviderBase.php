<?php
/**
 * 供应商基类（抽象）。无命名空间，forward 无 Container，使用全局 db()/config()。
 */
abstract class ProviderBase
{
    protected string $reqPath = '';
    protected string $providerName = '';
    protected array $currentKey = [];
    protected bool $streaming = false;

    public function forward(AppRequest $req, array $model, array $upstreamKey): void
    {
        $this->reqPath = $req->path;

        $prov = db_fetch(db(), 'SELECT id, name, type, base_url, api_path FROM providers WHERE id = ?', [$model['provider_id']]);
        $this->providerName = strtolower((string) ($prov['type'] ?? $prov['name'] ?? 'openai'));

        $clientFormat = $req->getAttribute('client_format') ?? config('default_client_format', 'openai');
        $openaiBody = ProviderFormatter::clientToOpenai($req->json ?? [], $clientFormat);

        if ($this->attempt($req, $model, $upstreamKey, $clientFormat, $openaiBody)) {
            return;
        }

        $retries = (int) config('upstream_retry', 0);
        for ($i = 0; $i < $retries; $i++) {
            $nextKey = (new ProviderKeyPool())->next($model['provider_id']);
            if ($nextKey === null) {
                break;
            }
            (new ProviderKeyPool())->markError($upstreamKey['id']);
            ProviderFactory::make($this->providerName)->forward($req, $model, $nextKey);
            return;
        }
        AppResponse::error('upstream error', 502);
    }

    protected function baseUrl(array $model): string
    {
        $prov = db_fetch(db(), 'SELECT base_url, api_path FROM providers WHERE id = ?', [$model['provider_id']]);
        $base = rtrim($prov['base_url'] ?? '', '/');
        $p = trim((string) ($prov['api_path'] ?? ''), '/');
        return $p !== '' ? $base . '/' . $p : $base;
    }

    protected function attempt(AppRequest $req, array $model, array $upstreamKey, string $clientFormat, array $openaiBody): bool
    {
        $this->currentKey = $upstreamKey;
        $this->streaming = !empty($openaiBody['stream']);

        $providerBody = $this->mapRequest($openaiBody, $model);
        $url = $this->endpoint($model);
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->buildAuthHeaders($model, $upstreamKey)
        );
        $postBody = $providerBody !== [] ? json_encode($providerBody, JSON_UNESCAPED_UNICODE) : '';

        $userId = (int) ($req->getAttribute('user')['id'] ?? 0);
        $keyId = (int) ($req->getAttribute('key')['id'] ?? 0);
        $start = microtime(true);

        $usage = ['input' => 0, 'output' => 0];

        if ($this->streaming) {
            AppResponse::header('Content-Type', 'text/event-stream');
            AppResponse::header('Cache-Control', 'no-cache');
            AppResponse::header('Connection', 'keep-alive');
            AppResponse::header('X-Accel-Buffering', 'no');

            $onChunk = function (string $providerChunk) use ($clientFormat, &$usage) {
                $openaiChunk = $this->mapProviderChunkToOpenai($providerChunk);
                if ($openaiChunk === null) {
                    return;
                }
                $u = $this->extractUsage($openaiChunk, true);
                $usage['input'] += $u['input'];
                $usage['output'] += $u['output'];
                $clientChunk = ProviderFormatter::openaiToClient($openaiChunk, $clientFormat);
                if ($clientChunk !== null && $clientChunk !== '') {
                    AppResponse::sendChunk($clientChunk);
                }
            };

            [$httpCode, $result, $errno, $error] = $this->curlExec($url, 'POST', $headers, $postBody, (int) config('upstream_timeout', 120), $onChunk);
            $ok = ($errno === 0 && $httpCode >= 200 && $httpCode < 300);

        if ($ok) {
            $this->recordBilling($userId, $keyId, $model, $usage, $httpCode, $start, $errno, $error);
        }
        return $ok;
    }

        [$httpCode, $result, $errno, $error] = $this->curlExec($url, 'POST', $headers, $postBody, (int) config('upstream_timeout', 120), null);
        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $result];
        }
        $openai = $this->mapResponse($decoded);
        $client = ProviderFormatter::openaiToClient($openai, $clientFormat);
        $usage = $this->extractUsage($openai, false);
        $this->recordBilling($userId, $keyId, $model, $usage, $httpCode, $start, $errno, $error);
        AppResponse::json($client);
    }

    protected function recordBilling(int $userId, int $keyId, array $model, array $usage, int $httpCode, float $start, int $errno = 0, string $error = ''): void
    {
        try {
            (new SvcBilling())->record($userId, $keyId, $model['alias'] ?? '', (int) $usage['input'], (int) $usage['output']);
        } catch (\Throwable $e) {
        }
        try {
            $latency = (int) ((\microtime(true) - $start) * 1000);
            $errMsg = '';
            if ($httpCode >= 400 || $errno !== 0) {
                $errMsg = $error !== '' ? $error : ('http ' . $httpCode);
            }
            (new SvcLogger())->log([
                'user_id' => $userId,
                'api_key_id' => $keyId,
                'path' => $this->reqPath,
                'model_alias' => $model['alias'] ?? '',
                'upstream_provider' => $this->providerName,
                'ip' => '',
                'status_code' => $httpCode,
                'input_tokens' => (int) $usage['input'],
                'output_tokens' => (int) $usage['output'],
                'latency_ms' => $latency,
                'error' => $errMsg,
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    protected function curlExec(string $url, string $method, array $headers, $body, int $timeout, ?callable $onChunk = null): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $onChunk === null);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($onChunk !== null) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk) {
                $onChunk($data);
                return strlen($data);
            });
        }
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [$httpCode, $result, $errno, $error];
    }

    protected function extractOpenaiUsage($openai): array
    {
        if (is_array($openai)) {
            $u = $openai['usage'] ?? [];
            return [
                'input' => (int) ($u['prompt_tokens'] ?? 0),
                'output' => (int) ($u['completion_tokens'] ?? 0),
            ];
        }
        $in = 0;
        $out = 0;
        foreach (ProviderFormatter::parseSse((string) $openai) as $ev) {
            if (isset($ev['usage'])) {
                $in += (int) ($ev['usage']['prompt_tokens'] ?? 0);
                $out += (int) ($ev['usage']['completion_tokens'] ?? 0);
            }
        }
        return ['input' => $in, 'output' => $out];
    }

    public static function mapFinishBack($r): ?string
    {
        return match ($r) {
            'stop', 'end_turn', 'stop_sequence' => 'stop',
            'length', 'max_tokens' => 'length',
            'content_filter' => 'content_filter',
            'tool_use' => 'tool_calls',
            null => null,
            default => 'stop',
        };
    }

    abstract protected function mapRequest(array $openaiBody, array $model): array;

    abstract protected function endpoint(array $model): string;

    abstract protected function extractUsage($openai, bool $streaming): array;

    abstract protected function mapResponse($providerBody): array;

    abstract protected function mapProviderChunkToOpenai(string $providerChunk): ?string;

    abstract protected function buildAuthHeaders(array $model, array $key): array;
}
