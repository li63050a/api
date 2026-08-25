# AI API 中转站（原生 PHP · 零依赖）

一个运行在虚拟主机上的 AI API 中转 / 聚合网关：对外暴露统一接口（支持 OpenAI / Anthropic / Gemini 等多种客户端格式），对内按 `model` 把请求路由到不同供应商，并自带鉴权、限流、配额、计费、日志与可视化管理后台。

全新重构版：**零第三方依赖**（无 Composer、无 Redis、无需后台进程），命名空间分层 + 依赖注入，后台为纯 SPA 单页。

---

## 一、功能特性

- **多供应商统一路由**：按请求里的 `model` 别名映射到对应供应商（OpenAI / Anthropic / Gemini，或任意 **OpenAI 兼容三方**如 DeepSeek / GLM / Qwen），上游 Key 支持多 Key 池 + 故障熔断 + 自动重试。
- **单管理员 / 无下游多用户**：后台仅一个管理员账号（默认 `admin666`），不再维护下游用户体系；API Key 独立存在，是唯一的调用与计费凭据。
- **多格式对外接口**：调用方可直接以 OpenAI 兼容格式，或 Anthropic / Gemini 客户端格式请求（通过 `X-Client-Format` 头切换）；内部以 OpenAI 为规范格式，供应商差异在适配器内消化。每个模型/供应商可指定自身接口格式。
- **流式 & 非流式**：聊天补全支持 SSE 流式透传；流式开始后不可重试，避免重复输出。
- **鉴权 / 限流 / 配额 / 权限（按 Key）**：API Key（sha256 索引 O(1) 定位 + bcrypt 校验）；文件锁 best-effort 限流；**每个 Key 可单独设置日 / 月 token 配额、IP 白名单、可用模型白名单**。
- **首登强制改密**：默认管理员 `admin666` / `admin666`，首次登录强制修改用户名与密码（`must_change=1`）。
- **管理后台（纯 SPA）**：仪表盘、密钥、模型映射、供应商、模型**一键同步**、**一键测速**、请求日志、账单、审计。
- **请求日志与指标**：按次记录请求，支持保留天数清理；近 7 日请求 / token 指标。

---

## 二、环境要求

- PHP ≥ 8.1
- 扩展：`curl`、`openssl`、`pdo_sqlite`、`json`、`mbstring`、`session`
- 虚拟主机需支持 PHP，且 **`data/` 目录可写**（存放 SQLite 与缓存 / 限流文件）
- 无需 Composer、无需 Redis、无需后台进程

---

## 三、安装与部署

1. 把整个项目上传到虚拟主机的 web 目录（如 `public_html/` 或子目录）。
2. 确保 `data/`、`logs/` 目录存在且 PHP 有写权限（首次访问会自动建表）。
3. 客户端访问基址为：`https://你的域名/路径/index.php`
   （本项目**不依赖 URL 重写**，调用时把 `index.php` 当作 base_url 即可。）
4. 首次访问任意 `/index.php/v1/...` 接口会自动执行建表，并写入默认管理员。

> **默认管理员账号：`admin666` / `admin666`。首次登录会被强制要求修改用户名与密码，否则无法进入后台。**

**重置管理员**（忘记密码 / 被锁定时）：

```bash
php scripts/reset_admin.php
```

它会把所有管理员账号重置为 `admin666` / 默认密码（`config.php` 的 `admin_default_password`，默认 `admin666`）并置 `must_change=1`。

---

## 四、配置（config.php）

| 键 | 默认值 | 说明 |
| --- | --- | --- |
| `db_path` | `data/app.db` | SQLite 数据库路径 |
| `log_dir` | `logs` | 日志目录 |
| `crypto_key` | 32 字节固定串 | 上游 Key 加解密密钥（**生产环境请修改 并备份**） |
| `admin_default_password` | `admin666` | 默认 / 重置用管理员密码 |
| `provider_max_retries` | 1 | 单个上游失败后重试次数 |
| `ratelimit_requests_per_minute` | 60 | 每个调用方密钥每分钟请求上限（0 = 不限） |
| `keypool_max_consecutive_failures` | 5 | 连续失败熔断阈值 |
| `keypool_disabled_seconds` | 300 | 密钥熔断时长（秒） |
| `log_retention_days` | 30 | 请求日志保留天数（0 = 不清理） |

完整键请直接查看 [config.php](config.php)。

---

## 五、后台使用

访问 `https://你的域名/路径/admin/index.php`：

1. **登录**：输入 `admin666` / `admin666`，首次被强制修改用户名 + 密码。
2. **供应商**：核对/编辑 `openai` / `anthropic` / `gemini`，或新增任意 **OpenAI 兼容三方**（如 `deepseek`、`glm`）；每个供应商需选择**接口格式**（OpenAI 兼容 / Anthropic / Gemini，决定适配器与鉴权方式）与 `base_url`，并添加上游 Key（自动加密存储，仅存密文）。
3. **模型映射 / 模型同步**：
   - 一键同步：从上游拉取模型列表写入 `model_map`（默认未启用），并自动继承供应商的接口格式。
   - 手动添加：`alias`（对外名）+ `provider`（需与供应商名称一致）+ `upstream_model`（真实模型）+ `client_format`。
   - 把需要开放的模型**启用**，调用方才能使用。
4. **密钥（创建与权限）**：直接生成独立 API Key（明文仅显示一次）。可单独设置：**可用模型白名单**（逗号分隔，空 = 全部）、**IP 白名单**（逗号分隔 IP / CIDR，空 = 不限）、**日 / 月 token 配额**（0 = 不限）。调用时若命中白名单外模型 / 来源 IP，或超配额，会被拒绝。
5. **测速 / 审计**：在供应商面板或测速页**一键测速**，逐上游 Key 探测延迟；查看管理员操作审计；模型面板或供应商面板可**一键同步**上游模型。

---

## 六、调用示例

对外 base_url = `https://你的域名/路径/index.php`

### 1. OpenAI 兼容格式（默认）

```bash
curl https://你的域名/路径/index.php/v1/chat/completions \
  -H "Authorization: Bearer sk-你的调用方Key" \
  -H "Content-Type: application/json" \
  -d '{"model":"你启用的模型alias","messages":[{"role":"user","content":"你好"}],"stream":false}'
```

### 2. 指定其它客户端格式

通过 `X-Client-Format` 头切换（`openai` / `anthropic` / `gemini`）：

```bash
curl https://你的域名/路径/index.php/v1/chat/completions \
  -H "Authorization: Bearer sk-你的Key" \
  -H "X-Client-Format: anthropic" \
  -H "Content-Type: application/json" \
  -d '{"model":"你的alias","messages":[{"role":"user","content":"hi"}],"max_tokens":256}'
```

内部流程：`客户端格式 --(Formatter)--> OpenAI 规范 --(适配器)--> 供应商 API`，响应逆向转换。

### 3. 向量化

```bash
curl https://你的域名/路径/index.php/v1/embeddings \
  -H "Authorization: Bearer sk-你的Key" \
  -H "Content-Type: application/json" \
  -d '{"model":"你的embedding-alias","input":"hello world"}'
```

### 4. 模型列表

```bash
curl https://你的域名/路径/index.php/v1/models \
  -H "Authorization: Bearer sk-你的Key"
```

---

## 七、计费 / 限流 / 配额

- **计费**：`BillingService::record()` 记录每次请求的 token 用量写入 `billing` 表（按原语义**不扣余额**，仅记账，供外部计费逻辑消费）。
- **限流**：`FileRateLimiter` 基于文件锁，按用户 + 路径 + 分钟窗口计数（阈值 `ratelimit_requests_per_minute`）。
- **配额**：`QuotaService` 按 `users.quota_daily / quota_monthly` 与当日 / 当月累计 token 判定，超限返回 429。

---

## 八、目录结构（命名空间分层，`App\` → `src/`）

```
index.php                    入口（API 薄壳）
admin/index.php              后台入口（SPA 壳 + AJAX 分派）
config.php                   配置（返回数组）
scripts/reset_admin.php      重置默认管理员
src/
├── bootstrap.php            PSR-4 风格 autoloader + Bootstrap 容器/内核装配
├── Container.php            极简依赖容器
├── Http/                    Request/Response/Router/Kernel/中间件/Handler
│   ├── Handler/             AbstractRelayHandler / Chat / Embed / ModelList
│   └── Middleware/          ClientFormat / Auth / RateLimit / ModelAlias
├── Db/                      Database(PDO) / Schema(建表+种子) / Repository/*
├── Domain/
│   ├── Auth/                ApiKeyAuth(O(1)) / AdminAuth(会话+强制改密)
│   ├── Provider/            Formatter / AbstractProvider / 三家 Provider / KeyPool / Factory
│   ├── Billing/             BillingService / QuotaService
│   ├── Cache/  Crypto/  Logger/  RateLimit/
│   ├── Sync/  SpeedTest/
├── Support/                 Config / Html / Notifier / HttpException / InternalException
└── Admin/                   AdminApp(SPA壳) / AdminController / View/views.php
data/                        SQLite / cache / ratelimit（运行时，已 gitignore）
logs/                        请求日志（运行时，已 gitignore）
tests/                       run.php 零依赖测试运行器 + Test/*.php
docs/superpowers/            设计文档 + 实施计划
```

```bash
# 运行测试（零依赖，无需安装任何东西）
php tests/run.php
```

---

## 九、安全提醒

- **默认管理员 `admin666` 首次登录必须改**；`scripts/reset_admin.php` 可随时重置。
- `crypto_key` 为上游 Key 加密密钥，**生产环境务必修改并备份**（丢失则无法解密已存上游 Key）。
- 上游 Key 仅以密文存库；日志对 Key 脱敏。
- `data/`、`logs/`、`*.db` 已在 `.gitignore` 中，勿提交到公开仓库。