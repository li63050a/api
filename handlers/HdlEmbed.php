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

        (new ProviderFactory())->make($providerName)->forward($req, $model, $upKey);
    }
}
