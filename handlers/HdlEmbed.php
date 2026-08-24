<?php
/**
 * Embeddings 处理器（带可缓存分支）
 */
class HdlEmbed implements HandlerInterface
{
    public function handle(AppRequest $req): void
    {
        $user = $req->getAttribute('user');
        $key = $req->getAttribute('key');
        $model = $req->getAttribute('model_map');

        if (!is_array($model) || empty($model['provider_id'])) {
            AppResponse::error('model not found', 404);
        }

        $provider = db_fetch(db(), 'SELECT * FROM providers WHERE id=?', [$model['provider_id']]);
        if ($provider === null) {
            AppResponse::error('provider not found', 502);
        }
        $providerName = strtolower((string) ($provider['type'] ?? $provider['name'] ?? 'openai'));

        $upKey = (new ProviderKeyPool())->next($model['provider_id']);
        if ($upKey === null) {
            AppResponse::error('no upstream key', 503);
        }

        if (!empty($model['cacheable'])) {
            $cacheKey = md5($model['alias'] . json_encode($req->json['input'] ?? ''));
            $cached = (new SvcCache())->get($cacheKey);
            if ($cached !== null) {
                // 命中：缓存以 OpenAI 规范格式存储，直接返回（简单处理，未做客户端格式回转）
                AppResponse::json(json_decode($cached, true));
            }
        }

        // 未命中主路径：转发上游；缓存写入依赖 Provider 层在输出前回调，
        // 因 forward() 内部直接 AppResponse::json 并 exit，Handler 无法在转发后写盘，
        // 故此处保留 TODO：需在 ProviderBase.forward 非流式分支增加 SvcCache::set 回调钩子。
        // TODO: 缓存写入 -> (new SvcCache())->set($cacheKey, json_encode($openaiBody), $ttl)
        (new ProviderFactory())->make($providerName)->forward($req, $model, $upKey);
    }
}
