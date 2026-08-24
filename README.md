# AI API 中转站（原生 PHP，扁平结构）

一个运行在虚拟主机上的 AI API 中转/聚合网关：对外暴露统一接口（支持 OpenAI / Anthropic / Gemini 等多种客户端格式），对内按 `model` 把请求路由到不同供应商，并自带鉴权、限流、配额、计费、缓存、日志与可视化管理后台。

---

## 一、功能特性

- **多供应商统一路由**：按请求里的 `model` 别名映射到对应供应商（OpenAI / Anthropic / Gemini…），上游 Key 支持多 Key 池 + 故障转移 + 自动重试。
- **多格式对外接口**：调用方可直接以 OpenAI 兼容格式，或 Anthropic / Gemini 客户端格式请求（通过 `X-Client-Format` 头切换）；内部以 OpenAI 为规范格式，供应商差异在适配器内消化。
- **流式 & 非流式**：聊天补全支持 SSE 流式透传。
- **鉴权 / 限流 / 配额**：基于 API Key；文件锁 best-effort 限流；按用户配额。
- **计费**：按 token（输入/输出）+ 每请求多维计费，写入 `billing` 表（对接你的计费逻辑）。
- **缓存**：可缓存端点（如 embeddings）按请求哈希缓存。
- **管理后台（HTML/CSS/JS）**：仪表盘、用户、密钥、模型映射、供应商、模型自动同步、一键测速。
- **模型自动同步**：从上游拉取模型列表写入 `model_map`，由管理员选择启用/停用。
- **一键测速 / 可用性检查**：逐上游 Key 探测可用性与延迟。

---

## 二、环境要求

- PHP ≥ 8.1
- 扩展：`curl`、`openssl`、`pdo_sqlite`、`json`、`mbstring`、`session`
- 虚拟主机需支持 PHP，且 **`data/` 目录可写**（用于 SQLite 与加密密钥文件）
- 无需 Composer、无需 Redis、无需后台进程

---

## 三、安装与部署

1. 把整个项目上传到虚拟主机的 web 目录（如 `public_html/` 或子目录）。
2. 确保 `data/` 目录存在且 PHP 有写权限（首次访问会自动建 `app.db` 与 `.key`）。
3. 客户端访问基址为：`https://你的域名/路径/index.php`
   （本项目**不依赖 URL 重写**，虚拟主机无需配置 .htaccess / rewrite，调用时把 `index.php` 当作 base_url 即可。）
4. 首次访问任意 `/index.php/v1/...` 接口会自动执行 `install_schema()` 建表，并写入种子管理员。

> 默认管理员账号：`admin` / `change_me_now`（**请登录后立即修改或删除**）。

---

## 四、配置上游（管理后台）

访问 `https://你的域名/路径/admin/index.php` 登录后：

1. **供应商（Providers）**
   - 默认已种子 `openai` / `anthropic` / `gemini`，请核对 `base_url` 是否正确（可在「供应商」页编辑）。
2. **上游密钥（Upstream Keys）**
   - 在「供应商」页为每个供应商添加上游 Key（存储时自动 `crypto_encrypt` 加密，库中仅存密文）。
   - 支持多条 Key，系统按权重轮询 + 故障时剔除。
3. **模型映射（Model Map）/ 模型同步**
   - **方式 A（推荐）一键同步**：在「模型同步」页选择供应商点「同步」，会从上游拉取模型列表写入 `model_map`（默认 `status=0` 未启用）。
   - **方式 B 手动**：在「模型映射」页手动添加 `alias`（对外显示名）、`provider`、`upstream_model`（真实模型名）、单价（每 1000 token）、是否可缓存。
   - 把需要开放的模型 **启用（status=1）**，调用方才能用它。
4. **用户 / 密钥**
   - 在「用户」页建用户；在「密钥」页为用户生成 API Key（明文仅显示一次，请保存）。

---

## 五、调用示例

中转站对外 base_url = `https://你的域名/路径/index.php`

### 1. OpenAI 兼容格式（默认）

```bash
curl https://你的域名/路径/index.php/v1/chat/completions \
  -H "Authorization: Bearer sk-你的调用方Key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "你启用的模型alias",
    "messages": [{"role":"user","content":"你好"}],
    "stream": false
  }'
```

### 2. 指定其它客户端格式

通过 `X-Client-Format` 头切换（支持 `openai` / `anthropic` / `gemini`）：

```bash
curl https://你的域名/路径/index.php/v1/chat/completions \
  -H "Authorization: Bearer sk-你的Key" \
  -H "X-Client-Format: anthropic" \
  -H "Content-Type: application/json" \
  -d '{"model":"你的alias","messages":[{"role":"user","content":"hi"}],"max_tokens":256}'
```

内部流程：`客户端格式 --(Formatter)--> OpenAI规范 --(适配器)--> 供应商API`，响应逆向转换。

### 3. 向量化（可缓存）

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

## 六、管理后台功能

- **仪表盘**：用户数、今日请求数、今日收入、错误率。
- **用户 / 密钥**：用户增删改与启停；为用户生成 API Key（明文仅显示一次）。
- **模型映射**：alias / 供应商 / 真实模型 / 单价 / 可缓存 / 启停。
- **供应商**：编辑 base_url；管理上游 Key（加密存储、脱敏展示）。
- **模型同步**：一键从上游拉取模型写入 `model_map`，再逐个启用。
- **一键测速**：逐上游 Key 探测可用性与延迟，结果写入 `speedtest_log` 并页面展示。

---

## 七、计费 / 限流 / 配额

- **计费**：`SvcBilling::record()` 依据 `model_map` 的 `price_input`/`price_output`（每 1000 token）与 `price_per_request`，写入 `billing` 表并扣减用户余额。
- **限流**：`SvcRateLimit` 基于文件锁，按 `api_keys` + 分钟窗口计数（阈值 `config('rate_limit_per_minute')`）。
- **配额**：`SvcQuota` 按 `users.quota_daily/quota_monthly` 与 `billing` 累计判断。

---

## 八、目录结构（扁平、无命名空间）

```
index.php              入口（API）
config.php core.php lib/ schema.php routes.php
core/        AppRequest AppResponse AppRouter 接口
providers/   ProviderBase OpenAI Anthropic Gemini KeyPool Factory Formatter
middleware/  MwClientFormat MwAuth MwRateLimit MwModelAlias MwAdminAuth
services/    SvcAuth SvcRateLimit SvcQuota SvcBilling SvcCache SvcLogger
             SvcModelSync SvcSpeedTest
handlers/    HdlChat HdlEmbed HdlModelList
admin/       index.php(单页UI) actions.php(ajax) Admin*(管理类)
data/        app.db  .key   （运行时生成，已加入 .gitignore）
logs/        ratelimit/ 等
```

类加载约定：**类名即文件名**，自动加载器在 `core/ providers/ middleware/ services/ handlers/ admin/` 下查找。

---

## 九、已知限制 / TODO

- **虚拟主机并发**：共享 FPM + SQLite + 流式长连接，高并发/高 QPS 受主机限制；建议前置 CDN/反代吸收连接。
- **Anthropic 模型列表**：Anthropic 无公开 list 接口，`SvcModelSync` 对其返回空并提示，需手动添加。
- **Embed 缓存回写**：`HdlEmbed` 缓存命中已实现，未命中转发的缓存回写为 TODO（建议在 `ProviderBase` 非流式分支加 `SvcCache::set` 钩子）。
- **流式多格式**：非流式多格式已完整；流式在 OpenAI/Anthropic/Gemini 间转换尽力实现，极端字段可能需微调。

---

## 十、安全提醒

- 默认管理员密码请立即修改；`data/.key` 为加密密钥，务必备份且不要泄露（丢失则无法解密已存上游 Key）。
- `data/`、`logs/` 与 `*.db` 已在 `.gitignore` 中，勿提交到公开仓库。
- 上游 Key 仅以密文存库；日志对 Key 脱敏。
