<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$repo = $c->get(\App\Db\Repository\AdminUserRepository::class);
$pass = (string)$c->get(\App\Support\Config::class)->get('admin_default_password', 'admin666');
foreach ($repo->all() as $admin) {
    $repo->updateCredentials((int)$admin['id'], 'admin666', password_hash($pass, PASSWORD_DEFAULT));
    $repo->setMustChange((int)$admin['id'], 1);
}
if ($repo->count() === 0) {
    $repo->create('admin666', password_hash($pass, PASSWORD_DEFAULT), 1);
}
echo "已重置默认管理员 admin666，下次登录须修改用户名与密码。\n";