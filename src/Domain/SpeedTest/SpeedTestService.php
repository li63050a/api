<?php
declare(strict_types=1);

namespace App\Domain\SpeedTest;

use App\Db\Database;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\SpeedTestRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Domain\Crypto\CryptoService;
use App\Support\Config;

/**
 * 上游测速：遍历启用的 providers 及其上游密钥，对每个 Key 做轻量探测。
 *
 * 探测方式差异：
 *  - openai：GET {base_url}/models（Bearer）
 *  - gemini：GET {base_url}/models?key={KEY}
 *  - anthropic：极短 POST {base_url}/messages（max_tokens 很小）
 * 结果写入 speedtest_log，并返回每个 key 的可用性/延迟数组。
 */
final class SpeedTestService
{
    public function __construct(
        private Database $db,
        private ProviderRepository $providers,
        private UpstreamKeyRepository $upstreamKeys,
        private ModelMapRepository $modelMap,
        private SpeedTestRepository $speedTests,
        private CryptoService $crypto,
        private Config $config,
    ) {}

    /** 一键测速：遍历 model_map 每个模型做真实转发探测；autoDisable 时失败自动禁用。 */
    public function testAllModels(bool $autoDisable = false): array
    {
        $results = [];
        foreach ($this->modelMap->all() as $model) {
            $results[] = $this->testModel($model, $autoDisable);
        }
        return $results;
    }

    /** 按供应商一键测速：仅探测该供应商的模型；autoDisable 时失败自动禁用。 */
    public function testProviderModels(string $providerName, bool $autoDisable = false): array
    {
        $results = [];
        foreach ($this->modelMap->all() as $model) {
            if ((string)($model['provider'] ?? '') === $providerName) {
                $results[] = $this->testModel($model, $autoDisable);
            }
        }
        return $results;
    }

    /**
     * 指定模型测速：按 model_map 行解析供应商与上游 Key，发一次最小请求。
     *
     * @param array<string, mixed> $model model_map 行
     * @return array<string, mixed>
     */
    public function testModel(array $model, bool $autoDisable = false): array
    {
        $modelId = (int)($model['id'] ?? 0);
        $alias = (string)($model['alias'] ?? '');
        $providerName = (string)($model['provider'] ?? '');
        $provider = $this->providers->findByName($providerName);
        if ($provider === null) {
            return $this->modelResult($modelId, $alias, $providerName, false, 0, 0, '供应商不存在: ' . $providerName, $model, $autoDisable);
        }
        $keys = $this->upstreamKeys->byProvider((int)$provider['id']);
        if ($keys === []) {
            return $this->modelResult($modelId, $alias, $providerName, false, 0, 0, '无可用上游密钥', $model, $autoDisable);
        }

        $rawKey = $this->decryptKey((string)$keys[0]['key_value']);
        $fmt = strtolower((string)($provider['client_format'] ?? 'openai'));
        $baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');
        $upstreamModel = (string)($model['upstream_model'] ?? $alias);
        if ($upstreamModel === '') {
            $upstreamModel = $alias;
        }

        $start = microtime(true);
        $resp = $this->probeModelHttp($baseUrl, $fmt, $rawKey, $upstreamModel);
        $latency = (int)round((microtime(true) - $start) * 1000);
        $ok = $resp['code'] >= 200 && $resp['code'] < 300;
        $detail = ($resp['ok'] === false && $resp['error'] !== '') ? $resp['error'] : 'http ' . $resp['code'];

        $this->speedTests->insert([
            'provider_id' => (int)$provider['id'],
            'model' => $upstreamModel,
            'endpoint' => $fmt === 'anthropic' ? '/v1/messages' : ($fmt === 'gemini' ? '/v1beta/models/{model}:generateContent' : '/v1/chat/completions'),
            'latency_ms' => $latency,
            'success' => $ok ? 1 : 0,
            'error' => mb_substr($detail, 0, 500),
        ]);

        return $this->modelResult($modelId, $alias, $providerName, $ok, $latency, $resp['code'], $detail, $model, $autoDisable);
    }

    /** 汇总探测结果；autoDisable 且失败时把模型置为停用。 */
    private function modelResult(
        int $modelId,
        string $alias,
        string $providerName,
        bool $ok,
        int $latency,
        int $httpCode,
        string $detail,
        array $model,
        bool $autoDisable,
    ): array {
        $disabled = false;
        if ($autoDisable && !$ok && $modelId > 0) {
            $this->modelMap->update($modelId, ['enabled' => 0]);
            $disabled = true;
        }
        return [
            'model_id' => $modelId,
            'alias' => $alias,
            'provider' => $providerName,
            'upstream_model' => (string)($model['upstream_model'] ?? $alias),
            'ok' => $ok,
            'latency_ms' => $latency,
            'http_code' => $httpCode,
            'detail' => $detail,
            'auto_disabled' => $disabled,
        ];
    }

    /** 按供应商格式发一次最小请求，返回 {ok, error, code, body}。 */
    private function probeModelHttp(string $baseUrl, string $fmt, string $rawKey, string $upstreamModel): array
    {
        if ($fmt === 'anthropic') {
            return $this->httpPostJson(
                $baseUrl . '/v1/messages',
                ['x-api-key: ' . $rawKey, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
                ['model' => $upstreamModel, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'hi']]]
            );
        }
        if ($fmt === 'gemini') {
            return $this->httpPostJson(
                $baseUrl . '/v1beta/models/' . urlencode($upstreamModel) . ':generateContent?key=' . urlencode($rawKey),
                ['content-type: application/json'],
                ['contents' => [['parts' => [['text' => 'hi']]]]]
            );
        }
        return $this->httpPostJson(
            $baseUrl . '/v1/chat/completions',
            ['content-type: application/json', 'Authorization: Bearer ' . $rawKey],
            ['model' => $upstreamModel, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'hi']]]
        );
    }

    private function decryptKey(string $stored): string
    {
        if (str_starts_with($stored, 'enc:')) {
            try {
                return $this->crypto->decrypt(substr($stored, 4));
            } catch (\Throwable) {
                return $stored;
            }
        }
        return $stored;
    }

    /** @return array{ok:bool, error:string, code:int, body:string} */
    private function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        $sslVerify = (bool)$this->config->get('upstream_ssl_verify', true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int)$this->config->get('upstream_timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'curl error: ' . $err, 'code' => 0, 'body' => ''];
        }
        return ['ok' => $code >= 200 && $code < 300, 'error' => '', 'code' => $code, 'body' => (string)$body];
    }

    /** @return array{ok:bool, error:string, code:int, body:string} */
    private function httpPostJson(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        $sslVerify = (bool)$this->config->get('upstream_ssl_verify', true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int)$this->config->get('upstream_timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'curl error: ' . $err, 'code' => 0, 'body' => ''];
        }
        return ['ok' => $code >= 200 && $code < 300, 'error' => '', 'code' => $code, 'body' => (string)$body];
    }
}
