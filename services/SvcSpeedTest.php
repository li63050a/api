<?php
/**
 * 上游测速服务（新）：遍历 providers 及其 upstream_keys，对每个 Key 做轻量探测。
 *
 * 探测方式差异：
 *  - openai：GET {base_url}/models（Bearer）
 *  - gemini：GET {base_url}/models?key={KEY}
 *  - anthropic：极短 POST {base_url}/messages（max_tokens 很小）
 * 结果写入 speedtest_log，并返回每个 key 的可用性/延迟数组。
 */
class SvcSpeedTest
{
    public function testAll(): array
    {
        $db = db();
        $providers = db_fetchall($db, "SELECT * FROM providers WHERE status = 1");
        $results = [];
        foreach ($providers as $p) {
            $keys = db_fetchall(
                $db,
                "SELECT * FROM upstream_keys WHERE provider_id = ? AND status = 1",
                [$p['id']]
            );
            foreach ($keys as $k) {
                $results[] = $this->probe($p, $k);
            }
        }
        return $results;
    }

    private function probe(array $provider, array $keyRow): array
    {
        $db = db();
        $name = strtolower((string) $provider['name']);
        $baseUrl = rtrim((string) $provider['base_url'], '/');
        $keyId = (int) $keyRow['id'];
        $rawKey = crypto_decrypt((string) $keyRow['key_value']);

        $start = microtime(true);
        if ($name === 'anthropic') {
            $resp = $this->httpPostJson(
                $baseUrl . '/messages',
                [
                    'x-api-key: ' . $rawKey,
                    'anthropic-version: 2023-06-01',
                    'content-type: application/json',
                ],
                [
                    'model' => $this->defaultModel($db, (int) $provider['id']),
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]
            );
        } elseif ($name === 'gemini') {
            $resp = $this->httpGet($baseUrl . '/models?key=' . urlencode($rawKey), []);
        } else {
            $resp = $this->httpGet($baseUrl . '/models', ['Authorization: Bearer ' . $rawKey]);
        }
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($name === 'anthropic') {
            // 401/403 视为 Key 被拒；其它 2xx/4xx（含 404 模型未知）视为端点可达、Key 有效
            $ok = $resp['code'] >= 200 && $resp['code'] < 500 && $resp['code'] !== 401 && $resp['code'] !== 403;
        } else {
            $ok = $resp['code'] >= 200 && $resp['code'] < 300;
        }

        $detail = ($resp['ok'] === false && isset($resp['error']))
            ? $resp['error']
            : 'http ' . $resp['code'];

        db_insert($db, 'speedtest_log', [
            'provider_id' => (int) $provider['id'],
            'upstream_key_id' => $keyId,
            'ok' => $ok ? 1 : 0,
            'latency_ms' => $latency,
            'detail' => mb_substr($detail, 0, 500),
            'created_at' => time(),
        ]);

        return [
            'provider_id' => (int) $provider['id'],
            'provider' => $name,
            'upstream_key_id' => $keyId,
            'ok' => $ok,
            'latency_ms' => $latency,
            'http_code' => $resp['code'],
            'detail' => $detail,
        ];
    }

    private function defaultModel($db, int $providerId): string
    {
        $row = db_fetch($db, "SELECT upstream_model FROM model_map WHERE provider_id = ? LIMIT 1", [$providerId]);
        return $row['upstream_model'] ?? 'claude-3-5-sonnet-20240620';
    }

    private function httpGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) (config('upstream_timeout') ?: 30),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'curl error: ' . $err, 'code' => 0];
        }
        return ['ok' => true, 'code' => $code, 'body' => (string) $body];
    }

    private function httpPostJson(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int) (config('upstream_timeout') ?: 30),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'curl error: ' . $err, 'code' => 0];
        }
        return ['ok' => true, 'code' => $code, 'body' => (string) $body];
    }
}
