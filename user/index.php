<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

$c = \App\Bootstrap::container();
$db = $c->get(\App\Db\Database::class);
$session = &$_SESSION;
$auth = new \App\Domain\Auth\UserAuth($c->get(\App\Db\Repository\UserRepository::class), $session);
$controller = new \App\User\UserController(
    $auth,
    $db,
    $c->get(\App\Support\Config::class),
    $c->get(\App\Domain\RateLimit\FileRateLimiter::class),
);

$isFetch = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$hasAction = ($_REQUEST['action'] ?? '') !== '' || (json_decode(file_get_contents('php://input') ?: '', true)['action'] ?? '') !== '';

if ($isFetch || $hasAction) {
    $resp = $controller->dispatch(\App\Http\Request::fromGlobals($c->get(\App\Support\Config::class)->all()));
    $resp->send();
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 中转站 · 用户中心</title>
<style>
:root { --bg:#1E1F2B; --panel:#272936; --panel2:#2E3040; --ink:#E7E8F2; --muted:#8B8DA3;
  --line:#33354A; --brand:#7C6CF0; --ok:#3ECF8E; --err:#F06A6A; --warn:#E2B44D; --radius:14px; --radius-sm:10px; }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  background:var(--bg); color:var(--ink); font-size:14px; min-height:100vh; }
h1 { font-size:20px; margin:0 0 16px; }
a { color:var(--brand); text-decoration:none; }
.muted { color:var(--muted); }
.overlay { position:fixed; inset:0; z-index:40; display:flex; align-items:center; justify-content:center;
  background:radial-gradient(1200px 700px at 30% 20%, #2A2B3E, var(--bg) 60%); padding:20px; }
.ov-card { width:min(380px,100%); background:var(--panel); border:1px solid var(--line); border-radius:20px;
  padding:32px 36px; box-shadow:0 8px 30px rgba(0,0,0,.35); }
.ov-card .brand { font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
.ov-card .brand .dot { width:10px; height:10px; border-radius:50%; background:var(--brand); box-shadow:0 0 12px var(--brand); }
.ov-card .sub { color:var(--muted); font-size:13px; margin:0 0 20px; }
.ov-card label { display:block; font-size:12px; color:var(--muted); margin:12px 0 5px; }
.ov-card input { width:100%; padding:11px 13px; border:1px solid var(--line); border-radius:var(--radius-sm);
  font-size:14px; background:var(--panel2); color:var(--ink); }
.ov-card input:focus { border-color:var(--brand); outline:none; }
.ov-card button { margin-top:18px; width:100%; padding:12px; background:var(--brand); color:#fff; border:0;
  border-radius:var(--radius-sm); cursor:pointer; font-size:15px; font-weight:600; }
.ov-card button:disabled { opacity:.6; }
.ov-err { color:var(--err); font-size:13px; margin-top:10px; min-height:18px; }
.ov-switch { text-align:center; margin-top:16px; font-size:13px; color:var(--muted); }
.captcha-row { display:flex; gap:10px; align-items:center; }
.captcha-row input { flex:1; }
.captcha-refresh { background:none; border:1px solid var(--line); color:var(--muted); border-radius:var(--radius-sm);
  padding:8px 12px; cursor:pointer; width:auto; margin:0; font-size:13px; }
.app { max-width:1100px; margin:0 auto; padding:24px; }
.topbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
.topbar .brand { font-weight:700; font-size:16px; display:flex; align-items:center; gap:8px; }
.topbar .spacer { flex:1; }
.topbar button { padding:8px 14px; background:var(--panel2); color:var(--ink); border:1px solid var(--line);
  border-radius:var(--radius-sm); cursor:pointer; }
.card { background:var(--panel); padding:18px 20px; border-radius:var(--radius); border:1px solid var(--line); margin-bottom:16px; }
.stats { display:flex; gap:12px; flex-wrap:wrap; }
.stat { flex:1; min-width:130px; background:var(--panel); padding:16px 18px; border-radius:var(--radius);
  border:1px solid var(--line); }
.stat .v { font-size:24px; font-weight:750; }
.stat .l { color:var(--muted); font-size:12px; margin-top:4px; }
table { width:100%; border-collapse:collapse; background:var(--panel); border-radius:var(--radius); border:1px solid var(--line); }
th,td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--line); font-size:13px; }
th { background:var(--panel2); color:var(--muted); }
button, .btn { padding:8px 14px; background:var(--brand); color:#fff; border:0; border-radius:var(--radius-sm);
  cursor:pointer; font-size:13px; }
button.ghost { background:var(--panel2); color:var(--ink); border:1px solid var(--line); }
button.danger { background:var(--err); }
.key-box { background:var(--panel2); border:1px dashed var(--brand); padding:12px; border-radius:var(--radius-sm);
  font-family:ui-monospace,Menlo,monospace; word-break:break-all; color:var(--brand); }
.pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; }
.pill.on { background:rgba(62,207,142,.14); color:var(--ok); }
.pill.off { background:rgba(240,106,106,.14); color:var(--err); }
.toolbar { display:flex; gap:10px; align-items:center; margin:0 0 12px; flex-wrap:wrap; }
.hidden { display:none !important; }
select[multiple].mm { min-height:120px; width:100%; background:var(--panel2); color:var(--ink); border:1px solid var(--line); }
.modal-mask { position:fixed; inset:0; z-index:80; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.55); padding:20px; }
.modal-mask.show { display:flex; }
.modal { width:480px; max-width:100%; max-height:86vh; overflow:auto; background:var(--panel);
  border:1px solid var(--line); border-radius:18px; padding:20px 22px; }
.modal label { display:block; font-size:12px; color:var(--muted); margin:10px 0 4px; }
.modal input { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:var(--radius-sm);
  font-size:13px; background:var(--panel2); color:var(--ink); }
.modal .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.modal .full { grid-column:1/-1; }
.modal .m-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
</style>
</head>
<body>

<div class="overlay" id="authOverlay">
  <div class="ov-card">
    <div class="brand"><span class="dot"></span>API 中转站 · 用户中心</div>
    <h1 id="authTitle">登录</h1>
    <div class="sub" id="authSub">请登录以管理你的 API 密钥</div>
    <form id="authForm" autocomplete="off">
      <div id="captchaBox" class="hidden">
        <label>人机验证</label>
        <div class="captcha-row">
          <input type="text" name="captcha" placeholder="计算结果" autocomplete="off">
          <button type="button" class="captcha-refresh" id="captchaRefresh">换一题</button>
        </div>
        <div class="muted" id="captchaQ" style="margin-top:6px"></div>
      </div>
      <label>用户名</label>
      <input type="text" name="username" autofocus>
      <label>密码</label>
      <input type="password" name="password">
      <div class="hidden"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
      <button type="submit" id="authBtn">登 录</button>
      <div class="ov-err" id="authErr"></div>
    </form>
    <div class="ov-switch"><span id="authSwitchText">还没有账号？</span> <a href="#" id="authSwitch">注册</a></div>
  </div>
</div>

<div class="app hidden" id="appShell">
  <div class="topbar">
    <div class="brand"><span class="dot"></span>API 中转站 · 用户中心</div>
    <span class="spacer"></span>
    <span class="muted" id="userName"></span>
    <button id="logoutBtn">退出</button>
  </div>
  <div class="card">
    <div class="stats" id="usageStats"></div>
  </div>
  <div class="card">
    <div class="toolbar">
      <h1 style="margin:0">我的 API 密钥</h1>
      <span class="spacer"></span>
      <button class="ghost" id="keyNewBtn">生成密钥</button>
    </div>
    <div id="rawKeyBox" class="hidden"></div>
    <table><tr><th>ID</th><th>前缀</th><th>备注</th><th>状态</th><th>允许模型</th><th>日/月配额</th><th>创建时间</th><th>操作</th></tr>
      <tbody id="keysBody"></tbody>
    </table>
  </div>
</div>

<div class="modal-mask" id="modalMask"><div class="modal" id="modalBox"></div></div>

<script>
(function () {
  'use strict';
  var mode = 'login';
  var modelsCache = [];
  var lastRawKey = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }
  function toast(msg, isErr) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--panel2);color:#fff;padding:12px 18px;'
      + 'border-radius:14px;font-size:13px;box-shadow:0 8px 30px rgba(0,0,0,.35);z-index:99;'
      + (isErr ? 'border:1px solid var(--err);' : 'border:1px solid var(--line);');
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }
  function api(action, body) {
    body = body || {};
    body.action = action;
    return fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { ok:false, error:{ message:'响应解析失败', type:'bad_response' } }; }); });
  }
  function pillStatus(s) { return s == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>'; }
  function fmtTime(t) { return t ? new Date(t * 1000).toLocaleString('zh-CN', { hour12:false }) : '-'; }

  function loadCaptcha() {
    api('captcha', {}).then(function (j) {
      if (j.ok) { document.getElementById('captchaQ').textContent = j.data.q; }
    });
  }
  function setMode(m) {
    mode = m;
    document.getElementById('authTitle').textContent = m === 'login' ? '登录' : '注册';
    document.getElementById('authSub').textContent = m === 'login' ? '请登录以管理你的 API 密钥' : '注册新账号，人机验证后即可使用';
    document.getElementById('authBtn').textContent = m === 'login' ? '登 录' : '注 册';
    document.getElementById('captchaBox').classList.toggle('hidden', m !== 'register');
    document.getElementById('authSwitchText').textContent = m === 'login' ? '还没有账号？' : '已有账号？';
    document.getElementById('authSwitch').textContent = m === 'login' ? '注册' : '登录';
    document.getElementById('authErr').textContent = '';
    if (m === 'register') { loadCaptcha(); }
  }

  document.getElementById('authSwitch').addEventListener('click', function (e) {
    e.preventDefault();
    setMode(mode === 'login' ? 'register' : 'login');
  });
  document.getElementById('captchaRefresh').addEventListener('click', loadCaptcha);

  document.getElementById('authForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('authBtn'); btn.disabled = true;
    var body = {};
    new FormData(this).forEach(function (v, k) { body[k] = v; });
    if (body.website) { toast('禁止机器人'); btn.disabled = false; return; }
    delete body.website;
    api(mode === 'login' ? 'login' : 'register', body).then(function (j) {
      btn.disabled = false;
      if (j.ok) { init(); }
      else {
        document.getElementById('authErr').textContent = (j.error && j.error.message) || '操作失败';
        if (mode === 'register') { loadCaptcha(); }
      }
    });
  });

  function loadKeys() {
    api('keys.list', {}).then(function (j) {
      if (!j.ok) { toast((j.error && j.error.message) || '加载失败', true); return; }
      modelsCache = j.data.models || [];
      var body = document.getElementById('keysBody');
      var items = j.data.items || [];
      var h = '';
      if (!items.length) { h = '<tr><td colspan="8" class="muted">暂无密钥，点击右上角「生成密钥」。</td></tr>'; }
      items.forEach(function (k) {
        h += '<tr><td>' + k.id + '</td><td><code>' + esc(k.key_prefix || '') + '…</code></td>'
          + '<td>' + esc(k.name || '-') + '</td><td>' + pillStatus(k.status) + '</td>'
          + '<td class="muted">' + esc((k.allowed_models || '').slice(0, 30) || '全部') + '</td>'
          + '<td class="muted">' + (k.quota_daily || 0) + ' / ' + (k.quota_monthly || 0) + '</td>'
          + '<td class="muted">' + fmtTime(k.created_at) + '</td>'
          + '<td><button type="button" class="ghost" data-edit="' + k.id + '">编辑</button> '
          + '<button type="button" class="danger" data-del="' + k.id + '">删除</button></td></tr>';
      });
      body.innerHTML = h;
      body.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () { editKeyModal(findKey(items, b.getAttribute('data-edit'))); });
      });
      body.querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('确定删除该密钥？')) return;
          api('keys.delete', { id: b.getAttribute('data-del') }).then(function (j) {
            if (j.ok) { toast('已删除'); loadKeys(); } else { toast((j.error && j.error.message) || '删除失败', true); }
          });
        });
      });
    });
  }

  function loadUsage() {
    api('usage', {}).then(function (j) {
      if (!j.ok) return;
      var d = j.data;
      document.getElementById('usageStats').innerHTML =
        '<div class="stat"><div class="v">' + d.today_tokens + '</div><div class="l">今日 Token</div></div>'
        + '<div class="stat"><div class="v">' + d.monthly_tokens + '</div><div class="l">本月 Token</div></div>'
        + '<div class="stat"><div class="v">$ ' + (+d.balance).toFixed(4) + '</div><div class="l">余额</div></div>'
        + '<div class="stat"><div class="v">' + (d.quota_daily || '∞') + '</div><div class="l">日配额</div></div>'
        + '<div class="stat"><div class="v">' + (d.quota_monthly || '∞') + '</div><div class="l">月配额</div></div>';
    });
  }

  function findKey(list, id) {
    for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) return list[i]; }
    return null;
  }

  function modelOptions(selected) {
    selected = (selected || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var opts = '';
    (modelsCache || []).forEach(function (mm) {
      var sel = selected.indexOf(mm.alias) >= 0 ? ' selected' : '';
      opts += '<option value="' + esc(mm.alias) + '"' + sel + '>' + esc(mm.alias) + (mm.enabled ? '' : '（停用）') + '</option>';
    });
    return opts;
  }

  var maskEl, boxEl;
  function getModal() {
    if (!maskEl) {
      maskEl = document.getElementById('modalMask');
      boxEl = document.getElementById('modalBox');
      maskEl.addEventListener('click', function (e) { if (e.target === maskEl) closeModal(); });
    }
    return { mask: maskEl, box: boxEl };
  }
  function closeModal() {
    var m = getModal();
    m.mask.classList.remove('show');
    m.box.innerHTML = '';
  }
  function openModal(title, fieldsHtml, onSubmit) {
    var m = getModal();
    m.box.innerHTML = '<h3 style="margin:0 0 12px">' + esc(title) + '</h3>'
      + '<form>' + fieldsHtml
      + '<div class="m-actions"><button type="button" class="ghost" data-mcancel>取消</button>'
      + '<button type="submit">保存</button></div></form>';
    m.box.querySelector('[data-mcancel]').addEventListener('click', closeModal);
    m.box.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var body = {};
      new FormData(this).forEach(function (v, k) {
        if (k in body) { if (!Array.isArray(body[k])) body[k] = [body[k]]; body[k].push(v); }
        else body[k] = v;
      });
      closeModal();
      onSubmit(body);
    });
    m.mask.classList.add('show');
  }

  function editKeyModal(k) {
    var isNew = !k;
    openModal(isNew ? '生成 API 密钥' : '编辑 API 密钥',
      '<input type="hidden" name="id" value="' + (k ? k.id : 0) + '">'
      + '<div class="full"><label>备注</label><input type="text" name="name" value="' + esc(k ? k.name : '') + '"></div>'
      + '<div class="full"><label>允许模型（Ctrl/Cmd 多选，留空=全部）</label><select name="allowed_models" multiple class="mm">' + modelOptions(k ? k.allowed_models : '') + '</select></div>'
      + '<div class="full"><label>IP 白名单（逗号分隔，空=不限）</label><input type="text" name="ip_whitelist" value="' + esc(k ? k.ip_whitelist : '') + '"></div>'
      + '<div><label>日配额</label><input type="number" name="quota_daily" min="0" value="' + (k ? k.quota_daily : 0) + '"></div>'
      + '<div><label>月配额</label><input type="number" name="quota_monthly" min="0" value="' + (k ? k.quota_monthly : 0) + '"></div>'
      + '<div class="full"><p class="muted" style="font-size:12px">' + (isNew ? '生成后明文仅显示一次。' : '编辑不改变密钥明文。') + '</p></div>',
      function (body) {
        body.allowed_models = Array.isArray(body.allowed_models) ? body.allowed_models.join(',') : String(body.allowed_models || '').trim();
        api(isNew ? 'keys.create' : 'keys.update', body).then(function (j) {
          if (j.ok) {
            if (j.data && j.data.raw_key) {
              lastRawKey = j.data.raw_key;
              var box = document.getElementById('rawKeyBox');
              box.classList.remove('hidden');
              box.innerHTML = '<p style="color:var(--ok);margin:0 0 8px">API Key 已生成（仅显示一次）：</p><div class="key-box">' + esc(lastRawKey) + '</div>'
                + '<p class="muted" style="font-size:12px">请立即复制保存。</p>';
            }
            toast('操作成功');
            loadKeys();
          } else { toast((j.error && j.error.message) || '保存失败', true); }
        });
      });
  }

  document.getElementById('keyNewBtn').addEventListener('click', function () { editKeyModal(null); });

  document.getElementById('logoutBtn').addEventListener('click', function () {
    api('logout', {}).then(function () { location.reload(); });
  });

  function showApp(username) {
    document.getElementById('authOverlay').classList.add('hidden');
    document.getElementById('appShell').classList.remove('hidden');
    document.getElementById('userName').textContent = username || '';
    loadKeys();
    loadUsage();
  }

  function init() {
    api('init', {}).then(function (j) {
      if (j.ok && j.data.isLoggedIn) { showApp(j.data.username); }
      else { setMode('login'); }
    });
  }

  init();
})();
</script>
</body>
</html>