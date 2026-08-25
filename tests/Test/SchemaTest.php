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
