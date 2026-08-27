<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\UserRepository;
use App\Support\Exception\HttpException;

/**
 * 用户（注册/登录）认证：会话键 user_id，与管理员(admin_id)互不影响。
 */
final class UserAuth
{
    public function __construct(
        private UserRepository $users,
        private array &$session,
    ) {}

    /** @return array 注册并登录后的用户行 */
    public function register(string $username, string $password): array
    {
        $username = trim($username);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            throw new HttpException('用户名长度需在 3-64 之间', 422, 'invalid_username');
        }
        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}.-]+$/u', $username)) {
            throw new HttpException('用户名只能包含字母、数字、下划线、点、横线或中文', 422, 'invalid_username');
        }
        if (strlen($password) < 8) {
            throw new HttpException('密码至少 8 位', 422, 'invalid_password');
        }
        if ($this->users->findByUsername($username) !== null) {
            throw new HttpException('用户名已被占用', 422, 'username_taken');
        }
        $id = $this->users->create([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 1,
        ]);
        $this->session['user_id'] = $id;
        return $this->users->find($id);
    }

    /** @return array 登录后的用户行 */
    public function login(string $username, string $password): array
    {
        $user = $this->users->findByUsername(trim($username));
        if ($user === null || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            throw new HttpException('用户名或密码错误', 401, 'invalid_credentials');
        }
        if ((int)($user['status'] ?? 1) !== 1) {
            throw new HttpException('账号已停用', 403, 'account_disabled');
        }
        $this->session['user_id'] = (int)$user['id'];
        return $this->users->find((int)$user['id']);
    }

    public function user(): ?array
    {
        $id = (int)($this->session['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        return $this->users->find($id);
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function logout(): void
    {
        unset($this->session['user_id']);
    }

    /** 生成数学验证码；返回题目文本，答案哈希写入会话，register 时用 verifyCaptcha 校验 */
    public function newCaptcha(): string
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $op = random_int(0, 1) === 0 ? '+' : '×';
        $answer = $op === '+' ? $a + $b : $a * $b;
        $this->session['captcha_hash'] = hash('sha256', (string)$answer);
        return "{$a} {$op} {$b} = ?";
    }

    /** 校验验证码（一次性，无论成败均作废） */
    public function verifyCaptcha(string $input): bool
    {
        $hash = (string)($this->session['captcha_hash'] ?? '');
        unset($this->session['captcha_hash']);
        $input = trim($input);
        if ($hash === '' || $input === '' || !ctype_digit($input)) {
            return false;
        }
        return hash_equals($hash, hash('sha256', $input));
    }
}