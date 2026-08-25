# AI API 中转站 · 彻底重构设计文档

* 日期：2026-08-25

* 状态：已获用户批准

* 目标：把当前"扁平结构屎山"PHP 网关重写为干净分层架构，保留全部功能与对外接口，同时满足**零依赖（虚拟主机部署）**、**后台合并为纯 SPA**、**首登强制修改默认账号**的要求。

## 一、约束（用户确认）

1. **零依赖**：不引入 Composer、不使用任何第三方包；PHP ≥ 8.1 原生能力 + 自研 autoloader。
2. **彻底重构**：架构重写，保留全部功能与对外 API 形态（`/index.php/v1/...`、`X-Client-Format`、SSE 流式）。
3. **后台合并为纯 SPA**：删除全部服务端渲染的 Admin\*Mgmt::handle() / admin\_layout() / AdminDispatcher 双 UI。
4. **数据兼容不强制**：保留原 schema 主体，仅新增列（key\_sha256、must\_change），支持 ALTER 兼容旧库。
5. **默认管理员账号**：`admin666` / `admin666`，首登强制修改用户名与密码后才能使用后台全功能。
6. 保留入口 `index.php`（API）与 `admin/index.php`（后台），不依赖 URL 重写。

## 二、目录结构

```
/workspace
├── index.php            # API 入口（薄壳）
├── admin/index.php      # 后台入口（薄壳）
├── config.php           # 配置（数组，保留 AI_API_* env 覆盖）
├── src/
│   ├── bootstrap.php    # 自动加载 + 容器装配（替代 core.php 的上帝职责）
│   ├── Container.php    # 极简依赖容器（get/set，无第三方）
│   ├── Support/
│   │   ├── Config.php             # 只读配置
│   │   ├── Html.php               # htmlspecialchars 助手 e()
│   │   └── Exception/HttpException.php  # 带 HTTP 状态码的异常
│   ├── Http/
│   │   ├── Request.php            # 替代 AppRequest
│   │   ├── Response.php           # 替代 AppResponse（不再 exit）
│   │   ├── Router.php
│   │   ├── Kernel.php             # 生命周期：路由→中间件→handler→异常渲染
│   │   ├── Middleware/
│   │   │   ├── ClientFormat.php   # 替代 MwClientFormat
│   │   │   ├── Auth.php           # 替代 MwAuth
│   │   │   ├── RateLimit.php      # 替代 MwRateLimit
│   │   │   └── ModelAlias.php     # 替代 MwModelAlias
│   │   └── Handler/
│   │       ├── ChatHandler.php    # 替代 HdlChat
│   │       ├── EmbedHandler.php   # 替代 HdlEmbed（消除与 Chat 的复制粘贴）
│   │       └── ModelListHandler.php # 替代 HdlModelList
│   ├── Domain/
│   │   ├── Auth/ApiKeyAuth.php     # O(1) 鉴权（sha256 索引 + bcrypt 校验）
│   │   ├── Auth/AdminAuth.php      # 会话认证 + 强制改密标志
│   │   ├── Billing/BillingService.php
│   │   ├── Billing/QuotaService.php # 补齐 README 宣称的日/月配额
│   │   ├── RateLimit/FileRateLimiter.php
│   │   ├── Logger/RequestLogger.php
│   │   ├── Cache/FileCache.php
│   │   ├── Crypto/CryptoService.php
│   │   ├── Sync/ModelSync.php
│   │   ├── SpeedTest/SpeedTestService.php
│   │   └── Provider/
│   │       ├── ProviderInterface.php
│   │       ├── AbstractProvider.php # 替代 ProviderBase（修缩进/逻辑 bug）
│   │       ├── OpenAIProvider.php
│   │       ├── AnthropicProvider.php
│   │       ├── GeminiProvider.php
│   │       ├── ProviderFactory.php
│   │       ├── KeyPool.php
│   │       └── Formatter.php       # openai↔anthropic↔gemini 互转（删死代码 detectFormat）
│   ├── Db/
│   │   ├── Database.php            # PDO 工厂（替代全局 db()/admin_db()）
│   │   ├── Schema.php              # 建表 + 兼容迁移（替代 schema.php + core.php 迁移块）
│   │   └── Repository/             # users/api_keys/model_map/providers/... 数据访问
│   └── Admin/
│       ├── AdminApp.php            # SPA 页面渲染（替代 admin/index.php 内联大文件拆分）
│       ├── AdminController.php     # 统一 AJAX 入口（替代 actions.php + AdminDispatcher）
│       └── View/*.php              # 各片段渲染（仪表盘/用户/密钥/模型/供应商/日志/账单/审计/账号/测速/指标）
├── data/  logs/  tests/  scripts/reset_admin.php
```

自动加载：`App\` → `src/`，`App\X\Y` → `src/X/Y.php`，手写约 20 行 spl\_autoload\_register。

## 三、核心机制改造

1. **Response 对象 + HttpException 取代 exit()**：

   * handler/中间件返回 `Response` 或抛出 `HttpException($msg, $code, $type)`。

   * `Kernel` 捕获异常统一渲染 `{"error":{message,type}}` JSON。

   * SSE 流式经 `Output::write()` 直出，保留长连接透传；流式开始后不可重试（沿用现状语义）。
2. **依赖注入**：`Container` 持有 db、config、cache、logger、各 Repository 单例；中间件/Handler/服务构造函数注入，消除全局函数。
3. **API Key 鉴权 O(1)**：

   * `api_keys` 新增 `key_sha256 TEXT UNIQUE`，存 `hash('sha256', rawKey)` 做等值查找。

   * `key_hash`（bcrypt）仅做最终校验；杜绝全表 password\_verify。
4. **错误处理**：删除静默 `catch(\Throwable){}`；失败路径记 `RequestLogger`，异常向上抛由 Kernel 兜底。

## 四、后台：纯 SPA + 首登强制改密

1. 删除：`admin_layout()`、`AdminDispatcher` 双 UI 分派、`MwAdminAuth` 登录/初始化表单、各 `Admin*Mgmt::handle()` 服务端渲染入口。
2. 保留：SPA 单页（界面沿用现 admin/index.php 的莫奈配色内联 SPA），所有操作经 `AdminController` 统一分发。
3. **首登强制改密**：

   * 建库种入 `admin_users` 记录：username=`admin666`，password\_hash=`password_hash('admin666')`，`must_change=1`（`AI_API_ADMIN_PASSWORD` 可覆盖密码）。

   * `AdminAuth::login()` 成功后若 `must_change=1`，任何页面都强制重定向到「账号」页；「账号」页在 must\_change 状态下仅提供「修改用户名+密码」表单；保存成功后 `must_change=0`，解锁全部功能。

   * 保留"当前密码为默认密码"的警告框逻辑（AdminProfileMgmt）。
4. **单一数据库**：合并 admin.db → app.db，删除 `admin_db()` 双连接；重置管理员走 `scripts/reset_admin.php`（更新为使用默认密码可再次强制改密）。

## 五、数据库

* 保留原表：users / api\_keys / providers / model\_map / upstream\_keys / billing / request\_log / speedtest\_log / admin\_audit / admin\_users（迁入主库）。

* 新增列：

  * `api_keys.key_sha256 TEXT UNIQUE`（O(1) 鉴权）

  * `admin_users.must_change INTEGER DEFAULT 0`（首登强制改密）

* 保留现有索引与字段；兼容旧库时用 `ALTER TABLE ... ADD COLUMN`（失败忽略）。

* 配额实现：`QuotaService` 按 users.quota\_daily/quota\_monthly 与 billing 累计判断，超限返回 429。

## 六、测试

* 保留零依赖运行器 `tests/run.php`（`php tests/run.php`），新增覆盖：

  * 加解密往返、FileCache 读写/过期

  * KeyPool 熔断/恢复

  * ApiKeyAuth O(1) 查找与校验

  * ProviderFormatter 三种格式互转往返

  * QuotaService 扣减与超限

  * Router 前缀分发

  * AdminAuth 强制改密流程（must\_change 生命周期）

* 测试用临时库/缓存目录隔离。

## 七、明确删除/纠正项

* 删除死代码：`ProviderFormatter::detectFormat()`、README 中不存在的 `SvcCache`/`SvcQuota` 类引用（落地为 FileCache/QuotaService）。

* 修复 `ProviderBase::attempt()` 缩进错乱、`return $ok` 嵌在流式分支的逻辑 bug。

* 清理 `core.php` 上帝文件：职责拆分到 bootstrap/Config/Database/Schema/FileCache/notify（告警保留，收进 Support/Notifier）。

* HdlChat 与 HdlEmbed 复制粘贴 → 抽取共用 `AbstractRelayHandler`，仅端点/可缓存差异。

## 八、非目标（明确不做）

* 不引入任何第三方依赖/框架。

* 不改变对外 API 请求/响应格式语义（OpenAI 兼容为主，X-Client-Format 多格式）。

* 不做数据全量迁移脚本（schema 主体不变，仅增量 ALTER）。

