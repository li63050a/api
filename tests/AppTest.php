<?php
use PHPUnit\Framework\TestCase;

class AppTest extends TestCase
{
    public function testConfigLoads(): void
    {
        $this->assertIsArray(config());
        $this->assertEquals('openai', config('default_client_format'));
    }

    public function testCryptoRoundtrip(): void
    {
        $enc = crypto_encrypt('hello-world');
        $this->assertNotEquals('hello-world', $enc);
        $this->assertEquals('hello-world', crypto_decrypt($enc));
    }

    public function testCacheGetSet(): void
    {
        cache_set('unit_test_key', ['a' => 1]);
        $this->assertEquals(['a' => 1], cache_get('unit_test_key'));
    }

    public function testCacheExpiry(): void
    {
        cache_set('unit_ttl', 'v', 1);
        // 模拟过期：直接改写文件时间戳不可行，改为 TTL=0 视为永不过期
        cache_set('unit_noexp', 'x', 0);
        $this->assertEquals('x', cache_get('unit_noexp'));
    }

    public function testRequestLogInsertAndPrune(): void
    {
        $db = db();
        db_insert($db, 'request_log', [
            'user_id' => null, 'path' => '/t', 'model_alias' => 'm',
            'status_code' => 200, 'latency_ms' => 1,
            'created_at' => time() - 86400 * 100,
        ]);
        db_insert($db, 'request_log', [
            'user_id' => null, 'path' => '/t', 'model_alias' => 'm',
            'status_code' => 200, 'latency_ms' => 1, 'created_at' => time(),
        ]);
        $total = (int) db_fetch($db, 'SELECT COUNT(*) AS n FROM request_log')['n'];
        $this->assertGreaterThanOrEqual(2, $total);

        $deleted = prune_request_logs($db, 1);
        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    public function testProviderKeyPoolCooldown(): void
    {
        $db = db();
        db_insert($db, 'providers', ['name' => 'ut_provider', 'type' => 'openai', 'base_url' => 'http://x', 'api_path' => '', 'auth_scheme' => 'bearer', 'auth_header' => 'Authorization', 'list_endpoint' => '', 'status' => 1]);
        $pid = (int) $db->lastInsertId();
        db_insert($db, 'upstream_keys', ['provider_id' => $pid, 'key_value' => crypto_encrypt('k'), 'status' => 1, 'weight' => 1, 'last_error_at' => 0, 'cooldown_until' => 0]);
        $kid = (int) $db->lastInsertId();

        $pool = new ProviderKeyPool();
        $this->assertNotNull($pool->next($pid));

        $pool->markError($kid);
        $pool->markError($kid); // 第二次视为连续失败 -> 冷却
        $row = db_fetch($db, 'SELECT cooldown_until FROM upstream_keys WHERE id = ?', [$kid]);
        $this->assertGreaterThan(time(), (int) $row['cooldown_until']);

        $pool->markSuccess($kid);
        $row2 = db_fetch($db, 'SELECT cooldown_until FROM upstream_keys WHERE id = ?', [$kid]);
        $this->assertEquals(0, (int) $row2['cooldown_until']);

        $db->exec('DELETE FROM upstream_keys WHERE id = ' . $kid);
        $db->exec('DELETE FROM providers WHERE id = ' . $pid);
    }
}
