# 更新日志（CHANGELOG）

本文件的记录方式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/)。版本号遵循[语义化版本](https://semver.org/lang/zh-CN/)。

## [2.0.0] - 2026-08-25

### 重大变更（Breaking）

- **整体架构重构**：由扁平的函数式代码（`core.php` / `handlers/` / `providers/` / `middleware/` / `services/` / `admin/*Mgmt.php`）重构为命名空间分层架构（`App\` → `src/`），引入依赖注入容器 `Container`、HTTP 层（`Request` / `Response` / `Router` / `Kernel` / 中间件）、数据层（`Database` / `Schema` / `Repository`）、领域服务层（`Crypto` / `Cache` / `Billing` / `Provider` / `RateLimit` / `Sync` / `SpeedTest`），入口统一收敛到 `src/bootstrap.php`。
- **去掉多用户体系**：后台仅保留**一个管理员账号**（默认 `admin666`，首次登录强制改密），删除下游用户管理相关代码与界面。
- **API Key 独立化**：API Key 不再关联用户，成为唯一的调用与计费凭据；鉴权改为 sha256 索引 O(1) 定位 + bcrypt 校验。

### 新增（Added）

- **多接口 / 多供应商支持**：新增 OpenAI 兼容三方接入（如 DeepSeek / GLM / Qwen），供应商可指定**接口格式**（OpenAI 兼容 / Anthropic / Gemini），按 `model` 别名自动路由到对应上游。
- **一键获取上游模型**：模型 / 供应商面板可**一键同步**上游模型列表并写入 `model_map`。
- **一键测速**：供应商面板或测速页**一键测速**，逐上游 Key 探测可用性与延迟。
- **按 Key 限制**：每个 API Key 可单独设置**可用模型白名单**、**IP 白名单（IP / CIDR）**、**日 / 月 token 配额**，命中白名单之外或超配额即被拒绝。
- **接口格式自适应**：请求方可经 `X-Client-Format` 头切换 OpenAI / Anthropic / Gemini 客户端格式，内部统一为 OpenAI 规范。
- **管理后台纯 SPA**：后台模板由内联 HTML+JS 重写为 `AdminApp` + `AdminController` + `View/views.php`，移除用户导航、账单统计改为按密钥。
- **完善测试**：新增 `tests/Test/*` 覆盖鉴权、账单、配额、缓存、加解密、数据库、格式化、Key 池、限流、路由、Schema 等；零依赖测试运行器 `tests/run.php`。
- **设计文档**：新增 `docs/superpowers/` 下的设计规格与实施计划。

### 变更（Changed）

- **配置**：`config.php` 移除 `admin_db_path`（后台并入同一库）；新增 `crypto_key`、`admin_default_password`、`ratelimit_requests_per_minute`、`keypool_max_consecutive_failures` 等配置项，并整理成组。
- **重置管理员**：`scripts/reset_admin.php` 改为批量重置默认管理员并置必应改密标志，无账号时自动创建。
- **测试引导**：`tests/bootstrap.php` / `tests/run.php` 迁移到依赖容器，统一扫描并汇总结果。

### 移除（Removed）

- 移除 `composer.json` / `phpunit.xml`（保持零依赖），移除旧 `core.php`、`core/`、`lib/`、`handlers/`、`routes.php`、`schema.php`、`services/`、`providers/`、`middleware/`、`admin/Admin*Mgmt.php`、`admin/actions.php` 等全部旧实现。