<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\RateLimit\FileRateLimiter;
use Tests\Framework;

Framework::test('FileRateLimiter: allow then exceed', function (): void {
    $dir = TESTS_TMP . '/rl';
    $rl = new FileRateLimiter($dir);
    $key = 'user:1:chat';
    Framework::assertSame(true, $rl->consume($key, 2, 60)); // 还剩 1
    Framework::assertSame(true, $rl->consume($key, 2, 60)); // 还剩 0
    Framework::assertSame(false, $rl->consume($key, 2, 60)); // 超限
});
