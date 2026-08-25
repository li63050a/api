<?php
/**
 * 数据库初始化（首次运行自动建表 + 种子）
 */
function install_schema(\PDO $db): void
{
    $db->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT DEFAULT 'admin',
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        status INTEGER DEFAULT 1,
        balance REAL DEFAULT 0,
        quota_daily INTEGER DEFAULT 0,
        quota_monthly INTEGER DEFAULT 0,
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        key_hash TEXT NOT NULL,
        key_prefix TEXT,
        status INTEGER DEFAULT 1,
        created_at INTEGER NOT NULL,
        expires_at INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS providers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        type TEXT DEFAULT 'openai',
        base_url TEXT NOT NULL,
        api_path TEXT DEFAULT '',
        auth_scheme TEXT DEFAULT 'bearer',
        auth_header TEXT DEFAULT 'Authorization',
        list_endpoint TEXT DEFAULT '',
        status INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS model_map (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        alias TEXT UNIQUE NOT NULL,
        provider_id INTEGER NOT NULL,
        upstream_model TEXT NOT NULL,
        price_input REAL DEFAULT 0,
        price_output REAL DEFAULT 0,
        price_per_request REAL DEFAULT 0,
        cacheable INTEGER DEFAULT 0,
        status INTEGER DEFAULT 1,
        source TEXT DEFAULT 'manual',
        fetched_at INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS upstream_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER NOT NULL,
        key_value TEXT NOT NULL,
        status INTEGER DEFAULT 1,
        weight INTEGER DEFAULT 1,
        last_error_at INTEGER DEFAULT 0,
        cooldown_until INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS quotas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        window TEXT DEFAULT 'daily',
        limit_count INTEGER DEFAULT 0,
        limit_tokens INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS billing (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        api_key_id INTEGER NOT NULL,
        model_alias TEXT,
        provider_id INTEGER,
        input_tokens INTEGER DEFAULT 0,
        output_tokens INTEGER DEFAULT 0,
        request_count INTEGER DEFAULT 1,
        amount REAL DEFAULT 0,
        created_at INTEGER NOT NULL
    );

    CREATE INDEX IF NOT EXISTS idx_billing_created ON billing(created_at);

    CREATE TABLE IF NOT EXISTS request_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        api_key_id INTEGER,
        path TEXT,
        model_alias TEXT,
        upstream_provider TEXT DEFAULT '',
        ip TEXT DEFAULT '',
        status_code INTEGER,
        input_tokens INTEGER DEFAULT 0,
        output_tokens INTEGER DEFAULT 0,
        latency_ms INTEGER DEFAULT 0,
        error TEXT DEFAULT '',
        trace_id TEXT DEFAULT '',
        created_at INTEGER NOT NULL
    );

    CREATE INDEX IF NOT EXISTS idx_request_log_created ON request_log(created_at);
    CREATE INDEX IF NOT EXISTS idx_request_log_user ON request_log(user_id);
    CREATE INDEX IF NOT EXISTS idx_request_log_status ON request_log(status_code);

    CREATE TABLE IF NOT EXISTS speedtest_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER,
        upstream_key_id INTEGER,
        ok INTEGER DEFAULT 0,
        latency_ms INTEGER DEFAULT 0,
        detail TEXT,
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS admin_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        action TEXT,
        detail TEXT DEFAULT '',
        created_at INTEGER NOT NULL
    );
    ");

    // 种子管理员
    $seed = config('admin_seed');
    $seedUser = trim((string) ($seed['username'] ?? ''));
    if ($seedUser === '') {
        $seedUser = 'admin';
    }
    $seedPwd = (string) ($seed['password'] ?? '');
    $generated = false;
    if ($seedPwd === '' || $seedPwd === 'change_me_now') {
        $seedPwd = substr(bin2hex(random_bytes(9)), 0, 16); // 16 位随机初始密码
        $generated = true;
    }
    if (!db_fetch($db, "SELECT id FROM admin_users WHERE username = ?", [$seedUser])) {
        db_insert($db, 'admin_users', [
            'username' => $seedUser,
            'password_hash' => password_hash($seedPwd, PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => time(),
        ]);
        if ($generated) {
            $hint = "初始管理员账号已自动生成随机密码（请登录后立即修改）：\n用户名: {$seedUser}\n密码: {$seedPwd}\n";
            @file_put_contents(__DIR__ . '/data/initial_admin_password.txt', $hint, LOCK_EX);
            error_log('[api-gateway] ' . str_replace($seedPwd, '***', $hint));
        }
    }

    // 种子供应商：base_url 为 host 根，api_path 为版本路径（可改）；type 决定适配器
    $seeds = [
        ['openai', 'openai', 'https://api.openai.com', '/v1', '/models'],
        ['anthropic', 'anthropic', 'https://api.anthropic.com', '/v1', ''],
        ['gemini', 'gemini', 'https://generativelanguage.googleapis.com', '/v1beta', '/models?key='],
    ];
    foreach ($seeds as [$name, $type, $url, $apiPath, $list]) {
        if (!db_fetch($db, "SELECT id FROM providers WHERE name = ?", [$name])) {
            db_insert($db, 'providers', [
                'name' => $name, 'type' => $type, 'base_url' => $url, 'api_path' => $apiPath,
                'auth_scheme' => 'bearer', 'auth_header' => 'Authorization',
                'list_endpoint' => $list, 'status' => 1,
            ]);
        }
    }
}
