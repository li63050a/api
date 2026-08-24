# 接口契约（CONTRACTS）

> 所有实现子代理必须先读此文件及下列“地基文件”，再只写自己负责的文件。
> 技术约定：原生 PHP ≥ 8.1；命名空间 `Src\`（PSR-4，`Src\Foo\Bar` => `src/Foo/Bar.php`）；
> 无第三方依赖；HTTP 用 `curl`；数据库用 `$container->db`（PDO SQLite，已 WAL）。

## 一、必须先读的地基文件（已存在，勿改）
- `/data/home/admin1/work/api/bootstrap.php` —— `config($k,$d)`、`db()`、`APP_ROOT`
- `/data/home/admin1/work/api/config.php` —— 配置项（db_path, upstream_timeout, upstream_retry, rate_limit_per_minute, default_client_format 等）
- `/data/home/admin1/work/api/src/Core/Request.php` —— `Src\Core\Request`：`$req->method`,`$req->path`,`$req->headers`,`$req->json`(array|null),`$req->query`,`$req->getHeader($n)`,`$req->getBearerToken()`,`$req->input($k,$d)`,`$req->setAttribute($k,$v)`,`$req->getAttribute($k,$d)`
- `/data/home/admin1/work/api/src/Core/Response.php` —— `Src\Core\Response`：`status($c)`,`header($k,$v)`,`json($data,$c=200)`(会 exit),`sse($data,$event=null)`,`sendChunk($s)`,`flush()`,`error($msg,$c=400,$type)`(会 exit)
- `/data/home/admin1/work/api/src/Core/Container.php` —— `Src\Core\Container`：`$c->db`(PDO),`$c->config`(array)
- `/data/home/admin1/work/api/src/Core/Middleware.php` —— `interface Src\Core\Middleware { public function handle(Request $req, Container $c); }` 返回 `Response` 则短路，返回 `null`/`true` 继续；可写 `$req->setAttribute(...)`
- `/data/home/admin1/work/api/src/Core/Handler.php` —— `interface Src\Core\Handler { public function handle(Request $req, Container $c): void; }`
- `/data/home/admin1/work/api/src/Core/Router.php` + `/data/home/admin1/work/api/src/routes.php` —— 路由已配置，勿改路由注册
- `/data/home/admin1/work/api/src/Lib/Crypto.php` —— `Src\Lib\Crypto::encrypt($plain)`,`::decrypt($payload)`（用于上游 Key）
- `/data/home/admin1/work/api/src/Lib/Db.php` —— `Src\Lib\Db::insert($db,$table,$data):int`,`::fetch($db,$sql,$params):?array`,`::fetchAll(...):array`
- `/data/home/admin1/work/api/src/schema.php` —— 表结构（`admin_users,users,api_keys,providers,model_map,upstream_keys,quotas,billing,request_log`）

## 二、数据库表关键字段（写 SQL 时务必对齐）
- `api_keys`: `id,user_id,key_hash,key_prefix,status,created_at,expires_at`
- `model_map`: `id,alias,provider_id,upstream_model,price_input,price_output,price_per_request,cacheable,status` （价格单位：每 1000 token；price_per_request 每次请求固定费）
- `upstream_keys`: `id,provider_id,key_value(已加密),status,weight,last_error_at`
- `billing`: `id,user_id,api_key_id,model_alias,provider_id,input_tokens,output_tokens,request_count,amount,created_at`
- `request_log`: `id,user_id,api_key_id,path,model_alias,status_code,input_tokens,output_tokens,latency_ms,created_at`

## 三、服务类契约（Handlers / Middleware 会调用，签名必须一致）
### `Src\Service\Auth`
- `__construct(Container $c)`
- `authenticate(string $rawKey): ?array` —— 返回 `['user'=>row,'key'=>row]` 或 `null`；按 `key_hash` 查 `api_keys` 再联 `users`，校验 `status`、过期。
- `static hashKey(string $raw): string` —— 用 `password_hash`。
- `static generateKey(): string` —— 生成形如 `sk-` + 随机串的原始 Key（明文，仅创建时返回一次）。

### `Src\Service\RateLimit`
- `__construct(Container $c)`
- `check(int $keyId, int $limit): bool` —— 基于文件锁的 best-effort 限流：keyId + 当前分钟窗口计数，超过 `$limit` 或 `config('rate_limit_per_minute')` 返回 false。计数文件放在 `config('log_dir')` 或 data 下，用 `flock`。

### `Src\Service\Quota`
- `__construct(Container $c)`
- `check(int $userId): bool` —— 检查 `users.quota_daily/quota_monthly` 与 `billing` 当日/当月累计是否超限。

### `Src\Service\Billing`
- `__construct(Container $c)`
- `record(int $userId, int $keyId, string $modelAlias, int $inputTokens, int $outputTokens, int $requestCount=1): void`
  —— 查 `model_map` 单价，计算 `amount = input_tokens*price_input/1000 + output_tokens*price_output/1000 + price_per_request*requestCount`，写入 `billing`（created_at=time()）。

### `Src\Service\Cache`
- `__construct(Container $c)`
- `get(string $key): ?string`
- `set(string $key, string $value, int $ttlSeconds): void`
- 存储介质：SQLite 小表或文件；key 用 `md5`。仅用于可缓存端点（embeddings / models）。

### `Src\Service\Logger`
- `__construct(Container $c)`
- `log(array $data): void` —— 写 `request_log`（字段见上）。建议在请求结束调用，避免高频全量（可由 Handler 决定是否记录）。

## 四、Provider 层契约
### `Src\Provider\KeyPool`
- `__construct(Container $c)`
- `next(int $providerId): ?array` —— 从 `upstream_keys` 选 status=1 的（可按 weight 轮询/随机），返回含**已解密** `key_value` 的行；无可用返回 null。
- `markError(int $keyId): void` —— 更新 `last_error_at=time()`（连续失败可用于临时剔除）。

### `Src\Provider\Factory`
- `static make(string $providerName): Src\Provider\Base` —— 按 provider name（openai/anthropic/gemini）返回对应适配器实例。

### 多格式架构（重要）
- **内部规范格式 = OpenAI 兼容**。所有适配器在「OpenAI ↔ 供应商」之间转换。
- **上游多格式**：每个供应商 API 不同（OpenAI / Anthropic / Gemini…），由对应 `Src\Provider\*` 适配器在内部消化。
- **下游多格式**：调用方可用不同客户端格式（openai / anthropic / gemini…）直接请求，由 `Src\Provider\Formatter` 在边界做「客户端格式 ↔ OpenAI」转换。
- 边界顺序：客户端格式 --(Formatter.clientToOpenai)--> OpenAI(canonical) --(adapter.mapRequest)--> 供应商格式；响应逆向。
- 客户端格式来源：`X-Client-Format` 请求头，默认 `config('default_client_format')`；由 `Src\Middleware\ClientFormat` 解析后写入 `$req->setAttribute('client_format', $fmt)`。

### `Src\Provider\Base`（abstract）
- `public function forward(Request $req, array $model, array $upstreamKey, Container $c): void`
  - `$model` = model_map 行；`$upstreamKey` = KeyPool 返回的含解密 `key_value` 的行。
  - 取 `$clientFormat = $req->getAttribute('client_format') ?? config('default_client_format')`。
  - 入站：`$openaiBody = \Src\Provider\Formatter::clientToOpenai($req->json ?? [], $clientFormat)`；再 `$providerBody = $this->mapRequest($openaiBody, $model)`。
  - 用 curl 请求 `$this->endpoint($model)` + `buildAuthHeaders`；流式用 `CURLOPT_WRITEFUNCTION` 逐块回调：每块先 `$openaiChunk = $this->mapProviderChunkToOpenai($chunk)` → `$clientChunk = \Src\Provider\Formatter::openaiToClient($openaiChunk, $clientFormat)` → `Src\Core\Response::sendChunk($clientChunk)`；非流式：`$providerBody` 全量 → `$openai = $this->mapResponse($providerBody)` → `$client = \Src\Provider\Formatter::openaiToClient($openai, $clientFormat)` → `Src\Core\Response::json($client)`。
  - 用量：流式时累积各 `$openaiChunk` 中的 usage；非流式从 `$openai` 取；调 `(new \Src\Service\Billing($c))->record($userId,$keyId,$model['alias'],$in,$out)` 与 `Logger`。
  - 失败（非 2xx/超时/curl 错误）按 `config('upstream_retry')` 次换 Key 重试；仍失败 `Response::error('upstream error',502)`。
  - protected：`buildAuthHeaders(array $model, array $key): array`、`curlExec(...)` 支持 streaming。
- 子类需实现：`mapRequest(array $openaiBody, array $model): array`、`endpoint(array $model): string`、`extractUsage($openai, bool $streaming): array`(返回 `['input'=>int,'output'=>int]`)、`mapResponse($providerBody): array`（供应商响应 → OpenAI 数组）、`mapProviderChunkToOpenai(string $providerChunk): ?string`（供应商流式块 → OpenAI SSE 文本，返回 null 跳过）。
  - 注：extractUsage 始终基于 OpenAI 规范格式（因为边界已归一），与客户端格式无关。

### 具体适配器（均继承 Base，各自消化上游格式）
- `Src\Provider\OpenAI` —— endpoint=`{base_url}/chat/completions`、`/embeddings`；OpenAI 格式基本透传（mapRequest/mapResponse/mapProviderChunkToOpenai 多为原样）。
- `Src\Provider\Anthropic` —— endpoint=`{base_url}/messages`；OpenAI `messages`(含 system)→ Anthropic `system` 独立字段 + `messages`；`x-api-key` + `anthropic-version` 头；响应/流式转 OpenAI 格式。
- `Src\Provider\Gemini` —— endpoint=`{base_url}/models/{upstream_model}:generateContent?key=...`（流式 `:streamGenerateContent`）；OpenAI messages→Gemini `contents`/`systemInstruction`/`generationConfig`；响应/流式转 OpenAI 格式。

### `Src\Provider\Formatter`（静态工具，负责下游多格式边界）
- `detectFormat(Request $req): string` —— 读 `X-Client-Format` 头，校验在 `config('client_formats')` 内，否则默认 `config('default_client_format')`。
- `clientToOpenai(array $body, string $format): array` —— 把客户端格式请求体转成 OpenAI 规范（anthropic/gemini 客户端格式 → OpenAI messages 等）。
- `openaiToClient($body, string $format)` —— 把 OpenAI 规范响应（`$body` 为数组用于非流式，或为字符串 SSE 块用于流式）转回客户端格式。
- 至少实现 `openai`(原样) 与 `anthropic`、`gemini` 三种客户端格式转换；未知格式按 openai 处理。

## 五、Middleware 契约（路由已挂载，见 routes.php）
- `Src\Middleware\Auth` —— 取 Bearer，调 `Auth::authenticate`；失败 `Response::error('unauthorized',401)`；成功 `setAttribute('user',...)`、`setAttribute('key',...)`。
- `Src\Middleware\RateLimit` —— 调 `RateLimit::check($keyId, limit)`；超限 `Response::error('rate limit',429)`。
- `Src\Middleware\ModelAlias` —— 从 `$req->json['model']` 查 `model_map`（alias 匹配、status=1）；找不到 `Response::error('model not found',404)`；找到 `setAttribute('model_map', row)`、`setAttribute('provider_id', row['provider_id'])`。
- `Src\Middleware\AdminAuth` —— 用 PHP `session_start()`，校验 `$_SESSION['admin_id']`；未登录且非 `/admin/login` 时重定向到登录页（输出 HTML 表单）。
- `Src\Middleware\ClientFormat` —— 调 `Src\Provider\Formatter::detectFormat($req)` 得到客户端格式，写入 `$req->setAttribute('client_format', $fmt)`；供 Provider 边界转换使用。需挂在 /v1 路由中间件链最前。

## 六、Handler 契约
- 三个 Handler 均从 `$req->getAttribute('user')` / `('key')` / `('model_map')` / `('client_format')` 读取已由中间件注入的属性。无需自己做格式转换（由 Provider 层 Formatter 边界处理）。
- `Src\Handler\Chat::handle` —— 取 `model_map`、`user`、`key`；按 `provider_id` 取 `providers` 行得到 provider name；`KeyPool::next` 取上游 Key；`Factory::make($providerName)->forward($req, $model, $keyRow, $c)`。超时用 `config('upstream_timeout')`。
- `Src\Handler\Embed::handle` —— 同上，但先做 `Cache::get`（key=md5(model+input)），命中直接返回（注意命中结果的格式应与客户端格式一致，可用 `Formatter::openaiToClient` 转回；若实现复杂可先以 openai 返回）；未命中转发，结束后 `Cache::set`。
- `Src\Handler\ModelList::handle` —— 返回 status=1 的 `model_map` 列表，OpenAI 风格 `{object:'list',data:[{id:alias,object:'model',...}]}`（可按 client_format 调整，默认 openai）。

## 七、Admin 后台契约
- `Src\Admin\Dispatcher::handle` —— 解析 `/admin/...` 之后的路径，分发到各 Admin 页面类或直接渲染。未登录走登录。
- `Src\Admin\Auth` —— `login($username,$password): bool` 校验 `admin_users`；`logout()`；`current(): ?array`。
- `Src\Admin\Dashboard` —— 展示统计（用户数、今日请求、今日收入、错误率），用简单 HTML + 内联 CSS。
- `Src\Admin\UserMgmt` —— 用户列表/新增/启停（HTML 表单 + POST 处理）。
- `Src\Admin\KeyMgmt` —— API Key 列表/为用户生成 Key（生成时一次性显示明文）/启停。
- `Src\Admin\ModelMapMgmt` —— model_map 增删改（alias、provider、upstream_model、单价、cacheable）。
- `Src\Admin\ProviderMgmt` —— providers 与 upstream_keys 管理（上游 Key 用 `Crypto::encrypt` 存储）。
- 所有 Admin 页用原生 PHP 输出 HTML，`session_start()` 保护。

## 八、硬性要求
- 只写自己负责的文件；不要改地基文件、不要改 routes.php/router。
- 不要运行服务器/数据库；只产出代码。
- 遵循 PSR-4 与上面所有签名。
- 代码可直接被 `new` 调用，方法可见性 public/protected 正确。
- 完成后用 2-3 句回报你创建了哪些文件。
