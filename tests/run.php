<?php
/**
 * 零依赖测试运行器：仅用 PHP 自带能力，无需 phpunit / composer / 网络。
 * 用法：php tests/run.php
 * 纯函数测试始终运行；数据库相关测试在缺少 pdo_sqlite 扩展时自动跳过。
 */
declare(strict_types=1);

putenv('AI_API_DB_PATH=' . sys_get_temp_dir() . '/aiapi_test.db');
putenv('AI_API_CACHE_DIR=' . sys_get_temp_dir() . '/aiapi_cache');
@mkdir(sys_get_temp_dir() . '/aiapi_cache', 0755, true);

require __DIR__ . '/../core.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}\n";
    }
}

echo "纯函数测试:\n";
ok(is_array(config()), 'config() 返回数组');
ok(config('default_client_format') === 'openai', '默认客户端格式 = openai');
$e = crypto_encrypt('hello-world');
ok($e !== 'hello-world' && crypto_decrypt($e) === 'hello-world', 'crypto 加解密往返');
cache_set('ut_k', ['a' => 1]);
ok(cache_get('ut_k') === ['a' => 1], 'cache 读写');
cache_set('ut_x', 'v', 0);
ok(cache_get('ut_x') === 'v', 'cache 永不过期(TTL=0)');

if (extension_loaded('pdo_sqlite')) {
    echo "数据库测试:\n";
    $db = db();
    db_insert($db, 'request_log', ['user_id' => null, 'path' => '/t', 'model_alias' => 'm', 'status_code' => 200, 'latency_ms' => 1, 'created_at' => time() - 86400 * 100]);
    db_insert($db, 'request_log', ['user_id' => null, 'path' => '/t', 'model_alias' => 'm', 'status_code' => 200, 'latency_ms' => 1, 'created_at' => time()]);
    $total = (int) db_fetch($db, 'SELECT COUNT(*) AS n FROM request_log')['n'];
    ok($total >= 2, 'request_log 写入');
    ok(prune_request_logs($db, 1) >= 1, 'prune 清理旧日志');

    db_insert($db, 'providers', ['name' => 'ut_p', 'type' => 'openai', 'base_url' => 'http://x', 'api_path' => '', 'auth_scheme' => 'bearer', 'auth_header' => 'Authorization', 'list_endpoint' => '', 'status' => 1]);
    $pid = (int) $db->lastInsertId();
    db_insert($db, 'upstream_keys', ['provider_id' => $pid, 'key_value' => crypto_encrypt('k'), 'status' => 1, 'weight' => 1, 'last_error_at' => 0, 'cooldown_until' => 0]);
    $kid = (int) $db->lastInsertId();
    $pool = new ProviderKeyPool();
    ok($pool->next($pid) !== null, 'KeyPool 可选取');
    $pool->markError($kid);
    $pool->markError($kid);
    ok((int) db_fetch($db, 'SELECT cooldown_until FROM upstream_keys WHERE id = ?', [$kid])['cooldown_until'] > time(), 'KeyPool 连续失败进入冷却');
    $pool->markSuccess($kid);
    ok((int) db_fetch($db, 'SELECT cooldown_until FROM upstream_keys WHERE id = ?', [$kid])['cooldown_until'] === 0, 'KeyPool 成功后恢复');
    $db->exec('DELETE FROM upstream_keys WHERE id = ' . $kid);
    $db->exec('DELETE FROM providers WHERE id = ' . $pid);
} else {
    echo "跳过数据库测试（环境无 pdo_sqlite 扩展）\n";
}

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
