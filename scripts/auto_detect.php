<?php
declare(strict_types=1);

/**
 * 自动检测模型可用度（配合 cron / systemd timer 调用，零后台进程）。
 *
 * 用法：
 *   * * * * * php /path/to/scripts/auto_detect.php
 *
 * 仅在后台「顶栏模型条」开启自动检测时生效，并按设定的间隔分钟判断是否到期。
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$db = $c->get(\App\Db\Database::class);
$settings = $c->get(\App\Db\Repository\SettingRepository::class);

if ((int)$settings->get('auto_detect_enabled', 0) !== 1) {
    echo "[auto_detect] disabled\n";
    exit(0);
}

$interval = max(1, (int)$settings->get('auto_detect_interval', 30));
$lastRun = (int)$settings->get('auto_detect_last_run', 0);
if ($lastRun > 0 && ($lastRun + $interval * 60) > time()) {
    echo "[auto_detect] not due (last=" . date('Y-m-d H:i:s', $lastRun) . ", interval={$interval}m)\n";
    exit(0);
}

$autoDisable = (int)$settings->get('auto_detect_auto_disable', 1) === 1;
$speedTest = new \App\Domain\SpeedTest\SpeedTestService(
    $db,
    $c->get(\App\Db\Repository\ProviderRepository::class),
    $c->get(\App\Db\Repository\UpstreamKeyRepository::class),
    $c->get(\App\Db\Repository\ModelMapRepository::class),
    $c->get(\App\Db\Repository\SpeedTestRepository::class),
    $c->get(\App\Domain\Crypto\CryptoService::class),
    $c->get(\App\Support\Config::class)
);

$start = microtime(true);
$results = $speedTest->testAllModels($autoDisable);
$settings->set('auto_detect_last_run', (string)time());

$ok = 0;
$failed = 0;
$disabled = 0;
foreach ($results as $r) {
    if ((bool)($r['ok'] ?? false)) {
        $ok++;
    } else {
        $failed++;
    }
    if ((bool)($r['auto_disabled'] ?? false)) {
        $disabled++;
    }
}
printf(
    "[auto_detect] done in %.1fs: %d ok, %d failed, %d auto-disabled\n",
    microtime(true) - $start,
    $ok,
    $failed,
    $disabled
);
exit(0);