<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Http\Router;
use Tests\Framework;

Framework::test('Router: match by prefix with middleware', function (): void {
    $r = new Router();
    $r->add('/v1/chat/completions', 'chat', ['auth', 'rate']);
    $r->add('/v1/embeddings', 'embed', ['auth']);
    $r->add('/v1/models', 'models', []);
    $hit = $r->match('/v1/chat/completions');
    Framework::assertSame('chat', $hit['handler']);
    Framework::assertSame(['auth', 'rate'], $hit['middleware']);
    Framework::assertSame(null, $r->match('/v1/nope'));
});
