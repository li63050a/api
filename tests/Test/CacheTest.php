<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Cache\FileCache;
use Tests\Framework;

Framework::test('FileCache: set/get/delete', function (): void {
    $dir = TESTS_TMP . '/cache';
    $cache = new FileCache($dir);
    $cache->set('k1', ['a' => 1], 60);
    Framework::assertSame(['a' => 1], $cache->get('k1'));
    $cache->delete('k1');
    Framework::assertSame(null, $cache->get('k1'));
});

Framework::test('FileCache: expires', function (): void {
    $dir = TESTS_TMP . '/cache_exp';
    $cache = new FileCache($dir);
    $cache->set('k2', 'v', -1);
    Framework::assertSame(null, $cache->get('k2'));
});
