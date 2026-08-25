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

    /** @return array<int, array<string, mixed>> */
    public function testAll(): array
    {
        $results = [];
        foreach ($this->providers->findEnabledSorted() as $provider) {
            foreach ($this->upstreamKeys->byProvider((int)$provider['id']) as $key) {
                $results[] = $this->probe($provider, $key);
            }
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function probe(array $provider, array $keyRow): array
    {
        $providerId = (int)$provider['id'];
        $type = strtolower((string)($provider['name'] ?? ''));
        $baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');
        $keyId = (int)$keyRow['id'];
        $rawKey = $this->decryptKey((string)$keyRow['key_value']);

        $start = microtime(true);
        if ($type === 'anthropic') {
            $resp = $this->httpPostJson(
                $baseUrl . '/messages',
                [
                    'x-api-key: ' . $rawKey,
                    'anthropic-version: 2023-06-01',
                    'content-type: application/json',
                ],
                [
                    'model' => $this->defaultModel($providerId),
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]
            );
        } elseif ($type === 'gemini') {
            $resp = $this->httpGet($baseUrl . '/models?key=' . urlencode($rawKey));
        } else {
            $resp = $this->httpGet($baseUrl . '/models', ['Authorization: Bearer ' . $rawKey]);
        }
        $latency = (int)round((microtime(true) - $start) * 1000);

        if ($type === 'anthropic') {
            // 401/403 视为 Key 被拒；其它 2xx/4xx（含 404 模型未知）视为端点可达、Key 有效
            $ok = $resp['code'] >= 200 && $resp['code'] < 500 && $resp['code'] !== 401 && $resp['code'] !== 403;
        } else {
            $ok = $resp['code'] >= 200 && $resp['code'] < 300;
        }

        $detail = ($resp['ok'] === false && $resp['error'] !== '')
            ? $resp['error']
            : 'http ' . $resp['code'];

        $this->speedTests->insert([
            'provider_id' => $providerId,
            'model' => $type === 'anthropic' ? $this->defaultModel($providerId) : '',
            'endpoint' => $type === 'anthropic' ? '/messages' : '/models',
            'latency_ms' => $latency,
            'success' => $ok ? 1 : 0,
            'error' => mb_substr($detail, 0, 500),
        ]);

        return [
            'provider_id' => $providerId,
            'provider' => $type,
            'upstream_key_id' => $keyId,
            'ok' => $ok,
            'latency_ms' => $latency,
            'http_code' => $resp['code'],
            'detail' => $detail,
        ];
    }

    private function defaultModel(int $providerId): string
    {
        $row = $this->db->fetchOne(
            'SELECT upstream_model FROM model_map WHERE provider = (SELECT name FROM providers WHERE id = ?) LIMIT 1',
            [$providerId]
        );
        return (string)($row['upstream_model'] ?? 'claude-3-5-sonnet-20240620');
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
