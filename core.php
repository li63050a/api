<?php
/**
 * 引导：配置、全局函数、自动加载、错误处理
 * 扁平结构：类名即文件名，autoloader 在 core/providers/middleware/services/handlers/admin 下查找 <Class>.php
 */
define('APP_ROOT', __DIR__);

$GLOBALS['APP_CONFIG'] = require __DIR__ . '/config.php';

require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/db.php';

function config($key = null, $default = null)
{
    $cfg = $GLOBALS['APP_CONFIG'] ?? [];
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = config('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec('PRAGMA busy_timeout=5000;');
    }
    return $pdo;
}

spl_autoload_register(function (string $class) {
    static $dirs = ['core', 'providers', 'middleware', 'services', 'handlers', 'admin'];
    foreach ($dirs as $d) {
        $f = APP_ROOT . '/' . $d . '/' . $class . '.php';
        if (is_file($f)) {
            require $f;
            return;
        }
    }
});

set_exception_handler(function (Throwable $e) {
    if (config('debug')) {
        $msg = $e->getMessage() . "\n" . $e->getTraceAsString();
    } else {
        $msg = 'Internal Server Error';
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => ['message' => $msg, 'type' => 'server_error']]);
    exit;
});

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// 会话存储到 data/sessions（避免虚拟主机默认路径不可写导致登录态丢失）
$sessionDir = dirname(config('db_path')) . '/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

// 首次运行自动建表（任何入口都会经过 core.php，确保后台也能初始化）
try {
    db()->query("SELECT 1 FROM model_map LIMIT 1");
} catch (\Throwable $e) {
    if (!function_exists('install_schema')) {
        require_once APP_ROOT . '/schema.php';
    }
    install_schema(db());
}

// 兼容旧库：补齐 providers 新增列（列已存在时 ALTER 会报错，忽略即可）
$db = db();
foreach (['type' => "TEXT DEFAULT 'openai'", 'api_path' => "TEXT DEFAULT ''"] as $col => $def) {
    try {
        $db->exec("ALTER TABLE providers ADD COLUMN {$col} {$def}");
    } catch (\Throwable $e) {
    }
}
