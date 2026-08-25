<?php
declare(strict_types=1);

namespace App\Domain\Sync;

use App\Db\Database;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Domain\Crypto\CryptoService;
use App\Support\Config;

/**
 * 模型同步：按"密钥"逐把拉取上游模型列表，写入各自 key_id 下的 model_map。
 *
 * 同一模型名（alias）可挂在多把密钥下，互不覆盖；同步结果按密钥返回。
 *
 * 三家供应商 list 端点差异：
 *  - openai：GET {base_url}/models，Bearer 鉴权
 *  - gemini：GET {base_url}/models?key={KEY}，取每个 model.name 并去掉 "models/" 前缀
 *  - anthropic：无公开 list 接口，返回空并附友好说明
 */
final class ModelSync
{
    public function __construct(
        private Database $db,
        private ProviderRepository $providers,
        private UpstreamKeyRepository $upstreamKeys,
        private ModelMapRepository $modelMap,
        private CryptoService $crypto,
        private Config $config,
    ) {}

    /** @return array{id:int, key_id:int, provider:string, ok:bool, count:int, note:string, error:string} */
    public function syncKey(int $keyId): array
    {
        $key = $this->upstreamKeys->find($keyId);
        if ($key === null) {
            return ['id' => $keyId, 'key_id' => $keyId, 'provider' => '', 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'upstream key not found'];
        }
        $provider = $this->providers->find((int)$key['provider_id']);
        if ($provider === null) {
            return ['id' => $keyId, 'key_id' => $keyId, 'provider' => '', 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'provider not found'];
        }
        $name = (string)($provider['name'] ?? '');
        $fmt = (string)($provider['client_format'] ?? 'openai');
        $baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');
        $rawKey = $this->decryptKey((string)$key['key_value']);

        if ($fmt === 'anthropic') {
            return [
                'id' => $keyId,
                'key_id' => $keyId,
                'provider' => $name,
                'ok' => true,
                'count' => 0,
                'note' => 'Anthropic 无公开模型列表接口，跳过自动同步，请手动维护模型。',
                'error' => '',
            ];
        }

        if ($fmt === 'gemini') {
            $resp = $this->httpGet($baseUrl . '/models?key=' . urlencode($rawKey));
            if (!$resp['ok']) {
                return ['id' => $keyId, 'key_id' => $keyId, 'provider' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => $resp['detail']];
            }
            $data = json_decode($resp['body'], true) ?: [];
            $fetched = [];
            foreach (($data['models'] ?? []) as $m) {
                $full = (string)($m['name'] ?? '');
                $id = preg_replace('#^models/#', '', $full);
                if ($id !== '' && $id !== null) {
                    $fetched[] = $id;
                }
            }
        } else {
            // openai 及其它 Bearer 风格
            $resp = $this->httpGet($baseUrl . '/models', ['Authorization: Bearer ' . $rawKey]);
            if (!$resp['ok']) {
                return ['id' => $keyId, 'key_id' => $keyId, 'provider' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => $resp['detail']];
            }
            $data = json_decode($resp['body'], true) ?: [];
            $fetched = [];
            foreach (($data['data'] ?? []) as $m) {
                $id = (string)($m['id'] ?? '');
                if ($id !== '') {
                    $fetched[] = $id;
                }
            }
        }

        $count = 0;
        foreach ($fetched as $id) {
            if ($this->modelMap->findByAliasAndKeyId($id, $keyId) !== null) {
                continue; // 该密钥下已存在则跳过，避免覆盖手动维护的配置
            }
            $this->modelMap->create([
                'alias' => $id,
                'provider' => $name,
                'key_id' => $keyId, // 关联当前密钥
                'upstream_model' => $id,
                'client_format' => $fmt,
                'enabled' => 0, // 自动同步默认停用，由管理员启用
            ]);
            $count++;
        }

        return [
            'id' => $keyId,
            'key_id' => $keyId,
            'provider' => $name,
            'ok' => true,
            'count' => $count,
            'note' => $count > 0 ? "同步 {$count} 个模型" : '无新增（该密钥下已存在）',
            'error' => '',
        ];
    }

    /** 同步某供应商下的全部密钥。 */
    public function syncProvider(int $providerId): array
    {
        $results = [];
        foreach ($this->upstreamKeys->byProvider($providerId) as $key) {
            $results[] = $this->syncKey((int)$key['id']);
        }
        return $results;
    }

    /** 同步全部密钥。 */
    public function syncAll(): array
    {
        $results = [];
        foreach ($this->db->fetchAll('SELECT id FROM upstream_keys ORDER BY id ASC') as $row) {
            $results[] = $this->syncKey((int)$row['id']);
        }
        return $results;
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

    /** @return array{ok:bool, detail:string, body:string} */
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
            return ['ok' => false, 'detail' => 'curl error: ' . $err, 'body' => ''];
        }
        return ['ok' => $code >= 200 && $code < 300, 'detail' => 'http ' . $code, 'body' => (string)$body];
    }
}
