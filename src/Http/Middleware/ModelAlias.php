<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\SpeedTestRepository;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Config;
use App\Support\Exception\HttpException;

final class ModelAlias implements MiddlewareInterface
{
    private const FAMILIES = ['openai', 'anthropic', 'gemini'];

    public function __construct(
        private ModelMapRepository $maps,
        private ProviderRepository $providers,
        private SpeedTestRepository $speedTests,
        private Config $config,
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
        // 路由模型：provider/model、all（最快）、单供应商（最快）
        $route = $this->resolveRouteModel($model);
        if ($route !== null) {
            $request->setAttribute('model_map', $route);
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

    /**
     * 路由模型解析（仿 new-api 模型路由）：
     *  - provider/model    ：精确路由到该供应商的模型（无需 model_map 行）
     *  - provider/*        ：该供应商下延迟最低的启用模型
     *  - provider（供应商名）：同上，最快
     *  - all（可配置别名） ：全部供应商中延迟最低的启用模型
     *
     * @return array<string, mixed>|null 已并入 provider 路由信息的 model_map 结构
     */
    private function resolveRouteModel(string $model): ?array
    {
        // 1) provider/model 精确路由
        if (str_contains($model, '/')) {
            [$name, $upstream] = explode('/', $model, 2);
            $name = trim($name);
            $upstream = trim($upstream);
            $provider = $name !== '' ? $this->providers->findByName($name) : null;
            if ($provider !== null) {
                if ($upstream === '' || $upstream === '*') {
                    return $this->fastestRoute((string)$provider['name']);
                }
                // 优先命中已配置的 model_map 行（保留其 client_format 等）
                $map = $this->maps->findEnabledByProviderAndModel($name, $upstream);
                if ($map !== null) {
                    return $this->resolveMap($map);
                }
                // 无 model_map 行则直接路由到该供应商模型
                if (trim((string)($provider['base_url'] ?? '')) !== '') {
                    $family = (string)($provider['client_format'] ?? 'openai');
                    if (!in_array($family, self::FAMILIES, true)) {
                        $family = 'openai';
                    }
                    return [
                        'alias' => $model,
                        'provider' => $family,
                        'provider_id' => (int)$provider['id'],
                        'upstream_model' => $upstream,
                        'client_format' => $family,
                        'base_url' => (string)($provider['base_url'] ?? ''),
                        'timeout' => (int)($provider['timeout'] ?? 0),
                        'enabled' => 1,
                    ];
                }
            }
        }
        // 2) all → 全部中最快
        $allAlias = (string)$this->config->get('route_all_alias', 'all');
        if (strtolower($model) === strtolower($allAlias)) {
            return $this->fastestRoute();
        }
        // 3) 供应商名 → 该供应商中最快
        $provider = $this->providers->findByName($model);
        if ($provider !== null) {
            return $this->fastestRoute((string)$provider['name']);
        }
        return null;
    }

    /** 取启用模型中近期测速延迟最低者；无测速数据时按 id 升序取最先 */
    private function fastestRoute(?string $providerName = null): ?array
    {
        $rows = $providerName !== null
            ? $this->maps->allEnabledByProvider($providerName)
            : $this->maps->allEnabled();
        if ($rows === []) {
            return null;
        }
        $pidByName = [];
        foreach ($this->providers->all() as $p) {
            $pidByName[(string)$p['name']] = (int)$p['id'];
        }
        // 过滤掉引用已删除供应商的脏行
        $rows = array_values(array_filter($rows, static fn (array $r) => isset($pidByName[(string)($r['provider'] ?? '')])));
        if ($rows === []) {
            return null;
        }
        $latency = [];
        foreach ($this->speedTests->recentSuccess() as $s) {
            $key = (int)$s['provider_id'] . ':' . (string)$s['model'];
            $lat = (int)$s['latency_ms'];
            if (!isset($latency[$key]) || $lat < $latency[$key]) {
                $latency[$key] = $lat;
            }
        }
        usort($rows, function (array $a, array $b) use ($pidByName, $latency): int {
            $ka = ($pidByName[(string)($a['provider'] ?? '')] ?? 0) . ':' . (string)($a['upstream_model'] ?? '');
            $kb = ($pidByName[(string)($b['provider'] ?? '')] ?? 0) . ':' . (string)($b['upstream_model'] ?? '');
            $la = $latency[$ka] ?? PHP_INT_MAX;
            $lb = $latency[$kb] ?? PHP_INT_MAX;
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });
        return $this->resolveMap($rows[0]);
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