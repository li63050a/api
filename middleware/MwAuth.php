<?php
/**
 * 鉴权中间件：取 Bearer Token，调 SvcAuth 校验。
 */
class MwAuth
{
    public function handle(AppRequest $req)
    {
        $token = $req->getBearerToken();
        if ($token === null || $token === '') {
            return AppResponse::error('Invalid API key', 401);
        }

        $result = (new SvcAuth())->authenticate($token);
        if ($result === null) {
            return AppResponse::error('Invalid API key', 401);
        }

        $req->setAttribute('user', $result['user']);
        $req->setAttribute('key', $result['key']);
        return null;
    }
}
