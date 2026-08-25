<?php
declare(strict_types=1);

namespace App\Db;

use App\Support\Config;

final class Schema
{
    public function __construct(private Database $db, private Config $config) {}

    public function install(): void
    {
        $this->db->execute('PRAGMA journal_mode = WAL');
        $this->db->execute('PRAGMA foreign_keys = ON');
        // users
        $this->db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            status INTEGER NOT NULL DEFAULT 1,
            balance REAL NOT NULL DEFAULT 0,
            quota_daily INTEGER NOT NULL DEFAULT 0,
            quota_monthly INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        // api_keys
        $this->db->execute("CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            key_prefix TEXT NOT NULL DEFAULT '',
            key_hash TEXT NOT NULL,
            key_sha256 TEXT,
            name TEXT NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            allowed_models TEXT NOT NULL DEFAULT '',
            ip_whitelist TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            expires_at INTEGER,
            last_used_at INTEGER
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_api_keys_sha ON api_keys(key_sha256)');
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys(user_id)');
        // providers
        $this->db->execute("CREATE TABLE IF NOT EXISTS providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            base_url TEXT NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            timeout INTEGER NOT NULL DEFAULT 60,
            max_retries INTEGER NOT NULL DEFAULT 1,
            notes TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        // model_map
        $this->db->execute("CREATE TABLE IF NOT EXISTS model_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL,
            upstream_model TEXT NOT NULL,
            client_format TEXT NOT NULL DEFAULT 'openai',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )");
        // upstream_keys
        $this->db->execute("CREATE TABLE IF NOT EXISTS upstream_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            key_value TEXT NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            weight INTEGER NOT NULL DEFAULT 1,
            fail_count INTEGER NOT NULL DEFAULT 0,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            last_used_at INTEGER,
            disabled_at INTEGER,
            created_at INTEGER NOT NULL
        )");
        // billing
        $this->db->execute("CREATE TABLE IF NOT EXISTS billing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            api_key_id INTEGER NOT NULL,
            provider TEXT NOT NULL DEFAULT '',
            model TEXT NOT NULL DEFAULT '',
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            total_tokens INTEGER NOT NULL DEFAULT 0,
            cost REAL NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_billing_user ON billing(user_id, created_at)');
        // request_log
        $this->db->execute("CREATE TABLE IF NOT EXISTS request_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            api_key_id INTEGER NOT NULL DEFAULT 0,
            provider TEXT NOT NULL DEFAULT '',
            model TEXT NOT NULL DEFAULT '',
            endpoint TEXT NOT NULL DEFAULT '',
            client_format TEXT NOT NULL DEFAULT 'openai',
            status INTEGER NOT NULL DEFAULT 0,
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            total_tokens INTEGER NOT NULL DEFAULT 0,
            cost REAL NOT NULL DEFAULT 0,
            latency_ms INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_request_log_created ON request_log(created_at)');
        // admin_users
        $this->db->execute("CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            must_change INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_login_at INTEGER
        )");
        // admin_audit
        $this->db->execute("CREATE TABLE IF NOT EXISTS admin_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL DEFAULT 0,
            action TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        // speedtest_log
        $this->db->execute("CREATE TABLE IF NOT EXISTS speedtest_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL DEFAULT 0,
            model TEXT NOT NULL DEFAULT '',
            endpoint TEXT NOT NULL DEFAULT '',
            latency_ms INTEGER NOT NULL DEFAULT 0,
            success INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->seedAdmin();
    }

    private function seedAdmin(): void
    {
        $count = (int)$this->db->value('SELECT COUNT(*) FROM admin_users');
        if ($count > 0) {
            return;
        }
        $pass = (string)$this->config->get('admin_default_password', 'admin666');
        $this->db->execute(
            'INSERT INTO admin_users (username, password_hash, must_change, created_at) VALUES (?, ?, 1, ?)',
            ['admin666', password_hash($pass, PASSWORD_DEFAULT), time()]
        );
    }
}
