<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\AdminUserRepository;
use App\Support\Exception\HttpException;

final class AdminAuth
{
    public function __construct(
        private AdminUserRepository $admins,
        private array &$session, // 引用传递的会话数组（由入口注入 $_SESSION 引用）
    ) {}

    /** @return array 包含 id/username/must_change */
    public function login(string $username, string $password): array
    {
        $admin = $this->admins->findByUsername($username);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            throw new HttpException('用户名或密码错误', 401, 'invalid_credentials');
        }
        $this->admins->touchLogin((int)$admin['id']);
        $this->session['admin_id'] = (int)$admin['id'];
        return $this->admins->find((int)$admin['id']);
    }

    public function user(): ?array
    {
        $id = $this->session['admin_id'] ?? 0;
        if ((int)$id === 0) {
            return null;
        }
        return $this->admins->find((int)$id);
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function mustChange(): bool
    {
        $u = $this->user();
        return $u !== null && (int)$u['must_change'] === 1;
    }

    /** 强制改密状态下，仅允许更新凭据；成功后清 must_change */
    public function changeCredentials(int $adminId, string $newUsername, string $newPassword): void
    {
        $username = trim($newUsername);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            throw new HttpException('用户名长度需在 3-64 之间', 422, 'invalid_credentials');
        }
        if (strlen($newPassword) < 8) {
            throw new HttpException('密码至少 8 位', 422, 'invalid_credentials');
        }
        $exists = $this->admins->findByUsername($username);
        if ($exists !== null && (int)$exists['id'] !== $adminId) {
            throw new HttpException('该用户名已被占用', 422, 'invalid_credentials');
        }
        $this->admins->updateCredentials($adminId, $username, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->admins->setMustChange($adminId, 0);
        $this->session['admin_id'] = $adminId;
    }

    public function logout(): void
    {
        unset($this->session['admin_id']);
    }
}
