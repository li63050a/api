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

    /** 一键测速：遍历全部密钥下的每个模型做真实转发探测；autoDisable 时失败自动禁用。 */
    public function testAllModels(bool $autoDisable = false): array
    {
        $results = [];
        foreach ($this->modelMap->all() as $model) {
            $results[] = $this->testModel($model, $autoDisable);
        }
        return $results;
    }

    /** 按密钥测速：只探测该密钥下的模型；autoDisable 时失败自动禁用该密钥下的模型。 */
    public function testAllModelsByKey(int $keyId, bool $autoDisable = false): array
    {
        $results = [];
        foreach ($this->modelMap->allByKeyId($keyId) as $model) {
            $results[] = $this->testModel($model, $autoDisable);
        }
        return $results;
    }

    /**
     * 指定模型测速：按 model_map 行解析其所属密钥与供应商，发一次最小请求。
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

        // 优先使用模型关联的密钥；旧数据 key_id=0 或无权限时回退到供应商第一把可用密钥
        $keyId = (int)($model['key_id'] ?? 0);
        $key = $keyId > 0 ? $this->upstreamKeys->find($keyId) : null;
        if ($key === null || (int)$key['provider_id'] !== (int)$provider['id'] || (int)$key['status'] !== 1) {
            $keys = $this->upstreamKeys->byProvider((int)$provider['id']);
            if ($keys === []) {
                return $this->modelResult($modelId, $alias, $providerName, false, 0, 0, '无可用上游密钥', $model, $autoDisable);
            }
            $key = $keys[0];
        }

        $rawKey = $this->decryptKey((string)$key['key_value']);
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

    /** 汇总探测结果；autoDisable 且失败时把该模型（仅当前密钥下的行）置为停用。 */
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
            'key_id' => (int)($model['key_id'] ?? 0),
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int)$this->config->get('upstream_timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int)$this->config->get('upstream_timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
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
