<?php
/**
 * 后台调度器（实现 HandlerInterface）。
 * 解析 $req->path 在 /admin 之后片段并分发：
 *  - /admin/login        自渲染登录表单并处理 POST
 *  - /admin              单页管理界面（admin/index.php）
 *  - /admin/users|keys|models|providers  渲染对应 Admin* 服务端页面
 *  - /admin/dashboard|sync|speedtest      渲染单页控制台（admin/index.php）
 *  - /admin/actions      调用 admin/actions.php（AJAX 处理）
 * MwAdminAuth 中间件已拦截未登录（/admin/login 除外）。
 */
class AdminDispatcher implements HandlerInterface
{
    public function handle(AppRequest $req): void
    {
        $seg = $this->segment($req);

        if ($seg === 'login') {
            $this->handleLogin($req);
            return;
        }

        if ($seg === 'logout') {
            (new AdminAuth())->logout();
            $this->redirect('/admin/login');
            return;
        }

        if ($seg === 'actions') {
            require __DIR__ . '/actions.php';
            return;
        }

        if ($seg === '' || $seg === 'dashboard' || $seg === 'sync' || $seg === 'speedtest') {
            require __DIR__ . '/index.php';
            return;
        }

        switch ($seg) {
            case 'users':
                (new AdminUserMgmt())->handle($req);
                break;
            case 'keys':
                (new AdminKeyMgmt())->handle($req);
                break;
            case 'models':
                (new AdminModelMapMgmt())->handle($req);
                break;
            case 'providers':
                (new AdminProviderMgmt())->handle($req);
                break;
            default:
                AppResponse::status(404);
                admin_layout('Not Found', '<p>Unknown admin page: ' . htmlspecialchars($seg) . '</p>');
        }
    }

    private function segment(AppRequest $req): string
    {
        $p = $req->path;
        if (strpos($p, '/admin') === 0) {
            $p = substr($p, strlen('/admin'));
        }
        $p = '/' . ltrim($p, '/');
        if ($p === '/') {
            return '';
        }
        $seg = trim($p, '/');
        $seg = preg_replace('/\.php$/', '', $seg);
        return $seg;
    }

    private function handleLogin(AppRequest $req): void
    {
        $error = '';
        if ($req->method === 'POST') {
            $u = trim((string) ($_POST['username'] ?? ''));
            $p = (string) ($_POST['password'] ?? '');
            if ((new AdminAuth())->login($u, $p)) {
                $this->redirect('/admin');
                return;
            }
            $error = 'Invalid username or password.';
        }
        $this->renderLogin($error);
    }

    private function renderLogin(string $error): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $errHtml = $error !== '' ? '<p class="error">' . htmlspecialchars($error) . '</p>' : '';
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理后台登录</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: linear-gradient(135deg,#0f172a,#1e293b); margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #fff; padding: 36px 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.25); width: 340px; }
  h1 { margin: 0 0 6px; font-size: 20px; color: #0f172a; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 22px; }
  label { display: block; font-size: 13px; color: #334155; margin: 12px 0 5px; }
  input[type=text], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
  input:focus { border-color: #2563eb; outline: none; }
  button { margin-top: 20px; width: 100%; padding: 11px; background: #2563eb; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-size: 14px; }
  button:hover { background: #1d4ed8; }
  .error { color: #dc2626; font-size: 13px; margin: 10px 0 0; }
</style>
</head>
<body>
  <div class="card">
    <h1>API 中转站 · 管理后台</h1>
    <div class="sub">请登录以继续</div>
    {$errHtml}
    <form method="post" action="/admin/login">
      <label>用户名</label>
      <input type="text" name="username" autofocus>
      <label>密码</label>
      <input type="password" name="password">
      <button type="submit">登 录</button>
    </form>
  </div>
</body>
</html>
HTML;
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

/**
 * 公共布局（顶部导航 + 内容），用于服务端渲染的 Admin* 页面。
 */
function admin_layout(string $title, string $body, string $active = ''): void
{
    $nav = [
        ''          => '仪表盘',
        'users'     => '用户',
        'keys'      => '密钥',
        'models'    => '模型映射',
        'providers' => '供应商',
    ];
    $links = '';
    foreach ($nav as $seg => $label) {
        $href = '/admin' . ($seg === '' ? '' : '/' . $seg);
        $cls = $seg === $active ? ' class="active"' : '';
        $links .= '<a href="' . $href . '"' . $cls . '>' . htmlspecialchars($label) . '</a>';
    }
    $console = '/admin';
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · 管理后台</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f1f5f9; color: #0f172a; }
  header { background: #0f172a; color: #fff; padding: 0 24px; display: flex; align-items: center; height: 56px; }
  header .brand { font-weight: 600; margin-right: 26px; }
  header nav a { color: #cbd5e1; text-decoration: none; padding: 18px 14px; font-size: 14px; }
  header nav a:hover { color: #fff; }
  header nav a.active { color: #fff; border-bottom: 2px solid #3b82f6; }
  header .right { margin-left: auto; font-size: 13px; }
  header .right a { color: #93c5fd; text-decoration: none; margin-left: 14px; }
  header .right a:hover { text-decoration: underline; }
  main { padding: 24px; max-width: 1200px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 0 0 18px; }
  h3 { margin: 0 0 12px; font-size: 16px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; }
  th { background: #f8fafc; color: #475569; font-weight: 600; }
  tr:last-child td { border-bottom: 0; }
  .card { background: #fff; padding: 18px 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 20px; }
  form.inline { display: inline; }
  input[type=text], input[type=number], input[type=password], select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; }
  button, .btn { padding: 8px 13px; background: #2563eb; color: #fff; border: 0; border-radius: 7px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
  button:hover, .btn:hover { background: #1d4ed8; }
  button.danger { background: #dc2626; }
  button.danger:hover { background: #b91c1c; }
  button.ghost { background: #e2e8f0; color: #0f172a; }
  button.ghost:hover { background: #cbd5e1; }
  .error { color: #dc2626; }
  .ok { color: #059669; }
  .muted { color: #64748b; }
  .stats { display: flex; gap: 16px; flex-wrap: wrap; }
  .stat { flex: 1; min-width: 160px; background: #fff; padding: 18px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .stat .v { font-size: 26px; font-weight: 700; }
  .stat .l { color: #64748b; font-size: 13px; margin-top: 4px; }
  .key-box { background: #f1f5f9; border: 1px dashed #94a3b8; padding: 12px; border-radius: 8px; font-family: monospace; word-break: break-all; }
  code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body>
<header>
  <span class="brand">API 中转站 · 管理后台</span>
  <nav>{$links}</nav>
  <span class="right">
    <a href="{$console}">单页控制台</a>
    <a href="/admin/logout">退出</a>
  </span>
</header>
<main>
{$body}
</main>
</body>
</html>
HTML;
}
