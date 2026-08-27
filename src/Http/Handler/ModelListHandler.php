<?php
declare(strict_types=1);

namespace App\Http\Handler;

use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;

final class ModelListHandler
{
    public function __construct(
        private ModelMapRepository $maps,
        private ProviderRepository $providers,
        private Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $items = [];
        $seen = [];
        foreach ($this->maps->allEnabled() as $m) {
            $alias = (string)$m['alias'];
            $items[] = [
                'id' => $alias,
                'object' => 'model',
                'created' => (int)($m['created_at'] ?? time()),
                'owned_by' => (string)$m['provider'],
            ];
            $seen[$alias] = true;
            // 供应商/上游模型 形式的路由模型
            $route = (string)($m['provider'] ?? '') . '/' . (string)($m['upstream_model'] ?? '');
            if ($route !== $alias && !isset($seen[$route])) {
                $items[] = [
                    'id' => $route,
                    'object' => 'model',
                    'created' => (int)($m['created_at'] ?? time()),
                    'owned_by' => (string)$m['provider'],
                ];
                $seen[$route] = true;
            }
        }
        // 最快路由别名（默认 all）
        $allAlias = (string)$this->config->get('route_all_alias', 'all');
        if (!isset($seen[$allAlias])) {
            $items[] = ['id' => $allAlias, 'object' => 'model', 'created' => time(), 'owned_by' => 'route'];
        }
        // 各供应商最快路由（供应商名 / 供应商/*）
        foreach ($this->providers->findEnabledSorted() as $p) {
            $name = (string)($p['name'] ?? '');
            if ($name === '') {
                continue;
            }
            foreach ([$name, $name . '/*'] as $route) {
                if (!isset($seen[$route])) {
                    $items[] = ['id' => $route, 'object' => 'model', 'created' => time(), 'owned_by' => 'route'];
                    $seen[$route] = true;
                }
            }
        }
        return Response::json(['object' => 'list', 'data' => $items]);
    }
}