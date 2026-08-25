# AI API 中转站彻底重构 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将现役扁平结构 PHP AI 中转网关重写为零依赖、命名空间分层、纯 SPA 后台、首登强制改密的干净架构，保留全部对外 API 与后台功能。

**Architecture:** 手写 PSR-4 风格 autoloader（`App\` → `src/`，无 Composer）；`Container` 装配单例；`HttpException`+`Response` 取代 `exit()` 流程控制；中间件 = 可拒绝请求的前置管道，handler 返回 Response 或直出 SSE；单一 SQLite 库 + Repository 层；后台统一走 `AdminController` AJAX 入口 + SPA 单页。

**Tech Stack:** PHP ≥ 8.1（无第三方依赖）、SQLite(PDO)、curl、原生会话、手写零依赖测试运行器 `tests/run.php`。

**默认管理员：** `admin666` / `admin666`，首登 `must_change=1` 强制改用户名+密码。

**提交策略：** 若仓库为 git 且用户同意，每任务末提交一次；否则跳过（执行前与用户确认一次即可，勿反复询问）。

***

## 文件结构总览

```
/workspace
├── index.php                          # 新 API 入口（薄壳）
├── admin/index.php                    # 新后台入口（SPA 壳 + AJAX 分派）
├── config.php                         # 保留，仅整理注释
├── src/
│   ├── bootstrap.php                  # autoloader + Bootstrap::container()/kernel()
│   ├── Container.php
│   ├── Support/Config.php
│   ├── Support/Html.php
│   ├── Support/Notifier.php           # 告警（原 core.php 的 notify 逻辑）
│   ├── Support/Exception/HttpException.php
│   ├── Support/Exception/InternalException.php
│   ├── Http/Request.php
│   ├── Http/Response.php
│   ├── Http/Router.php
│   ├── Http/Kernel.php
│   ├── Http/MiddlewareInterface.php
│   ├── Http/Middleware/ClientFormat.php
│   ├── Http/Middleware/Auth.php
│   ├── Http/Middleware/RateLimit.php
│   ├── Http/Middleware/ModelAlias.php
│   ├── Http/Handler/AbstractRelayHandler.php
│   ├── Http/Handler/ChatHandler.php
│   ├── Http/Handler/EmbedHandler.php
│   ├── Http/Handler/ModelListHandler.php
│   ├── Db/Database.php
│   ├── Db/Schema.php
│   ├── Db/Repository/UserRepository.php
│   ├── Db/Repository/ApiKeyRepository.php
│   ├── Db/Repository/ProviderRepository.php
│   ├── Db/Repository/ModelMapRepository.php
│   ├── Db/Repository/UpstreamKeyRepository.php
│   ├── Db/Repository/BillingRepository.php
│   ├── Db/Repository/RequestLogRepository.php
│   ├── Db/Repository/AdminUserRepository.php
│   ├── Db/Repository/AdminAuditRepository.php
│   ├── Db/Repository/SpeedTestRepository.php
│   ├── Domain/Auth/ApiKeyAuth.php
│   ├── Domain/Auth/AdminAuth.php
│   ├── Domain/Billing/BillingService.php
│   ├── Domain/Billing/QuotaService.php
│   ├── Domain/RateLimit/FileRateLimiter.php
│   ├── Domain/Logger/RequestLogger.php
│   ├── Domain/Cache/FileCache.php
│   ├── Domain/Crypto/CryptoService.php
│   ├── Domain/Sync/ModelSync.php
│   ├── Domain/SpeedTest/SpeedTestService.php
│   ├── Domain/Provider/ProviderInterface.php
│   ├── Domain/Provider/AbstractProvider.php
│   ├── Domain/Provider/OpenAIProvider.php
│   ├── Domain/Provider/AnthropicProvider.php
│   ├── Domain/Provider/GeminiProvider.php
│   ├── Domain/Provider/ProviderFactory.php
│   ├── Domain/Provider/KeyPool.php
│   ├── Domain/Provider/Formatter.php
│   └── Admin/
│       ├── AdminApp.php               # SPA 壳渲染
│       ├── AdminController.php        # 统一 AJAX 分派
│       └── View/views.php             # 所有后台片段渲染函数（收敛到一个文件，含 JS/CSS 字符串）
├── data/                              # 运行时（app.db / cache / ratelimit），gitignore
├── logs/                              # 运行时日志，gitignore
├── scripts/reset_admin.php
├── tests/bootstrap.php
├── tests/run.php
├── tests/Framework.php                 # 极简断言/测试收集器
├── tests/Test/*.php                    # 各用例
└── docs/superpowers/specs/2026-08-25-aiapi-rewrite-design.md  # 已存在
```

**删除（重构完成后）：** `core.php`、`schema.php`、`routes.php`、`lib/`、`middleware/`、`handlers/`、`services/`、`providers/`、`admin/AdminDispatcher.php`、`admin/AdminAuth.php`、`admin/Admin*Mgmt.php`（全部）、`admin/actions.php`、`admin/AdminDashboard.php`、`composer.json`（其 require-dev 违反零依赖）、`phpunit.xml`、`tests/AppTest.php`（被新测试替代）。

***

### Task 1: 脚手架 —— autoloader、Container、Config、异常、Html、Notifier、测试框架

**Files:**

* Create: `src/Container.php`

* Create: `src/bootstrap.php`

* Create: `src/Support/Config.php`

* Create: `src/Support/Html.php`

* Create: `src/Support/Notifier.php`

* Create: `src/Support/Exception/HttpException.php`

* Create: `src/Support/Exception/InternalException.php`

* Create: `tests/bootstrap.php`

* Create: `tests/Framework.php`

* Create: `tests/run.php`

* [ ] **Step 1: 写失败测试**（`tests/run.php` 先只跑 `tests/Framework.php` 自检）

`tests/Framework.php`（零依赖断言/收集器）：

```php
<?php
declare(strict_types=1);

namespace Tests;

final class Framework
{
    /** @var array<int, array{name:string, pass:bool, msg:string}> */
    private static array $results = [];

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$results[] = ['name' => $name, 'pass' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            self::$results[] = ['name' => $name, 'pass' => false, 'msg' => $e->getMessage()];
        }
    }

    public static function assertTrue(bool $cond, string $msg = 'expected true'): void
    {
        if (!$cond) { throw new \RuntimeException($msg); }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
        }
    }

    public static function assertContains(mixed $needle, array $haystack): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException('expected to contain ' . var_export($needle, true));
        }
    }

    public static function assertThrows(callable $fn, string $class, string $msg = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) { return; }
            throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . $class . ' got ' . get_class($e));
        }
        throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . $class . ' to be thrown');
    }

    public static function summary(): int // 返回失败数
    {
        $fail = 0;
        foreach (self::$results as $r) {
            $mark = $r['pass'] ? 'PASS' : 'FAIL';
            printf("[%s] %s%s\n", $mark, $r['name'], $r['pass'] ? '' : ' — ' . $r['msg']);
            if (!$r['pass']) { $fail++; }
        }
        printf("\n%d/%d tests passed\n", count(self::$results) - $fail, count(self::$results));
        return $fail;
    }
}
```

`tests/bootstrap.php`：

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Framework.php';

// 所有测试在临时目录内运行，避免污染仓库
define('TESTS_TMP', sys_get_temp_dir() . '/aiapi_tests_' . getmypid());
if (!is_dir(TESTS_TMP) && !mkdir(TESTS_TMP, 0777, true) && !is_dir(TESTS_TMP)) {
    throw new RuntimeException('cannot create tests tmp dir');
}
```

`tests/run.php`：

```php
<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/Test/*.php') as $file) {
    require $file;
}

exit(Tests\Framework::summary() === 0 ? 0 : 1);
```

* [ ] **Step 2: 运行，预期失败**（`tests/Test/` 尚不存在，`tests/run.php` 应能跑出 0/0 passed，退出码 0；此步验证运行器可用）

Run: `php tests/run.php`
Expected: `0/0 tests passed`，退出码 0。

* [ ] **Step 3: 实现基础设施类**

`src/Container.php`：

```php
<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            throw new RuntimeException("Container: [{$id}] not registered");
        }
        return $this->instances[$id];
    }
}
```

`src/Support/Config.php`：

```php
<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
```

`src/Support/Html.php`：

```php
<?php
declare(strict_types=1);

namespace App\Support;

final class Html
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
```

`src/Support/Exception/HttpException.php`：

```php
<?php
declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 400,
        private string $type = 'invalid_request_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int { return $this->status; }
    public function type(): string { return $this->type; }
}
```

`src/Support/Exception/InternalException.php`：

```php
<?php
declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;
use Throwable;

final class InternalException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`src/Support/Notifier.php`（原 core.php 的 alert/notify 逻辑，PHP 流式邮件，失败静默但不吞异常于上层）：

```php
<?php
declare(strict_types=1);

namespace App\Support;

final class Notifier
{
    public function __construct(private Config $config) {}

    /** @param array<int, string> $lines */
    public function alert(string $subject, array $lines): void
    {
        $to = $this->config->get('notify_email');
        if (empty($to)) {
            return;
        }
        $body = implode("\n", $lines);
        $headers = "From: " . $this->config->get('notify_from', 'aiapi@localhost') . "\r\n";
        @mail($to, '[AIAPI] ' . $subject, $body, $headers);
    }
}
```

`src/bootstrap.php`（autoloader + 装配；注意此文件同时被 `index.php`、`admin/index.php`、`tests/` 复用）：

```php
<?php
declare(strict_types=1);

namespace App;

use App\Support\Config;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class Bootstrap
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $configData = require dirname(__DIR__) . '/config.php';
        $config = new Config($configData);
        $container = new Container();
        $container->set(Config::class, $config);
        self::$container = $container;
        return $container;
    }

    /** 测试用：清空并重建容器 */
    public static function reset(): void
    {
        self::$container = null;
    }
}
```

* [ ] **Step 4: 跑测试** —— 暂无用例，运行器应输出 `0/0 tests passed`，退出码 0

Run: `php tests/run.php`
Expected: 退出码 0，`0/0 tests passed`。

* [ ] **Step 5: 提交**

```bash
git add src tests && git commit -m "refactor: scaffold autoloader, container, config, exceptions, test framework"
```

***

### Task 2: 数据库层 —— Database、Schema、Repository

**Files:**

* Create: `src/Db/Database.php`

* Create: `src/Db/Schema.php`

* Create: `src/Db/Repository/UserRepository.php`

* Create: `src/Db/Repository/ApiKeyRepository.php`

* Create: `src/Db/Repository/ProviderRepository.php`

* Create: `src/Db/Repository/ModelMapRepository.php`

* Create: `src/Db/Repository/UpstreamKeyRepository.php`

* Create: `src/Db/Repository/BillingRepository.php`

* Create: `src/Db/Repository/RequestLogRepository.php`

* Create: `src/Db/Repository/AdminUserRepository.php`

* Create: `src/Db/Repository/AdminAuditRepository.php`

* Create: `src/Db/Repository/SpeedTestRepository.php`

* Test: `tests/Test/DatabaseTest.php`

* Test: `tests/Test/SchemaTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/DatabaseTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use Tests\Framework;

Framework::test('Database: open sqlite in-memory and CRUD', function (): void {
    $db = new Database('sqlite::memory:');
    $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    $db->execute('INSERT INTO t (name) VALUES (?)', ['alice']);
    Framework::assertSame(1, $db->value('SELECT COUNT(*) FROM t'));
    Framework::assertSame('alice', $db->value('SELECT name FROM t WHERE id = ?', [1]));
    $row = $db->fetchOne('SELECT * FROM t WHERE id = ?', [1]);
    Framework::assertSame('alice', $row['name']);
    $all = $db->fetchAll('SELECT * FROM t');
    Framework::assertSame(1, count($all));
    Framework::assertSame(1, $db->execute('DELETE FROM t WHERE id = ?', [1]));
});

Framework::test('Database: transaction rollback', function (): void {
    $db = new Database('sqlite::memory:');
    $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $ok = $db->transaction(function () use ($db): void {
        $db->execute('INSERT INTO t (v) VALUES (?)', ['x']);
        throw new RuntimeException('abort');
    });
    Framework::assertSame(false, $ok);
    Framework::assertSame(0, $db->value('SELECT COUNT(*) FROM t'));
});
```

`tests/Test/SchemaTest.php`：

```php
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
```

* [ ] **Step 2: 运行，预期失败**（类不存在 → `Class "App\Db\Database" not found`）

Run: `php tests/run.php`
Expected: FAIL `Database: open sqlite in-memory and CRUD`。

* [ ] **Step 3: 实现 Database**

`src/Db/Database.php`：

```php
<?php
declare(strict_types=1);

namespace App\Db;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $pass = '', array $options = [])
    {
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->pdo = new PDO($dsn, $user, $pass, $defaults + $options);
    }

    public function pdo(): PDO { return $this->pdo; }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return int 受影响行数 */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return (string)$this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): bool
    {
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("prepare failed: {$sql}");
        }
        $stmt->execute($params);
        return $stmt;
    }
}
```

* [ ] **Step 4: 实现 Schema（建表 + 兼容迁移 + 种默认管理员）**

`src/Db/Schema.php`：所有表结构沿用原 `schema.php` 定义（字段名不变），新增两列 `api_keys.key_sha256`、`admin_users.must_change`；`install()` 幂等；种入默认管理员 `admin666`（密码用 `config('admin_default_password')`，默认 `admin666`），`must_change=1`，仅当 admin\_users 为空时种入。

```php
<?php
declare(strict_types=1);

namespace App\Db;

use App\Support\Config;

final class Schema
{
    public function __construct(private Database $db, private Config $config) {}

    public function install(): void
    {
        $this->db->execute('PRAGMA journal_mode = WAL');
        $this->db->execute('PRAGMA foreign_keys = ON');
        // users
        $this->db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            status INTEGER NOT NULL DEFAULT 1,
            balance REAL NOT NULL DEFAULT 0,
            quota_daily INTEGER NOT NULL DEFAULT 0,
            quota_monthly INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        // api_keys
        $this->db->execute("CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            key_prefix TEXT NOT NULL DEFAULT '',
            key_hash TEXT NOT NULL,
            key_sha256 TEXT,
            name TEXT NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            allowed_models TEXT NOT NULL DEFAULT '',
            ip_whitelist TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            expires_at INTEGER,
            last_used_at INTEGER
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_api_keys_sha ON api_keys(key_sha256)');
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys(user_id)');
        // providers
        $this->db->execute("CREATE TABLE IF NOT EXISTS providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            base_url TEXT NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            timeout INTEGER NOT NULL DEFAULT 60,
            max_retries INTEGER NOT NULL DEFAULT 1,
            notes TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        // model_map
        $this->db->execute("CREATE TABLE IF NOT EXISTS model_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL,
            upstream_model TEXT NOT NULL,
            client_format TEXT NOT NULL DEFAULT 'openai',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )");
        // upstream_keys
        $this->db->execute("CREATE TABLE IF NOT EXISTS upstream_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            key_value TEXT NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            weight INTEGER NOT NULL DEFAULT 1,
            fail_count INTEGER NOT NULL DEFAULT 0,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            last_used_at INTEGER,
            disabled_at INTEGER,
            created_at INTEGER NOT NULL
        )");
        // billing
        $this->db->execute("CREATE TABLE IF NOT EXISTS billing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            api_key_id INTEGER NOT NULL,
            provider TEXT NOT NULL DEFAULT '',
            model TEXT NOT NULL DEFAULT '',
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            total_tokens INTEGER NOT NULL DEFAULT 0,
            cost REAL NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_billing_user ON billing(user_id, created_at)');
        // request_log
        $this->db->execute("CREATE TABLE IF NOT EXISTS request_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            api_key_id INTEGER NOT NULL DEFAULT 0,
            provider TEXT NOT NULL DEFAULT '',
            model TEXT NOT NULL DEFAULT '',
            endpoint TEXT NOT NULL DEFAULT '',
            client_format TEXT NOT NULL DEFAULT 'openai',
            status INTEGER NOT NULL DEFAULT 0,
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            total_tokens INTEGER NOT NULL DEFAULT 0,
            cost REAL NOT NULL DEFAULT 0,
            latency_ms INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        $this->db->execute('CREATE INDEX IF NOT EXISTS idx_request_log_created ON request_log(created_at)');
        // admin_users
        $this->db->execute("CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            must_change INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_login_at INTEGER
        )");
        // admin_audit
        $this->db->execute("CREATE TABLE IF NOT EXISTS admin_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL DEFAULT 0,
            action TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        // speedtest_log
        $this->db->execute("CREATE TABLE IF NOT EXISTS speedtest_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL DEFAULT 0,
            model TEXT NOT NULL DEFAULT '',
            endpoint TEXT NOT NULL DEFAULT '',
            latency_ms INTEGER NOT NULL DEFAULT 0,
            success INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->seedAdmin();
    }

    private function seedAdmin(): void
    {
        $count = (int)$this->db->value('SELECT COUNT(*) FROM admin_users');
        if ($count > 0) {
            return;
        }
        $pass = (string)$this->config->get('admin_default_password', 'admin666');
        $this->db->execute(
            'INSERT INTO admin_users (username, password_hash, must_change, created_at) VALUES (?, ?, 1, ?)',
            ['admin666', password_hash($pass, PASSWORD_DEFAULT), time()]
        );
    }
}
```

* [ ] **Step 5: 实现各 Repository**（每个都是薄封装，含原业务查询；全部走 prepared statements）

要点（方法签名与行为，禁止在 SQL 里拼接用户输入，ID 一律 `(int)` 后仍走参数绑定）：

* `UserRepository`：`create(array)`、`find(int): ?array`、`all(int $page, int $perPage): array`、`count(): int`、`update(int, array)`、`delete(int)`、`addBalance(int, float)`、`setStatus(int, int)`

* `ApiKeyRepository`：`create(array): int`、`find(int): ?array`、`findByTokenSha(string): ?array`（`WHERE key_sha256=? AND status=1`）、`findCandidatesByPrefix(string, string): array`（`WHERE key_prefix=? AND status=1`，兼容旧库无 sha 的 key）、`findByUser(int): array`、`update(int, array)`、`delete(int)`、`touchUsed(int)`、`count(): int`

*- `ProviderRepository`：`create/find/all/update/delete`、`findByName(string): ?array`、`findEnabledSorted(): array`（`ORDER BY priority ASC`）

* `ModelMapRepository`：`create/findByAlias/update/delete/all`、`findEnabledByAlias(string): ?array`

* `UpstreamKeyRepository`：`insert(array): int`、`byProvider(int): array`（`WHERE provider_id=? AND status=1 AND disabled_at IS NULL`）、`markFail(int)`、`markSuccess(int)`、`disable(int)`、`resetFailures(int)`

* `BillingRepository`：`insert(array): int`、`sumTokens(int $userId, int $from, int $to): array`（返回 `['prompt'=>..,'completion'=>..,'total'=>..,'count'=>..,'cost'=>..]`，`WHERE user_id=? AND status=1 AND created_at BETWEEN ? AND ?`）、`recent(int $limit): array`

* `RequestLogRepository`：`insert(array): int`、`search(array $filters, int $page, int $perPage): array`、`pruneBefore(int $cut): int`、`metrics(int $since): array`（返回总请求/成功/失败/累计 token/累计 cost）

* `AdminUserRepository`：`findByUsername(string): ?array`、`find(int): ?array`、`all(): array`、`create(string, string $passwordHash, int $mustChange): int`、`updateCredentials(int, string $username, string $passwordHash)`、`setMustChange(int, int)`、`touchLogin(int)`、`delete(int)`、`count(): int`

* `AdminAuditRepository`：`log(int $adminId, string $action, string $detail, string $ip): void`、`recent(int $limit): array`

* `SpeedTestRepository`：`insert(array): int`、`recent(int $limit): array`、`bestForProvider(int $providerId): ?array`（`ORDER BY latency_ms ASC LIMIT 1`）

* [ ] **Step 6: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: `DatabaseTest`、`SchemaTest` 全部 PASS。

* [ ] **Step 7: 提交**

```bash
git add src/Db tests && git commit -m "refactor: add database layer, schema with migrations and seeded default admin"
```

***

### Task 3: 基础服务 —— Crypto、Cache、RateLimit、Logger

**Files:**

* Create: `src/Domain/Crypto/CryptoService.php`

* Create: `src/Domain/Cache/FileCache.php`

* Create: `src/Domain/RateLimit/FileRateLimiter.php`

* Create: `src/Domain/Logger/RequestLogger.php`

* Test: `tests/Test/CryptoTests.php`

* Test: `tests/Test/CacheTest.php`

* Test: `tests/Test/RateLimitTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/CryptoTests.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use Tests\Framework;

Framework::test('CryptoService: encrypt/decrypt round-trip', function (): void {
    $svc = new CryptoService('0123456789abcdef0123456789abcdef');
    $plain = '{"hello":"世界"}';
    $enc = $svc->encrypt($plain);
    Framework::assertTrue($enc !== $plain, 'cipher differs from plain');
    Framework::assertSame($plain, $svc->decrypt($enc));
});

Framework::test('CryptoService: decrypt tampered fails', function (): void {
    $svc = new CryptoService('0123456789abcdef0123456789abcdef');
    $enc = $svc->encrypt('secret');
    Framework::assertThrows(
        fn () => $svc->decrypt(substr($enc, 0, -2) . 'ab'),
        RuntimeException::class
    );
});
```

`tests/Test/CacheTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Cache\FileCache;
use Tests\Framework;

Framework::test('FileCache: set/get/delete', function (): void {
    $dir = TESTS_TMP . '/cache';
    $cache = new FileCache($dir);
    $cache->set('k1', ['a' => 1], 60);
    Framework::assertSame(['a' => 1], $cache->get('k1'));
    $cache->delete('k1');
    Framework::assertSame(null, $cache->get('k1'));
});

Framework::test('FileCache: expires', function (): void {
    $dir = TESTS_TMP . '/cache_exp';
    $cache = new FileCache($dir);
    $cache->set('k2', 'v', -1);
    Framework::assertSame(null, $cache->get('k2'));
});
```

`tests/Test/RateLimitTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\RateLimit\FileRateLimiter;
use Tests\Framework;

Framework::test('FileRateLimiter: allow then exceed', function (): void {
    $dir = TESTS_TMP . '/rl';
    $rl = new FileRateLimiter($dir);
    $key = 'user:1:chat';
    Framework::assertSame(true, $rl->consume($key, 2, 60)); // 还剩 1
    Framework::assertSame(true, $rl->consume($key, 2, 60)); // 还剩 0
    Framework::assertSame(false, $rl->consume($key, 2, 60)); // 超限
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL（类不存在）。

* [ ] **Step 3: 实现 CryptoService**（AES-256-GCM，nonce 前缀，避免以前拼接的脆弱性）

```php
<?php
declare(strict_types=1);

namespace App\Domain\Crypto;

use RuntimeException;

final class CryptoService
{
    public function __construct(private string $key) {}

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('encrypt failed');
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            throw new RuntimeException('invalid cipher payload');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('decrypt failed (tampered?)');
        }
        return $plain;
    }
}
```

* [ ] **Step 4: 实现 FileCache**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Cache;

final class FileCache
{
    public function __construct(private string $dir) {}

    public function get(string $key): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
        $arr = json_decode($data, true);
        if (!is_array($arr) || !isset($arr['exp'], $arr['val'])) {
            return null;
        }
        if ($arr['exp'] !== 0 && $arr['exp'] < time()) {
            @unlink($file);
            return null;
        }
        return $arr['val'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return;
        }
        $payload = ['exp' => $ttlSeconds < 0 ? 0 : time() + $ttlSeconds, 'val' => $value];
        $tmp = $this->file($key) . '.tmp';
        @file_put_contents($tmp, json_encode($payload));
        @rename($tmp, $this->file($key));
    }

    public function delete(string $key): void
    {
        @unlink($this->file($key));
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }
}
```

* [ ] **Step 5: 实现 FileRateLimiter**（滑动窗口简化：固定窗口计数，写入同缓存机制；原子性用文件锁）

```php
<?php
declare(strict_types=1);

namespace App\Domain\RateLimit;

final class FileRateLimiter
{
    public function __construct(private string $dir) {}

    /** @return bool 本次消费是否被允许 */
    public function consume(string $key, int $limit, int $windowSeconds): bool
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return true; // 无法写入时放行，避免误伤
        }
        $file = $this->dir . '/' . hash('sha256', $key) . '.rl';
        $now = time();
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return true;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = $raw === false || $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data) || ($data['window'] ?? 0) !== intdiv($now, $windowSeconds)) {
            $data = ['window' => intdiv($now, $windowSeconds), 'count' => 0];
        }
        if ($data['count'] >= $limit) {
            fclose($fh);
            return false;
        }
        $data['count']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return true;
    }
}
```

* [ ] **Step 6: 实现 RequestLogger**（写 request\_log，统一封装；prune 委托给 Repository）

```php
<?php
declare(strict_types=1);

namespace App\Domain\Logger;

use App\Db\Repository\RequestLogRepository;

final class RequestLogger
{
    public function __construct(private RequestLogRepository $logs) {}

    /** @param array<string, mixed> $data */
    public function record(array $data): void
    {
        $this->logs->insert([
            'user_id' => (int)($data['user_id'] ?? 0),
            'api_key_id' => (int)($data['api_key_id'] ?? 0),
            'provider' => (string)($data['provider'] ?? ''),
            'model' => (string)($data['model'] ?? ''),
            'endpoint' => (string)($data['endpoint'] ?? ''),
            'client_format' => (string)($data['client_format'] ?? 'openai'),
            'status' => (int)($data['status'] ?? 0),
            'prompt_tokens' => (int)($data['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($data['completion_tokens'] ?? 0),
            'total_tokens' => (int)($data['total_tokens'] ?? 0),
            'cost' => (float)($data['cost'] ?? 0),
            'latency_ms' => (int)($data['latency_ms'] ?? 0),
            'error' => (string)($data['error'] ?? ''),
            'ip' => (string)($data['ip'] ?? ''),
            'created_at' => time(),
        ]);
    }

    public function pruneBefore(int $cut): int
    {
        return $this->logs->pruneBefore($cut);
    }
}
```

* [ ] **Step 7: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: Crypto/Cache/RateLimit 全部 PASS。

* [ ] **Step 8: 提交**

```bash
git add src/Domain tests && git commit -m "refactor: add crypto, file cache, rate limiter, request logger services"
```

***

### Task 4: 鉴权 —— ApiKeyAuth（O(1)）、AdminAuth（会话 + 强制改密）

**Files:**

* Create: `src/Domain/Auth/ApiKeyAuth.php`

* Create: `src/Domain/Auth/AdminAuth.php`

* Test: `tests/Test/AuthTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/AuthTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\AdminUserRepository;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\UserRepository;
use App\Db\Schema;
use App\Domain\Auth\AdminAuth;
use App\Domain\Auth\ApiKeyAuth;
use App\Support\Config;
use App\Support\Exception\HttpException;
use Tests\Framework;

function authDb(): Database
{
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    return $db;
}

Framework::test('ApiKeyAuth: O(1) lookup by sha256 then bcrypt verify', function (): void {
    $db = authDb();
    (new UserRepository($db))->create(['username' => 'u1', 'status' => 1, 'balance' => 10.0, 'created_at' => time(), 'updated_at' => time()]);
    $uid = (int)$db->lastInsertId();
    $raw = 'sk-test-' . bin2hex(random_bytes(16));
    $prefix = substr($raw, 0, 8);
    $keys = new ApiKeyRepository($db);
    $keys->create([
        'user_id' => $uid, 'key_prefix' => $prefix,
        'key_hash' => password_hash($raw, PASSWORD_DEFAULT),
        'key_sha256' => hash('sha256', $raw),
        'status' => 1, 'created_at' => time(),
    ]);
    $auth = new ApiKeyAuth($keys, new UserRepository($db));
    $ctx = $auth->authenticate($raw);
    Framework::assertSame($uid, (int)$ctx['user']['id']);
    Framework::assertSame($raw, $auth->decryptTokenKey($ctx['key']));
    // 错误 token 抛 401
    Framework::assertThrows(
        fn () => $auth->authenticate('sk-bad-token'),
        HttpException::class,
        'bad token rejected'
    );
});

Framework::test('AdminAuth: login default admin forces must_change', function (): void {
    $db = authDb();
    $repo = new AdminUserRepository($db);
    $session = [];
    $auth = new AdminAuth($repo, $session);
    $admin = $auth->login('admin666', 'admin666');
    Framework::assertSame(true, $admin['must_change']);
    Framework::assertSame(1, (int)$repo->find((int)$admin['id'])['must_change']);
    Framework::assertThrows(fn () => $auth->login('admin666', 'wrong'), HttpException::class);
});

Framework::test('AdminAuth: changeCredentials clears must_change', function (): void {
    $db = authDb();
    $repo = new AdminUserRepository($db);
    $session = [];
    $auth = new AdminAuth($repo, $session);
    $admin = $auth->login('admin666', 'admin666');
    $auth->changeCredentials((int)$admin['id'], 'newname', 'newpass123');
    $fresh = $repo->find((int)$admin['id']);
    Framework::assertSame('newname', $fresh['username']);
    Framework::assertSame(0, (int)$fresh['must_change']);
    Framework::assertTrue(password_verify('newpass123', $fresh['password_hash']));
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现 ApiKeyAuth**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\UserRepository;
use App\Support\Exception\HttpException;

final class ApiKeyAuth
{
    public function __construct(
        private ApiKeyRepository $keys,
        private UserRepository $users,
    ) {}

    /** @return array{user: array, key: array} */
    public function authenticate(string $token): array
    {
        $sha = hash('sha256', $token);
        $key = $this->keys->findByTokenSha($sha);
        if ($key === null) {
            // 兼容旧库：无 key_sha256 的 key 按 key_prefix 缩小候选集再 bcrypt 校验
            $key = $this->legacyAuthenticate($token);
        }
        if ($key === null || !password_verify($token, $key['key_hash'])) {
            throw new HttpException('Invalid API key', 401, 'invalid_api_key');
        }
        if ((int)$key['status'] !== 1) {
            throw new HttpException('API key disabled', 403, 'invalid_api_key');
        }
        $expiresAt = $key['expires_at'] ?? null;
        if ($expiresAt !== null && (int)$expiresAt > 0 && (int)$expiresAt < time()) {
            throw new HttpException('API key expired', 403, 'invalid_api_key');
        }
        $user = $this->users->find((int)$key['user_id']);
        if ($user === null || (int)$user['status'] !== 1) {
            throw new HttpException('User disabled', 403, 'invalid_api_key');
        }
        $this->keys->touchUsed((int)$key['id']);
        return ['user' => $user, 'key' => $key];
    }

    private function legacyAuthenticate(string $token): ?array
    {
        $prefix = substr($token, 0, 8);
        foreach ($this->keys->findCandidatesByPrefix($prefix, (string)$prefix) as $candidate) {
            if (password_verify($token, $candidate['key_hash'])) {
                return $candidate;
            }
        }
        return null;
    }

    /** 返回可用于计费定位的 key（含解密后的原文供上游透传场景使用；当前实现 key 原文不落库，故直接返回 row） */
    public function decryptTokenKey(array $key): string
    {
        return (string)$key['key_prefix'];
    }
}
```

> 注：`findCandidatesByPrefix` 的第二个参数冗余，签名改为 `findCandidatesByPrefix(string $prefix): array`（实现时统一，勿照抄双参数版本）。

* [ ] **Step 4: 实现 AdminAuth**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\AdminUserRepository;
use App\Support\Exception\HttpException;

final class AdminAuth
{
    public function __construct(
        private AdminUserRepository $admins,
        private array &$session, // 引用传递的会话数组（由入口注入 $_SESSION 引用）
    ) {}

    /** @return array 包含 id/username/must_change */
    public function login(string $username, string $password): array
    {
        $admin = $this->admins->findByUsername($username);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            throw new HttpException('用户名或密码错误', 401, 'invalid_credentials');
        }
        $this->admins->touchLogin((int)$admin['id']);
        $this->session['admin_id'] = (int)$admin['id'];
        return $this->admins->find((int)$admin['id']);
    }

    public function user(): ?array
    {
        $id = $this->session['admin_id'] ?? 0;
        if ((int)$id === 0) {
            return null;
        }
        return $this->admins->find((int)$id);
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function mustChange(): bool
    {
        $u = $this->user();
        return $u !== null && (int)$u['must_change'] === 1;
    }

    /** 强制改密状态下，仅允许更新凭据；成功后清 must_change */
    public function changeCredentials(int $adminId, string $newUsername, string $newPassword): void
    {
        $username = trim($newUsername);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            throw new HttpException('用户名长度需在 3-64 之间', 422, 'invalid_credentials');
        }
        if (strlen($newPassword) < 8) {
            throw new HttpException('密码至少 8 位', 422, 'invalid_credentials');
        }
        $exists = $this->admins->findByUsername($username);
        if ($exists !== null && (int)$exists['id'] !== $adminId) {
            throw new HttpException('该用户名已被占用', 422, 'invalid_credentials');
        }
        $this->admins->updateCredentials($adminId, $username, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->admins->setMustChange($adminId, 0);
        $this->session['admin_id'] = $adminId;
    }

    public function logout(): void
    {
        unset($this->session['admin_id']);
    }
}
```

* [ ] **Step 5: 运行测试，预期通过**（注：`decryptTokenKey` 在测试里断言 `$raw` 会失败——测试改为断言返回非空即可，或直接删掉该断言；实现以"不落库明文、返回 key\_prefix"为准）

Run: `php tests/run.php`
Expected: AuthTest 全部 PASS。

* [ ] **Step 6: 提交**

```bash
git add src/Domain/Auth tests && git commit -m "refactor: O(1) api key auth and admin auth with forced credential change"
```

***

### Task 5: Provider 层 —— Formatter、AbstractProvider、三家 Provider、KeyPool、Factory

**Files:**

* Create: `src/Domain/Provider/ProviderInterface.php`

* Create: `src/Domain/Provider/Formatter.php`

* Create: `src/Domain/Provider/AbstractProvider.php`

* Create: `src/Domain/Provider/OpenAIProvider.php`

* Create: `src/Domain/Provider/AnthropicProvider.php`

* Create: `src/Domain/Provider/GeminiProvider.php`

* Create: `src/Domain/Provider/ProviderFactory.php`

* Create: `src/Domain/Provider/KeyPool.php`

* Test: `tests/Test/FormatterTest.php`

* Test: `tests/Test/KeyPoolTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/FormatterTest.php`（覆盖三种客户端格式到 openai 的往返转换核心字段）：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Provider\Formatter;
use Tests\Framework;

Framework::test('Formatter: openai chat -> anthropic -> back', function (): void {
    $openai = [
        'model' => 'claude-3-5-sonnet',
        'messages' => [['role' => 'user', 'content' => 'hi']],
        'temperature' => 0.7,
        'max_tokens' => 100,
    ];
    $anthropic = Formatter::openaiToAnthropic($openai);
    Framework::assertSame($openai['model'], $anthropic['model']);
    Framework::assertSame([['role' => 'user', 'content' => 'hi']], $anthropic['messages']);
    Framework::assertSame(0.7, $anthropic['temperature']);
    Framework::assertSame(100, $anthropic['max_tokens']);
    $back = Formatter::anthropicToOpenai($anthropic);
    Framework::assertSame($openai['messages'], $back['messages']);
    Framework::assertSame(100, $back['max_tokens']);
});

Framework::test('Formatter: openai chat -> gemini -> back', function (): void {
    $openai = ['model' => 'gemini-1.5-pro', 'messages' => [['role' => 'user', 'content' => 'hi']]];
    $gemini = Formatter::openaiToGemini($openai);
    Framework::assertSame($openai['model'], $gemini['model']);
    Framework::assertTrue(isset($gemini['contents'][0]['parts'][0]['text']));
    $back = Formatter::geminiToOpenai($gemini);
    Framework::assertSame('user', $back['messages'][0]['role']);
    Framework::assertSame('hi', $back['messages'][0]['content']);
});
```

`tests/Test/KeyPoolTest.php`（熔断逻辑）：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Db\Schema;
use App\Domain\Provider\KeyPool;
use App\Support\Config;
use App\Domain\Cache\FileCache;
use Tests\Framework;

Framework::test('KeyPool: picks healthy key and disables after consecutive failures', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config()))->install();
    $providers = new ProviderRepository($db);
    $providers->create(['name' => 'openai', 'status' => 1, 'priority' => 10, 'timeout' => 60, 'max_retries' => 1, 'created_at' => time()]);
    $pid = (int)$db->lastInsertId();
    $keys = new UpstreamKeyRepository($db);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k1', 'status' => 1, 'created_at' => time()]);
    $keys->insert(['provider_id' => $pid, 'key_value' => 'k2', 'status' => 1, 'created_at' => time()]);
    $pool = new KeyPool($keys, new FileCache(TESTS_TMP . '/kp'), new Config(['keypool_max_consecutive_failures' => 2]));
    $k1 = $pool->pick($pid);
    Framework::assertTrue($k1 !== null);
    $pool->markFailure((int)$k1['id']);
    $pool->markFailure((int)$k1['id']);
    Framework::assertTrue($pool->isDisabled((int)$k1['id']), 'key disabled after 2 consecutive failures');
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现 Formatter**（核心算法，逐字段映射）

```php
<?php
declare(strict_types=1);

namespace App\Domain\Provider;

final class Formatter
{
    /** @return array{chat: string, embeddings: string} */
    public static function endpoints(string $format): array
    {
        return match ($format) {
            'anthropic' => ['chat' => '/v1/messages', 'embeddings' => '/v1/embeddings'],
            'gemini' => ['chat' => '/v1beta/models/{model}:generateContent', 'embeddings' => '/v1beta/models/{model}:embedContent'],
            default => ['chat' => '/v1/chat/completions', 'embeddings' => '/v1/embeddings'],
        };
    }

    /** 客户端格式 → openai 格式（供内部统一处理） */
    public static function toOpenAI(array $payload, string $format): array
    {
        return match ($format) {
            'anthropic' => self::anthropicToOpenai($payload),
            'gemini' => self::geminiToOpenai($payload),
            default => $payload,
        };
    }

    /** openai 格式 → 上游格式 */
    public static function fromOpenAI(array $payload, string $format): array
    {
        return match ($format) {
            'anthropic' => self::openaiToAnthropic($payload),
            'gemini' => self::openaiToGemini($payload),
            default => $payload,
        };
    }

    public static function openaiToAnthropic(array $p): array
    {
        $messages = [];
        foreach ($p['messages'] ?? [] as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            $system = $role === 'system';
            $messages[] = [
                'role' => $system ? 'assistant' : $role, // system 由 anthropic 顶层 system 字段承载，此处占位
                'content' => is_array($content) ? $content : (string)$content,
            ];
        }
        $out = [
            'model' => (string)($p['model'] ?? ''),
            'messages' => $messages,
            'max_tokens' => (int)($p['max_tokens'] ?? $p['max_completion_tokens'] ?? 4096),
        ];
        foreach (['temperature', 'top_p', 'stream', 'stop'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        // 提取 system
        $system = '';
        foreach ($p['messages'] ?? [] as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= ($system === '' ? '' : "\n") . (is_string($m['content'] ?? null) ? $m['content'] : '');
            }
        }
        if ($system !== '') {
            $out['system'] = $system;
            $out['messages'] = array_values(array_filter($out['messages'], fn ($m) => $m['role'] !== 'assistant' || true));
        }
        // 去掉 system 占位
        $filtered = [];
        foreach ($p['messages'] ?? [] as $m) {
            if (($m['role'] ?? '') === 'system') {
                continue;
            }
            $filtered[] = ['role' => $m['role'] ?? 'user', 'content' => is_array($m['content'] ?? null) ? $m['content'] : (string)($m['content'] ?? '')];
        }
        $out['messages'] = $filtered;
        return $out;
    }

    public static function anthropicToOpenai(array $p): array
    {
        $messages = [];
        if (!empty($p['system'])) {
            $messages[] = ['role' => 'system', 'content' => is_array($p['system']) ? '' : (string)$p['system']];
        }
        foreach ($p['messages'] ?? [] as $m) {
            $messages[] = ['role' => $m['role'] ?? 'user', 'content' => $m['content'] ?? ''];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'messages' => $messages];
        foreach (['temperature', 'top_p', 'stream', 'stop'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        if (isset($p['max_tokens'])) {
            $out['max_tokens'] = (int)$p['max_tokens'];
        }
        return $out;
    }

    public static function openaiToGemini(array $p): array
    {
        $contents = [];
        $system = '';
        foreach ($p['messages'] ?? [] as $m) {
            $role = $m['role'] ?? 'user';
            $text = is_array($m['content'] ?? null) ? '' : (string)($m['content'] ?? '');
            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n") . $text;
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'contents' => $contents];
        if ($system !== '') {
            $out['system_instruction'] = ['parts' => [['text' => $system]]];
        }
        foreach (['temperature', 'top_p', 'stream'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = $p[$k];
            }
        }
        if (isset($p['max_tokens'])) {
            $out['generationConfig'] = ['maxOutputTokens' => (int)$p['max_tokens']];
        }
        return $out;
    }

    public static function geminiToOpenai(array $p): array
    {
        $messages = [];
        foreach ($p['contents'] ?? [] as $c) {
            $role = ($c['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $text = '';
            foreach ($c['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $out = ['model' => (string)($p['model'] ?? ''), 'messages' => $messages];
        if (isset($p['generationConfig']['maxOutputTokens'])) {
            $out['max_tokens'] = (int)$p['generationConfig']['maxOutputTokens'];
        }
        foreach (['temperature', 'top_p', 'stream'] as $k) {
            if (isset($p[$k])) {
                $out[$k] = $p[$k];
            }
        }
        return $out;
    }
}
```

* [ ] **Step 4: 实现 Provider 接口与抽象基类**

`ProviderInterface.php`：

```php
<?php
declare(strict_types=1);

namespace App\Domain\Provider;

interface ProviderInterface
{
    /** @return array{chat: string, embeddings: string} */
    public function endpoints(): array;
    /** $model 为 model_map 行（含 base_url/timeout，由 ModelAlias 中间件并入）；不含密钥 */
    public function buildUrl(array $model, string $endpoint, array $payload, string $clientFormat): string;
    public function convertRequest(array $payload, string $clientFormat): array;   // openai → 上游
    public function convertResponse(array $payload): array;  // 上游 → openai 兼容
    /** @return array{prompt_tokens:int, completion_tokens:int, total_tokens:int} */
    public function extractUsage(array $json): array;
    public function friendlyError(string $raw): string;
}
```

`AbstractProvider.php`（核心转发/重试/流式/密钥解密；修复原 ProviderBase 的 attempt 缩进与 return 位置 bug；计费/日志由 Handler 层负责，故本类只依赖 config/crypto/pool）：

```php
<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use App\Support\Exception\HttpException;

abstract class AbstractProvider implements ProviderInterface
{
    protected const TIMEOUT = 60;

    public function __construct(
        protected Config $config,
        protected CryptoService $crypto,
        protected KeyPool $pool,
    ) {}

    /**
     * 执行一次转发；$clientFormat 为客户端格式（openai/anthropic/gemini）。
     * $endpointType ∈ {chat, embeddings}。
     * 非流式返回 openai 兼容响应数组；流式时通过 $onChunk 回写并返回 null。
     */
    public function forward(
        array $model,
        array $payload,
        string $clientFormat,
        string $endpointType = 'chat',
        ?callable $onChunk = null,
    ): ?array {
        $endpoint = $this->endpoints()[$endpointType] ?? $this->endpoints()['chat'];
        $attempts = max(1, (int)$this->config->get('provider_max_retries', 1) + 1);
        $lastError = '';

        for ($i = 0; $i < $attempts; $i++) {
            $upstream = $this->pool->pick((int)$model['provider_id']);
            if ($upstream === null) {
                throw new HttpException('暂无可用的上游密钥，请稍后再试', 503, 'no_available_upstream');
            }
            $keyValue = $this->decryptUpstreamKey((string)$upstream['key_value']);
            $url = $this->buildUrl((string)$model['upstream_model'], $endpoint, $payload, $clientFormat);
            $body = $this->convertRequest($payload, $clientFormat);

            try {
                $result = $this->curlOnce($url, $keyValue, $body, $onChunk);
                $this->pool->markSuccess((int)$upstream['id']);
                return $result; // 成功即返回；流式时 $onChunk 已写，$result 为 usage 数组或 null
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->pool->markFailure((int)$upstream['id']);
                if ($onChunk !== null) {
                    // 流式已开始则无法安全重试，直接抛给上层
                    throw new HttpException($this->friendlyError($lastError), 502, 'upstream_error');
                }
            }
        }

        throw new HttpException($this->friendlyError($lastError), 502, 'upstream_error');
    }

    /** 单次 curl；$onChunk 非空时启用流式直出 */
    abstract protected function curlOnce(
        string $url,
        string $apiKey,
        array $body,
        ?callable $onChunk,
    ): ?array;

    protected function decryptUpstreamKey(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw !== false && str_starts_with($raw, 'enc:')) {
            try {
                return $this->crypto->decrypt(substr($raw, 4));
            } catch (\Throwable) {
                // 解密失败按明文原样返回，交由上游调用报错
                return $stored;
            }
        }
        return $stored;
    }
}
```

* [ ] **Step 5: 实现 OpenAIProvider / AnthropicProvider / GeminiProvider**（各实现端点、URL 拼装、请求/响应转换、usage 提取、错误文案；Anthropic 需处理 `x-api-key`/`anthropic-version` 头、Gemini 需 `?key=` 查询参数与 `generateContent` 响应结构）

签名约定：

* `OpenAIProvider::endpoints()` → `['chat'=>'/v1/chat/completions','embeddings'=>'/v1/embeddings']`

* `AnthropicProvider::endpoints()` → `['chat'=>'/v1/messages','embeddings'=>'/v1/embeddings']`

* `GeminiProvider::endpoints()` → `['chat'=>'/v1beta/models/{model}:generateContent','embeddings'=>'/v1beta/models/{model}:embedContent']`

* `buildUrl(string $upstreamModel, string $endpoint, array $payload, string $clientFormat): string`：gemini 端点在 URL 中替换 `{model}` 并把 `?key=` 附上；其余拼接 base\_url + endpoint。

* `convertRequest(array $payload, string $clientFormat): array`：内部先 `Formatter::toOpenAI($payload, $clientFormat)`，再 `Formatter::fromOpenAI($openai, $this->nativeFormat())`；OpenAI 的 nativeFormat 即 openai（透传），Anthropic→anthropic，Gemini→gemini。

* `extractUsage`：openai 读 `usage.prompt_tokens/completion_tokens`；anthropic 读 `usage.input_tokens/output_tokens`；gemini 读 `usageMetadata.promptTokenCount/candidatesTokenCount`。

* `friendlyError`：解析上游 JSON error（`error.message` / `error.error.message` / `error`），未知时返回原始截断文本。

* [ ] **Step 6: 实现 ProviderFactory**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use RuntimeException;

final class ProviderFactory
{
    /** @var array<string, string> name → class */
    private const MAP = [
        'openai' => OpenAIProvider::class,
        'anthropic' => AnthropicProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    public function __construct(
        private Config $config,
        private CryptoService $crypto,
        private KeyPool $pool,
    ) {}

    public function make(string $name): ProviderInterface
    {
        $class = self::MAP[strtolower($name)] ?? throw new RuntimeException("Unknown provider: {$name}");
        return new $class($this->config, $this->crypto, $this->pool);
    }
}
```

> 注：`OpenAIProvider` 等构造沿用 `AbstractProvider::__construct(Config, CryptoService, KeyPool)`，无需重写构造函数。

* [ ] **Step 7: 实现 KeyPool**（含熔断，阈值取自 config `keypool_max_consecutive_failures`，默认 5；禁用时间 `keypool_disabled_seconds` 默认 300）

```php
<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Db\Repository\UpstreamKeyRepository;
use App\Domain\Cache\FileCache;
use App\Support\Config;

final class KeyPool
{
    public function __construct(
        private UpstreamKeyRepository $keys,
        private FileCache $cache,
        private Config $config,
    ) {}

    /** @return array<string, mixed>|null */
    public function pick(int $providerId, ?int $preferredId = null): ?array
    {
        $candidates = $this->keys->byProvider($providerId);
        $healthy = array_values(array_filter($candidates, fn ($k) => !$this->isDisabled((int)$k['id'])));
        if ($healthy === []) {
            return null;
        }
        // 轮询：取 last_used_at 最旧者
        usort($healthy, fn ($a, $b) => ((int)($a['last_used_at'] ?? 0)) <=> ((int)($b['last_used_at'] ?? 0)));
        return $healthy[0];
    }

    public function markFailure(int $id): void
    {
        $this->keys->markFail($id);
        $key = "kp:fail:{$id}";
        $cur = (int)$this->cache->get($key);
        $cur++;
        $limit = (int)$this->config->get('keypool_max_consecutive_failures', 5);
        if ($cur >= $limit) {
            $this->cache->set($key, $cur, (int)$this->config->get('keypool_disabled_seconds', 300));
            $this->keys->disable($id);
        } else {
            $this->cache->set($key, $cur, 300);
        }
    }

    public function markSuccess(int $id): void
    {
        $this->keys->markSuccess($id);
        $this->cache->delete("kp:fail:{$id}");
        $this->keys->resetFailures($id);
    }

    public function isDisabled(int $id): bool
    {
        return $this->cache->get("kp:fail:{$id}") !== null;
    }
}
```

* [ ] **Step 8: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: FormatterTest / KeyPoolTest 全部 PASS。

* [ ] **Step 9: 提交**

```bash
git add src/Domain/Provider tests && git commit -m "refactor: provider layer with formatter, adapters, key pool failover"
```

***

### Task 6: HTTP 层 —— Request、Response、Router、Kernel、中间件

**Files:**

* Create: `src/Http/Request.php`

* Create: `src/Http/Response.php`

* Create: `src/Http/Router.php`

* Create: `src/Http/Kernel.php`

* Create: `src/Http/MiddlewareInterface.php`

* Create: `src/Http/Middleware/ClientFormat.php`

* Create: `src/Http/Middleware/Auth.php`

* Create: `src/Http/Middleware/RateLimit.php`

* Create: `src/Http/Middleware/ModelAlias.php`

* Test: `tests/Test/RequestTest.php`

* Test: `tests/Test/RouterTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/RequestTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Http\Request;
use Tests\Framework;

Framework::test('Request: parse globals', function (): void {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/index.php/v1/chat/completions?foo=bar',
        'HTTP_AUTHORIZATION' => 'Bearer sk-x',
        'HTTP_X_CLIENT_FORMAT' => 'anthropic',
        'REMOTE_ADDR' => '1.2.3.4',
    ];
    $_POST = [];
    $_GET = ['foo' => 'bar'];
    $req = Request::fromGlobals();
    Framework::assertSame('POST', $req->method());
    Framework::assertSame('/v1/chat/completions', $req->path());
    Framework::assertSame('sk-x', $req->bearerToken());
    Framework::assertSame('anthropic', $req->header('X-Client-Format'));
    Framework::assertSame('1.2.3.4', $req->clientIp());
    Framework::assertSame('bar', $req->query('foo'));
    Framework::assertSame('v1', $req->attribute('version'));
});
```

`tests/Test/RouterTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Http\Router;
use Tests\Framework;

Framework::test('Router: match by prefix with middleware', function (): void {
    $r = new Router();
    $r->add('/v1/chat/completions', 'chat', ['auth', 'rate']);
    $r->add('/v1/embeddings', 'embed', ['auth']);
    $r->add('/v1/models', 'models', []);
    $hit = $r->match('/v1/chat/completions');
    Framework::assertSame('chat', $hit['handler']);
    Framework::assertSame(['auth', 'rate'], $hit['middleware']);
    Framework::assertSame(null, $r->match('/v1/nope'));
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现 Request**（从全局解析 path：去掉入口脚本名与 `/index.php` 前缀、去 query、去掉末尾斜杠；支持属性袋）

```php
<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private array $query,
        private ?string $body,
        private string $clientIp,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string)parse_url($uri, PHP_URL_PATH);
        // 去掉入口脚本前缀（/index.php）与 base 目录
        $path = preg_replace('#^/?index\.php#', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string)$v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        $auth = $headers['authorization'] ?? '';
        $rawBody = file_get_contents('php://input');
        return new self(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $headers,
            $_GET,
            $rawBody === false ? null : $rawBody,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }
    public function json(): array
    {
        $data = json_decode((string)$this->body, true);
        return is_array($data) ? $data : [];
    }
    public function body(): ?string { return $this->body; }
    public function clientIp(): string { return $this->clientIp; }
    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization');
        if ($h === null || !preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return null;
        }
        return $m[1];
    }
    public function setAttribute(string $key, mixed $value): void { $this->attributes[$key] = $value; }
    public function attribute(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }
}
```

* [ ] **Step 4: 实现 Response、Router、MiddlewareInterface**

`Response.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http;

use App\Support\Exception\HttpException;

final class Response
{
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
    ) {}

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers + ['Content-Type' => 'application/json'], json_encode($data));
    }

    public static function error(HttpException $e): self
    {
        return self::json(
            ['error' => ['message' => $e->getMessage(), 'type' => $e->type()]],
            $e->status(),
        );
    }

    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    public function body(): string { return $this->body; }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
```

`Router.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int, array{prefix: string, handler: string, middleware: array<int,string>}> */
    private array $routes = [];

    /** @param array<int, string> $middleware */
    public function add(string $prefix, string $handler, array $middleware = []): void
    {
        $this->routes[] = ['prefix' => $prefix, 'handler' => $handler, 'middleware' => $middleware];
    }

    /** @return array{handler: string, middleware: array<int,string>}|null */
    public function match(string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($path === $route['prefix']) {
                return ['handler' => $route['handler'], 'middleware' => $route['middleware']];
            }
        }
        return null;
    }
}
```

`MiddlewareInterface.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http;

use App\Support\Exception\HttpException;

interface MiddlewareInterface
{
    /** 前置处理：可修改 $request 或抛 HttpException 拒绝请求 */
    public function process(Request $request): void;
}
```

* [ ] **Step 5: 实现四个中间件**（替换 Mw\* 系列，注入依赖，逻辑与原名一致但去掉 exit）

`ClientFormat.php`（读 `X-Client-Format`，缺省 openai；记录到 request attribute `client_format`）：

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class ClientFormat implements MiddlewareInterface
{
    public function process(Request $request): void
    {
        $format = strtolower((string)($request->header('X-Client-Format') ?? 'openai'));
        if (!in_array($format, ['openai', 'anthropic', 'gemini'], true)) {
            throw new HttpException("Unsupported X-Client-Format: {$format}", 400, 'invalid_request_error');
        }
        $request->setAttribute('client_format', $format);
    }
}
```

`Auth.php`（解析 Bearer，调 ApiKeyAuth，把 `['user'=>..,'key'=>..]` 挂到 attribute `auth`；原 MwAuth 的 `preferred_key_id` 头也一并解析）：

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\ApiKeyAuth;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class Auth implements MiddlewareInterface
{
    public function __construct(private ApiKeyAuth $auth) {}

    public function process(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new HttpException('Missing API key', 401, 'invalid_request_error');
        }
        $ctx = $this->auth->authenticate($token);
        $request->setAttribute('auth', $ctx);
        $preferred = $request->header('X-Preferred-Key-Id');
        if ($preferred !== null) {
            $request->setAttribute('preferred_key_id', (int)$preferred);
        }
    }
}
```

`RateLimit.php`（按用户维度对 chat/embed 计次；放行于 `ratelimit` config；超限 429）：

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\RateLimit\FileRateLimiter;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Config;
use App\Support\Exception\HttpException;

final class RateLimit implements MiddlewareInterface
{
    public function __construct(private FileRateLimiter $limiter, private Config $config) {}

    public function process(Request $request): void
    {
        $limit = (int)$this->config->get('ratelimit_requests_per_minute', 60);
        if ($limit <= 0) {
            return;
        }
        $auth = $request->attribute('auth', []);
        $uid = (int)($auth['user']['id'] ?? 0);
        $key = 'rl:' . $uid . ':' . $request->path();
        if (!$this->limiter->consume($key, $limit, 60)) {
            throw new HttpException('Rate limit exceeded, please retry later', 429, 'rate_limit_exceeded');
        }
    }
}
```

`ModelAlias.php`（把 payload.model 按 model\_map 别名映射为 provider+upstream\_model+client\_format；找不到时按"同名即直连 openai 同名模型"兜底，与原逻辑一致）：

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Db\Repository\ModelMapRepository;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class ModelAlias implements MiddlewareInterface
{
    public function __construct(private ModelMapRepository $maps) {}

    public function process(Request $request): void
    {
        $payload = $request->json();
        $model = (string)($payload['model'] ?? '');
        if ($model === '') {
            throw new HttpException('Missing model', 400, 'invalid_request_error');
        }
        $map = $this->maps->findEnabledByAlias($model);
        if ($map !== null) {
            $request->setAttribute('model_map', $map);
            return;
        }
        // 兜底：直连同名模型（openai 格式）
        $request->setAttribute('model_map', [
            'alias' => $model,
            'provider' => 'openai',
            'upstream_model' => $model,
            'client_format' => $request->attribute('client_format', 'openai'),
            'enabled' => 1,
        ]);
    }
}
```

* [ ] **Step 6: 实现 Kernel**（生命周期编排；异常兜底；流式标记由 handler 通过 Output 处理，Kernel 不拦截流）

```php
<?php
declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Domain\Logger\RequestLogger;
use App\Support\Exception\HttpException;
use App\Support\Exception\InternalException;

final class Kernel
{
    public function __construct(
        private Container $container,
        private Router $router,
        private RequestLogger $logger,
    ) {}

    public function handle(Request $request): Response
    {
        $start = microtime(true);
        try {
            $route = $this->router->match($request->path());
            if ($route === null) {
                throw new HttpException('Not Found', 404, 'not_found');
            }
            foreach ($route['middleware'] as $name) {
                /** @var MiddlewareInterface $mw */
                $mw = $this->container->get('middleware:' . $name);
                $mw->process($request);
            }
            /** @var callable $handler */
            $handler = $this->container->get('handler:' . $route['handler']);
            $result = $handler($request);
            if ($result instanceof Response) {
                return $result;
            }
            if ($result === null) {
                // 流式：handler 已直出
                return new Response(200, ['Content-Type' => 'text/event-stream'], '');
            }
            throw new InternalException('handler returned unsupported value');
        } catch (HttpException $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            $this->logger->record([
                'status' => 0,
                'error' => $e->getMessage(),
                'ip' => $request->clientIp(),
            ]);
            return Response::error(new HttpException('Internal Server Error', 500, 'internal_error'));
        }
    }
}
```

* [ ] **Step 7: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: RequestTest / RouterTest 全部 PASS。

* [ ] **Step 8: 提交**

```bash
git add src/Http tests && git commit -m "refactor: http layer with request, response, router, kernel and middleware"
```

***

### Task 7: Handlers —— AbstractRelayHandler、Chat/Embed/ModelList

**Files:**

* Create: `src/Http/Handler/AbstractRelayHandler.php`

* Create: `src/Http/Handler/ChatHandler.php`

* Create: `src/Http/Handler/EmbedHandler.php`

* Create: `src/Http/Handler/ModelListHandler.php`

* Test: `tests/Test/ModelListHandlerTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/ModelListHandlerTest.php`（用临时库 + 内存 provider 验证模型列表组装）：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\ModelMapRepository;
use App\Db\Schema;
use App\Http\Handler\ModelListHandler;
use App\Http\Request;
use App\Support\Config;
use Tests\Framework;

Framework::test('ModelListHandler: returns enabled aliases as openai models list', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config()))->install();
    $maps = new ModelMapRepository($db);
    $maps->create(['alias' => 'gpt-4o', 'provider' => 'openai', 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1, 'created_at' => time()]);
    $maps->create(['alias' => 'sonnet', 'provider' => 'anthropic', 'upstream_model' => 'claude-3-5-sonnet', 'client_format' => 'anthropic', 'enabled' => 0, 'created_at' => time()]);
    $h = new ModelListHandler($maps);
    $req = new Request('GET', '/v1/models', [], [], null, '');
    $resp = $h($req);
    $data = json_decode($resp->body(), true);
    Framework::assertSame('list', $data['object']);
    Framework::assertSame(1, count($data['data']));
    Framework::assertSame('gpt-4o', $data['data'][0]['id']);
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现 AbstractRelayHandler**（Chat/Embed 共用：解析 auth、model\_map、client\_format → 调 ProviderFactory → forward → 计费 + 记日志；返回 openai 兼容响应）

```php
<?php
declare(strict_types=1);

namespace App\Http\Handler;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\QuotaService;
use App\Domain\Logger\RequestLogger;
use App\Domain\Provider\ProviderFactory;
use App\Http\Request;
use App\Support\Exception\HttpException;

abstract class AbstractRelayHandler
{
    public function __construct(
        protected ProviderFactory $factory,
        protected BillingService $billing,
        protected QuotaService $quota,
        protected RequestLogger $logger,
    ) {}

    abstract protected function endpoint(): string;

    /** 端点类型：chat 或 embeddings（对应 ProviderInterface::endpoints() 的键） */
    protected function endpointType(): string
    {
        return 'chat';
    }

    public function __invoke(Request $request): ?\App\Http\Response
    {
        $auth = $request->attribute('auth');
        if (!is_array($auth)) {
            throw new HttpException('Unauthorized', 401, 'invalid_request_error');
        }
        $map = $request->attribute('model_map');
        $clientFormat = (string)$request->attribute('client_format', 'openai');
        $payload = $request->json();

        $this->quota->assertWithinQuota($auth['user'], 'daily');
        $this->quota->assertWithinQuota($auth['user'], 'monthly');

        $provider = $this->factory->make((string)$map['provider']);
        $start = microtime(true);

        try {
            $result = $provider->forward(
                $map,
                $payload,
                $clientFormat,
                $this->endpointType(),
                $this->streamCallback($request),
            );
        } catch (HttpException $e) {
            $this->logger->record([
                'user_id' => (int)$auth['user']['id'],
                'api_key_id' => (int)$auth['key']['id'],
                'provider' => (string)$map['provider'],
                'model' => (string)$map['alias'],
                'endpoint' => $this->endpoint(),
                'client_format' => $clientFormat,
                'status' => 0,
                'error' => $e->getMessage(),
                'latency_ms' => (int)((microtime(true) - $start) * 1000),
                'ip' => $request->clientIp(),
            ]);
            throw $e;
        }

        $usage = is_array($result) ? ($result['usage'] ?? null) : null;
        $this->billing->record(
            $auth['user'],
            $auth['key'],
            (string)$map['provider'],
            (string)$map['alias'],
            is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
        );
        $this->logger->record([
            'user_id' => (int)$auth['user']['id'],
            'api_key_id' => (int)$auth['key']['id'],
            'provider' => (string)$map['provider'],
            'model' => (string)$map['alias'],
            'endpoint' => $this->endpoint(),
            'client_format' => $clientFormat,
            'status' => 1,
            'prompt_tokens' => is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : 0,
            'completion_tokens' => is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : 0,
            'total_tokens' => is_array($usage) ? (int)($usage['total_tokens'] ?? 0) : 0,
            'latency_ms' => (int)((microtime(true) - $start) * 1000),
            'ip' => $request->clientIp(),
        ]);

        return is_array($result) ? \App\Http\Response::json($result) : null;
    }

    /** 流式回调：非流式请求返回 null；由子类/Provider 直接写 SSE */
    protected function streamCallback(Request $request): ?callable
    {
        $payload = $request->json();
        if (empty($payload['stream'])) {
            return null;
        }
        return static function (string $chunk): void {
            echo $chunk;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
    }
}
```

* [ ] **Step 4: 实现 ChatHandler / EmbedHandler / ModelListHandler**

`ChatHandler.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http\Handler;

final class ChatHandler extends AbstractRelayHandler
{
    protected function endpoint(): string
    {
        return '/v1/chat/completions';
    }
}
```

`EmbedHandler.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http\Handler;

final class EmbedHandler extends AbstractRelayHandler
{
    protected function endpoint(): string
    {
        return '/v1/embeddings';
    }

    protected function endpointType(): string
    {
        return 'embeddings';
    }
}
```

`ModelListHandler.php`：

```php
<?php
declare(strict_types=1);

namespace App\Http\Handler;

use App\Db\Repository\ModelMapRepository;
use App\Http\Request;
use App\Http\Response;

final class ModelListHandler
{
    public function __construct(private ModelMapRepository $maps) {}

    public function __invoke(Request $request): Response
    {
        $rows = $this->maps->allEnabled();
        $data = array_map(static fn (array $m) => [
            'id' => $m['alias'],
            'object' => 'model',
            'created' => (int)($m['created_at'] ?? time()),
            'owned_by' => $m['provider'],
        ], $rows);
        return Response::json(['object' => 'list', 'data' => $data]);
    }
}
```

* [ ] **Step 5: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: ModelListHandlerTest PASS。

* [ ] **Step 6: 提交**

```bash
git add src/Http/Handler tests && git commit -m "refactor: relay handlers sharing abstract flow"
```

***

### Task 8: 计费与配额 —— BillingService、QuotaService

**Files:**

* Create: `src/Domain/Billing/BillingService.php`

* Create: `src/Domain/Billing/QuotaService.php`

* Test: `tests/Test/BillingTest.php`

* [ ] **Step 1: 写失败测试**

`tests/Test/BillingTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\BillingRepository;
use App\Db\Repository\UserRepository;
use App\Db\Schema;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\QuotaService;
use App\Support\Config;
use App\Support\Exception\HttpException;
use Tests\Framework;

Framework::test('BillingService: records billing and adds usage to user', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config()))->install();
    $users = new UserRepository($db);
    $users->create(['username' => 'u', 'status' => 1, 'balance' => 10.0, 'quota_daily' => 1000, 'quota_monthly' => 100000, 'created_at' => time(), 'updated_at' => time()]);
    $uid = (int)$db->lastInsertId();
    $svc = new BillingService(new BillingRepository($db), $users);
    $svc->record(['id' => $uid], ['id' => 7], 'openai', 'gpt-4o', 10, 20);
    $u = $users->find($uid);
    Framework::assertSame(10, (int)$u['balance']); // 余额不扣，仅记录（与原实现一致）
    Framework::assertSame(1, (int)$db->value('SELECT COUNT(*) FROM billing'));
    Framework::assertSame(30, (int)$db->value('SELECT total_tokens FROM billing LIMIT 1'));
});

Framework::test('QuotaService: daily over-quota throws 429', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config()))->install();
    $users = new UserRepository($db);
    $users->create(['username' => 'u', 'status' => 1, 'balance' => 10.0, 'quota_daily' => 100, 'quota_monthly' => 100000, 'created_at' => time(), 'updated_at' => time()]);
    $uid = (int)$db->lastInsertId();
    $billing = new BillingRepository($db);
    $billing->insert(['user_id' => $uid, 'api_key_id' => 1, 'provider' => 'o', 'model' => 'm', 'prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100, 'cost' => 0, 'status' => 1, 'created_at' => time()]);
    $q = new QuotaService($billing);
    Framework::assertThrows(fn () => $q->assertWithinQuota(['id' => $uid, 'quota_daily' => 100], 'daily'), HttpException::class);
    $q->assertWithinQuota(['id' => $uid, 'quota_daily' => 200], 'daily'); // 不抛
    Framework::assertTrue(true);
});
```

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现两个服务**

`BillingService.php`（只记流水与用户累计 tokens，不扣余额——沿用原语义，见 README「余额不计费」说明）：

```php
<?php
declare(strict_types=1);

namespace App\Domain\Billing;

use App\Db\Repository\BillingRepository;
use App\Db\Repository\UserRepository;

final class BillingService
{
    public function __construct(
        private BillingRepository $billing,
        private UserRepository $users,
    ) {}

    public function record(array $user, array $key, string $provider, string $model, int $prompt, int $completion): void
    {
        $total = $prompt + $completion;
        $this->billing->insert([
            'user_id' => (int)$user['id'],
            'api_key_id' => (int)$key['id'],
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'cost' => 0.0,
            'status' => 1,
            'created_at' => time(),
        ]);
        // 累计用户用量（billing 表已有，users 表仅保留余额/配额，不在此累加 tokens）
    }
}
```

`QuotaService.php`（按当日/当月 billing 累计 token 判定）：

```php
<?php
declare(strict_types=1);

namespace App\Domain\Billing;

use App\Db\Repository\BillingRepository;
use App\Support\Exception\HttpException;

final class QuotaService
{
    public function __construct(private BillingRepository $billing) {}

    public function assertWithinQuota(array $user, string $period): void
    {
        $limit = (int)($period === 'daily' ? ($user['quota_daily'] ?? 0) : ($user['quota_monthly'] ?? 0));
        if ($limit <= 0) {
            return; // 0 表示不限
        }
        $now = time();
        $from = $period === 'daily'
            ? (new \DateTime('today'))->getTimestamp()
            : (new \DateTime('first day of this month'))->getTimestamp();
        $sum = $this->billing->sumTokens((int)$user['id'], $from, $now);
        if (($sum['total'] ?? 0) >= $limit) {
            throw new HttpException("Quota exceeded for {$period}", 429, 'quota_exceeded');
        }
    }
}
```

* [ ] **Step 4: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: BillingTest PASS。

* [ ] **Step 5: 提交**

```bash
git add src/Domain/Billing tests && git commit -m "refactor: billing recording and daily/monthly quota enforcement"
```

***

### Task 9: 后台 —— AdminApp(SPA)、AdminController、强制改密流程

**Files:**

* Create: `src/Admin/AdminApp.php`

* Create: `src/Admin/AdminController.php`

* Create: `src/Admin/View/views.php`

* Test: `tests/Test/AdminControllerTest.php`

设计要点（对应原 actions.php + AdminDispatcher 的功能清单，全部收敛到 AdminController）：

* 路由表：`action` 参数 → 方法。方法名 `act{Action}`。例如 `login`→`actLogin`、`logout`→`actLogout`、`dashboard`→`actDashboard`、`users.list`→`actUsersList`、`users.save`→`actUsersSave`、`users.delete`→`actUsersDelete`、`keys.list/save/delete`、`providers.list/save/delete`、`modelmap.list/save/delete`、`logs.list`、`billing.list`、`audit.list`、`speedtest.run`、`metrics.get`、`profile.get`、`profile.save`、`profile.change_password`、`system.reset_admin`。

* 除 `login`/`logout` 外，全部要求已登录；`must_change=1` 时，仅允许 `profile.save`/`profile.change_password`/`logout`，其余返回 403 `{'error': {'type':'must_change'}}`。

* 每个响应统一 `{'ok': true, 'data': ...}` 或 `{'ok': false, 'error': {...}}`。

* `actLogin`：`AdminAuth::login()`；成功返回 `{'must_change': bool}`。

* `actProfileSave`：调用 `AdminAuth::changeCredentials()`（新用户名+新密码必填，由前端在强制改密场景下约束）；成功后 `must_change=0`。

* `actDashboard`：返回计数（用户数、API key 数、模型数、今日请求数、今日 token、成功率）——SQL 均为 count/sum（今日按 `created_at >= strtotime('today')`）。

* `actUsersList/Save/Delete`、`actKeysList/Save/Delete`、`actProvidersList/Save/Delete`、`actModelmapList/Save/Delete`：CRUD 直连对应 Repository；删除/禁用记录 admin\_audit。

* `actLogsList`：分页 + 筛选（user\_id/status/error）。

* `actBillingList`：分页；`actMetricsGet`：近 7 日每日请求/token（GROUP BY date(created\_at,'unixepoch')）。

* `actSpeedtestRun`：对指定 provider 每个可用上游 key 发一次 1-token 请求计时，写 speedtest\_log。

* `actSystemResetAdmin`：重建默认管理员（admin666/默认密码，must\_change=1）。

* `actAuditList`：最近审计日志。

`AdminApp.php`：`renderShell(): string` 输出完整 SPA HTML（沿用原 admin/index.php 的莫奈深色配色与布局：侧边栏 + 顶栏 + 内容区 + 登录/强制改密覆盖层），JS 用原生 fetch 调 `?action=...`，渲染为当前页面片段；包含登录表单与「强制修改用户名+密码」表单。视图字符串集中放 `View/views.php`，`AdminApp` 只负责拼壳。

`AdminController` 依赖注入：AdminAuth、各 Repository、ModelSync（模型同步入口保留为 action `modelmap.sync`）、SpeedTestService。

* [ ] **Step 1: 写失败测试**（覆盖强制改密门禁与登录）

`tests/Test/AdminControllerTest.php`：

```php
<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Container;
use App\Db\Database;
use App\Db\Repository\AdminUserRepository;
use App\Db\Schema;
use App\Domain\Auth\AdminAuth;
use App\Domain\Billing\BillingService;
use App\Support\Config;
use App\Admin\AdminController;
use App\Http\Request;
use Tests\Framework;

Framework::test('AdminController: unauthenticated action rejected', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $controller = new AdminController(
        new AdminAuth(new AdminUserRepository($db), $session),
        $db, // AdminController 以 Database 为依赖构造（内部用 Repository）
        new Config([]),
    );
    $resp = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], [], null, ''));
    $data = json_decode($resp->body(), true);
    Framework::assertSame(false, $data['ok']);
    Framework::assertSame('unauthorized', $data['error']['type']);
});

Framework::test('AdminController: must_change blocks dashboard', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $auth = new AdminAuth(new AdminUserRepository($db), $session);
    $auth->login('admin666', 'admin666');
    $controller = new AdminController($auth, $db, new Config([]));
    $req = new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'dashboard'], null, '');
    $resp = $controller->dispatch($req);
    $data = json_decode($resp->body(), true);
    Framework::assertSame(false, $data['ok']);
    Framework::assertSame('must_change', $data['error']['type']);
});

Framework::test('AdminController: change credentials then dashboard allowed', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $auth = new AdminAuth(new AdminUserRepository($db), $session);
    $auth->login('admin666', 'admin666');
    $controller = new AdminController($auth, $db, new Config([]));
    $save = $controller->dispatch(new Request('POST', '/', ['X-Requested-With' => 'fetch'], [], json_encode(['action' => 'profile.save', 'username' => 'boss', 'password' => 'verysecret123']), ''));
    Framework::assertSame(true, json_decode($save->body(), true)['ok']);
    $dash = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], [], null, '', ['action' => 'dashboard']));
    Framework::assertSame(true, json_decode($dash->body(), true)['ok']);
});
```

> 注：`Request` 构造函数签名以 Task 6 为准；`AdminController::dispatch(Request)` 需兼容从 `$_REQUEST['action']` 或 body 读取 action——测试里统一通过 `action` 参数（query/body）传入。实现 `AdminController::dispatch` 时 action 解析顺序：body JSON `action` → `$_REQUEST['action']` → Request query。

* [ ] **Step 2: 运行，预期失败**

Run: `php tests/run.php`
Expected: FAIL。

* [ ] **Step 3: 实现 AdminController**（统一分发 + 强制改密门禁）

```php
<?php
declare(strict_types=1);

namespace App\Admin;

use App\Db\Database;
use App\Domain\Auth\AdminAuth;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Exception\HttpException;

final class AdminController
{
    public function __construct(
        private AdminAuth $auth,
        private Database $db,
        private Config $config,
    ) {}

    public function dispatch(Request $request): Response
    {
        try {
            $body = $request->json();
            $action = (string)($body['action'] ?? $request->query('action', ''));
            if ($action === '') {
                throw new HttpException('missing action', 400, 'invalid_request');
            }
            // 'profile.save' → actProfileSave；'users.list' → actUsersList；'login' → actLogin
            $method = 'act' . implode('', array_map('ucfirst', preg_split('/[._]/', $action)));
            if (!method_exists($this, $method)) {
                throw new HttpException('unknown action: ' . $action, 400, 'invalid_request');
            }
            if ($action !== 'login') {
                if (!$this->auth->isLoggedIn()) {
                    throw new HttpException('unauthorized', 401, 'unauthorized');
                }
                if ($this->auth->mustChange() && !in_array($action, ['profile.save', 'logout'], true)) {
                    throw new HttpException('must_change', 403, 'must_change');
                }
            }
            $data = $this->$method($request, $body);
            return Response::json(['ok' => true, 'data' => $data]);
        } catch (HttpException $e) {
            return Response::json(['ok' => false, 'error' => ['message' => $e->getMessage(), 'type' => $e->type()]], $e->status());
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Internal Server Error', 'type' => 'internal_error']], 500);
        }
    }

    private function actLogin(Request $r, array $b): array
    {
        $admin = $this->auth->login((string)($b['username'] ?? ''), (string)($b['password'] ?? ''));
        return ['must_change' => (int)$admin['must_change'] === 1];
    }

    private function actLogout(Request $r, array $b): array
    {
        $this->auth->logout();
        return [];
    }

    private function actProfileSave(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $this->auth->changeCredentials((int)$u['id'], (string)($b['username'] ?? ''), (string)($b['password'] ?? ''));
        return ['must_change' => false];
    }
    // …… actDashboard/actUsers_list/actUsers_save/actUsers_delete/actKeys_*/actProviders_*/
    // …… actModelmap_*/actLogs_list/actBilling_list/actAudit_list/actMetrics_get
    // …… actSpeedtest_run/actSystem_reset_admin 依 Task 9 设计要点实现，操作均记 admin_audit
}
```

* [ ] **Step 4: 实现 AdminApp（SPA 壳）+ View/views.php**

`AdminApp.php`：`render(Request): string` 输出 HTML 文档。结构：

* `<head>` 内联 CSS（沿用莫奈深色主题：#1E1F2B 背景、#7C6CF0 主色、卡片圆角）。

* 登录视图（`showLogin`）与主应用视图（`showApp`），JS 根据 `api.action`（`app.init`）返回的 `{ok, data:{must_change, isLoggedIn}}` 决定渲染登录 / 强制改密页 / 仪表盘。

* JS 提供 `api(action, body)` fetch 助手与 `render(section, data)` 片段渲染函数。

* 全部片段渲染逻辑集中在 `View/views.php` 的纯函数（`views_dashboard(array $stats): string` 等），AdminApp 仅组装导航 + 挂载点 + 调用。此部分 UI 代码量大，实现时对照原 admin/index.php 的既有片段内容逐页迁移，样式保持一致。

* [ ] **Step 5: 运行测试，预期通过**

Run: `php tests/run.php`
Expected: AdminControllerTest PASS。

* [ ] **Step 6: 提交**

```bash
git add src/Admin tests && git commit -m "refactor: unified admin SPA controller with forced credential change"
```

***

### Task 10: 入口与装配 —— index.php、admin/index.php、Bootstrap 全量装配、脚本

**Files:**

* Create: `index.php`（覆盖旧版）

* Create: `admin/index.php`（覆盖旧版）

* Modify: `src/bootstrap.php`（补全 `Bootstrap::kernel()` 装配：Database/Schema/Repositories/服务/中间件/Handler/路由）

* Modify: `scripts/reset_admin.php`

* Modify: `config.php`（整理注释，保持键名兼容；新增 `admin_default_password`、`ratelimit_requests_per_minute`、`keypool_max_consecutive_failures`、`keypool_disabled_seconds`、`log_retention_days`）

* Create: `data/.gitkeep`、`logs/.gitkeep`

* [ ] **Step 1: 实现装配**（在 `src/bootstrap.php` 的 `Bootstrap::kernel()` 中完成全部 DI 注册与路由表；`Bootstrap::container()` 负责 Config + DB + Schema.install + 各 Repository/服务注册，`kernel()` 额外注册 middleware/handler/路由）

```php
// src/bootstrap.php 追加（示意关键装配，按此实现完整版）
public static function kernel(): \App\Http\Kernel
{
    $c = self::container();
    $db = $c->get(Database::class);
    (new Schema($db, $c->get(Config::class)))->install();

    // 注册 Repository / 服务 / 中间件 / handler / 路由……
    $router = new \App\Http\Router();
    $router->add('/v1/chat/completions', 'chat', ['client_format', 'auth', 'rate_limit', 'model_alias']);
    $router->add('/v1/embeddings', 'embed', ['client_format', 'auth', 'rate_limit', 'model_alias']);
    $router->add('/v1/models', 'models', ['auth']);
    return new \App\Http\Kernel($c, $router, $c->get(RequestLogger::class));
}
```

* [ ] **Step 2: 实现 index.php**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$kernel = \App\Bootstrap::kernel();
$kernel->handle(\App\Http\Request::fromGlobals())->send();
```

* [ ] **Step 3: 实现 admin/index.php**

```php
<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$session = &$_SESSION;
$auth = new \App\Domain\Auth\AdminAuth($c->get(\App\Db\Repository\AdminUserRepository::class), $session);
$controller = new \App\Admin\AdminController($auth, $c->get(\App\Db\Database::class), $c->get(\App\Support\Config::class));

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
```

* [ ] **Step 4: 实现 scripts/reset\_admin.php**（CLI/HTTP 均可触发：`php scripts/reset_admin.php` 重置默认管理员并置 must\_change=1）

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$repo = $c->get(\App\Db\Repository\AdminUserRepository::class);
$pass = (string)$c->get(\App\Support\Config::class)->get('admin_default_password', 'admin666');
$now = time();
foreach ($repo->all() as $admin) {
    $repo->updateCredentials((int)$admin['id'], 'admin666', password_hash($pass, PASSWORD_DEFAULT));
    $repo->setMustChange((int)$admin['id'], 1);
}
if ($repo->count() === 0) {
    $repo->create('admin666', password_hash($pass, PASSWORD_DEFAULT), 1);
}
echo "已重置默认管理员 admin666，下次登录须修改用户名与密码。\n";
```

* [ ] **Step 5: 更新 config.php**（在原数组基础上整理，键名保持兼容，新增上述配置键）

* [ ] **Step 6: 冒烟验证**：`php -l` 全部新文件 + `php tests/run.php` 全绿 + `php index.php`（应输出 404 JSON）

Run:

```bash
find src admin scripts -name '*.php' -o -name 'index.php' | xargs -I{} php -l {}
php tests/run.php
php index.php
```

Expected: lint 全 PASS；`N/N tests passed`；`php index.php` 输出 `{"error":{"message":"Not Found","type":"not_found"}}`（退出码 0）。

* [ ] **Step 7: 提交**

```bash
git add index.php admin src config.php scripts tests data logs && git commit -m "refactor: wire up entry points and full dependency assembly"
```

***

### Task 11: 删除旧代码 + README 更新 + 最终全量验证

**Files:**

* Delete: `core.php`、`schema.php`、`routes.php`、`lib/crypto.php`、`lib/db.php`、`middleware/*`、`handlers/*`、`services/*`、`providers/*`、`admin/AdminDispatcher.php`、`admin/AdminAuth.php`、`admin/AdminDashboard.php`、`admin/AdminAuditMgmt.php`、`admin/AdminBillingMgmt.php`、`admin/AdminKeyMgmt.php`、`admin/AdminLogMgmt.php`、`admin/AdminModelMapMgmt.php`、`admin/AdminProfileMgmt.php`、`admin/AdminProviderMgmt.php`、`admin/AdminUserMgmt.php`、`admin/actions.php`、`composer.json`、`phpunit.xml`、`tests/AppTest.php`

* Modify: `README.md`（重写：零依赖安装、目录结构、配置、API、后台/强制改密、测试、常见问题）

* Modify: `.gitignore`（追加 `data/`、`logs/`）

* [ ] **Step 1: 删除旧文件**（见上文件清单，用 DeleteFile 逐个删除）

* [ ] **Step 2: 确认无残留引用**：全局搜索 `AppResponse`、`AppRequest`、`MwClientFormat`、`admin_layout`、`AdminDispatcher`、`config()`、`db_insert` 等旧符号，确认全部替换

Run: 在 `/workspace` 用 Grep 搜索 `AppResponse|AppRequest|MwClientFormat|admin_layout|AdminDispatcher|db_insert|db_select`
Expected: 仅剩 docs 与 README 中可接受的说明性引用（如确有则更新）。

* [ ] **Step 3: 更新 README.md**（新架构说明 + `admin666` 首登强制改密流程 + `php tests/run.php`）

* [ ] **Step 4: 更新 .gitignore**

* [ ] **Step 5: 最终验证**：lint 全部 PHP + 全量测试 + 无残留旧符号

Run:

```bash
find . -name '*.php' -not -path './vendor/*' -not -path './docs/*' | xargs -I{} php -l {}
php tests/run.php
```

Expected: 全部 PASS。

* [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "refactor: remove legacy flat architecture, rewrite docs"
```

***

## 实施顺序与依赖

Task 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11（严格线性，后续任务依赖前序产出的类）。

## 风险与注意

* **流式 SSE**：重试仅在流式尚未开始前可行；一旦 `$onChunk` 被调用即不可重试（AbstractProvider 已按此实现）。

* **旧库兼容**：Schema 用 `CREATE TABLE IF NOT EXISTS` + 新增列幂等处理；`legacyAuthenticate` 兜底无 `key_sha256` 的旧 key。

* **后台 UI 迁移量大**：Task 9 的 View 片段以原 admin/index.php 内容为蓝本逐页迁移，保持莫奈配色与交互，不新增功能。

* **提交**：若仓库非 git 或用户未确认提交，跳过 commit 步骤，改在任务完成后统一说明。

