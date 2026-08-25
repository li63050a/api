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
        // 同一模型名可挂在多把密钥下：取全部启用行，转发时在这些密钥中挑健康的一把
        $maps = $this->maps->findAllEnabledByAlias($model);
        if ($maps !== []) {
            $resolved = $this->resolveMap($maps[0]);
            $resolved['candidate_key_ids'] = array_values(array_unique(array_map(
                'intval',
                array_column($maps, 'key_id')
            )));
            $request->setAttribute('model_map', $resolved);
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
            'candidate_key_ids' => [],
        ]);
    }

    /** 将 provider 路由信息并入 model_map，供 AbstractProvider::forward/buildUrl 使用 */
    private function resolveMap(array $map): array
    {
        $provider = $this->providers->findByName((string)($map['provider'] ?? ''));
        $family = $provider !== null
            ? (string)($provider['client_format'] ?? 'openai')
            : (string)($map['client_format'] ?? 'openai');
        if (!in_array($family, self::FAMILIES, true)) {
            $family = 'openai';
        }
        return array_merge($map, [
            'provider' => $family,
            'provider_id' => $provider === null ? 0 : (int)$provider['id'],
            'base_url' => $provider === null ? '' : (string)($provider['base_url'] ?? ''),
            'timeout' => $provider === null ? 0 : (int)($provider['timeout'] ?? 0),
        ]);
    }
}
