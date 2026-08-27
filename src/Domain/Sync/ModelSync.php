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

    /** @return array{id:int, name:string, ok:bool, count:int, note:string, error:string, tested?:int, failed?:int, disabled?:int, details?:array<int,array<string,mixed>>} */
    public function syncProvider(int $providerId, bool $autoDisable = false): array
    {
        $provider = $this->providers->find($providerId);
        if ($provider === null) {
            return ['id' => $providerId, 'name' => '', 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'provider not found'];
        }
        $name = (string)($provider['name'] ?? '');
        $fmt = (string)($provider['client_format'] ?? 'openai');
        $baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');

        $keys = $this->upstreamKeys->byProvider($providerId);
        if ($keys === []) {
            return ['id' => $providerId, 'name' => $name, 'ok' => false, 'count' => 0, 'note' => '', 'error' => 'no available upstream key'];
        }
        $rawKey = $this->decryptKey((string)$keys[0]['key_value']);

        if ($fmt === 'anthropic') {
            return [
                'id' => $providerId,
                'name' => $name,
                'ok' => true,
                'count' => 0,
                'note' => 'Anthropic 无公开模型列表接口，跳过自动同步，请手动维护 model_map。',
                'error' => '',
            ];
        }

        if ($fmt === 'gemini') {
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
        $tested = 0;
        $failed = 0;
        $disabled = 0;
        $details = [];
        foreach ($fetched as $id) {
            if ($this->modelMap->findByAlias($id) !== null) {
                continue; // 已存在则跳过，避免覆盖手动维护的配置
            }
            $this->modelMap->create([
                'alias' => $id,
                'provider' => $name,
                'upstream_model' => $id,
                'client_format' => $fmt,
                'enabled' => 1, // 同步默认全部启用，由管理员或自动检测停用
            ]);
            $count++;
            if ($autoDisable) {
                $tested++;
                $probe = $this->probeModelHttp($baseUrl, $fmt, $rawKey, $id);
                if (!$probe['ok']) {
                    $failed++;
                    $row = $this->modelMap->findByAlias($id);
                    if ($row !== null) {
                        $this->modelMap->update((int)$row['id'], ['enabled' => 0]);
                        $disabled++;
                    }
                    $details[] = ['model' => $id, 'ok' => false, 'latency_ms' => $probe['latency_ms'], 'error' => $probe['error']];
                } else {
                    $details[] = ['model' => $id, 'ok' => true, 'latency_ms' => $probe['latency_ms'], 'error' => ''];
                }
            }
        }

        $note = $count > 0 ? "同步 {$count} 个模型" : '无新增（已存在）';
        if ($autoDisable) {
            $note .= "，检测 {$tested} 个，不可用 {$disabled} 个已自动禁用";
        }
        return [
            'id' => $providerId,
            'name' => $name,
            'ok' => true,
            'count' => $count,
            'note' => $note,
            'error' => '',
            'tested' => $tested,
            'failed' => $failed,
            'disabled' => $disabled,
            'details' => $details,
        ];
    }

    /** @return array<int, array{id:int, name:string, ok:bool, count:int, note:string, error:string}> */
    public function syncAll(bool $autoDisable = false): array
    {
        $results = [];
        foreach ($this->providers->all() as $provider) {
            $results[] = $this->syncProvider((int)$provider['id'], $autoDisable);
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

    /** 同步时检测单个模型是否可用；返回 {ok, code, latency_ms, error} */
    private function probeModelHttp(string $baseUrl, string $fmt, string $rawKey, string $upstreamModel): array
    {
        $sslVerify = (bool)$this->config->get('upstream_ssl_verify', true);
        if ($fmt === 'anthropic') {
            $url = $baseUrl . '/v1/messages';
            $headers = ['x-api-key: ' . $rawKey, 'anthropic-version: 2023-06-01', 'content-type: application/json'];
            $body = ['model' => $upstreamModel, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'hi']]];
        } elseif ($fmt === 'gemini') {
            $url = $baseUrl . '/v1beta/models/' . urlencode($upstreamModel) . ':generateContent?key=' . urlencode($rawKey);
            $headers = ['content-type: application/json'];
            $body = ['contents' => [['parts' => [['text' => 'hi']]]]];
        } else {
            $url = $baseUrl . '/v1/chat/completions';
            $headers = ['content-type: application/json', 'Authorization: Bearer ' . $rawKey];
            $body = ['model' => $upstreamModel, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'hi']]];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => (int)$this->config->get('upstream_timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $start = microtime(true);
        $result = curl_exec($ch);
        $latency = (int)round((microtime(true) - $start) * 1000);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            return ['ok' => false, 'code' => 0, 'latency_ms' => $latency, 'error' => 'curl error: ' . $err];
        }
        $ok = $code >= 200 && $code < 300;
        return [
            'ok' => $ok,
            'code' => $code,
            'latency_ms' => $latency,
            'error' => $ok ? '' : mb_substr((string)$result, 0, 300),
        ];
    }

    /** @return array{ok:bool, detail:string, body:string} */
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
            return ['ok' => false, 'detail' => 'curl error: ' . $err, 'body' => ''];
        }
        return ['ok' => $code >= 200 && $code < 300, 'detail' => 'http ' . $code, 'body' => (string)$body];
    }
}
