<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use App\Support\Exception\HttpException;
use RuntimeException;

abstract class AbstractProvider implements ProviderInterface
{
    protected const TIMEOUT = 60;

    /** buildUrl 时记录的上游超时（秒），0 表示未指定 */
    protected int $curlTimeout = 0;

    public function __construct(
        protected Config $config,
        protected CryptoService $crypto,
        protected KeyPool $pool,
    ) {}

    /**
     * 执行一次转发；$clientFormat 为客户端格式（openai/anthropic/gemini）。
     * $endpointType ∈ {chat, embeddings}。
     * 非流式返回 openai 兼容响应数组；流式时通过 $onChunk 回写并返回 null。
     */
    public function forward(
        array $model,
        array $payload,
        string $clientFormat,
        string $endpointType = 'chat',
        ?callable $onChunk = null,
    ): ?array {
        $endpoint = $this->endpoints()[$endpointType] ?? $this->endpoints()['chat'];
        $attempts = max(1, (int)$this->config->get('provider_max_retries', 1) + 1);
        $lastError = '';

        for ($i = 0; $i < $attempts; $i++) {
            $upstream = $this->pool->pick((int)$model['provider_id'], (int)($model['preferred_key_id'] ?? 0));
            if ($upstream === null) {
                $ctx = 'provider=' . (string)($model['provider'] ?? '')
                    . ', provider_id=' . (int)($model['provider_id'] ?? 0)
                    . ', model=' . (string)($model['alias'] ?? '');
                throw new HttpException(
                    '暂无可用的上游密钥（' . $ctx . '），请确认该供应商已配置 API Key 且未被熔断',
                    503,
                    'no_available_upstream'
                );
            }
            $keyValue = $this->decryptUpstreamKey((string)$upstream['key_value']);
            // $model 为完整 model_map 行（含 base_url/upstream_model/timeout），必须整体传入 buildUrl
            $url = $this->buildUrl($model, $endpoint, $payload, $clientFormat);
            $body = $this->convertRequest($payload, $clientFormat);

            try {
                $result = $this->curlOnce($url, $keyValue, $body, $onChunk);
                $this->pool->markSuccess((int)$upstream['id']);
                return $result; // 成功即返回；流式时 $onChunk 已写，$result 为 usage 数组或 null
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->pool->markFailure((int)$upstream['id']);
                if ($onChunk !== null) {
                    // 流式已开始则无法安全重试，直接抛给上层
                    throw new HttpException($this->friendlyError($lastError), 502, 'upstream_error');
                }
            }
        }

        throw new HttpException($this->friendlyError($lastError), 502, 'upstream_error');
    }

    /** 客户端格式 → openai → 上游原生格式 */
    public function convertRequest(array $payload, string $clientFormat): array
    {
        $openai = Formatter::toOpenAI($payload, $clientFormat);
        return Formatter::fromOpenAI($openai, $this->nativeFormat());
    }

    /** 上游原生格式名（openai/anthropic/gemini） */
    abstract protected function nativeFormat(): string;

    /** 单次 curl；$onChunk 非空时启用流式直出 */
    abstract protected function curlOnce(
        string $url,
        string $apiKey,
        array $body,
        ?callable $onChunk,
    ): ?array;

    /** 执行 POST 并返回 openai 兼容数组；流式时返回 null；上游错误抛 RuntimeException(原始文本) */
    protected function post(string $url, array $headers, array $body, ?callable $onChunk): ?array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $payload = $json === false ? '{}' : $json;
        [$code, $result, $errno, $error] = $this->httpCall($url, $headers, $payload, $this->effectiveTimeout(), $onChunk);
        if ($errno !== 0 || $code < 200 || $code >= 300) {
            $raw = (is_string($result) && $result !== '') ? $result : ($error !== '' ? $error : 'http ' . $code);
            throw new RuntimeException($raw);
        }
        if ($onChunk !== null) {
            return null;
        }
        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string)$result];
        }
        return $this->convertResponse($decoded);
    }

    /** 原始 curl 执行；返回 [httpCode, result, errno, error] */
    protected function httpCall(string $url, array $headers, string $body, int $timeout, ?callable $onChunk): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $onChunk === null);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $sslVerify = (bool)$this->config->get('upstream_ssl_verify', true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        if ($onChunk !== null) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use ($onChunk): int {
                $onChunk($data);
                return strlen($data);
            });
        }
        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [$httpCode, $result, $errno, $error];
    }

    protected function effectiveTimeout(): int
    {
        if ($this->curlTimeout > 0) {
            return $this->curlTimeout;
        }
        $cfg = (int)$this->config->get('upstream_timeout', self::TIMEOUT);
        return $cfg > 0 ? $cfg : self::TIMEOUT;
    }

    /** 解析上游 JSON error：error.message / error.error.message / error；未知时返回原始截断文本 */
    protected function parseError(string $raw): string
    {
        $trimmed = trim($raw);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $msg = $decoded['error']['message'] ?? $decoded['error']['error']['message'] ?? $decoded['error'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return mb_substr($msg, 0, 500);
            }
        }
        return $trimmed === '' ? 'upstream error' : mb_substr($trimmed, 0, 500);
    }

    protected function decryptUpstreamKey(string $stored): string
    {
        if (!str_starts_with($stored, 'enc:')) {
            return $stored;
        }
        try {
            return $this->crypto->decrypt(substr($stored, 4));
        } catch (\Throwable $e) {
            throw new HttpException(
                '上游密钥解密失败：' . $e->getMessage()
                . '（config.php 的 crypto_key 可能已被更改，与已存储的密钥不匹配）',
                503,
                'upstream_key_decrypt_failed'
            );
        }
    }
}
