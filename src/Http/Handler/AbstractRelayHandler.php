<?php
declare(strict_types=1);

namespace App\Http\Handler;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\QuotaService;
use App\Domain\Logger\RequestLogger;
use App\Domain\Provider\ProviderFactory;
use App\Http\Request;
use App\Support\Exception\HttpException;

abstract class AbstractRelayHandler
{
    public function __construct(
        protected ProviderFactory $factory,
        protected BillingService $billing,
        protected QuotaService $quota,
        protected RequestLogger $logger,
    ) {}

    abstract protected function endpoint(): string;

    /** 端点类型：chat 或 embeddings（对应 ProviderInterface::endpoints() 的键） */
    protected function endpointType(): string
    {
        return 'chat';
    }

    public function __invoke(Request $request): ?\App\Http\Response
    {
        $auth = $request->attribute('auth');
        if (!is_array($auth)) {
            throw new HttpException('Unauthorized', 401, 'invalid_request_error');
        }
        $map = $request->attribute('model_map');
        $clientFormat = (string)$request->attribute('client_format', 'openai');
        $payload = $request->json();
        $key = $auth['key'];
        if (is_array($map)) {
            $map['preferred_key_id'] = (int)$request->attribute('preferred_key_id', 0);
        }

        $this->assertModelAllowed($key, $map);
        $this->quota->assertWithinQuota($key, 'daily');
        $this->quota->assertWithinQuota($key, 'monthly');

        try {
            $provider = $this->factory->make((string)$map['provider']);
        } catch (\RuntimeException $e) {
            throw new HttpException(
                'Provider not available for model: ' . ($map['alias'] ?? 'unknown') . ' (' . $e->getMessage() . ')',
                503,
                'provider_unavailable'
            );
        }
        if (!$provider) {
            throw new HttpException(
                'Provider not available for model: ' . ($map['alias'] ?? 'unknown'),
                503,
                'provider_unavailable'
            );
        }
        $start = microtime(true);

        try {
            $result = $provider->forward(
                $map,
                $payload,
                $clientFormat,
                $this->endpointType(),
                $this->streamCallback($request),
            );
        } catch (HttpException $e) {
            $this->logger->record([
                'user_id' => (int)($key['user_id'] ?? 0),
                'api_key_id' => (int)$key['id'],
                'provider' => (string)$map['provider'],
                'model' => (string)$map['alias'],
                'endpoint' => $this->endpoint(),
                'client_format' => $clientFormat,
                'status' => $e->status(),
                'error' => $e->getMessage(),
                'latency_ms' => (int)((microtime(true) - $start) * 1000),
                'ip' => $request->clientIp(),
            ]);
            throw $e;
        }

        $usage = is_array($result) ? ($result['usage'] ?? null) : null;
        $this->billing->record(
            $key,
            (string)$map['provider'],
            (string)$map['alias'],
            is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
            (float)($map['prompt_price'] ?? 0),
            (float)($map['completion_price'] ?? 0),
        );
        $this->logger->record([
            'user_id' => (int)($key['user_id'] ?? 0),
            'api_key_id' => (int)$key['id'],
            'provider' => (string)$map['provider'],
            'model' => (string)$map['alias'],
            'endpoint' => $this->endpoint(),
            'client_format' => $clientFormat,
            'status' => 200,
            'prompt_tokens' => is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            'completion_tokens' => is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
            'total_tokens' => is_array($usage) ? (int)($usage['total_tokens'] ?? 0) : 0,
            'latency_ms' => (int)((microtime(true) - $start) * 1000),
            'ip' => $request->clientIp(),
        ]);

        return is_array($result) ? \App\Http\Response::json($result) : null;
    }

    /** 模型白名单校验：key.allowed_models 为空或命中 alias 时放行 */
    private function assertModelAllowed(array $key, mixed $map): void
    {
        $allowed = trim((string)($key['allowed_models'] ?? ''));
        if ($allowed === '') {
            return;
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $allowed)), static fn (string $m) => $m !== ''));
        if ($list !== [] && (is_array($map) && !in_array((string)$map['alias'], $list, true))) {
            throw new HttpException('model not allowed for this key', 403, 'model_not_allowed');
        }
    }

    /** 流式回调：非流式请求返回 null；由子类/Provider 直接写 SSE */
    protected function streamCallback(Request $request): ?callable
    {
        $payload = $request->json();
        if (empty($payload['stream'])) {
            return null;
        }
        return static function (string $chunk): void {
            echo $chunk;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
    }
}
