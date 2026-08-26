<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class ModelAlias implements MiddlewareInterface
{
    private const FAMILIES = ['openai', 'anthropic', 'gemini'];

    public function __construct(
        private ModelMapRepository $maps,
        private ProviderRepository $providers,
    ) {}

    public function process(Request $request): void
    {
        $payload = $request->json();
        $model = (string)($payload['model'] ?? '');
        if ($model === '') {
            throw new HttpException('Missing model', 400, 'invalid_request_error');
        }
        $map = $this->maps->findEnabledByAlias($model);
        if ($map !== null) {
            $request->setAttribute('model_map', $this->resolveMap($map));
            return;
        }
        // 兜底：直连同名模型（openai 格式）
        $provider = $this->providers->findByName('openai');
        $request->setAttribute('model_map', [
            'alias' => $model,
            'provider' => 'openai',
            'provider_id' => $provider === null ? 0 : (int)$provider['id'],
            'upstream_model' => $model,
            'client_format' => $request->attribute('client_format', 'openai'),
            'base_url' => $provider === null ? '' : (string)($provider['base_url'] ?? ''),
            'timeout' => $provider === null ? 0 : (int)($provider['timeout'] ?? 0),
            'enabled' => 1,
        ]);
    }

    /** 将 provider 路由信息并入 model_map，供 AbstractProvider::forward/buildUrl 使用 */
    private function resolveMap(array $map): array
    {
        $providerName = (string)($map['provider'] ?? '');
        $provider = $this->providers->findByName($providerName);
        if ($provider === null) {
            throw new HttpException(
                '模型映射引用的供应商不存在：' . $providerName
                . '（请在后台核对模型映射的 provider 与供应商 name 是否完全一致，区分大小写）',
                503,
                'provider_not_found'
            );
        }
        $baseUrl = trim((string)($provider['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new HttpException(
                '供应商 ' . $providerName . ' 未配置 base_url，无法转发请求',
                503,
                'provider_misconfigured'
            );
        }
        $family = (string)($provider['client_format'] ?? 'openai');
        if (!in_array($family, self::FAMILIES, true)) {
            $family = 'openai';
        }
        return array_merge($map, [
            'provider' => $family,
            'provider_id' => (int)$provider['id'],
            'base_url' => (string)($provider['base_url'] ?? ''),
            'timeout' => (int)($provider['timeout'] ?? 0),
        ]);
    }
}
