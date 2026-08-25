<?php
/**
 * 命令行创建/重置管理员账号（需已安装 pdo_sqlite 扩展，且操作与 web 端同一数据库）。
 * 用法: php scripts/reset_admin.php <用户名> <密码(至少8位)>
 */
require_once __DIR__ . '/../core.php';

$user = $argv[1] ?? '';
$pass = $argv[2] ?? '';
if ($user === '' || $pass === '' || strlen($pass) < 8) {
    fwrite(STDERR, "用法: php scripts/reset_admin.php <用户名> <密码(至少8位)>\n");
    exit(1);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "错误: 未加载 pdo_sqlite 扩展，请先安装 php-sqlite3 并重启 web 服务。\n");
    exit(1);
}

$db = db();
$hash = password_hash($pass, PASSWORD_DEFAULT);
$row = db_fetch($db, "SELECT id FROM admin_users WHERE username = ?", [$user]);
if ($row) {
    db_update($db, 'admin_users', ['password_hash' => $hash], ['id' => $row['id']]);
    echo "已更新管理员密码: {$user}\n";
} else {
    db_insert($db, 'admin_users', [
        'username'    => $user,
        'password_hash' => $hash,
        'role'        => 'admin',
        'created_at'  => time(),
    ]);
    echo "已创建管理员账号: {$user}\n";
}
