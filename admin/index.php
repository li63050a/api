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

// 自动检测模型可用度（虚拟主机无 cron 的替代方案）：
// 开启后，每次访问后台（页面或 AJAX）时若已到间隔则执行一次全量检测。
// 有 cron 的主机可改用 scripts/auto_detect.php，更稳定。
$settingsRepo = $c->get(\App\Db\Repository\SettingRepository::class);
if ((int)$settingsRepo->get('auto_detect_enabled', 0) === 1) {
    $interval = max(1, (int)$settingsRepo->get('auto_detect_interval', 30));
    $lastRun = (int)$settingsRepo->get('auto_detect_last_run', 0);
    if ($lastRun === 0 || ($lastRun + $interval * 60) <= time()) {
        $autoDisable = (int)$settingsRepo->get('auto_detect_auto_disable', 1) === 1;
        $db = $c->get(\App\Db\Database::class);
        $runner = new \App\Domain\SpeedTest\SpeedTestService(
            $db,
            $c->get(\App\Db\Repository\ProviderRepository::class),
            $c->get(\App\Db\Repository\UpstreamKeyRepository::class),
            $c->get(\App\Db\Repository\ModelMapRepository::class),
            $c->get(\App\Db\Repository\SpeedTestRepository::class),
            $c->get(\App\Domain\Crypto\CryptoService::class),
            $cfg
        );
        $runner->testAllModels($autoDisable);
        $settingsRepo->set('auto_detect_last_run', (string)time());
    }
}

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