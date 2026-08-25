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
            $this->forbidden();
            return;
        }

        $path = $req->path;
        $auth = new AdminAuth();

        // 首次初始化向导：尚无任何管理员时，引导在页面上创建账号
        if (!$auth->hasAnyAdmin()) {
            if ($path === '/admin/login' && $req->method === 'POST' && ($_POST['action'] ?? '') === 'setup') {
                $this->handleSetup($req);
                return;
            }
            $this->renderSetupForm();
            return;
        }

        if ($path === '/admin/login') {
            return null;
        }
        if ($auth->current() !== null) {
            return null;
        }

        $this->renderLoginForm();
    }

    private function ipAllowed(string $allow): bool
    {
        $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        // 仅在连接 IP 属于可信代理时才信任 X-Forwarded-For，避免公网伪造绕过白名单
        $trusted = false;
        $tp = (string) config('trusted_proxies', '');
        if ($tp !== '' && $remoteIp !== '') {
            foreach (explode(',', $tp) as $p) {
                if (trim($p) === $remoteIp) {
                    $trusted = true;
                    break;
                }
            }
        }

        if ($trusted) {
            $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $ip = $xff !== '' ? explode(',', $xff)[0] : $remoteIp;
        } else {
            $ip = $remoteIp;
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

    private function forbidden(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Forbidden';
        exit;
    }

    private function renderLoginForm(): void
    {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>Admin Login</title></head><body>'
            . '<h1>Admin Login</h1>'
            . '<form action="/admin/login" method="POST">'
            . '<p><label>Username<br><input type="text" name="username" required></label></p>'
            . '<p><label>Password<br><input type="password" name="password" required></label></p>'
            . '<p><button type="submit">Login</button></p>'
            . '</form></body></html>';
        exit;
    }

    private function renderSetupForm(string $error = ''): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
        }
        $errHtml = $error !== '' ? '<p class="error">' . htmlspecialchars($error) . '</p>' : '';
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>初始化管理后台</title>'
            . '<style>*{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;'
            . 'background:linear-gradient(135deg,#0f172a,#1e293b);margin:0;display:flex;align-items:center;'
            . 'justify-content:center;min-height:100vh}.card{background:#fff;padding:36px 40px;border-radius:12px;'
            . 'box-shadow:0 10px 40px rgba(0,0,0,.25);width:340px}h1{margin:0 0 6px;font-size:20px;color:#0f172a}'
            . '.sub{color:#64748b;font-size:13px;margin-bottom:22px}label{display:block;font-size:13px;color:#334155;'
            . 'margin:12px 0 5px}input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;'
            . 'border-radius:8px;font-size:14px}input:focus{border-color:#2563eb;outline:none}'
            . 'button{margin-top:20px;width:100%;padding:11px;background:#2563eb;color:#fff;border:0;border-radius:8px;'
            . 'cursor:pointer;font-size:14px}button:hover{background:#1d4ed8}.error{color:#dc2626;font-size:13px;margin:10px 0 0}'
            . '</style></head><body><div class="card"><h1>API 中转站 · 初始化</h1>'
            . '<div class="sub">首次使用，请创建管理员账号</div>' . $errHtml
            . '<form method="post" action="/admin/login">'
            . '<input type="hidden" name="action" value="setup">'
            . '<label>用户名</label><input type="text" name="username" autofocus>'
            . '<label>密码（至少 8 位）</label><input type="password" name="password">'
            . '<button type="submit">创 建</button></form></div></body></html>';
        exit;
    }

    private function handleSetup(AppRequest $req): void
    {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || strlen($p) < 8) {
            $this->renderSetupForm('用户名不能为空，且密码至少 8 位。');
            return;
        }
        try {
            $db = admin_db();
            db_insert($db, 'admin_users', [
                'username'      => $u,
                'password_hash' => password_hash($p, PASSWORD_DEFAULT),
                'role'          => 'admin',
                'created_at'    => time(),
            ]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $db->lastInsertId();
            $_SESSION['admin_last'] = time();
            $this->redirect('/admin');
            return;
        } catch (\Throwable $e) {
            $this->renderSetupForm('创建失败：' . $e->getMessage());
        }
    }

    private function redirect(string $to): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to);
        }
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to) . '">';
        exit;
    }
}
