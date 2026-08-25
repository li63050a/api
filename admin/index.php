<?php
/**
 * 单页管理界面（HTML + 内联 CSS + 原生 JS）。
 * 独立可访问（admin/index.php）。未登录显示登录框；登录后左侧导航、主区通过
 * fetch('actions.php') 做 AJAX 加载与操作（统计、列表、表单、模型同步、一键测速）。
 * 全部内联，不引用任何外部资源。
 */
require_once __DIR__ . '/../core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$admin = (new AdminAuth())->current();
$adminName = $admin['username'] ?? '';
$needSetup = ($admin === null) && !(new AdminAuth())->hasAnyAdmin();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 中转站 · 管理后台</title>
<style>
  :root {
    /* 莫奈配色：柔和粉蓝 / 薰衣草紫 / 奶油绿 / 暖白，朦胧朦胧 */
    --bg: #e9eceb;
    --panel: #fbfbf7;
    --ink: #41474d;
    --muted: #8b949c;
    --line: #e1e4df;
    --brand: #8fa9c9;       /* 柔雾粉蓝 */
    --brand-d: #6f8bb0;
    --ok: #7faa8e;          /* 鼠尾草绿 */
    --ok-bg: #e6f0e7;
    --err: #c98f8f;         /* 灰玫瑰 */
    --err-bg: #f6e7e7;
    --warn: #c9a66b;
    --sb: #5d6f72;          /* 雾霭石板青 */
    --sb-2: #6f8386;
    --shadow: 0 2px 12px rgba(80,100,110,.12);
    --radius: 16px;
    --radius-sm: 12px;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; background: var(--bg); color: var(--ink); }
  a { color: var(--brand); text-decoration: none; }

  /* 登录 */
  .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#cdd9e2,#e9dce9,#e1eae3); padding: 20px; }
  .login-card { background: #fbfbf8; padding: 40px 44px; border-radius: 22px; box-shadow: 0 22px 55px rgba(90,110,120,.22); width: 366px; }
  .login-card h1 { margin: 0 0 4px; font-size: 21px; color: #3f4a4f; }
  .login-card .sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
  .login-card label { display: block; font-size: 13px; color: #5a636b; margin: 12px 0 5px; }
  .login-card input { width: 100%; padding: 12px 14px; border: 1px solid #cfd6d3; border-radius: var(--radius-sm); font-size: 14px; transition: border-color .15s; background: #fff; }
  .login-card input:focus { border-color: var(--brand); outline: none; box-shadow: 0 0 0 3px rgba(143,169,201,.18); }
  .login-card button { margin-top: 22px; width: 100%; padding: 13px; background: var(--brand); color: #fff; border: 0; border-radius: var(--radius-sm); cursor: pointer; font-size: 15px; transition: background .15s; }
  .login-card button:hover { background: var(--brand-d); }
  .login-err { color: var(--err); font-size: 13px; margin-top: 12px; min-height: 18px; }

  /* 布局 */
  .app { display: flex; min-height: 100vh; }
  .sidebar { width: 224px; background: var(--sb); color: #e3eae8; flex-shrink: 0; display: flex; flex-direction: column; }
  .sidebar .brand { padding: 22px; font-weight: 700; color: #f3f6f4; border-bottom: 1px solid rgba(255,255,255,.12); font-size: 15px; letter-spacing: .3px; }
  .sidebar nav { padding: 12px 0; flex: 1; }
  .sidebar nav a { display: flex; align-items: center; gap: 10px; padding: 13px 22px; color: #d7e0de; font-size: 14px; cursor: pointer; border-left: 3px solid transparent; border-radius: 0 12px 12px 0; transition: background .15s, color .15s; }
  .sidebar nav a:hover { background: var(--sb-2); color: #fff; }
  .sidebar nav a.active { background: var(--sb-2); color: #fff; border-left-color: var(--brand); }
  .sidebar .foot { padding: 14px 22px; font-size: 12px; color: #aebab8; border-top: 1px solid rgba(255,255,255,.12); }
  .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
  .topbar { height: 60px; background: var(--panel); border-bottom: 1px solid var(--line); display: flex; align-items: center; padding: 0 24px; }
  .topbar .title { font-weight: 600; font-size: 15px; }
  .topbar .spacer { flex: 1; }
  .topbar .user { font-size: 13px; color: #5a636b; margin-right: 14px; }
  .topbar button { padding: 8px 16px; background: #e7eae7; color: var(--ink); border: 0; border-radius: 10px; cursor: pointer; font-size: 13px; transition: background .15s; }
  .topbar button:hover { background: #dde2de; }
  .content { padding: 24px; overflow: auto; }

  h1 { font-size: 22px; margin: 0 0 18px; }
  h3 { margin: 0 0 12px; font-size: 16px; }
  .card { background: var(--panel); padding: 22px 24px; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 20px; }
  .card h3 { display: flex; align-items: center; gap: 8px; }
  table { width: 100%; border-collapse: collapse; background: var(--panel); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); }
  th, td { text-align: left; padding: 12px 15px; border-bottom: 1px solid #eef1ee; font-size: 13px; vertical-align: middle; }
  th { background: #f1f3f0; color: #5a636b; font-weight: 600; white-space: nowrap; }
  tr:last-child td { border-bottom: 0; }
  tr:hover td { background: #f4f7f4; }
  form.inline { display: inline; }
  input[type=text], input[type=number], input[type=password], select { padding: 10px 12px; border: 1px solid #cfd6d3; border-radius: var(--radius-sm); font-size: 13px; background: #fff; color: var(--ink); }
  input:focus, select:focus { border-color: var(--brand); outline: none; box-shadow: 0 0 0 3px rgba(143,169,201,.18); }
  label { font-size: 12px; color: #5a636b; }
  button, .btn { padding: 10px 16px; background: var(--brand); color: #fff; border: 0; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background .15s; }
  button:hover, .btn:hover { background: var(--brand-d); }
  button.danger { background: var(--err); }
  button.danger:hover { background: #b56f6f; }
  button.ghost { background: #e9edea; color: var(--ink); }
  button.ghost:hover { background: #dde3df; }
  button.success { background: var(--ok); }
  button.success:hover { background: #6c9a7c; }
  button:disabled { opacity: .6; cursor: not-allowed; }
  .error { color: var(--err); }
  .ok { color: var(--ok); }
  .muted { color: var(--muted); }
  .stats { display: flex; gap: 16px; flex-wrap: wrap; }
  .stat { flex: 1; min-width: 150px; background: var(--panel); padding: 20px 22px; border-radius: var(--radius); box-shadow: var(--shadow); }
  .stat .v { font-size: 27px; font-weight: 700; }
  .stat .l { color: var(--muted); font-size: 13px; margin-top: 4px; }
  .key-box { background: #f1f3f0; border: 1px dashed #c2ccc6; padding: 14px; border-radius: var(--radius-sm); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
  code { background: #eef1ee; padding: 1px 6px; border-radius: 6px; font-family: ui-monospace, monospace; }
  .row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
  .row > div { display: flex; flex-direction: column; gap: 5px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
  .pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 500; }
  .pill.on { background: var(--ok-bg); color: #3f6b4f; }
  .pill.off { background: var(--err-bg); color: #9a5454; }
  .toolbar { display: flex; gap: 10px; align-items: center; margin: 4px 0 14px; flex-wrap: wrap; }
  .prov-block { border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 16px; overflow: hidden; }
  .prov-head { display: flex; align-items: center; gap: 12px; padding: 15px 18px; background: #f1f3f0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .prov-head .pname { font-weight: 700; font-size: 15px; }
  .prov-head .purl { color: var(--muted); font-size: 12px; word-break: break-all; flex: 1; min-width: 160px; }
  .prov-body { padding: 18px; }
  .sub-t { font-size: 13px; font-weight: 600; color: #5a636b; margin: 0 0 10px; }
  .spinner { width: 15px; height: 15px; border: 2px solid #cbd6d3; border-top-color: var(--brand); border-radius: 50%; display: inline-block; animation: spin .7s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
  .hint { font-size: 12px; color: var(--muted); margin: 0 0 12px; }
  .toast { position: fixed; bottom: 24px; right: 24px; background: var(--sb); color: #fff; padding: 13px 19px; border-radius: 14px; font-size: 13px; box-shadow: 0 12px 32px rgba(90,110,120,.3); opacity: 0; transform: translateY(10px); transition: opacity .2s, transform .2s; z-index: 50; }
  .toast.show { opacity: 1; transform: translateY(0); }
  .toast.err { background: #b06a6a; }

  @media (max-width: 720px) {
    .sidebar { width: 64px; }
    .sidebar .brand, .sidebar .foot, .sidebar nav a span { display: none; }
    .sidebar nav a { justify-content: center; padding: 14px 0; }
    .content { padding: 16px; }
  }
</style>
</head>
<body>
<?php if ($admin === null): ?>
  <?php if ($needSetup): ?>
  <div class="login-wrap">
    <div class="login-card">
      <h1>API 中转站 · 初始化</h1>
      <div class="sub">首次使用，请创建管理员账号</div>
      <form id="setupForm">
        <label>用户名</label>
        <input type="text" name="username" autofocus>
        <label>密码（至少 8 位）</label>
        <input type="password" name="password">
        <button type="submit">创 建</button>
      </form>
      <div class="login-err" id="setupErr"></div>
    </div>
  </div>
  <script>
    const ACTIONS = (location.pathname.replace(/\/admin\/?.*$/, '/admin/') + 'actions.php').replace('//', '/');
    document.getElementById('setupForm').addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(this);
      fd.append('action', 'setup');
      fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(j => { if (j.ok) { location.reload(); } else { document.getElementById('setupErr').textContent = j.error || '创建失败'; } })
        .catch(() => { document.getElementById('setupErr').textContent = '网络错误'; });
    });
  </script>
  <?php else: ?>
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
      fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(j => { if (j.ok) { location.reload(); } else { document.getElementById('loginErr').textContent = j.error || '登录失败'; } })
        .catch(() => { document.getElementById('loginErr').textContent = '网络错误'; });
    });
  </script>
  <?php endif; ?>
<?php else: ?>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">API 中转站</div>
      <nav>
        <a data-view="dashboard" class="active"><span>仪表盘</span></a>
        <a data-view="users"><span>用户</span></a>
        <a data-view="keys"><span>密钥</span></a>
        <a data-view="models"><span>模型管理</span></a>
        <a data-view="providers"><span>供应商</span></a>
        <a data-view="logs"><span>日志</span></a>
        <a data-view="billing"><span>账单</span></a>
        <a data-view="audit"><span>操作审计</span></a>
        <a data-view="metrics"><span>指标</span></a>
        <a data-view="profile"><span>账号</span></a>
        <a data-view="speedtest"><span>测速</span></a>
      </nav>
      <div class="foot">v1.0 · 内联 SPA</div>
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

  <div class="toast" id="toast"></div>

  <script>
    const ACTIONS = (location.pathname.replace(/\/admin\/?.*$/, '/admin/') + 'actions.php').replace('//', '/');
    const titles = { dashboard:'仪表盘', users:'用户', keys:'API 密钥', models:'模型管理', providers:'供应商', logs:'日志', billing:'账单统计', audit:'操作审计', metrics:'运行指标', profile:'账号', speedtest:'一键测速' };
    const REFRESH = <?php echo (int) config('dashboard_refresh_seconds', 0); ?>;
    let dashTimer = null;
    let currentView = 'dashboard';

    function fd(obj) { const f = new FormData(); if (obj) { for (const k in obj) f.append(k, obj[k]); } return f; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c])); }
    function toast(msg, isErr) {
      const t = document.getElementById('toast');
      t.textContent = msg; t.className = 'toast show' + (isErr ? ' err' : '');
      clearTimeout(t._t); t._t = setTimeout(() => { t.className = 'toast'; }, 2600);
    }

    function setActive(v) {
      document.querySelectorAll('.sidebar nav a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
      document.getElementById('viewTitle').textContent = titles[v] || '';
    }

    // 通用：把对象数据回填到指定表单（用于列表中的「编辑」）
    function fillForm(formId, data) {
      const f = document.getElementById(formId);
      if (!f) return;
      for (const k in data) {
        const el = f.elements[k];
        if (!el) continue;
        if (el.type === 'checkbox') { el.checked = !!Number(data[k]); }
        else { el.value = data[k]; }
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function editRow(btn, formId) {
      try { fillForm(formId, JSON.parse(atob(btn.getAttribute('data-edit')))); }
      catch (e) { toast('编辑数据解析失败', true); }
    }

    // 拦截所有表单提交 -> fetch 到 actions.php -> 用返回 HTML 替换整个内容区
    function bindForms(root) {
      root.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          const btn = form.querySelector('button[type=submit]');
          if (btn) { btn.disabled = true; }
          fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
            .then(r => {
              const ct = r.headers.get('content-type') || '';
              if (ct.indexOf('json') !== -1) { return r.json().then(j => ({ j, status: r.status })); }
              return r.text().then(t => ({ t, status: r.status }));
            })
            .then(res => {
              if (res.j) {
                if (res.j.error === 'unauthorized') { location.reload(); return; }
                toast(res.j.error || '操作完成', !res.j.ok);
                if (res.j.ok) { loadView(currentView); }
                else if (btn) { btn.disabled = false; }
              } else {
                const c = document.getElementById('content');
                c.innerHTML = res.t;
                bindForms(c);
                toast('操作成功');
              }
            })
            .catch(() => { toast('网络错误', true); if (btn) { btn.disabled = false; } });
        });
      });
    }

    function loadView(v) {
      currentView = v;
      setActive(v);
      if (dashTimer) { clearInterval(dashTimer); dashTimer = null; }
      const c = document.getElementById('content');
      if (v === 'speedtest') { c.innerHTML = speedPanel(); bindSpeed(); return; }
      if (v === 'metrics') {
        fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd({ action: 'metrics' }) })
          .then(r => r.text())
          .then(t => { c.innerHTML = '<pre style="background:#0b1020;color:#d1fae5;padding:16px;border-radius:10px;overflow:auto;font-size:12px;line-height:1.5">' + esc(t) + '</pre>'; });
        return;
      }
      fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd({ action: v }) })
        .then(r => r.text())
        .then(html => { c.innerHTML = html; bindForms(c); bindProvType(); });
      if (v === 'dashboard' && REFRESH > 0) {
        dashTimer = setInterval(() => loadView('dashboard'), REFRESH * 1000);
      }
    }

    /* ---------- 测速 ---------- */
    function speedPanel() {
      return '<h1>一键测速</h1>'
        + '<div class="card"><p class="hint">对每个供应商的每个上游 Key 进行一次轻量探测，记录可用性与延迟。</p>'
        + '<div class="toolbar"><button id="speedBtn" class="success">开始测速</button><span id="speedStatus"></span></div>'
        + '<div id="speedResult"></div></div>';
    }
    function bindSpeed() {
      document.getElementById('speedBtn').addEventListener('click', function () {
        const st = document.getElementById('speedStatus');
        st.innerHTML = '<span class="spinner"></span> 探测中…';
        document.getElementById('speedResult').innerHTML = '';
        fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd({ action: 'speed_test' }) })
          .then(r => r.json())
          .then(data => {
            st.textContent = '完成';
            if (!Array.isArray(data)) { document.getElementById('speedResult').innerHTML = '<p class="error">无数据</p>'; return; }
            let rows = '';
            data.forEach(d => {
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

    /* ---------- 模型同步（供应商页内集成） ---------- */
    function doSync(btn) {
      const pid = btn.getAttribute('data-pid');
      const orig = btn.textContent;
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> 同步中';
      const f = fd({ action: 'sync_models' }); if (pid) f.append('provider_id', pid);
      fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: f })
        .then(r => r.text())
        .then(html => {
          const box = document.getElementById('provResult'); if (box) box.innerHTML = html;
          btn.disabled = false; btn.textContent = orig;
          toast(pid ? '该供应商同步完成' : '全部同步完成');
        })
        .catch(() => { btn.disabled = false; btn.textContent = orig; toast('同步失败', true); });
    }

    // 事件委托：同步按钮（同页增删改提交由 bindForms 处理）
    document.addEventListener('click', function (e) {
      const sb = e.target.closest('.js-sync'); if (sb) { doSync(sb); return; }
      const sa = e.target.closest('.js-sync-all'); if (sa) { doSync(sa); return; }
    });

    document.querySelectorAll('.sidebar nav a').forEach(a => {
      a.addEventListener('click', () => loadView(a.dataset.view));
    });
    document.getElementById('logoutBtn').addEventListener('click', function () {
      fetch(ACTIONS, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd({ action: 'logout' }) })
        .then(() => { location.reload(); });
    });

    /* ---------- 供应商表单：接口类型联动默认 API 路径 ---------- */
    const API_PATH_DEFAULTS = { openai: '/v1', anthropic: '/v1', gemini: '/v1beta' };
    function bindProvType() {
      const typeSel = document.getElementById('provType');
      const pathInput = document.getElementById('api_path');
      if (!typeSel || !pathInput) return;
      typeSel.addEventListener('change', function () {
        const def = API_PATH_DEFAULTS[typeSel.value] || '';
        pathInput.value = def;
      });
    }

    loadView('dashboard');
  </script>
<?php endif; ?>
</body>
</html>
