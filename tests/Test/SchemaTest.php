<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Schema;
use App\Support\Config;
use Tests\Framework;

Framework::test('Schema: install creates all tables and seeds default admin', function (): void {
    $db = new Database('sqlite::memory:');
    $schema = new Schema($db, new Config(['admin_default_password' => 'admin666']));
    $schema->install();
    $tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    $names = array_column($tables, 'name');
    foreach (['users', 'api_keys', 'providers', 'model_map', 'upstream_keys', 'billing', 'request_log', 'admin_users', 'admin_audit', 'speedtest_log'] as $t) {
        Framework::assertContains($t, $names);
    }
    $admin = $db->fetchOne('SELECT * FROM admin_users WHERE username = ?', ['admin666']);
    Framework::assertTrue($admin !== null, 'default admin exists');
    Framework::assertSame(1, (int)$admin['must_change']);
    Framework::assertTrue(password_verify('admin666', $admin['password_hash']), 'default password verifies');
});

Framework::test('Schema: install is idempotent', function (): void {
    $db = new Database('sqlite::memory:');
    $schema = new Schema($db, new Config(['admin_default_password' => 'admin666']));
    $schema->install();
    $schema->install(); // 不抛异常
    Framework::assertTrue(true);
});

Framework::test('Schema: 旧版 model_map（无 key_id）迁移为 key_id=0 行', function (): void {
    $db = new Database('sqlite::memory:');
    // 手工建旧版表：无 key_id，alias 全局 UNIQUE
    $db->execute('CREATE TABLE model_map (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        alias TEXT NOT NULL UNIQUE,
        provider TEXT NOT NULL,
        upstream_model TEXT NOT NULL,
        client_format TEXT NOT NULL DEFAULT \'openai\',
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL
    )');
    $db->execute("INSERT INTO model_map (alias, provider, upstream_model, client_format, enabled, created_at) VALUES ('gpt-4o', 'openai', 'gpt-4o', 'openai', 1, ?)", [time()]);

    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();

    $cols = array_column($db->fetchAll('PRAGMA table_info(model_map)'), 'name');
    Framework::assertContains('key_id', $cols, '重建后包含 key_id 列');
    $row = $db->fetchOne('SELECT * FROM model_map WHERE alias = ?', ['gpt-4o']);
    Framework::assertTrue($row !== null, '旧行保留');
    Framework::assertSame(0, (int)$row['key_id'], '旧行归入 key_id=0');
    Framework::assertTrue($db->fetchOne('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = \'model_map_legacy\'') === null, '遗留表已删除');
});
