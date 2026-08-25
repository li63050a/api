<?php
declare(strict_types=1);

namespace App\Admin;

use App\Db\Database;
use App\Db\Repository\AdminAuditRepository;
use App\Db\Repository\AdminUserRepository;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\BillingRepository;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\RequestLogRepository;
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
    private BillingRepository $billing;
    private RequestLogRepository $logs;
    private SpeedTestRepository $speedTests;

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
        $this->upstreamKeys = new UpstreamKeyRepository($db);
        $this->billing = new BillingRepository($db);
        $this->logs = new RequestLogRepository($db);
        $this->speedTests = new SpeedTestRepository($db);
    }

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
        $results = $pid > 0
            ? [$this->modelSync()->syncProvider($pid)]
            : $this->modelSync()->syncAll();
        $this->auditLog($r, 'modelmap.sync', ['provider_id' => $pid]);
        return ['results' => $results];
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

    private function actBillingList(Request $r, array $b): array
    {
        $days = (int)($b['days'] ?? 30);
        $cut = $days > 0 ? time() - $days * 86400 : 0;
        $w = $days > 0 ? ' WHERE created_at >= ?' : '';
        $wp = $days > 0 ? [$cut] : [];
        $total = $this->db->fetchOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(cost),0) AS cost, COALESCE(SUM(total_tokens),0) AS tokens FROM billing' . $w,
            $wp
        );
        $byKey = $this->db->fetchAll(
            'SELECT k.key_prefix, COUNT(*) AS count, COALESCE(SUM(b.cost),0) AS cost, COALESCE(SUM(b.total_tokens),0) AS tokens
             FROM billing b LEFT JOIN api_keys k ON k.id = b.api_key_id' . $w . ' GROUP BY b.api_key_id ORDER BY cost DESC LIMIT 20',
            $wp
        );
        $byModel = $this->db->fetchAll(
            'SELECT model, COUNT(*) AS count, COALESCE(SUM(cost),0) AS cost
             FROM billing' . $w . ' GROUP BY model ORDER BY cost DESC LIMIT 20',
            $wp
        );
        return [
            'days' => $days,
            'summary' => [
                'count' => (int)$total['count'],
                'cost' => (float)$total['cost'],
                'tokens' => (int)$total['tokens'],
            ],
            'by_key' => $byKey,
            'by_model' => $byModel,
        ];
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

    /* ---------- 测速 / 系统 ---------- */

    private function actSpeedtestRun(Request $r, array $b): array
    {
        $results = $this->speedTest()->testAll();
        $this->auditLog($r, 'speedtest.run', ['count' => count($results)]);
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
