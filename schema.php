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
        base_url TEXT NOT NULL,
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
        last_error_at INTEGER DEFAULT 0
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

    CREATE TABLE IF NOT EXISTS request_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        api_key_id INTEGER,
        path TEXT,
        model_alias TEXT,
        status_code INTEGER,
        input_tokens INTEGER DEFAULT 0,
        output_tokens INTEGER DEFAULT 0,
        latency_ms INTEGER DEFAULT 0,
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS speedtest_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER,
        upstream_key_id INTEGER,
        ok INTEGER DEFAULT 0,
        latency_ms INTEGER DEFAULT 0,
        detail TEXT,
        created_at INTEGER NOT NULL
    );
    ");

    // 种子管理员
    $seed = config('admin_seed');
    if (!db_fetch($db, "SELECT id FROM admin_users WHERE username = ?", [$seed['username']])) {
        db_insert($db, 'admin_users', [
            'username' => $seed['username'],
            'password_hash' => password_hash($seed['password'], PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => time(),
        ]);
    }

    // 种子供应商（base_url / list_endpoint 可按实际修改）
    $seeds = [
        ['openai', 'https://api.openai.com/v1', '/models'],
        ['anthropic', 'https://api.anthropic.com/v1', ''],
        ['gemini', 'https://generativelanguage.googleapis.com/v1beta', '/models?key='],
    ];
    foreach ($seeds as [$name, $url, $list]) {
        if (!db_fetch($db, "SELECT id FROM providers WHERE name = ?", [$name])) {
            db_insert($db, 'providers', [
                'name' => $name, 'base_url' => $url, 'auth_scheme' => 'bearer',
                'auth_header' => 'Authorization', 'list_endpoint' => $list, 'status' => 1,
            ]);
        }
    }
}
