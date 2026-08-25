<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\RateLimit\FileRateLimiter;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Config;
use App\Support\Exception\HttpException;

final class RateLimit implements MiddlewareInterface
{
    public function __construct(private FileRateLimiter $limiter, private Config $config) {}

    public function process(Request $request): void
    {
        $limit = (int)$this->config->get('ratelimit_requests_per_minute', 60);
        if ($limit <= 0) {
            return;
        }
        $auth = $request->attribute('auth', []);
        $uid = (int)($auth['user']['id'] ?? 0);
        $key = 'rl:' . $uid . ':' . $request->path();
        if (!$this->limiter->consume($key, $limit, 60)) {
            throw new HttpException('Rate limit exceeded, please retry later', 429, 'rate_limit_exceeded');
        }
    }
}
