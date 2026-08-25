<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Db\Schema;
use App\Domain\Provider\KeyPool;
use App\Support\Config;
use App\Domain\Cache\FileCache;
use Tests\Framework;

Framework::test('KeyPool: picks healthy key and disables after consecutive failures', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $providers = new ProviderRepository($db);
    $providers->create(['name' => 'openai', 'status' => 1, 'priority' => 10, 'timeout' => 60, 'max_retries' => 1, 'created_at' => time()]);
    $pid = (int)$db->lastInsertId();
    $keys = new UpstreamKeyRepository($db);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k1', 'status' => 1, 'created_at' => time()]);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k2', 'status' => 1, 'created_at' => time()]);
    $pool = new KeyPool($keys, new FileCache(TESTS_TMP . '/kp'), new Config(['keypool_max_consecutive_failures' => 2]));
    $k1 = $pool->pick($pid);
    Framework::assertTrue($k1 !== null);
    $pool->markFailure((int)$k1['id']);
    $pool->markFailure((int)$k1['id']);
    Framework::assertTrue($pool->isDisabled((int)$k1['id']), 'key disabled after 2 consecutive failures');
});
