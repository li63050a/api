<?php
declare(strict_types=1);

namespace App\User;

use App\Db\Database;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\BillingRepository;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\UserRepository;
use App\Domain\Auth\UserAuth;
use App\Domain\RateLimit\FileRateLimiter;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Exception\HttpException;

/**
 * 用户门户控制器：注册/登录/验证码 + 自助管理自己的 API Key。
 * 响应统一 {'ok':true,'data':...} / {'ok':false,'error':{message,type}}。
 */
final class UserController
{
    private UserRepository $users;
    private ApiKeyRepository $keys;
    private ModelMapRepository $modelMap;

    public function __construct(
        private UserAuth $auth,
        private Database $db,
        private Config $config,
        private FileRateLimiter $limiter,
    ) {
        $this->users = new UserRepository($db);
        $this->keys = new ApiKeyRepository($db);
        $this->modelMap = new ModelMapRepository($db);
    }

    public function dispatch(Request $request): Response
    {
        try {
            $body = $request->json();
            $action = (string)($body['action'] ?? $request->query('action', ''));
            if ($action === '') {
                throw new HttpException('missing action', 400, 'invalid_request');
            }
            $method = 'act' . implode('', array_map('ucfirst', preg_split('/[._]/', $action)));
            if (!method_exists($this, $method)) {
                throw new HttpException('unknown action: ' . $action, 400, 'invalid_request');
            }
            if (!in_array($action, ['init', 'login', 'register', 'captcha'], true)) {
                if (!$this->auth->isLoggedIn()) {
                    throw new HttpException('unauthorized', 401, 'unauthorized');
                }
            }
            $data = $this->$method($request, $body);
            return Response::json(['ok' => true, 'data' => $data]);
        } catch (HttpException $e) {
            return Response::json(
                ['ok' => false, 'error' => ['message' => $e->getMessage(), 'type' => $e->type()]],
                $e->status()
            );
        } catch (\Throwable) {
            return Response::json(
                ['ok' => false, 'error' => ['message' => 'Internal Server Error', 'type' => 'internal_error']],
                500
            );
        }
    }

    private function actInit(Request $r, array $b): array
    {
        $u = $this->auth->user();
        return [
            'isLoggedIn' => $u !== null,
            'username' => $u !== null ? (string)$u['username'] : '',
        ];
    }

    private function actCaptcha(Request $r, array $b): array
    {
        return ['q' => $this->auth->newCaptcha()];
    }

    private function actRegister(Request $r, array $b): array
    {
        $ip = $r->clientIp();
        if ($ip !== '' && !$this->limiter->consume('reg:' . $ip, 5, 60)) {
            throw new HttpException('注册过于频繁，请稍后再试', 429, 'rate_limit_exceeded');
        }
        if (!$this->auth->verifyCaptcha((string)($b['captcha'] ?? ''))) {
            throw new HttpException('人机验证不通过，请重新作答', 422, 'captcha_failed');
        }
        $user = $this->auth->register((string)($b['username'] ?? ''), (string)($b['password'] ?? ''));
        return ['id' => (int)$user['id'], 'username' => (string)$user['username']];
    }

    private function actLogin(Request $r, array $b): array
    {
        $user = $this->auth->login((string)($b['username'] ?? ''), (string)($b['password'] ?? ''));
        return ['id' => (int)$user['id'], 'username' => (string)$user['username']];
    }

    private function actLogout(Request $r, array $b): array
    {
        $this->auth->logout();
        return [];
    }

    private function actKeysList(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $rows = $this->keys->findByUser((int)$u['id']);
        $models = [];
        foreach ($this->modelMap->all() as $m) {
            $models[] = ['alias' => (string)$m['alias'], 'enabled' => (int)$m['enabled']];
        }
        return ['items' => $rows, 'models' => $models];
    }

    private function actKeysCreate(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $raw = 'sk-' . bin2hex(random_bytes(16));
        $id = $this->keys->create([
            'user_id' => (int)$u['id'],
            'key_prefix' => substr($raw, 0, 8),
            'key_hash' => password_hash($raw, PASSWORD_DEFAULT),
            'key_sha256' => hash('sha256', $raw),
            'name' => (string)($b['name'] ?? ''),
            'status' => 1,
            'quota_daily' => max(0, (int)($b['quota_daily'] ?? 0)),
            'quota_monthly' => max(0, (int)($b['quota_monthly'] ?? 0)),
            'allowed_models' => trim((string)($b['allowed_models'] ?? '')),
            'ip_whitelist' => trim((string)($b['ip_whitelist'] ?? '')),
            'created_at' => time(),
        ]);
        return ['id' => $id, 'raw_key' => $raw];
    }

    private function actKeysUpdate(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $id = (int)($b['id'] ?? 0);
        $row = $id > 0 ? $this->keys->find($id) : null;
        if ($row === null || (int)($row['user_id'] ?? -1) !== (int)$u['id']) {
            throw new HttpException('密钥不存在', 404, 'not_found');
        }
        $data = [];
        foreach (['name', 'status', 'quota_daily', 'quota_monthly', 'allowed_models', 'ip_whitelist'] as $col) {
            if (array_key_exists($col, $b)) {
                $data[$col] = $col === 'status'
                    ? (int)$b[$col]
                    : ($col === 'quota_daily' || $col === 'quota_monthly'
                        ? max(0, (int)$b[$col])
                        : (string)$b[$col]);
            }
        }
        $this->keys->update($id, $data);
        return ['id' => $id];
    }

    private function actKeysDelete(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $id = (int)($b['id'] ?? 0);
        $row = $id > 0 ? $this->keys->find($id) : null;
        if ($row === null || (int)($row['user_id'] ?? -1) !== (int)$u['id']) {
            throw new HttpException('密钥不存在', 404, 'not_found');
        }
        $this->keys->delete($id);
        return ['id' => $id];
    }

    /** 预留：用户用量概览 */
    private function actUsage(Request $r, array $b): array
    {
        $u = $this->auth->user();
        $billing = new BillingRepository($this->db);
        $today = strtotime('today');
        $daily = $billing->sumTokens((int)$u['id'], $today, time());
        $monthly = $billing->sumTokens((int)$u['id'], strtotime('first day of this month'), time());
        return [
            'today_tokens' => $daily['total'],
            'monthly_tokens' => $monthly['total'],
            'balance' => (float)($u['balance'] ?? 0),
            'quota_daily' => (int)($u['quota_daily'] ?? 0),
            'quota_monthly' => (int)($u['quota_monthly'] ?? 0),
        ];
    }
}