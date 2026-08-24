# 扁平化契约（FLAT CONTRACT）

> 项目已从 `src/` 命名空间结构重构为扁平结构。所有子代理必须遵循本文件。
> 根目录 /data/home/admin1/work/api。已存在且勿改的地基文件：
> `core.php`, `config.php`, `lib/crypto.php`, `lib/db.php`, `schema.php`,
> `core/AppRequest.php`, `core/AppResponse.php`, `core/AppRouter.php`,
> `core/MiddlewareInterface.php`, `core/HandlerInterface.php`, `routes.php`, `index.php`

## 一、约定
- **无 namespace**。类名即文件名：`ClassName.php` 放在对应层目录，自动加载器会扫描 `core/ providers/ middleware/ services/ handlers/ admin/`。
- 全局函数（已存在，直接调用，不要重复定义）：
  - `config($k=null,$d=null)` —— 读配置
  - `db(): PDO` —— SQLite 单例（已 WAL）
  - `db_insert($db,$table,$data):int`、`db_fetch($db,$sql,$params):?array`、`db_fetchall($db,$sql,$params):array`、`db_update($db,$table,$set,$where):int`
  - `crypto_encrypt($plain):string`、`crypto_decrypt($payload):string`
- 类不再接收 `Container`；需要 DB 直接 `db()`，需要配置 `config()`。
- 请求对象统一是 `AppRequest`（方法：`getBearerToken()`,`getHeader()`,`input()`,`getAttribute()`,`setAttribute()`,`json`,`path`,`method`）。
- 响应：`AppResponse::json($d,$c)`、`AppResponse::sse($d,$ev)`、`AppResponse::sendChunk($s)`、`AppResponse::error($msg,$c,$type)`（均会 exit）。
- 中间件实现 `MiddlewareInterface::handle(AppRequest $req)` 返回 `AppResponse|null|true`。
- 处理器实现 `HandlerInterface::handle(AppRequest $req): void`。

## 二、类名 → 文件 映射（必须唯一，禁止重名）
- 中间件 `middleware/`：`MwClientFormat.php`、`MwAuth.php`、`MwRateLimit.php`、`MwModelAlias.php`、`MwAdminAuth.php`
- 服务 `services/`：`SvcAuth.php`、`SvcRateLimit.php`、`SvcQuota.php`、`SvcBilling.php`、`SvcCache.php`、`SvcLogger.php`、`SvcModelSync.php`(新)、`SvcSpeedTest.php`(新)
- 供应商 `providers/`：`ProviderBase.php`(abstract)、`ProviderOpenAI.php`、`ProviderAnthropic.php`、`ProviderGemini.php`、`ProviderKeyPool.php`、`ProviderFactory.php`、`ProviderFormatter.php`
- 处理器 `handlers/`：`HdlChat.php`、`HdlEmbed.php`、`HdlModelList.php`
- 后台 `admin/`：`AdminDispatcher.php`、`AdminAuth.php`、`AdminDashboard.php`、`AdminUserMgmt.php`、`AdminKeyMgmt.php`、`AdminModelMapMgmt.php`、`AdminProviderMgmt.php`
- 后台界面：`admin/index.php`（HTML/CSS/JS 单页）、`admin/actions.php`（AJAX/POST 处理）

## 三、各组件职责（逻辑请参考原 `src/` 同名文件后改写）
### 中间件
- `MwClientFormat`：读 `X-Client-Format` 头，校验在 `config('client_formats')`（数组 openai/anthropic/gemini），否则 `config('default_client_format')`；`$req->setAttribute('client_format', $fmt)`。
- `MwAuth`：`$token=$req->getBearerToken()`；`(new SvcAuth())->authenticate($token)`；失败 `AppResponse::error('Invalid API key',401)`；成功 `setAttribute('user',$u)`、`setAttribute('key',$k)`。
- `MwRateLimit`：取 `$req->getAttribute('key')['id']`；`(new SvcRateLimit())->check($keyId,(int)config('rate_limit_per_minute'))`；超限 `AppResponse::error('Rate limit exceeded',429)`。
- `MwModelAlias`：从 `$req->json['model']` 查 `model_map`（alias 匹配且 status=1）；找不到 `AppResponse::error('model not found: '.$m,404)`；找到 `setAttribute('model_map',$row)`、`setAttribute('provider_id',$row['provider_id'])`。
- `MwAdminAuth`：`session_start()`；`$_SESSION['admin_id']` 不存在且路径非 `/admin/login` 时输出极简登录表单 HTML 并 exit；已登录继续。

### 服务
- `SvcAuth`：`authenticate(string $rawKey):?array`（遍历 api_keys status=1 用 `password_verify` 匹配 key_hash，联 users，检查过期；返回 `['user'=>$u,'key'=>$k]` 或 null）；`static hashKey($raw)`=`password_hash`；`static generateKey():string`=`'sk-'.bin2hex(random_bytes(16))`。
- `SvcRateLimit`：`check(int $keyId,int $limit):bool`（文件锁 best-effort；计数文件 `config('log_dir').'/ratelimit/rl_{keyId}_{YYYYMMDDHHII}`，fopen+flock(LOCK_EX) 读写；超 `$limit` 返回 false）。
- `SvcQuota`：`check(int $userId):bool`（读 users quota_daily/quota_monthly，统计 billing 当日/当月 request_count 累计，超限 false）。
- `SvcBilling`：`record(int $userId,int $keyId,string $alias,int $in,int $out,int $reqCount=1):void`（查 model_map 单价；amount=in*price_input/1000+out*price_output/1000+price_per_request*reqCount；`db_insert` billing；可扣 users.balance）。
- `SvcCache`：`get(string $key):?string`/`set(string $key,string $v,int $ttl):void`（自建 SQLite 表 `cache(key TEXT PRIMARY KEY,value TEXT,expires_at INTEGER)`）。
- `SvcLogger`：`log(array $data):void`（写 request_log）。
- `SvcModelSync`（新）：`syncProvider(int $providerId):array` —— 调对应供应商「列出模型」接口（见 providers 表 list_endpoint），把返回模型写入 `model_map`（status 默认 0=未启用，source='auto'，fetched_at=time()，upstream_model 与 alias 默认都取上游模型名；若 alias 已存在则更新 fetched_at 不重复插入）；返回同步到的模型列表。OpenAI: GET {base_url}/models（Bearer）；Gemini: GET {base_url}/models?key={KEY}（取每个 model.name，去掉前缀 `models/`）；Anthropic 无公开 list 接口可留空/抛友好提示。
- `SvcSpeedTest`（新）：`testAll():array` —— 遍历 providers + 其 upstream_keys，对每个做一次轻量探测（OpenAI 用 GET {base_url}/models 带 Key；Gemini 类似；Anthropic 用一次极短 messages 请求或 HEAD），记录 `speedtest_log`（ok,latency_ms,detail）并返回结果数组（含每个 key 的可用性与耗时）。

### 供应商（多格式：内部规范=OpenAI，上游各供应商由适配器转，下游由 ProviderFormatter 转）
- `ProviderFormatter`（静态）：`detectFormat(AppRequest $req):string`、`clientToOpenai(array $body,string $fmt):array`、`openaiToClient($body,string $fmt)`（$body 非流式数组/流式字符串），实现 openai/anthropic/gemini 三种。
- `ProviderBase`（abstract）：`forward(AppRequest $req, array $model, array $upstreamKey): void`（无 Container）。
  - `$clientFormat=$req->getAttribute('client_format')??config('default_client_format')`；
  - 入站：`$openaiBody=ProviderFormatter::clientToOpenai($req->json??[],$clientFormat)` → `$providerBody=$this->mapRequest($openaiBody,$model)`；
  - curl 请求 `$this->endpoint($model)` + `buildAuthHeaders`；超时 `config('upstream_timeout')`；流式用 `CURLOPT_WRITEFUNCTION`：`$openaiChunk=$this->mapProviderChunkToOpenai($chunk)`（null 跳过）→ `$clientChunk=ProviderFormatter::openaiToClient($openaiChunk,$clientFormat)` → `AppResponse::sendChunk($clientChunk)`；非流式：`$openai=$this->mapResponse($providerBody)` → `$client=ProviderFormatter::openaiToClient($openai,$clientFormat)` → `AppResponse::json($client)`；
  - 用量：流式累积 openaiChunk 中 usage；非流式从 `$openai` 取；调 `(new SvcBilling())->record($userId,$keyId,$model['alias'],$in,$out)` 与 `(new SvcLogger())->log([...])`；`$userId/$keyId` 取自 `$req->getAttribute('user')['id']`/`('key')['id']`；
  - 失败按 `config('upstream_retry')` 次换 Key 重试：`(new ProviderKeyPool())->next($model['provider_id'])` 重新取并 `ProviderFactory::make($pn)->forward($req,$model,$keyRow)`；仍失败 `AppResponse::error('upstream error',502)`；
  - protected：`buildAuthHeaders(array $model,array $key):array`、`curlExec(...)`（支持 streaming 回调）；
  - 抽象：`mapRequest($openaiBody,$model):array`、`endpoint($model):string`、`extractUsage($openai,bool $streaming):array`、`mapResponse($providerBody):array`、`mapProviderChunkToOpenai(string $chunk):?string`。
- `ProviderOpenAI`/`ProviderAnthropic`/`ProviderGemini`：按原 `src/Provider/*` 逻辑改写为对应方法（OpenAI 基本透传；Anthropic messages 端点、system 独立字段、x-api-key 头；Gemini contents 结构、?key= 鉴权）；`endpoint` 仅用 `$model`（如需 base_url 可通过 `db_fetch(db(),"SELECT base_url FROM providers WHERE id=?",[$model['provider_id']])` 取得）。
- `ProviderKeyPool`：`next(int $providerId):?array`（status=1 按 weight 随机/轮询，返回时 `key_value` 用 `crypto_decrypt` 解密）、`markError(int $keyId):void`。
- `ProviderFactory`：`make(string $name):ProviderBase`（openai/anthropic/gemini→实例；未知 `AppResponse::error('unknown provider',400)`）。

### 处理器
- `HdlChat`：取 user/key/model_map；按 provider_id 查 providers 得 name；`ProviderKeyPool::next(provider_id)`（null→`AppResponse::error('no upstream key',503)`）；`ProviderFactory::make($name)->forward($req,$model,$upKey)`。
- `HdlEmbed`：同上；若 `$model['cacheable']` 为真先 `SvcCache::get(md5($model['alias'].json_encode($req->json['input']??'')))`，命中直接 `AppResponse::json(json_decode($cached,true))`；未命中转发（缓存写入可留 TODO，建议后续在 ProviderBase 非流式分支加 `SvcCache::set` 钩子）。
- `HdlModelList`：查 model_map status=1，返回 OpenAI 风格 `{object:'list',data:[{id:alias,object:'model',owned_by:'api-relay'}]}`。

### 后台（HTML/CSS/JS）
- `AdminAuth`：`login($u,$p):bool`、`logout():void`、`current():?array`（session）。
- `AdminDispatcher`（实现 HandlerInterface）：解析 `$req->path` 在 `/admin` 之后片段，分发到各 Admin* 页面类或 `admin/actions.php`；登录页 `/admin/login` 自渲染。
- `AdminDashboard`/`AdminUserMgmt`/`AdminKeyMgmt`/`AdminModelMapMgmt`/`AdminProviderMgmt`：原生 PHP 输出 HTML（内联 CSS），处理各自列表/增删改（用 db_* 函数）。
- `admin/index.php`：单页管理界面，含导航（仪表盘/用户/密钥/模型映射/供应商/模型同步/测速），用 HTML+CSS+原生 JS（`fetch` 调 `admin/actions.php` 做 AJAX），展示统计、列表、表单、同步按钮、一键测速按钮（异步显示每个上游 Key 的可用性与延迟）。
- `admin/actions.php`：接收 POST（`action` 字段区分：login/logout/sync_models/speed_test/add_user/toggle_user/...），调用对应 `Svc*`/`Admin*` 逻辑并返回 JSON/HTML。

## 四、硬性要求
- 只创建自己负责的文件；不要改地基文件、routes.php、core/*、lib/*、schema.php。
- 不要使用 namespace，类名必须与上面对应文件名一致，可被自动加载找到。
- 不运行服务器/数据库；只产出代码。
- 完成后用 2-3 句回报创建了哪些文件及关键假设。
