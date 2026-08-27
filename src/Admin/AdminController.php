<?php
declare(strict_types=1);

namespace App\Admin;

use App\Db\Database;
use App\Db\Repository\AdminAuditRepository;
use App\Db\Repository\AdminUserRepository;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\ModelChannelRepository;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\RequestLogRepository;
use App\Db\Repository\SettingRepository;
use App\Db\Repository\SpeedTestRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Db\Repository\UserRepository;
use App\Domain\Auth\AdminAuth;
use App\Domain\Crypto\CryptoService;
use App\Domain\SpeedTest\SpeedTestService;
use App\Domain\Sync\ModelSync;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Exception\HttpException;

/**
 * 后台统一控制器（SPA 数据入口）。
 *
 * 路由表：action 参数 → act{Action} 方法。
 * 除 login 外全部要求已登录；must_change=1 时仅允许 profile.save / logout。
 * 响应统一为 {'ok': true, 'data': ...} 或 {'ok': false, 'error': {message, type}}。
 */
final class AdminController
{
    private AdminUserRepository $admins;
    private AdminAuditRepository $audit;
    private UserRepository $users;
    private ApiKeyRepository $keys;
    private ProviderRepository $providers;
    private ModelMapRepository $modelMap;
    private UpstreamKeyRepository $upstreamKeys;
    private RequestLogRepository $logs;
    private SpeedTestRepository $speedTests;
    private SettingRepository $settings;
    private ModelChannelRepository $modelChannels;

    public function __construct(
        private AdminAuth $auth,
        private Database $db,
        private Config $config,
    ) {
        $this->admins = new AdminUserRepository($db);
        $this->audit = new AdminAuditRepository($db);
        $this->users = new UserRepository($db);
        $this->keys = new ApiKeyRepository($db);
        $this->providers = new ProviderRepository($db);
        $this->modelMap = new ModelMapRepository($db);
        $this->modelChannels = new ModelChannelRepository($db);
        $this->upstreamKeys = new UpstreamKeyRepository($db);
        $this->logs = new RequestLogRepository($db);
        $this->speedTests = new SpeedTestRepository($db);
        $this->settings = new SettingRepository($db);
    }

    public function dispatch(Request $request): Response
    {
        try {
            $this->assertSameOrigin($request);
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
            return Response::json(
                ['ok' => false, 'error' => ['message' => $e->getMessage(), 'type' => $e->type()]],
                $e->status()
            );
        } catch (\Throwable $e) {
            return Response::json(
                ['ok' => false, 'error' => ['message' => 'Internal Server Error', 'type' => 'internal_error']],
                500
            );
        }
    }

    /* ---------- 会话 / 应用 ---------- */

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

    private function actAppInit(Request $r, array $b): array
    {
        $u = $this->auth->user();
        return [
            'isLoggedIn' => $u !== null,
            'must_change' => $u !== null && (int)$u['must_change'] === 1,
            'username' => $u !== null ? (string)$u['username'] : '',
        ];
    }

    /* ---------- 账号 ---------- */

    private function actProfileGet(Request $r, array $b): array
    {
        $u = $this->auth->user();
        if ($u === null) {
            throw new HttpException('unauthorized', 401, 'unauthorized');
        }
        return [
            'id' => (int)$u['id'],
            'username' => (string)$u['username'],
            'must_change' => (int)$u['must_change'] === 1,
            'created_at' => (int)$u['created_at'],
            'last_login_at' => $u['last_login_at'] !== null ? (int)$u['last_login_at'] : null,
        ];
    }

    private function actProfileSave(Request $r, array $b): array
    {
        $u = $this->auth->user();
        if ($u === null) {
            throw new HttpException('unauthorized', 401, 'unauthorized');
        }
        $this->auth->changeCredentials(
            (int)$u['id'],
            (string)($b['username'] ?? ''),
            (string)($b['password'] ?? '')
        );
        $this->auditLog($r, 'profile.save', ['id' => (int)$u['id']]);
        return ['must_change' => false];
    }

    private function actProfileChangePassword(Request $r, array $b): array
    {
        $u = $this->auth->user();
        if ($u === null) {
            throw new HttpException('unauthorized', 401, 'unauthorized');
        }
        $old = (string)($b['old_password'] ?? '');
        $new = (string)($b['new_password'] ?? '');
        if ($new === '' || !password_verify($old, (string)$u['password_hash'])) {
            throw new HttpException('当前密码不正确', 422, 'invalid_credentials');
        }
        if (strlen($new) < 8) {
            throw new HttpException('密码至少 8 位', 422, 'invalid_credentials');
        }
        $this->admins->updateCredentials((int)$u['id'], (string)$u['username'], password_hash($new, PASSWORD_DEFAULT));
        $this->admins->setMustChange((int)$u['id'], 0);
        $this->auditLog($r, 'profile.change_password', ['id' => (int)$u['id']]);
        return ['must_change' => false];
    }

    /* ---------- 仪表盘 ---------- */

    private function actDashboard(Request $r, array $b): array
    {
        $today = strtotime('today');
        $metrics = $this->logs->metrics($today);
        return [
            'api_keys' => $this->keys->count(),
            'models' => (int)$this->db->value('SELECT COUNT(*) FROM model_map WHERE enabled = 1'),
            'today_requests' => $metrics['total'],
            'today_tokens' => $metrics['tokens'],
            'today_cost' => $metrics['cost'],
            'today_success_rate' => $metrics['total'] > 0
                ? round($metrics['success'] / $metrics['total'] * 100, 2)
                : 0.0,
        ];
    }

    /* ---------- API 密钥 ---------- */

    private function actKeysList(Request $r, array $b): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM api_keys ORDER BY id DESC');
        $models = [];
        foreach ($this->modelMap->all() as $m) {
            $models[] = ['alias' => (string)$m['alias'], 'provider' => (string)$m['provider'], 'enabled' => (int)$m['enabled']];
        }
        return ['items' => $rows, 'models' => $models];
    }

    private function actKeysSave(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $raw = null;
        $data = [];
        if (array_key_exists('name', $b)) {
            $data['name'] = (string)$b['name'];
        }
        if (array_key_exists('status', $b)) {
            $data['status'] = (int)$b['status'];
        }
        if (array_key_exists('allowed_models', $b)) {
            $data['allowed_models'] = trim((string)$b['allowed_models']);
        }
        if (array_key_exists('ip_whitelist', $b)) {
            $data['ip_whitelist'] = trim((string)$b['ip_whitelist']);
        }
        if (array_key_exists('quota_daily', $b)) {
            $data['quota_daily'] = (int)$b['quota_daily'];
        }
        if (array_key_exists('quota_monthly', $b)) {
            $data['quota_monthly'] = (int)$b['quota_monthly'];
        }
        if ($id > 0) {
            $this->keys->update($id, $data);
        } else {
            $raw = 'sk-' . bin2hex(random_bytes(16));
            $id = $this->keys->create([
                'user_id' => 0,
                'key_prefix' => substr($raw, 0, 8),
                'key_hash' => password_hash($raw, PASSWORD_DEFAULT),
                'key_sha256' => hash('sha256', $raw),
                'name' => (string)($b['name'] ?? ''),
                'status' => (int)($b['status'] ?? 1),
                'quota_daily' => (int)($b['quota_daily'] ?? 0),
                'quota_monthly' => (int)($b['quota_monthly'] ?? 0),
                'allowed_models' => (string)($b['allowed_models'] ?? ''),
                'ip_whitelist' => (string)($b['ip_whitelist'] ?? ''),
                'created_at' => time(),
            ]);
        }
        $this->auditLog($r, 'keys.save', ['id' => $id]);
        return $raw !== null ? ['id' => $id, 'raw_key' => $raw] : ['id' => $id];
    }

    private function actKeysDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $this->keys->delete($id);
        $this->auditLog($r, 'keys.delete', ['id' => $id]);
        return ['id' => $id];
    }

    /* ---------- 供应商 ---------- */

    private function actProvidersList(Request $r, array $b): array
    {
        $crypto = $this->crypto();
        $keysByProvider = [];
        foreach ($this->db->fetchAll('SELECT * FROM upstream_keys ORDER BY id DESC') as $k) {
            $k['key_value'] = $this->revealUpstreamKey($k, $crypto);
            $k['has_key'] = $k['key_value'] !== '';
            $keysByProvider[(int)$k['provider_id']][] = $k;
        }
        $providers = $this->providers->all();
        foreach ($providers as &$p) {
            $p['upstream_keys'] = $keysByProvider[(int)$p['id']] ?? [];
            $p['key_count'] = count($p['upstream_keys']);
        }
        unset($p);
        $models = [];
        foreach ($this->modelMap->all() as $m) {
            $models[] = [
                'id' => (int)$m['id'],
                'alias' => (string)$m['alias'],
                'provider' => (string)$m['provider'],
                'upstream_model' => (string)$m['upstream_model'],
                'client_format' => (string)$m['client_format'],
                'enabled' => (int)$m['enabled'],
            ];
        }
        return [
            'items' => $providers,
            'models' => $models,
            'formats' => ['openai' => 'OpenAI 兼容', 'anthropic' => 'Anthropic', 'gemini' => 'Gemini'],
        ];
    }

    /** 解密上游密钥明文；无法解密或为空时返回空串。 */
    private function revealUpstreamKey(array $row, CryptoService $crypto): string
    {
        $v = (string)($row['key_value'] ?? '');
        if ($v === '') {
            return '';
        }
        if (str_starts_with($v, 'enc:')) {
            $v = substr($v, 4);
        }
        try {
            return $crypto->decrypt($v);
        } catch (\Throwable) {
            return '';
        }
    }

    private function actProvidersSave(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $name = trim((string)($b['name'] ?? ''));
        $baseUrl = trim((string)($b['base_url'] ?? ''));
        if ($name === '' || $baseUrl === '') {
            throw new HttpException('名称与 API URL 必填', 422, 'invalid_request');
        }
        $data = [
            'name' => $name,
            'base_url' => $baseUrl,
            'client_format' => (string)($b['client_format'] ?? 'openai'),
            'status' => (int)($b['status'] ?? 1),
        ];
        if ($id > 0) {
            $this->providers->update($id, $data);
        } else {
            $id = $this->providers->create($data);
        }
        $apiKey = (string)($b['api_key'] ?? '');
        if ($id > 0 && $apiKey !== '') {
            $this->upsertProviderKey($id, $apiKey);
        }
        $this->auditLog($r, 'providers.save', ['id' => $id, 'name' => $name]);
        return ['id' => $id];
    }

    private function actProvidersDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $this->upstreamKeys->deleteByProvider($id);
        $this->providers->delete($id);
        $this->auditLog($r, 'providers.delete', ['id' => $id]);
        return ['id' => $id];
    }

    /* ---------- 上游密钥（单条） ---------- */

    private function actUpstreamKeySave(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $providerId = (int)($b['provider_id'] ?? 0);
        $raw = trim((string)($b['key_value'] ?? ''));
        if ($providerId <= 0) {
            throw new HttpException('provider_id 必填', 422, 'invalid_request');
        }
        $provider = $this->providers->find($providerId);
        if ($provider === null) {
            throw new HttpException('供应商不存在', 404, 'not_found');
        }
        if ($id > 0) {
            // 编辑单条：可改状态 / 权重；填了新密钥则替换
            $data = [];
            if (array_key_exists('status', $b)) {
                $data['status'] = (int)$b['status'];
            }
            if (array_key_exists('weight', $b)) {
                $data['weight'] = max(0, (int)$b['weight']);
            }
            if ($raw !== '') {
                $data['key_value'] = 'enc:' . $this->crypto()->encrypt($raw);
            }
            $this->upstreamKeys->update($id, $data);
        } else {
            if ($raw === '') {
                throw new HttpException('API Key 必填', 422, 'invalid_request');
            }
            $id = $this->upstreamKeys->insert([
                'provider_id' => $providerId,
                'key_value' => 'enc:' . $this->crypto()->encrypt($raw),
                'status' => (int)($b['status'] ?? 1),
                'weight' => max(0, (int)($b['weight'] ?? 1)),
            ]);
        }
        $this->auditLog($r, 'upstream.key.save', ['id' => $id, 'provider_id' => $providerId]);
        return ['id' => $id];
    }

    private function actUpstreamKeyDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $this->upstreamKeys->delete($id);
        $this->auditLog($r, 'upstream.key.delete', ['id' => $id]);
        return ['id' => $id];
    }

    private function upsertProviderKey(int $providerId, string $rawKey): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM upstream_keys WHERE provider_id = ? ORDER BY id ASC LIMIT 1',
            [$providerId]
        );
        $value = 'enc:' . $this->crypto()->encrypt($rawKey);
        if ($existing !== null) {
            $this->upstreamKeys->update((int)$existing['id'], ['key_value' => $value]);
        } else {
            $this->upstreamKeys->insert([
                'provider_id' => $providerId,
                'key_value' => $value,
                'status' => 1,
                'weight' => 1,
            ]);
        }
    }

    /* ---------- 模型映射 ---------- */

    private function actModelmapList(Request $r, array $b): array
    {
        return ['items' => $this->modelMap->all()];
    }

    private function actModelmapSave(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $alias = trim((string)($b['alias'] ?? ''));
        $provider = trim((string)($b['provider'] ?? ''));
        if ($alias === '' || $provider === '') {
            throw new HttpException('alias 与 provider 必填', 422, 'invalid_request');
        }
        if ($this->providers->findByName($provider) === null) {
            throw new HttpException(
                'provider 不存在：' . $provider . '（须与供应商列表中的 name 完全一致，区分大小写）',
                422,
                'invalid_request'
            );
        }
        $data = [
            'alias' => $alias,
            'provider' => $provider,
            'upstream_model' => (string)($b['upstream_model'] ?? $alias),
            'client_format' => (string)($b['client_format'] ?? 'openai'),
            'enabled' => (int)($b['enabled'] ?? 1),
        ];
        if ($id > 0) {
            $this->modelMap->update($id, $data);
        } else {
            $id = $this->modelMap->create($data);
        }
        $this->auditLog($r, 'modelmap.save', ['id' => $id, 'alias' => $alias]);
        return ['id' => $id];
    }

    private function actModelmapDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $this->modelMap->delete($id);
        $this->auditLog($r, 'modelmap.delete', ['id' => $id]);
        return ['id' => $id];
    }

    private function actModelmapSync(Request $r, array $b): array
    {
        $pid = (int)($b['provider_id'] ?? 0);
        $autoDisable = (int)($b['auto_disable'] ?? 0) === 1;
        $results = $pid > 0
            ? [$this->modelSync()->syncProvider($pid, $autoDisable)]
            : $this->modelSync()->syncAll($autoDisable);
        $this->auditLog($r, 'modelmap.sync', ['provider_id' => $pid, 'auto_disable' => $autoDisable]);
        return ['results' => $results];
    }

    /* ---------- 模型渠道（仿 new-api：一模型多供应商，优先级+故障转移） ---------- */

    private function actModelChannelsList(Request $r, array $b): array
    {
        $modelId = (int)($b['model_id'] ?? 0);
        $model = $modelId > 0 ? $this->modelMap->find($modelId) : null;
        if ($model === null) {
            throw new HttpException('模型不存在', 404, 'not_found');
        }
        $family = (string)($model['client_format'] ?? 'openai');
        $providers = [];
        foreach ($this->providers->all() as $p) {
            $pFamily = (string)($p['client_format'] ?? 'openai');
            if ($pFamily !== $family) {
                continue; // 渠道接口格式须与模型一致
            }
            $providers[] = [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'base_url' => (string)($p['base_url'] ?? ''),
                'status' => (int)$p['status'],
            ];
        }
        $channels = [];
        foreach ($this->modelChannels->byModel($modelId) as $ch) {
            $p = $this->providers->find((int)$ch['provider_id']);
            $channels[] = [
                'id' => (int)$ch['id'],
                'provider_id' => (int)$ch['provider_id'],
                'provider_name' => $p === null ? ('#' . $ch['provider_id']) : (string)$p['name'],
                'priority' => (int)$ch['priority'],
                'weight' => (int)$ch['weight'],
                'status' => (int)$ch['status'],
            ];
        }
        return ['model' => ['id' => $modelId, 'alias' => (string)$model['alias']], 'channels' => $channels, 'providers' => $providers];
    }

    private function actModelChannelsSave(Request $r, array $b): array
    {
        $modelId = (int)($b['model_id'] ?? 0);
        $providerId = (int)($b['provider_id'] ?? 0);
        $model = $this->modelMap->find($modelId);
        $provider = $this->providers->find($providerId);
        if ($model === null || $provider === null) {
            throw new HttpException('模型或供应商不存在', 404, 'not_found');
        }
        if ((string)($provider['client_format'] ?? 'openai') !== (string)($model['client_format'] ?? 'openai')) {
            throw new HttpException('渠道供应商接口格式必须与模型一致', 422, 'invalid_request');
        }
        $id = $this->modelChannels->upsert(
            $modelId,
            $providerId,
            max(0, (int)($b['priority'] ?? 100)),
            max(1, (int)($b['weight'] ?? 1)),
            (int)($b['status'] ?? 1),
        );
        $this->auditLog($r, 'model.channels.save', ['model_id' => $modelId, 'provider_id' => $providerId, 'id' => $id]);
        return ['id' => $id];
    }

    private function actModelChannelsDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $this->modelChannels->delete($id);
        $this->auditLog($r, 'model.channels.delete', ['id' => $id]);
        return ['id' => $id];
    }

    /* ---------- 日志 / 账单 / 审计 / 指标 ---------- */

    private function actLogsList(Request $r, array $b): array
    {
        $page = max(1, (int)($b['page'] ?? 1));
        $perPage = min(100, max(1, (int)($b['per_page'] ?? 50)));
        $where = [];
        $params = [];
        if (array_key_exists('user_id', $b) && $b['user_id'] !== '' && $b['user_id'] !== null) {
            $where[] = 'user_id = ?';
            $params[] = (int)$b['user_id'];
        }
        if (array_key_exists('status', $b) && $b['status'] !== '' && $b['status'] !== null) {
            $where[] = 'status = ?';
            $params[] = (int)$b['status'];
        }
        if (array_key_exists('error', $b) && $b['error'] !== '' && $b['error'] !== null) {
            $where[] = 'error LIKE ?';
            $params[] = '%' . (string)$b['error'] . '%';
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)$this->db->value('SELECT COUNT(*) FROM request_log' . $whereSql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            'SELECT * FROM request_log' . $whereSql . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** 删除指定日志（按 id）。 */
    private function actLogsDelete(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $n = $this->db->execute('DELETE FROM request_log WHERE id = ?', [$id]);
        $this->auditLog($r, 'logs.delete', ['id' => $id]);
        return ['deleted' => $n];
    }

    /** 清空/批量删除日志：按当前筛选条件（user_id/status/error）删除；无筛选则清空全部。 */
    private function actLogsClear(Request $r, array $b): array
    {
        $where = [];
        $params = [];
        if (array_key_exists('user_id', $b) && $b['user_id'] !== '' && $b['user_id'] !== null) {
            $where[] = 'user_id = ?';
            $params[] = (int)$b['user_id'];
        }
        if (array_key_exists('status', $b) && $b['status'] !== '' && $b['status'] !== null) {
            $where[] = 'status = ?';
            $params[] = (int)$b['status'];
        }
        if (array_key_exists('error', $b) && $b['error'] !== '' && $b['error'] !== null) {
            $where[] = 'error LIKE ?';
            $params[] = '%' . (string)$b['error'] . '%';
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $n = $this->db->execute('DELETE FROM request_log' . $whereSql, $params);
        $this->auditLog($r, 'logs.clear', ['filters' => ['user_id' => $b['user_id'] ?? '', 'status' => $b['status'] ?? '', 'error' => $b['error'] ?? ''], 'deleted' => $n]);
        return ['deleted' => $n];
    }

    private function actAuditList(Request $r, array $b): array
    {
        $limit = min(200, max(1, (int)($b['limit'] ?? 100)));
        $rows = $this->audit->recent($limit);
        $names = [];
        foreach ($this->admins->all() as $a) {
            $names[(int)$a['id']] = (string)$a['username'];
        }
        foreach ($rows as &$row) {
            $row['admin_name'] = $names[(int)($row['admin_id'] ?? 0)] ?? '未知';
        }
        unset($row);
        return ['items' => $rows];
    }

    private function actMetricsGet(Request $r, array $b): array
    {
        $since = strtotime('-6 days', strtotime('today'));
        $daily = $this->db->fetchAll(
            "SELECT date(created_at, 'unixepoch') AS day,
                    COUNT(*) AS requests,
                    COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(cost),0) AS cost
             FROM request_log WHERE created_at >= ?
             GROUP BY day ORDER BY day ASC",
            [$since]
        );
        return ['daily' => $daily, 'totals' => $this->logs->metrics($since)];
    }

    /* ---------- 模型价格 / 自动检测（顶栏模型条） ---------- */

    /** 全部模型 + 价格 + 最近测速可用性 + 自动检测设置 */
    private function actModelsList(Request $r, array $b): array
    {
        $pidByName = [];
        foreach ($this->providers->all() as $p) {
            $pidByName[(string)$p['name']] = (int)$p['id'];
        }
        $items = [];
        foreach ($this->modelMap->all() as $m) {
            $last = $this->speedTests->latestForModel($pidByName[(string)($m['provider'] ?? '')] ?? 0, (string)($m['upstream_model'] ?? ''));
            $items[] = [
                'id' => (int)$m['id'],
                'alias' => (string)$m['alias'],
                'provider' => (string)$m['provider'],
                'upstream_model' => (string)$m['upstream_model'],
                'client_format' => (string)$m['client_format'],
                'enabled' => (int)$m['enabled'],
                'prompt_price' => (float)($m['prompt_price'] ?? 0),
                'completion_price' => (float)($m['completion_price'] ?? 0),
                'last_latency' => $last === null ? null : (int)$last['latency_ms'],
                'last_success' => $last === null ? null : (int)$last['success'],
                'last_tested_at' => $last === null ? null : (int)$last['created_at'],
            ];
        }
        return [
            'items' => $items,
            'settings' => [
                'auto_detect_enabled' => (int)$this->settings->get('auto_detect_enabled', 0),
                'auto_detect_interval' => (int)$this->settings->get('auto_detect_interval', 30),
                'auto_detect_auto_disable' => (int)$this->settings->get('auto_detect_auto_disable', 1),
                'auto_detect_last_run' => (int)$this->settings->get('auto_detect_last_run', 0),
            ],
        ];
    }

    /** 保存单个模型价格（每百万 token 美元） */
    private function actModelsPriceSave(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('invalid id', 422, 'invalid_request');
        }
        $prompt = max(0.0, (float)($b['prompt_price'] ?? 0));
        $completion = max(0.0, (float)($b['completion_price'] ?? 0));
        $this->modelMap->update($id, ['prompt_price' => $prompt, 'completion_price' => $completion]);
        $this->auditLog($r, 'model.price.save', ['id' => $id, 'prompt_price' => $prompt, 'completion_price' => $completion]);
        return ['id' => $id];
    }

    /** 保存自动检测设置（间隔分钟 / 失败自动禁用） */
    private function actDetectSettings(Request $r, array $b): array
    {
        $enabled = (int)($b['auto_detect_enabled'] ?? 0) === 1;
        $interval = max(1, (int)($b['auto_detect_interval'] ?? 30));
        $autoDisable = (int)($b['auto_detect_auto_disable'] ?? 1) === 1;
        $this->settings->set('auto_detect_enabled', $enabled ? '1' : '0');
        $this->settings->set('auto_detect_interval', (string)$interval);
        $this->settings->set('auto_detect_auto_disable', $autoDisable ? '1' : '0');
        $this->auditLog($r, 'detect.settings', ['enabled' => $enabled, 'interval' => $interval, 'auto_disable' => $autoDisable]);
        return ['saved' => true];
    }

    /** 立即执行一次全模型可用度检测 */
    private function actDetectRun(Request $r, array $b): array
    {
        $autoDisable = (int)($b['auto_disable'] ?? (int)$this->settings->get('auto_detect_auto_disable', 1)) === 1;
        $results = $this->speedTest()->testAllModels($autoDisable);
        $this->settings->set('auto_detect_last_run', (string)time());
        $this->auditLog($r, 'detect.run', ['count' => count($results), 'auto_disable' => $autoDisable]);
        return ['results' => $results];
    }

    /* ---------- 模型级测速 / 系统 ---------- */

    /** 一键测速：对 model_map 每个模型做一次真实转发探测；auto_disable=1 时失败自动禁用。 */
    private function actSpeedtestAll(Request $r, array $b): array
    {
        $autoDisable = (int)($b['auto_disable'] ?? 0) === 1;
        $results = $this->speedTest()->testAllModels($autoDisable);
        $this->auditLog($r, 'speedtest.all', ['count' => count($results), 'auto_disable' => $autoDisable]);
        return ['results' => $results];
    }

    /** 指定测速：对单个模型做真实转发探测；auto_disable=1 时失败自动禁用。 */
    private function actSpeedtestModel(Request $r, array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $model = $id > 0 ? $this->modelMap->find($id) : null;
        if ($model === null) {
            throw new HttpException('模型不存在', 404, 'not_found');
        }
        $autoDisable = (int)($b['auto_disable'] ?? 0) === 1;
        $result = $this->speedTest()->testModel($model, $autoDisable);
        $this->auditLog($r, 'speedtest.model', ['model_id' => $id, 'alias' => (string)$model['alias'], 'auto_disable' => $autoDisable]);
        return ['result' => $result];
    }

    /** 按供应商一键测速：对该供应商全部模型做探测；auto_disable=1 时失败自动禁用。 */
    private function actSpeedtestProvider(Request $r, array $b): array
    {
        $name = trim((string)($b['provider'] ?? ''));
        if ($name === '') {
            throw new HttpException('provider 必填', 422, 'invalid_request');
        }
        $autoDisable = (int)($b['auto_disable'] ?? 0) === 1;
        $results = $this->speedTest()->testProviderModels($name, $autoDisable);
        $this->auditLog($r, 'speedtest.provider', ['provider' => $name, 'count' => count($results), 'auto_disable' => $autoDisable]);
        return ['results' => $results];
    }

    private function actSystemResetAdmin(Request $r, array $b): array
    {
        $u = $this->auth->user();
        if ($u === null) {
            throw new HttpException('unauthorized', 401, 'unauthorized');
        }
        $username = 'admin666';
        $pass = (string)$this->config->get('admin_default_password', 'admin666');
        $existing = $this->admins->findByUsername($username);
        if ($existing !== null && (int)$existing['id'] !== (int)$u['id']) {
            $this->admins->delete((int)$existing['id']);
        }
        $this->admins->updateCredentials((int)$u['id'], $username, password_hash($pass, PASSWORD_DEFAULT));
        $this->admins->setMustChange((int)$u['id'], 1);
        $this->auditLog($r, 'system.reset_admin', ['id' => (int)$u['id']]);
        $this->auth->logout();
        return ['username' => $username, 'must_change' => true];
    }

    /* ---------- 内部辅助 ---------- */

    /**
     * CSRF 防护：后台仅接受同源请求（校验 Origin/Referer 的 host）。
     * 跨站 no-cors fetch 发送 text/plain JSON 时无法通过预检但会携带会话 Cookie，
     * 若不校验可被恶意页面改写管理员凭据/删除数据。
     */
    private function assertSameOrigin(Request $r): void
    {
        $host = (string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if ($host === '') {
            return; // CLI / 无法判定 Host 时放行
        }
        $origin = $r->header('Origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $o = parse_url($origin, PHP_URL_HOST);
            if ($o !== null && $o !== $host) {
                throw new HttpException('cross-origin request rejected', 403, 'csrf_rejected');
            }
            return;
        }
        $ref = $r->header('Referer');
        if ($ref !== null && $ref !== '') {
            $rHost = parse_url($ref, PHP_URL_HOST);
            if ($rHost !== null && $rHost !== $host) {
                throw new HttpException('cross-origin request rejected', 403, 'csrf_rejected');
            }
        }
    }

    /** @param array<string, mixed> $detail */
    private function auditLog(Request $r, string $action, array $detail): void
    {
        $u = $this->auth->user();
        $clean = $detail;
        unset($clean['password'], $clean['old_password'], $clean['new_password'], $clean['api_key']);
        $this->audit->log(
            (int)($u['id'] ?? 0),
            $action,
            json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $r->clientIp()
        );
    }

    private function crypto(): CryptoService
    {
        $key = (string)$this->config->get('crypto_key', '');
        if (strlen($key) < 32) {
            $key = str_repeat('aiapi-dev-key-', 3); // 32 字节开发回退
        }
        return new CryptoService(substr($key, 0, 32));
    }

    private function modelSync(): ModelSync
    {
        return new ModelSync(
            $this->db,
            $this->providers,
            $this->upstreamKeys,
            $this->modelMap,
            $this->crypto(),
            $this->config
        );
    }

    private function speedTest(): SpeedTestService
    {
        return new SpeedTestService(
            $this->db,
            $this->providers,
            $this->upstreamKeys,
            $this->modelMap,
            $this->speedTests,
            $this->crypto(),
            $this->config
        );
    }
}
