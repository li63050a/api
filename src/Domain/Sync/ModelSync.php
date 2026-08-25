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
 * 模型同步：从上游供应商拉取模型列表写入 model_map。
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

    /** @return array{id:int, name:string, ok:bool, count:int, note:string, error:string} */
    public function syncProvider(int $providerId): array
    {
        $provider = $this->providers->find($providerId);
        if ($provider === null) {
            return ['id' => $providerId, 'name' => '', 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'provider not found'];
        }
        $name = (string)($provider['name'] ?? '');
        $type = strtolower($name);
        $baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');

        $keys = $this->upstreamKeys->byProvider($providerId);
        if ($keys === []) {
            return ['id' => $providerId, 'name' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'no available upstream key'];
        }
        $rawKey = $this->decryptKey((string)$keys[0]['key_value']);

        if ($type === 'anthropic') {
            return [
                'id' => $providerId,
                'name' => $name,
                'ok' => true,
                'count' => 0,
                'note' => 'Anthropic 无公开模型列表接口，跳过自动同步，请手动维护 model_map。',
                'error' => '',
            ];
        }

        if ($type === 'gemini') {
            $resp = $this->httpGet($baseUrl . '/models?key=' . urlencode($rawKey));
            if (!$resp['ok']) {
                return ['id' => $providerId, 'name' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => $resp['detail']];
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
                return ['id' => $providerId, 'name' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => $resp['detail']];
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
            if ($this->modelMap->findByAlias($id) !== null) {
                continue; // 已存在则跳过，避免覆盖手动维护的配置
            }
            $this->modelMap->create([
                'alias' => $id,
                'provider' => $name,
                'upstream_model' => $id,
                'client_format' => 'openai',
                'enabled' => 0, // 自动同步默认停用，由管理员启用
            ]);
            $count++;
        }

        return [
            'id' => $providerId,
            'name' => $name,
            'ok' => true,
            'count' => $count,
            'note' => $count > 0 ? "同步 {$count} 个模型" : '无新增（已存在）',
            'error' => '',
        ];
    }

    /** @return array<int, array{id:int, name:string, ok:bool, count:int, note:string, error:string}> */
    public function syncAll(): array
    {
        $results = [];
        foreach ($this->providers->all() as $provider) {
            $results[] = $this->syncProvider((int)$provider['id']);
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
