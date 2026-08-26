<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$cfg = $c->get(\App\Support\Config::class);

// 后台 IP 白名单（admin_allowed_ips，逗号分隔，空=不限制）
$adminIps = trim((string)$cfg->get('admin_allowed_ips', ''));
if ($adminIps !== '') {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $list = array_filter(array_map('trim', explode(',', $adminIps)), static fn (string $e) => $e !== '');
    if ($ip === '' || !in_array($ip, $list, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$session = &$_SESSION;
$auth = new \App\Domain\Auth\AdminAuth(
    $c->get(\App\Db\Repository\AdminUserRepository::class),
    $session
);
$controller = new \App\Admin\AdminController(
    $auth,
    $c->get(\App\Db\Database::class),
    $c->get(\App\Support\Config::class)
);

$isFetch = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$hasAction = ($_REQUEST['action'] ?? '') !== '' || (json_decode(file_get_contents('php://input') ?: '', true)['action'] ?? '') !== '';

if ($isFetch || $hasAction) {
    $resp = $controller->dispatch(\App\Http\Request::fromGlobals($cfg->all()));
    $resp->send();
    exit;
}

$app = new \App\Admin\AdminApp($auth);
echo $app->render(\App\Http\Request::fromGlobals($cfg->all()));