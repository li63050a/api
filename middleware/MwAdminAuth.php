<?php
/**
 * 管理员后台鉴权：未登录输出极简登录表单。
 */
class MwAdminAuth
{
    public function handle(AppRequest $req)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 后台 IP 白名单
        $allow = config('admin_allowed_ips', '');
        if ($allow !== '' && !$this->ipAllowed($allow)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Forbidden';
            exit;
        }

        $path = $req->path;
        if ($path === '/admin/login') {
            return null;
        }
        if ((new AdminAuth())->current() !== null) {
            return null;
        }

        $this->renderLoginForm();
        exit;
    }

    private function ipAllowed(string $allow): bool
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($ip !== '') {
            $ip = explode(',', $ip)[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        $ip = trim((string) $ip);
        foreach (explode(',', $allow) as $a) {
            $a = trim($a);
            if ($a === '' || $a === $ip) {
                return true;
            }
        }
        return false;
    }

    private function renderLoginForm(): void
    {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Admin Login</title></head><body>'
            . '<h1>Admin Login</h1>'
            . '<form action="/admin/login" method="POST">'
            . '<p><label>Username<br><input type="text" name="username" required></label></p>'
            . '<p><label>Password<br><input type="password" name="password" required></label></p>'
            . '<p><button type="submit">Login</button></p>'
            . '</form></body></html>';
    }
}
