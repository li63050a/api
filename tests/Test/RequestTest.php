<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Http\Request;
use Tests\Framework;

Framework::test('Request: parse globals', function (): void {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/index.php/v1/chat/completions?foo=bar',
        'HTTP_AUTHORIZATION' => 'Bearer sk-x',
        'HTTP_X_CLIENT_FORMAT' => 'anthropic',
        'REMOTE_ADDR' => '1.2.3.4',
    ];
    $_POST = [];
    $_GET = ['foo' => 'bar'];
    $req = Request::fromGlobals();
    Framework::assertSame('POST', $req->method());
    Framework::assertSame('/v1/chat/completions', $req->path());
    Framework::assertSame('sk-x', $req->bearerToken());
    Framework::assertSame('anthropic', $req->header('X-Client-Format'));
    Framework::assertSame('1.2.3.4', $req->clientIp());
    Framework::assertSame('bar', $req->query('foo'));
    Framework::assertSame('v1', $req->attribute('version'));
});
