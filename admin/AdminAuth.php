<?php
/**
 * 管理员认证（session）。无 namespace；类名=文件名。
 */
class AdminAuth
{
    public function login(string $username, string $password): bool
    {
        $row = db_fetch(db(), "SELECT * FROM admin_users WHERE username = ?", [$username]);
        if ($row === null) {
            return false;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_id'] = $row['id'];
        return true;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['admin_id']);
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
        return db_fetch(db(), "SELECT * FROM admin_users WHERE id = ?", [$_SESSION['admin_id']]);
    }
}
