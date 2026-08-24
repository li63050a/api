<?php
/**
 * 引导：配置、全局函数、自动加载、错误处理
 * 扁平结构：类名即文件名，autoloader 在 core/providers/middleware/services/handlers/admin 下查找 <Class>.php
 */
define('APP_ROOT', __DIR__);

$GLOBALS['APP_CONFIG'] = require __DIR__ . '/config.php';

require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/db.php';

// 环境变量覆盖（AI_API_<KEY> 覆盖顶层配置；AI_API_ADMIN_PASSWORD 覆盖种子密码）
foreach ($_ENV as $k => $v) {
    if (strpos($k, 'AI_API_') === 0) {
        $cfg =& $GLOBALS['APP_CONFIG'];
        if ($k === 'AI_API_ADMIN_PASSWORD') {
            $cfg['admin_seed']['password'] = $v;
        } else {
            $cfg[strtolower(substr($k, 7))] = $v;
        }
        unset($cfg);
    }
}
if (function_exists('getenv')) {
    foreach (getenv() ?: [] as $k => $v) {
        if (is_string($k) && strpos($k, 'AI_API_') === 0) {
            $cfg =& $GLOBALS['APP_CONFIG'];
            if ($k === 'AI_API_ADMIN_PASSWORD') {
                $cfg['admin_seed']['password'] = $v;
            } else {
                $cfg[strtolower(substr($k, 7))] = $v;
            }
            unset($cfg);
        }
    }
}

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

// 兼容旧库：补齐 request_log 新增列 + 索引 + 审计表
foreach ([
    'ip'                => "TEXT DEFAULT ''",
    'upstream_provider' => "TEXT DEFAULT ''",
    'error'             => "TEXT DEFAULT ''",
    'trace_id'          => "TEXT DEFAULT ''",
] as $col => $def) {
    try {
        $db->exec("ALTER TABLE request_log ADD COLUMN {$col} {$def}");
    } catch (\Throwable $e) {
    }
}
foreach (['cooldown_until' => "INTEGER DEFAULT 0"] as $col => $def) {
    try {
        $db->exec("ALTER TABLE upstream_keys ADD COLUMN {$col} {$def}");
    } catch (\Throwable $e) {
    }
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_request_log_created ON request_log(created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_request_log_user ON request_log(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_request_log_status ON request_log(status_code)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_billing_created ON billing(created_at)");
$db->exec("CREATE TABLE IF NOT EXISTS admin_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    action TEXT,
    detail TEXT DEFAULT '',
    created_at INTEGER NOT NULL
)");

// 低概率自动清理过期请求日志（避免表无限膨胀；保留天数见 config）
if (mt_rand(1, 100) <= 2) {
    try {
        prune_request_logs($db, (int) config('log_retention_days', 30));
    } catch (\Throwable $e) {
    }
}

// 极低概率 VACUUM，回收 SQLite 空间、降低碎片
if (mt_rand(1, 500) === 1) {
    try {
        $db->exec('VACUUM');
    } catch (\Throwable $e) {
    }
}

// 全局安全响应头（不破坏内联脚本，故未启用严格 CSP）
apply_security_headers();

/**
 * 删除 created_at 早于 $days 天的请求日志。days<=0 不清理。
 */
function prune_request_logs(\PDO $db, int $days): int
{
    if ($days <= 0) {
        return 0;
    }
    $cut = time() - $days * 86400;
    return $db->exec("DELETE FROM request_log WHERE created_at < {$cut}");
}

/**
 * 文件缓存（按 key + TTL）。用于聚合类只读查询，降低数据库压力。
 */
function cache_get(string $key)
{
    $f = cache_path($key);
    if (!is_file($f)) {
        return null;
    }
    $txt = (string) @file_get_contents($f);
    $pos = strpos($txt, '|');
    if ($pos === false) {
        return null;
    }
    $exp = (int) substr($txt, 0, $pos);
    if ($exp !== 0 && $exp < time()) {
        @unlink($f);
        return null;
    }
    $v = @json_decode(substr($txt, $pos + 1), true);
    return $v === null ? null : $v;
}

function cache_set(string $key, $value, int $ttl = 0): void
{
    $dir = config('cache_dir');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $f = cache_path($key);
    $exp = $ttl > 0 ? (time() + $ttl) : 0;
    @file_put_contents($f, $exp . '|' . json_encode($value, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cache_path(string $key): string
{
    return rtrim(config('cache_dir'), '/') . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.cache';
}

/**
 * 失败告警：POST JSON 到 config('alert_webhook')（留空则不发送）。
 */
function notify_alert(string $title, string $detail = ''): void
{
    $url = config('alert_webhook', '');
    if ($url === '') {
        return;
    }
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['title' => $title, 'detail' => $detail, 'time' => date('c')]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
    }
}

function apply_security_headers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    AppResponse::header('X-Content-Type-Options', 'nosniff');
    AppResponse::header('X-Frame-Options', 'DENY');
    AppResponse::header('Referrer-Policy', 'no-referrer');
    AppResponse::header('X-XSS-Protection', '1; mode=block');
}
