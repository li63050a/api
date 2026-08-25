<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\ApiKeyAuth;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class Auth implements MiddlewareInterface
{
    public function __construct(private ApiKeyAuth $auth) {}

    public function process(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new HttpException('Missing API key', 401, 'invalid_request_error');
        }
        $ctx = $this->auth->authenticate($token, $request->clientIp());
        $request->setAttribute('auth', $ctx);
        $preferred = $request->header('X-Preferred-Key-Id');
        if ($preferred !== null) {
            $request->setAttribute('preferred_key_id', (int)$preferred);
        }
    }
}
