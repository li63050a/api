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

Framework::test('KeyPool: pick restricted to onlyIds (per-model candidate keys)', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $providers = new ProviderRepository($db);
    $providers->create(['name' => 'openai', 'status' => 1, 'priority' => 10, 'timeout' => 60, 'max_retries' => 1, 'created_at' => time()]);
    $pid = (int)$db->lastInsertId();
    $keys = new UpstreamKeyRepository($db);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k1', 'status' => 1, 'created_at' => time()]);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k2', 'status' => 1, 'created_at' => time()]);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k3', 'status' => 1, 'created_at' => time()]);
    $pool = new KeyPool($keys, new FileCache(TESTS_TMP . '/kp-onlyids'), new Config(['keypool_max_consecutive_failures' => 1]));

    // 模型只挂在 k1/k2 下 → 只在 {1,2} 中挑选
    for ($i = 0; $i < 5; $i++) {
        $picked = $pool->pick($pid, null, [1, 2]);
        Framework::assertTrue($picked !== null && in_array((int)$picked['id'], [1, 2], true), 'only allowed ids selected');
    }

    // 候选密钥全部故障后 → 无可用密钥
    $pool->markFailure(1);
    $pool->markFailure(2);
    Framework::assertTrue($pool->pick($pid, null, [1, 2]) === null, 'no healthy candidate among onlyIds');
});
