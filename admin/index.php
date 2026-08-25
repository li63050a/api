<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
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
    $resp = $controller->dispatch(\App\Http\Request::fromGlobals());
    $resp->send();
    exit;
}

$app = new \App\Admin\AdminApp($auth);
echo $app->render(\App\Http\Request::fromGlobals());