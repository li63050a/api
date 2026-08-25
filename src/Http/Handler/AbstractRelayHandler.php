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

        $this->quota->assertWithinQuota($auth['user'], 'daily');
        $this->quota->assertWithinQuota($auth['user'], 'monthly');

        $provider = $this->factory->make((string)$map['provider']);
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
                'user_id' => (int)$auth['user']['id'],
                'api_key_id' => (int)$auth['key']['id'],
                'provider' => (string)$map['provider'],
                'model' => (string)$map['alias'],
                'endpoint' => $this->endpoint(),
                'client_format' => $clientFormat,
                'status' => 0,
                'error' => $e->getMessage(),
                'latency_ms' => (int)((microtime(true) - $start) * 1000),
                'ip' => $request->clientIp(),
            ]);
            throw $e;
        }

        $usage = is_array($result) ? ($result['usage'] ?? null) : null;
        $this->billing->record(
            $auth['user'],
            $auth['key'],
            (string)$map['provider'],
            (string)$map['alias'],
            is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
        );
        $this->logger->record([
            'user_id' => (int)$auth['user']['id'],
            'api_key_id' => (int)$auth['key']['id'],
            'provider' => (string)$map['provider'],
            'model' => (string)$map['alias'],
            'endpoint' => $this->endpoint(),
            'client_format' => $clientFormat,
            'status' => 1,
            'prompt_tokens' => is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            'completion_tokens' => is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
            'total_tokens' => is_array($usage) ? (int)($usage['total_tokens'] ?? 0) : 0,
            'latency_ms' => (int)((microtime(true) - $start) * 1000),
            'ip' => $request->clientIp(),
        ]);

        return is_array($result) ? \App\Http\Response::json($result) : null;
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
