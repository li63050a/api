<?php
/**
 * 客户端格式中间件：解析 X-Client-Format 头并写入 client_format 属性。
 */
class MwClientFormat
{
    public function handle(AppRequest $req)
    {
        $header = $req->getHeader('x-client-format');
        $formats = (array) (config('client_formats') ?? ['openai']);
        $default = config('default_client_format') ?? 'openai';

        $fmt = $header !== null ? strtolower(trim($header)) : $default;
        if (!in_array($fmt, $formats, true)) {
            $fmt = $default;
        }

        $req->setAttribute('client_format', $fmt);
        return null;
    }
}
