<?php
/**
 * 限流中间件：基于单 Key 的每分钟请求数 best-effort 限流。
 */
class MwRateLimit
{
    public function handle(AppRequest $req)
    {
        $key = $req->getAttribute('key');
        if (!is_array($key) || !isset($key['id'])) {
            return AppResponse::error('Unauthorized', 401);
        }

        $keyId = (int) $key['id'];
        $limit = (int) (config('rate_limit_per_minute') ?? 60);

        if (!(new SvcRateLimit())->check($keyId, $limit)) {
            return AppResponse::error('Rate limit exceeded', 429);
        }

        return null;
    }
}
