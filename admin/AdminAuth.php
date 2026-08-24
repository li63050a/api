<?php
/**
 * 管理员认证（session）。无 namespace；类名=文件名。
 */
class AdminAuth
{
    public function login(string $username, string $password): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // 登录失败锁定
        $now = time();
        if (($now < (int) ($_SESSION['admin_lock_until'] ?? 0))) {
            return false;
        }
        $row = db_fetch(db(), "SELECT * FROM admin_users WHERE username = ?", [$username]);
        if ($row === null) {
            $this->registerFail();
            return false;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->registerFail();
            return false;
        }
        $_SESSION['admin_id'] = $row['id'];
        $_SESSION['admin_last'] = $now;
        unset($_SESSION['admin_attempts'], $_SESSION['admin_lock_until']);
        return true;
    }

    private function registerFail(): void
    {
        $max = (int) config('admin_login_max_attempts', 5);
        $lock = (int) config('admin_login_lock_seconds', 300);
        $n = (int) ($_SESSION['admin_attempts'] ?? 0) + 1;
        $_SESSION['admin_attempts'] = $n;
        if ($n >= $max) {
            $_SESSION['admin_lock_until'] = time() + $lock;
        }
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_last'], $_SESSION['admin_attempts'], $_SESSION['admin_lock_until']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function current(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['admin_id'])) {
            return null;
        }
        // 会话超时
        $lifetime = (int) config('admin_session_lifetime', 7200);
        if ($lifetime > 0 && (time() - (int) ($_SESSION['admin_last'] ?? 0)) > $lifetime) {
            $this->logout();
            return null;
        }
        $_SESSION['admin_last'] = time();
        return db_fetch(db(), "SELECT * FROM admin_users WHERE id = ?", [$_SESSION['admin_id']]);
    }
}
