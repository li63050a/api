<?php
/**
 * 单页管理界面（HTML + CSS + 原生 JS）。
 * 独立可访问（admin/index.php）。未登录显示登录框；登录后左侧导航、主区通过
 * fetch('actions.php') 做 AJAX 加载与操作（统计、列表、表单、模型同步、一键测速）。
 */
require_once __DIR__ . '/../core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$admin = (new AdminAuth())->current();
$adminName = $admin['username'] ?? '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 中转站 · 管理后台</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; background: #f1f5f9; color: #0f172a; }
  a { color: #2563eb; }

  /* 登录 */
  .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#0f172a,#1e293b); }
  .login-card { background: #fff; padding: 36px 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.25); width: 340px; }
  .login-card h1 { margin: 0 0 6px; font-size: 20px; }
  .login-card .sub { color: #64748b; font-size: 13px; margin-bottom: 22px; }
  .login-card label { display: block; font-size: 13px; color: #334155; margin: 12px 0 5px; }
  .login-card input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
  .login-card input:focus { border-color: #2563eb; outline: none; }
  .login-card button { margin-top: 20px; width: 100%; padding: 11px; background: #2563eb; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-size: 14px; }
  .login-card button:hover { background: #1d4ed8; }
  .login-err { color: #dc2626; font-size: 13px; margin-top: 10px; }

  /* 布局 */
  .app { display: flex; min-height: 100vh; }
  .sidebar { width: 210px; background: #0f172a; color: #cbd5e1; flex-shrink: 0; }
  .sidebar .brand { padding: 18px 20px; font-weight: 700; color: #fff; border-bottom: 1px solid #1e293b; font-size: 15px; }
  .sidebar nav { padding: 10px 0; }
  .sidebar nav a { display: block; padding: 11px 20px; color: #cbd5e1; text-decoration: none; font-size: 14px; cursor: pointer; border-left: 3px solid transparent; }
  .sidebar nav a:hover { background: #1e293b; color: #fff; }
  .sidebar nav a.active { background: #1e293b; color: #fff; border-left-color: #3b82f6; }
  .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
  .topbar { height: 56px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; padding: 0 24px; }
  .topbar .title { font-weight: 600; }
  .topbar .spacer { flex: 1; }
  .topbar .user { font-size: 13px; color: #475569; margin-right: 14px; }
  .topbar button { padding: 7px 13px; background: #e2e8f0; color: #0f172a; border: 0; border-radius: 7px; cursor: pointer; font-size: 13px; }
  .topbar button:hover { background: #cbd5e1; }
  .content { padding: 24px; overflow: auto; }

  h1 { font-size: 22px; margin: 0 0 18px; }
  h3 { margin: 0 0 12px; font-size: 16px; }
  .card { background: #fff; padding: 18px 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; vertical-align: middle; }
  th { background: #f8fafc; color: #475569; font-weight: 600; }
  tr:last-child td { border-bottom: 0; }
  form.inline { display: inline; }
  input[type=text], input[type=number], input[type=password], select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; }
  input:focus, select:focus { border-color: #2563eb; outline: none; }
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
  .row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
  .row > div { display: flex; flex-direction: column; gap: 4px; }
  .row label { font-size: 12px; color: #334155; }
  .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; }
  .pill.on { background: #dcfce7; color: #166534; }
  .pill.off { background: #fee2e2; color: #991b1b; }
  .toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; }
  .spinner { width: 16px; height: 16px; border: 2px solid #cbd5e1; border-top-color: #2563eb; border-radius: 50%; display: inline-block; animation: spin .7s linear infinite; vertical-align: middle; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<?php if ($admin === null): ?>
  <div class="login-wrap">
    <div class="login-card">
      <h1>API 中转站 · 管理后台</h1>
      <div class="sub">请登录以继续</div>
      <form id="loginForm">
        <label>用户名</label>
        <input type="text" name="username" autofocus>
        <label>密码</label>
        <input type="password" name="password">
        <button type="submit">登 录</button>
      </form>
      <div class="login-err" id="loginErr"></div>
    </div>
  </div>
  <script>
    const ACTIONS = (location.pathname.replace(/\/admin\/?.*$/, '/admin/') + 'actions.php').replace('//', '/');
    document.getElementById('loginForm').addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(this);
      fd.append('action', 'login');
      fetch(ACTIONS, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
          if (j.ok) { location.reload(); }
          else { document.getElementById('loginErr').textContent = j.error || '登录失败'; }
        })
        .catch(() => { document.getElementById('loginErr').textContent = '网络错误'; });
    });
  </script>
<?php else: ?>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">API 中转站</div>
      <nav>
        <a data-view="dashboard" class="active">仪表盘</a>
        <a data-view="users">用户</a>
        <a data-view="keys">密钥</a>
        <a data-view="models">模型映射</a>
        <a data-view="providers">供应商</a>
        <a data-view="sync">模型同步</a>
        <a data-view="speedtest">一键测速</a>
      </nav>
    </aside>
    <div class="main">
      <div class="topbar">
        <span class="title" id="viewTitle">仪表盘</span>
        <span class="spacer"></span>
        <span class="user">你好，<?php echo htmlspecialchars($adminName); ?></span>
        <button id="logoutBtn">退出</button>
      </div>
      <div class="content" id="content"></div>
    </div>
  </div>

  <script>
    const ACTIONS = (location.pathname.replace(/\/admin\/?.*$/, '/admin/') + 'actions.php').replace('//', '/');
    const titles = { dashboard:'仪表盘', users:'用户', keys:'API 密钥', models:'模型映射', providers:'供应商', sync:'模型同步', speedtest:'一键测速' };
    let currentView = 'dashboard';

    function fd(obj) {
      const f = new FormData();
      if (obj) { for (const k in obj) f.append(k, obj[k]); }
      return f;
    }

    function setActive(v) {
      document.querySelectorAll('.sidebar nav a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
      document.getElementById('viewTitle').textContent = titles[v] || '';
    }

    function bindForms(root) {
      root.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          fetch(ACTIONS, { method: 'POST', body: new FormData(form) })
            .then(r => r.text())
            .then(html => { document.getElementById('content').innerHTML = html; bindForms(document.getElementById('content')); });
        });
      });
    }

    function loadView(v) {
      currentView = v;
      setActive(v);
      const c = document.getElementById('content');
      if (v === 'sync') { c.innerHTML = syncPanel(); bindSync(); return; }
      if (v === 'speedtest') { c.innerHTML = speedPanel(); bindSpeed(); return; }
      fetch(ACTIONS, { method: 'POST', body: fd({ action: v }) })
        .then(r => r.text())
        .then(html => { c.innerHTML = html; bindForms(c); });
    }

    function syncPanel() {
      return '<h1>模型同步</h1>'
        + '<div class="card"><p class="muted">从已配置供应商的「列出模型」接口拉取模型，写入 model_map（默认停用，source=auto）。已存在的别名仅更新 fetched_at。</p>'
        + '<div class="toolbar"><button id="syncBtn">同步所有供应商模型</button><span id="syncStatus"></span></div>'
        + '<div id="syncResult"></div></div>';
    }
    function bindSync() {
      document.getElementById('syncBtn').addEventListener('click', function () {
        const st = document.getElementById('syncStatus');
        st.innerHTML = '<span class="spinner"></span> 同步中…';
        document.getElementById('syncResult').innerHTML = '';
        fetch(ACTIONS, { method: 'POST', body: fd({ action: 'sync_models' }) })
          .then(r => r.text())
          .then(html => { st.textContent = '完成'; document.getElementById('syncResult').innerHTML = html; });
      });
    }

    function speedPanel() {
      return '<h1>一键测速</h1>'
        + '<div class="card"><p class="muted">对每个供应商的每个上游 Key 进行一次轻量探测，记录可用性与延迟。</p>'
        + '<div class="toolbar"><button id="speedBtn">开始测速</button><span id="speedStatus"></span></div>'
        + '<div id="speedResult"></div></div>';
    }
    function bindSpeed() {
      document.getElementById('speedBtn').addEventListener('click', function () {
        const st = document.getElementById('speedStatus');
        st.innerHTML = '<span class="spinner"></span> 探测中…';
        document.getElementById('speedResult').innerHTML = '';
        fetch(ACTIONS, { method: 'POST', body: fd({ action: 'speed_test' }) })
          .then(r => r.json())
          .then(data => {
            st.textContent = '完成';
            let rows = '';
            (data || []).forEach(d => {
              const pill = d.ok ? '<span class="pill on">可用</span>' : '<span class="pill off">不可用</span>';
              rows += '<tr><td>' + esc(d.provider) + '</td><td>#' + d.upstream_key_id + '</td><td>' + pill
                + '</td><td>' + (d.latency_ms != null ? d.latency_ms + ' ms' : '-') + '</td><td class="muted">' + esc(d.detail) + '</td></tr>';
            });
            document.getElementById('speedResult').innerHTML =
              '<table><tr><th>供应商</th><th>Key</th><th>状态</th><th>延迟</th><th>详情</th></tr>' + rows + '</table>';
          })
          .catch(() => { st.textContent = '测速失败'; });
      });
    }

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    }

    document.querySelectorAll('.sidebar nav a').forEach(a => {
      a.addEventListener('click', () => loadView(a.dataset.view));
    });
    document.getElementById('logoutBtn').addEventListener('click', function () {
      fetch(ACTIONS, { method: 'POST', body: fd({ action: 'logout' }) })
        .then(() => { location.reload(); });
    });

    loadView('dashboard');
  </script>
<?php endif; ?>
</body>
</html>
