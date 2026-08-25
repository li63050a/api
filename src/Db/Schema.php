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
        // model_map 旧库迁移：旧表无 key_id（alias 全局唯一）→ 重建为"模型名+多密钥"
        $this->migrateModelMap();
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
            quota_daily INTEGER NOT NULL DEFAULT 0,
            quota_monthly INTEGER NOT NULL DEFAULT 0,
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
            client_format TEXT NOT NULL DEFAULT 'openai',
            status INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            timeout INTEGER NOT NULL DEFAULT 60,
            max_retries INTEGER NOT NULL DEFAULT 1,
            notes TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        // model_map：同一模型名(alias)可挂在多把密钥(key_id)下，各自独立启停/测速
        $this->db->execute("CREATE TABLE IF NOT EXISTS model_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL,
            provider TEXT NOT NULL,
            key_id INTEGER NOT NULL DEFAULT 0,
            upstream_model TEXT NOT NULL,
            client_format TEXT NOT NULL DEFAULT 'openai',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            UNIQUE(alias, key_id)
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_model_map_key ON model_map(key_id)');
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_model_map_alias ON model_map(alias)');
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

        // 幂等列迁移：旧库补列（确保 CREATE TABLE IF NOT EXISTS 不会漏掉新列）
        $this->ensureColumn('providers', 'client_format', "TEXT NOT NULL DEFAULT 'openai'");
        $this->ensureColumn('api_keys', 'quota_daily', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('api_keys', 'quota_monthly', 'INTEGER NOT NULL DEFAULT 0');

        $this->seedAdmin();
    }

    /**
     * 旧版 model_map 无 key_id（alias 全局 UNIQUE，模型合并到供应商）。
     * 检测到缺列时重建表：旧行归入 key_id=0，解除 alias 全局唯一限制。
     */
    private function migrateModelMap(): void
    {
        $exists = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'model_map'");
        if ($exists === null) {
            return; // 新库，由下方 CREATE TABLE 直接建表
        }
        $cols = [];
        foreach ($this->db->fetchAll('PRAGMA table_info(model_map)') as $row) {
            $cols[] = (string)$row['name'];
        }
        if (in_array('key_id', $cols, true)) {
            return;
        }
        $this->db->execute('ALTER TABLE model_map RENAME TO model_map_legacy');
        $this->db->execute("CREATE TABLE model_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL,
            provider TEXT NOT NULL,
            key_id INTEGER NOT NULL DEFAULT 0,
            upstream_model TEXT NOT NULL,
            client_format TEXT NOT NULL DEFAULT 'openai',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            UNIQUE(alias, key_id)
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_model_map_key ON model_map(key_id)');
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_model_map_alias ON model_map(alias)');
        $this->db->execute(
            'INSERT INTO model_map (alias, provider, key_id, upstream_model, client_format, enabled, created_at)
             SELECT alias, provider, 0, upstream_model, client_format, enabled, created_at FROM model_map_legacy'
        );
        $this->db->execute('DROP TABLE model_map_legacy');
    }

    private function ensureColumn(string $table, string $col, string $def): void
    {
        $exists = false;
        foreach ($this->db->fetchAll("PRAGMA table_info({$table})") as $row) {
            if ((string)$row['name'] === $col) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $this->db->execute("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
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
