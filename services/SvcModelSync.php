<?php
/**
 * 模型同步服务（新）：从上游供应商拉取模型列表写入 model_map。
 *
 * 三家供应商 list 端点差异：
 *  - openai：GET {base_url}/models，Bearer 鉴权（从 upstream_keys 取一条解密 Key）
 *  - gemini：GET {base_url}/models?key={KEY}，取每个 model.name 并去掉 "models/" 前缀
 *  - anthropic：无公开 list 接口，返回空并附友好说明
 */
class SvcModelSync
{
    public function syncProvider(int $providerId): array
    {
        $db = db();
        $provider = db_fetch($db, "SELECT * FROM providers WHERE id = ?", [$providerId]);
        if ($provider === null) {
            return ['ok' => false, 'error' => 'provider not found', 'models' => []];
        }

        $name = strtolower((string) $provider['name']);
        $baseUrl = rtrim((string) $provider['base_url'], '/');

        $keyRow = db_fetch(
            $db,
            "SELECT * FROM upstream_keys WHERE provider_id = ? AND status = 1 ORDER BY id ASC LIMIT 1",
            [$providerId]
        );
        if ($keyRow === null) {
            return ['ok' => false, 'error' => 'no available upstream key', 'models' => []];
        }
        $rawKey = crypto_decrypt((string) $keyRow['key_value']);

        if ($name === 'anthropic') {
            return [
                'ok' => true,
                'provider' => $name,
                'note' => 'Anthropic 无公开模型列表接口，跳过自动同步，请手动维护 model_map。',
                'models' => [],
            ];
        }

        if ($name === 'gemini') {
            $resp = $this->httpGet($baseUrl . '/models?key=' . urlencode($rawKey), []);
            if (!$resp['ok']) {
                return ['ok' => false, 'error' => $resp['detail'], 'models' => []];
            }
            $data = json_decode($resp['body'], true) ?: [];
            $fetched = [];
            foreach (($data['models'] ?? []) as $m) {
                $full = (string) ($m['name'] ?? '');
                $id = preg_replace('#^models/#', '', $full);
                if ($id !== '') {
                    $fetched[] = $id;
                }
            }
        } else {
            // openai 及其它 Bearer 风格
            $resp = $this->httpGet($baseUrl . '/models', ['Authorization: Bearer ' . $rawKey]);
            if (!$resp['ok']) {
                return ['ok' => false, 'error' => $resp['detail'], 'models' => []];
            }
            $data = json_decode($resp['body'], true) ?: [];
            $fetched = [];
            foreach (($data['data'] ?? []) as $m) {
                $id = (string) ($m['id'] ?? '');
                if ($id !== '') {
                    $fetched[] = $id;
                }
            }
        }

        $now = time();
        $synced = [];
        foreach ($fetched as $id) {
            $existing = db_fetch($db, "SELECT id FROM model_map WHERE alias = ? LIMIT 1", [$id]);
            if ($existing !== null) {
                db_update($db, 'model_map', ['fetched_at' => $now], ['id' => $existing['id']]);
            } else {
                db_insert($db, 'model_map', [
                    'alias' => $id,
                    'provider_id' => $providerId,
                    'upstream_model' => $id,
                    'price_input' => 0,
                    'price_output' => 0,
                    'price_per_request' => 0,
                    'cacheable' => 0,
                    'status' => 0,
                    'source' => 'auto',
                    'fetched_at' => $now,
                ]);
            }
            $synced[] = ['alias' => $id, 'upstream_model' => $id, 'provider_id' => $providerId];
        }

        return [
            'ok' => true,
            'provider' => $name,
            'count' => count($synced),
            'models' => $synced,
        ];
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
            return ['ok' => false, 'detail' => 'curl error: ' . $err, 'body' => ''];
        }
        return ['ok' => $code >= 200 && $code < 300, 'detail' => 'http ' . $code, 'body' => (string) $body];
    }
}
