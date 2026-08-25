<?php
declare(strict_types=1);

namespace App\Http;

use App\Support\Exception\HttpException;

interface MiddlewareInterface
{
    /** 前置处理：可修改 $request 或抛 HttpException 拒绝请求 */
    public function process(Request $request): void;
}
