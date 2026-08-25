<?php
declare(strict_types=1);

/**
 * 后台 SPA 视图层：全部壳渲染与片段渲染逻辑集中于此。
 * 均为纯函数（接收数据，返回 HTML/JS 字符串）；AdminApp 仅负责拼装文档。
 */

/**
 * 莫奈深色主题 CSS（#1E1F2B 背景、#7C6CF0 主色）。
 */
function views_css(): string
{
    return <<<'CSS'
:root {
  --bg: #1E1F2B;
  --panel: #272936;
  --panel-2: #2E3040;
  --ink: #E7E8F2;
  --muted: #8B8DA3;
  --line: #33354A;
  --brand: #7C6CF0;
  --brand-d: #6754E4;
  --brand-bg: rgba(124,108,240,.14);
  --ok: #3ECF8E;
  --ok-bg: rgba(62,207,142,.14);
  --err: #F06A6A;
  --err-bg: rgba(240,106,106,.14);
  --warn: #E2B44D;
  --warn-bg: rgba(226,180,77,.14);
  --radius: 14px;
  --radius-sm: 10px;
  --shadow: 0 8px 30px rgba(0,0,0,.35);
}
* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0; font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  background: var(--bg); color: var(--ink); font-size: 14px;
}
a { color: var(--brand); text-decoration: none; }
h1 { font-size: 21px; margin: 0 0 18px; font-weight: 650; }
h3 { margin: 0 0 12px; font-size: 15px; }

/* 覆盖层（登录 / 强制改密） */
.overlay { position: fixed; inset: 0; z-index: 40; display: none; align-items: center; justify-content: center;
  background: radial-gradient(1200px 700px at 30% 20%, #2A2B3E, var(--bg) 60%); padding: 20px; }
.overlay.show { display: flex; }
.ov-card { width: min(380px, 100%); background: var(--panel); border: 1px solid var(--line); border-radius: 20px;
  padding: 34px 36px; box-shadow: var(--shadow); }
.ov-card h1 { font-size: 20px; }
.ov-card .sub { color: var(--muted); font-size: 13px; margin: -6px 0 20px; }
.ov-card label { display: block; font-size: 12px; color: var(--muted); margin: 12px 0 5px; }
.ov-card input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--radius-sm);
  font-size: 14px; background: var(--panel-2); color: var(--ink); transition: border-color .15s, box-shadow .15s; }
.ov-card input:focus { border-color: var(--brand); outline: none; box-shadow: 0 0 0 3px var(--brand-bg); }
.ov-card button { margin-top: 22px; width: 100%; padding: 12px; background: var(--brand); color: #fff; border: 0;
  border-radius: var(--radius-sm); cursor: pointer; font-size: 15px; font-weight: 600; transition: background .15s; }
.ov-card button:hover { background: var(--brand-d); }
.ov-card button:disabled { opacity: .6; cursor: not-allowed; }
.ov-err { color: var(--err); font-size: 13px; margin-top: 12px; min-height: 18px; }
.ov-brand { display: flex; align-items: center; gap: 8px; font-weight: 700; letter-spacing: .3px; margin-bottom: 6px; }
.ov-brand .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 12px var(--brand); }

/* 布局 */
.app { display: flex; min-height: 100vh; }
.sidebar { width: 224px; background: #23242F; border-right: 1px solid var(--line); flex-shrink: 0;
  display: flex; flex-direction: column; }
.sidebar .brand { padding: 22px 22px 18px; font-weight: 700; font-size: 15px; letter-spacing: .3px;
  display: flex; align-items: center; gap: 9px; border-bottom: 1px solid var(--line); }
.sidebar .brand .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 12px var(--brand); }
.sidebar nav { padding: 12px 0; flex: 1; }
.sidebar nav a { display: flex; align-items: center; gap: 10px; padding: 12px 22px; color: #A6A8BC; font-size: 14px;
  cursor: pointer; border-left: 3px solid transparent; transition: background .15s, color .15s; }
.sidebar nav a:hover { background: var(--brand-bg); color: #fff; }
.sidebar nav a.active { background: var(--brand-bg); color: #fff; border-left-color: var(--brand); }
.sidebar .foot { padding: 14px 22px; font-size: 12px; color: #5D5F73; border-top: 1px solid var(--line); }
.main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar { height: 60px; background: var(--panel); border-bottom: 1px solid var(--line); display: flex;
  align-items: center; padding: 0 24px; }
.topbar .title { font-weight: 650; font-size: 15px; }
.topbar .spacer { flex: 1; }
.topbar .user { font-size: 13px; color: var(--muted); margin-right: 14px; }
.topbar button { padding: 8px 16px; background: var(--panel-2); color: var(--ink); border: 1px solid var(--line);
  border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; transition: background .15s; }
.topbar button:hover { background: var(--brand-bg); border-color: var(--brand); }
.content { padding: 24px; overflow: auto; }

.card { background: var(--panel); padding: 20px 22px; border-radius: var(--radius); box-shadow: var(--shadow);
  border: 1px solid var(--line); margin-bottom: 18px; }
table { width: 100%; border-collapse: collapse; background: var(--panel); border-radius: var(--radius); overflow: hidden;
  box-shadow: var(--shadow); border: 1px solid var(--line); }
th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line); font-size: 13px; vertical-align: middle; }
th { background: var(--panel-2); color: var(--muted); font-weight: 600; white-space: nowrap; }
tr:last-child td { border-bottom: 0; }
tr:hover td { background: rgba(255,255,255,.02); }
form.inline { display: inline; }
input[type=text], input[type=number], input[type=password], input[type=email], select {
  padding: 9px 11px; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: 13px;
  background: var(--panel-2); color: var(--ink); }
input:focus, select:focus { border-color: var(--brand); outline: none; box-shadow: 0 0 0 3px var(--brand-bg); }
label { font-size: 12px; color: var(--muted); }
button, .btn { padding: 9px 14px; background: var(--brand); color: #fff; border: 0; border-radius: var(--radius-sm);
  cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
  transition: background .15s; }
button:hover, .btn:hover { background: var(--brand-d); }
button.danger { background: var(--err); }
button.danger:hover { background: #D14F4F; }
button.ghost { background: var(--panel-2); color: var(--ink); border: 1px solid var(--line); }
button.ghost:hover { background: var(--brand-bg); border-color: var(--brand); color: #fff; }
button.success { background: var(--ok); color: #0C1B13; }
button.success:hover { background: #34B87D; }
button:disabled { opacity: .6; cursor: not-allowed; }
.error { color: var(--err); }
.ok { color: var(--ok); }
.muted { color: var(--muted); }
.stats { display: flex; gap: 14px; flex-wrap: wrap; }
.stat { flex: 1; min-width: 150px; background: var(--panel); padding: 18px 20px; border-radius: var(--radius);
  box-shadow: var(--shadow); border: 1px solid var(--line); }
.stat .v { font-size: 26px; font-weight: 750; }
.stat .l { color: var(--muted); font-size: 13px; margin-top: 4px; }
.key-box { background: var(--panel-2); border: 1px dashed var(--brand); padding: 14px; border-radius: var(--radius-sm);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; color: var(--brand); }
code { background: var(--panel-2); padding: 1px 6px; border-radius: 6px; font-family: ui-monospace, monospace; }
.row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.row > div { display: flex; flex-direction: column; gap: 5px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; }
.pill { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: 12px; font-weight: 500; }
.pill.on { background: var(--ok-bg); color: var(--ok); }
.pill.off { background: var(--err-bg); color: var(--err); }
.pill.warn { background: var(--warn-bg); color: var(--warn); }
.toolbar { display: flex; gap: 10px; align-items: center; margin: 4px 0 14px; flex-wrap: wrap; }
.hint { font-size: 12px; color: var(--muted); margin: 0 0 12px; }
.spinner { width: 15px; height: 15px; border: 2px solid var(--line); border-top-color: var(--brand); border-radius: 50%;
  display: inline-block; animation: spin .7s linear infinite; vertical-align: -3px; }
@keyframes spin { to { transform: rotate(360deg); } }
.toast { position: fixed; bottom: 24px; right: 24px; background: var(--panel-2); border: 1px solid var(--line);
  color: #fff; padding: 12px 18px; border-radius: 14px; font-size: 13px; box-shadow: var(--shadow);
  opacity: 0; transform: translateY(10px); transition: opacity .2s, transform .2s; z-index: 60; }
.toast.show { opacity: 1; transform: translateY(0); }
.toast.err { border-color: var(--err); background: rgba(240,106,106,.16); color: var(--err); }
.bars { display: flex; align-items: flex-end; gap: 6px; height: 130px; padding: 14px 4px 4px; }
.bar { flex: 1; background: linear-gradient(180deg, var(--brand), var(--brand-d)); border-radius: 6px 6px 0 0;
  position: relative; min-height: 2px; transition: opacity .15s; }
.bar:hover { opacity: .85; }
.bar .v { position: absolute; top: -17px; left: 50%; transform: translateX(-50%); font-size: 11px; color: var(--muted);
  white-space: nowrap; }
.prov-block { border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 16px; overflow: hidden;
  background: var(--panel); }
.prov-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--panel-2);
  border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.prov-head .pname { font-weight: 700; font-size: 15px; }
.prov-head .purl { color: var(--muted); font-size: 12px; word-break: break-all; flex: 1; min-width: 160px; }
.prov-body { padding: 18px; }
.sub-t { font-size: 13px; font-weight: 600; color: var(--muted); margin: 0 0 10px; }

/* 模态弹窗 */
.modal-mask { position: fixed; inset: 0; z-index: 80; display: none; align-items: center; justify-content: center;
  background: rgba(0,0,0,.55); padding: 20px; }
.modal-mask.show { display: flex; }
.modal { width: 480px; max-width: 100%; max-height: 86vh; overflow: auto; background: var(--panel);
  border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow); padding: 22px 24px; }
.modal h3 { margin: 0 0 14px; font-size: 16px; }
.modal .m-close { float: right; background: none; border: 0; color: var(--muted); font-size: 20px; cursor: pointer; line-height: 1; }
.modal .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.modal .grid .full { grid-column: 1 / -1; }
.modal label { display: block; font-size: 12px; color: var(--muted); margin: 0 0 5px; }
.modal input, .modal select { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: var(--radius-sm);
  font-size: 13px; background: var(--panel-2); color: var(--ink); }
.modal .m-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px;
  background: var(--brand-bg); color: var(--ink); font-size: 12px; }
.chip button { background: none; border: 0; color: var(--muted); cursor: pointer; line-height: 1; padding: 0; font-size: 13px; }
.chip button:hover { color: var(--err); }
select[multiple].mm { min-height: 120px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }

/* ========== 响应式：电脑 >1024px / 平板 641-1024px / 手机 <=640px ==========
   原则：任何元素不隐藏，仅通过收窄、换行、重排与横向滚动适配。 */

/* 平板：侧边栏适度收窄（全部文字仍可见）+ 表格横向滚动 */
@media (max-width: 1024px) {
  .sidebar { width: 180px; }
  .sidebar .brand { padding: 18px 16px; }
  .sidebar nav a { padding: 12px 16px; }
  .sidebar .foot { padding: 12px 16px; }
  .content { padding: 20px; }
  .content table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .content table th, .content table td { white-space: nowrap; }
}

/* 手机：侧边栏整体转为顶部栏（品牌+导航+版本号全部保留），弹窗单列 */
@media (max-width: 640px) {
  .app { flex-direction: column; }
  .sidebar { width: 100%; flex-direction: column; border-right: 0; border-bottom: 1px solid var(--line); flex-shrink: 0; }
  .sidebar .brand { width: 100%; justify-content: flex-start; padding: 12px 14px; font-size: 14px;
    border-bottom: 1px solid var(--line); }
  .sidebar nav { display: flex; flex-direction: row; width: 100%; padding: 0; overflow-x: auto; }
  .sidebar nav a { flex: 0 0 auto; min-width: 58px; flex-direction: column; gap: 3px; padding: 10px 8px;
    font-size: 11px; border-left: 0; border-bottom: 2px solid transparent; }
  .sidebar nav a.active { border-left-color: transparent; border-bottom-color: var(--brand); }
  .sidebar .foot { width: 100%; padding: 8px 14px; font-size: 11px; border-top: 1px solid var(--line); }
  .topbar { height: auto; min-height: 52px; padding: 0 14px; flex-wrap: wrap; gap: 6px; }
  .topbar .user { font-size: 12px; margin-right: 8px; }
  .content { padding: 14px 12px 24px; }
  h1 { font-size: 18px; }
  .modal { width: 100%; max-width: 100%; padding: 18px 16px; }
  .modal .grid { grid-template-columns: 1fr; }
  .toolbar button, .toolbar .btn { flex: 1 1 auto; justify-content: center; }
  .stat { min-width: 44%; }
  .prov-head { padding: 12px; }
  .prov-head .purl { flex-basis: 100%; }
  .toast { bottom: 16px; right: 16px; left: 16px; text-align: center; z-index: 90; }
  .key-box { font-size: 12px; }
}
CSS;
}

/**
 * 登录覆盖层。
 */
function views_login_overlay(): string
{
    return <<<'HTML'
<div class="overlay" id="loginOverlay">
  <div class="ov-card">
    <div class="ov-brand"><span class="dot"></span>API 中转站</div>
    <h1>管理后台</h1>
    <div class="sub">请登录以继续</div>
    <form id="loginForm" autocomplete="off">
      <label>用户名</label>
      <input type="text" name="username" autofocus>
      <label>密码</label>
      <input type="password" name="password">
      <button type="submit">登 录</button>
      <div class="ov-err" id="loginErr"></div>
    </form>
  </div>
</div>
HTML;
}

/**
 * 强制修改用户名 + 密码覆盖层（must_change）。
 */
function views_must_change_overlay(): string
{
    return <<<'HTML'
<div class="overlay" id="changeOverlay">
  <div class="ov-card">
    <div class="ov-brand"><span class="dot"></span>API 中转站</div>
    <h1>安全提醒</h1>
    <div class="sub">当前仍使用初始默认凭据，请立即修改用户名与密码后继续。</div>
    <form id="changeForm" autocomplete="off">
      <label>新用户名（3-64 字符）</label>
      <input type="text" name="username" autofocus>
      <label>新密码（至少 8 位）</label>
      <input type="password" name="password">
      <button type="submit">保存并进入</button>
      <div class="ov-err" id="changeErr"></div>
    </form>
  </div>
</div>
HTML;
}

/**
 * SPA 应用 JS：api(action, body) fetch 助手 + render(section, data) 片段渲染 + 交互绑定。
 */
function views_app_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  var VIEWS = {
    dashboard: { title: '仪表盘', action: 'dashboard' },
    keys:      { title: 'API 密钥', action: 'keys.list' },
    providers: { title: '供应商', action: 'providers.list' },
    logs:      { title: '日志', action: 'logs.list' },
    billing:   { title: '账单', action: 'billing.list' },
    audit:     { title: '操作审计', action: 'audit.list' },
    metrics:   { title: '运行指标', action: 'metrics.get' },
    profile:   { title: '账号', action: 'profile.get' },
    speedtest: { title: '一键测速', action: 'speedtest.run' }
  };
  var current = 'dashboard';
  var lastRawKey = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, isErr) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(t._t); t._t = setTimeout(function () { t.className = 'toast'; }, 2600);
  }
  function pillOn(cond) { return cond ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>'; }
  function pillStatus(s) { return s == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>'; }
  function stat(v, l) { return '<div class="stat"><div class="v">' + v + '</div><div class="l">' + esc(l) + '</div></div>'; }
  function fmtTime(t) { return t ? new Date(t * 1000).toLocaleString('zh-CN', { hour12: false }) : '-'; }
  function trunc(s) { s = s == null ? '' : String(s); return s.length > 24 ? s.slice(0, 24) + '…' : s; }

  /* ---------- 模态弹窗 ---------- */
  var maskEl = null, boxEl = null, modalAction = null;
  function getModal() {
    if (!maskEl) { maskEl = document.getElementById('modalMask'); boxEl = document.getElementById('modalBox');
      maskEl.addEventListener('click', function (e) { if (e.target === maskEl) closeModal(); }); }
    return { mask: maskEl, box: boxEl };
  }
  function openModal(title, fieldsHtml, onSubmit) {
    var m = getModal();
    modalAction = onSubmit;
    boxEl.innerHTML = '<button type="button" class="m-close" data-mclose>&times;</button><h3>' + esc(title) + '</h3>'
      + '<form data-mform>' + fieldsHtml
      + '<div class="m-actions"><button type="button" data-mcancel class="ghost">取消</button>'
      + '<button type="submit">保存</button></div></form>';
    boxEl.querySelector('[data-mclose]').addEventListener('click', closeModal);
    boxEl.querySelector('[data-mcancel]').addEventListener('click', closeModal);
    boxEl.querySelector('form[data-mform]').addEventListener('submit', function (e) {
      e.preventDefault();
      var body = {};
      /* 多选（<select multiple>）同 key 多次出现 → 聚合为数组 */
      new FormData(this).forEach(function (v, k) {
        if (k in body) {
          if (!Array.isArray(body[k])) { body[k] = [body[k]]; }
          body[k].push(v);
        } else {
          body[k] = v;
        }
      });
      closeModal();
      if (modalAction) { modalAction(body); }
    });
    m.mask.classList.add('show');
    var first = boxEl.querySelector('[name]:not([type=hidden])');
    if (first) first.focus();
  }
  function closeModal() {
    var m = getModal();
    m.mask.classList.remove('show');
    boxEl.innerHTML = '';
  }
  function confirmModal(title, msg, onOk) {
    var m = getModal();
    modalAction = onOk;
    boxEl.innerHTML = '<button type="button" class="m-close" data-mclose>&times;</button><h3>' + esc(title) + '</h3>'
      + '<p style="margin:0 0 18px">' + esc(msg) + '</p>'
      + '<div class="m-actions"><button type="button" data-mcancel class="ghost">取消</button>'
      + '<button type="button" class="danger" data-mok>确认删除</button></div>';
    boxEl.querySelector('[data-mclose]').addEventListener('click', closeModal);
    boxEl.querySelector('[data-mcancel]').addEventListener('click', closeModal);
    boxEl.querySelector('[data-mok]').addEventListener('click', function () {
      closeModal(); if (modalAction) modalAction();
    });
    m.mask.classList.add('show');
  }
  function submitMutate(action, body) {
    api(action, body).then(function (j) {
      if (j.ok && action === 'keys.save' && j.data && j.data.raw_key) { lastRawKey = j.data.raw_key; }
      afterMutate(j);
    });
  }
  function modelOptions(selected, models) {
    selected = (selected || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var opts = '';
    (models || []).forEach(function (mm) {
      var sel = selected.indexOf(mm.alias) >= 0 ? ' selected' : '';
      var dis = mm.enabled ? '' : '';
      opts += '<option value="' + esc(mm.alias) + '"' + sel + dis + '>' + esc(mm.alias) + (mm.enabled ? '' : '（停用）') + '</option>';
    });
    return opts;
  }
  function fieldsWrap(html) { return html; }

  /* ---------- 数据获取 ---------- */
  function api(action, body) {
    body = body || {};
    body.action = action;
    return fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: { message: '响应解析失败', type: 'bad_response' } }; }); });
  }

  /* ---------- 片段渲染 ---------- */
  function fDashboard(d) {
    var rate = (d.today_success_rate == null ? 100 : d.today_success_rate) + '%';
    return '<h1>仪表盘</h1><div class="stats">'
      + stat(d.api_keys, 'API Key 数')
      + stat(d.models, '启用模型')
      + stat(d.today_requests, '今日请求')
      + stat(d.today_tokens, '今日 Token')
      + stat(rate, '今日成功率')
      + '</div>';
  }

  function fKeys(data) {
    var models = data.models || [];
    var h = '<h1>API 密钥</h1><div class="card"><div class="toolbar">'
      + '<button type="button" class="success" id="keyNewBtn">生成密钥</button>'
      + '<span class="muted">密钥明文仅生成时显示一次，之后不可见。</span></div></div>';
    if (lastRawKey) {
      h += '<div class="card"><p class="ok">API Key 已生成（仅显示一次）：</p><div class="key-box">' + esc(lastRawKey) + '</div>'
        + '<p class="muted" style="font-size:12px">请立即复制保存，之后无法再次查看。</p></div>';
    }
    h += '<table><tr><th>ID</th><th>前缀</th><th>备注</th><th>状态</th><th>允许模型</th><th>IP白名单</th><th>日配额</th><th>月配额</th><th>创建时间</th><th>操作</th></tr>';
    (data.items || []).forEach(function (k) {
      h += '<tr><td>' + k.id + '</td><td><code>' + esc(k.key_prefix || '') + '…</code></td>'
        + '<td>' + esc(k.name || '-') + '</td><td>' + pillStatus(k.status) + '</td>'
        + '<td class="muted">' + esc(trunc(k.allowed_models || '')) + '</td><td class="muted">' + esc(trunc(k.ip_whitelist || '')) + '</td>'
        + '<td class="muted">' + (k.quota_daily || 0) + '</td><td class="muted">' + (k.quota_monthly || 0) + '</td>'
        + '<td class="muted">' + fmtTime(k.created_at) + '</td><td>'
        + '<button type="button" class="ghost" data-editkey="' + k.id + '">编辑</button> '
        + '<button type="button" class="ghost" data-api-js="keys.save" data-toggle="' + k.id + '">' + (k.status == 1 ? '停用' : '启用') + '</button> '
        + '<button type="button" class="danger" data-delkey="' + k.id + '" data-name="' + esc(k.name || k.key_prefix || '') + '">删除</button>'
        + '</td></tr>';
    });
    h += '</table>';
    /* 延迟绑定生成/编辑/删除，避免依赖页内表单 */
    setTimeout(function () {
      var nb = document.getElementById('keyNewBtn');
      if (nb) { nb.addEventListener('click', function () { editKeyModal(null, models); }); }
      var content = document.getElementById('content');
      content.querySelectorAll('[data-editkey]').forEach(function (b) {
        var k = findKey((data.items || []), b.getAttribute('data-editkey'));
        b.addEventListener('click', function () { editKeyModal(k, models); });
      });
      content.querySelectorAll('[data-delkey]').forEach(function (b) {
        b.addEventListener('click', function () {
          confirmModal('删除 API 密钥', '确定要删除该密钥吗？此操作不可恢复。', function () {
            submitMutate('keys.delete', { id: b.getAttribute('data-delkey') });
          });
        });
      });
    }, 0);
    return h;
  }

  function findKey(list, id) {
    for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) return list[i]; }
    return null;
  }

  function editKeyModal(k, models) {
    var isNew = !k;
    var sel = modelOptions(k ? k.allowed_models : '', models);
    openModal(isNew ? '生成 API 密钥' : '编辑 API 密钥',
      '<input type="hidden" name="id" value="' + (k ? k.id : 0) + '">'
      + '<div class="full"><label>备注</label><input type="text" name="name" value="' + esc(k ? k.name : '') + '" placeholder="密钥名称/备注"></div>'
      + '<div class="full"><label>允许模型（可多选，Ctrl/Cmd+点击）</label>'
      + '<select name="allowed_models" multiple class="mm">' + sel + '</select></div>'
      + '<div class="full"><label>IP 白名单（逗号分隔，空=不限）</label><input type="text" name="ip_whitelist" value="' + esc(k ? k.ip_whitelist : '') + '" placeholder="如 1.2.3.4,192.168.1.0/24"></div>'
      + '<div><label>日配额Token</label><input type="number" name="quota_daily" min="0" value="' + (k ? k.quota_daily : 0) + '"></div>'
      + '<div><label>月配额Token</label><input type="number" name="quota_monthly" min="0" value="' + (k ? k.quota_monthly : 0) + '"></div>'
      + '<div class="full"><label>状态</label><select name="status">'
      + '<option value="1"' + (k && k.status == 0 ? '' : ' selected') + '>启用</option>'
      + '<option value="0"' + (k && k.status == 0 ? ' selected' : '') + '>停用</option></select></div>'
      + '<div class="full"><p class="hint" style="margin:0">' + (isNew ? '生成密钥明文仅显示一次，请立即复制保存。' : '编辑不会改变已有密钥明文。') + '</p></div>',
      function (body) {
        body.allowed_models = Array.isArray(body.allowed_models)
          ? body.allowed_models.join(',')                     /* 多选序列化为逗号分隔 */
          : String(body.allowed_models || '').trim();
        submitMutate('keys.save', body);
      });
  }

  function formatLabel(f, formats) {
    f = f || 'openai';
    return (formats && formats[f]) ? formats[f] : f;
  }

  /* 模型管理区（供应商页顶部统一表格） */
  function modelManageCard(providers, models) {
    var h = '<h1>供应商 / 模型管理</h1><div class="card"><div class="toolbar">'
      + '<button type="button" class="success" id="modelNewBtn">新增模型</button>'
      + '<button type="button" class="ghost" id="provSyncAllBtn">同步全部模型</button>'
      + '<button type="button" class="ghost" id="provSpeedBtn2">一键测速</button>'
      + '<span class="muted">模型中继映射：别名→供应商→上游模型。上游一键同步后可在此启用/禁用。</span></div>'
      + '<div id="provResult"></div></div>';
    h += '<table><tr><th>ID</th><th>别名</th><th>供应商</th><th>上游模型</th><th>格式</th><th>状态</th><th>操作</th></tr>';
    (models || []).forEach(function (m) {
      h += '<tr><td>' + m.id + '</td><td>' + esc(m.alias) + '</td><td>' + esc(m.provider) + '</td>'
        + '<td>' + esc(m.upstream_model || '-') + '</td><td>' + esc(m.client_format) + '</td><td>' + pillStatus(m.enabled) + '</td><td>'
        + '<button type="button" class="ghost" data-editmodel="' + m.id + '">编辑</button> '
        + '<button type="button" class="ghost" data-togglemodel="' + m.id + '">' + (m.enabled == 1 ? '禁用' : '启用') + '</button> '
        + '<button type="button" class="danger" data-delmodel="' + m.id + '">删除</button>'
        + '</td></tr>';
    });
    h += '</table>';
    setTimeout(function () {
      var nb = document.getElementById('modelNewBtn');
      if (nb) { nb.addEventListener('click', function () { editModelModal(null, providers); }); }
      var sba = document.getElementById('provSyncAllBtn');
      if (sba) { sba.addEventListener('click', function () { submitMutate('modelmap.sync', {}); }); }
      var sp = document.getElementById('provSpeedBtn2');
      if (sp) {
        sp.addEventListener('click', function () {
          document.getElementById('provResult').innerHTML = '<span class="spinner"></span> 测速中…';
          api('speedtest.run', {}).then(function (j) {
            document.getElementById('provResult').innerHTML = j.ok ? fSpeedResult(j.data.results)
              : '<p class="error">' + esc((j.error && j.error.message) || '测速失败') + '</p>';
          }).catch(function () { document.getElementById('provResult').innerHTML = '<p class="error">测速失败</p>'; });
        });
      }
      var content = document.getElementById('content');
      content.querySelectorAll('[data-editmodel]').forEach(function (b) {
        var m = findModel(models, b.getAttribute('data-editmodel'));
        b.addEventListener('click', function () { editModelModal(m, providers); });
      });
      content.querySelectorAll('[data-togglemodel]').forEach(function (b) {
        b.addEventListener('click', function () {
          var m = findModel(models, b.getAttribute('data-togglemodel'));
          submitMutate('modelmap.save', { id: m.id, alias: m.alias, provider: m.provider, upstream_model: m.upstream_model, client_format: m.client_format, enabled: m.enabled == 1 ? 0 : 1 });
        });
      });
      content.querySelectorAll('[data-delmodel]').forEach(function (b) {
        b.addEventListener('click', function () {
          confirmModal('删除模型映射', '确定要删除该模型映射吗？此操作不可恢复。', function () {
            submitMutate('modelmap.delete', { id: b.getAttribute('data-delmodel') });
          });
        });
      });
    }, 0);
    return h;
  }

  function findModel(list, id) {
    for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) return list[i]; }
    return null;
  }

  function editModelModal(m, providers) {
    var isNew = !m;
    var pOpts = '';
    (providers || []).forEach(function (p) {
      var sel = m && m.provider === p.name ? ' selected' : '';
      pOpts += '<option value="' + esc(p.name) + '"' + sel + '>' + esc(p.name) + '</option>';
    });
    openModal(isNew ? '新增模型' : '编辑模型',
      '<input type="hidden" name="id" value="' + (m ? m.id : 0) + '">'
      + '<div class="full"><label>别名 (alias，对外名)</label><input type="text" name="alias" value="' + esc(m ? m.alias : '') + '" required></div>'
      + '<div class="full"><label>供应商</label><select name="provider">' + pOpts + '</select></div>'
      + '<div class="full"><label>上游模型（真实模型名）</label><input type="text" name="upstream_model" value="' + esc(m ? m.upstream_model : '') + '"></div>'
      + '<div class="full"><label>客户端格式</label><select name="client_format">'
      + '<option value="openai"' + (m && m.client_format == 'anthropic' ? '' : (m && m.client_format == 'gemini' ? '' : ' selected')) + '>OpenAI 兼容</option>'
      + '<option value="anthropic"' + (m && m.client_format == 'anthropic' ? ' selected' : '') + '>Anthropic</option>'
      + '<option value="gemini"' + (m && m.client_format == 'gemini' ? ' selected' : '') + '>Gemini</option></select></div>'
      + '<div class="full"><label>状态</label><select name="enabled">'
      + '<option value="1"' + (m && m.enabled == 0 ? '' : ' selected') + '>启用</option>'
      + '<option value="0"' + (m && m.enabled == 0 ? ' selected' : '') + '>停用</option></select></div>'
      + '<div class="full"><p class="hint" style="margin:0">上游模型留空时默认等于别名。</p></div>',
      function (body) { submitMutate('modelmap.save', body); });
  }

  /* 上游密钥（单条）弹窗 */
  function editUpstreamKeyModal(k, provider) {
    var isNew = !k;
    openModal(isNew ? '添加上游密钥' : '上游密钥（可替换）',
      '<input type="hidden" name="id" value="' + (k ? k.id : 0) + '">'
      + '<input type="hidden" name="provider_id" value="' + provider.id + '">'
      + '<div class="full"><label>API Key 明文</label>'
      + '<input type="text" name="key_value" value="' + (!isNew && k.has_key ? esc(k.key_value) : '') + '" placeholder="' + (isNew ? '粘贴上游 API Key' : '留空则保持不变') + '"></div>'
      + '<div class="full"><label>权重（越大越优先）</label><input type="number" name="weight" min="0" value="' + (k ? k.weight : 1) + '"></div>'
      + '<div class="full"><label>状态</label><select name="status">'
      + '<option value="1"' + (k && k.status == 0 ? '' : ' selected') + '>启用</option>'
      + '<option value="0"' + (k && k.status == 0 ? ' selected' : '') + '>停用</option></select></div>',
      function (body) { submitMutate('upstream.key.save', body); });
  }

  function fProviders(data) {
    var h = modelManageCard(data.items, data.models);
    h += '<div class="card"><div class="toolbar">'
      + '<button type="button" class="success" id="provNewBtn">新增供应商</button>'
      + '<span class="muted">上游密钥加密存储，明文可回显（仅管理员可见）。</span></div></div>';
    (data.items || []).forEach(function (p) {
      h += '<div class="prov-block"><div class="prov-head">'
        + '<span class="pill on">' + esc(formatLabel(p.client_format, data.formats)) + '</span>'
        + '<span class="pname">' + esc(p.name) + '</span>'
        + '<span class="purl">' + esc(p.base_url) + '</span>' + pillStatus(p.status)
        + '<button type="button" class="ghost" data-editprov="' + p.id + '">编辑</button> '
        + '<button type="button" class="ghost" data-syncprov="' + p.id + '">同步模型</button> '
        + '<button type="button" class="danger" data-delprov="' + p.id + '">删除</button>'
        + '</div><div class="prov-body">'
        + '<div class="toolbar" style="margin-bottom:8px"><p class="sub-t" style="margin:0;align-self:center">上游密钥（加密存储）</p>'
        + '<button type="button" class="ghost" data-addkey="' + p.id + '">添加上游密钥</button></div>';
      var keys = p.upstream_keys || [];
      if (keys.length) {
        h += '<table><tr><th>ID</th><th>状态</th><th>权重</th><th>连续失败</th><th>密钥明文</th><th>操作</th></tr>';
        keys.forEach(function (k) {
          h += '<tr><td>' + k.id + '</td><td>' + pillStatus(k.status) + '</td><td>' + k.weight + '</td>'
            + '<td class="muted">' + k.consecutive_failures + '</td>'
            + '<td class="mono muted">' + (k.has_key ? esc(trunc(k.key_value)) : '<span class="muted">无</span>') + '</td>'
            + '<td>'
            + '<button type="button" class="ghost" data-editkey2="' + k.id + '" data-prov="' + p.id + '">查看/替换</button> '
            + '<button type="button" class="danger" data-delkey2="' + k.id + '">删除</button>'
            + '</td></tr>';
        });
        h += '</table>';
      } else {
        h += '<p class="muted" style="margin:0 0 12px">该供应商暂无上游密钥，请点击「添加上游密钥」。</p>';
      }
      h += '</div></div>';
    });
    if (!(data.items || []).length) { h += '<p class="muted">尚未配置供应商。</p>'; }
    setTimeout(function () {
      var nb = document.getElementById('provNewBtn');
      if (nb) { nb.addEventListener('click', function () { editProviderModal(null, data.formats); }); }
      var content = document.getElementById('content');
      content.querySelectorAll('[data-editprov]').forEach(function (b) {
        var p = findProvider(data.items, b.getAttribute('data-editprov'));
        b.addEventListener('click', function () { editProviderModal(p, data.formats); });
      });
      content.querySelectorAll('[data-syncprov]').forEach(function (b) {
        b.addEventListener('click', function () { submitMutate('modelmap.sync', { provider_id: b.getAttribute('data-syncprov') }); });
      });
      content.querySelectorAll('[data-delprov]').forEach(function (b) {
        b.addEventListener('click', function () {
          confirmModal('删除供应商', '删除该供应商会同时删除其全部上游密钥与关联模型映射，确定继续？', function () {
            submitMutate('providers.delete', { id: b.getAttribute('data-delprov') });
          });
        });
      });
      content.querySelectorAll('[data-addkey]').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = findProvider(data.items, b.getAttribute('data-addkey'));
          editUpstreamKeyModal(null, p);
        });
      });
      content.querySelectorAll('[data-editkey2]').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = findProvider(data.items, b.getAttribute('data-prov'));
          var k = findKey((p.upstream_keys || []), b.getAttribute('data-editkey2'));
          editUpstreamKeyModal(k, p);
        });
      });
      content.querySelectorAll('[data-delkey2]').forEach(function (b) {
        b.addEventListener('click', function () {
          confirmModal('删除上游密钥', '确定要删除该上游密钥吗？此操作不可恢复。', function () {
            submitMutate('upstream.key.delete', { id: b.getAttribute('data-delkey2') });
          });
        });
      });
    }, 0);
    return h;
  }

  function findProvider(list, id) {
    for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) return list[i]; }
    return null;
  }

  function editProviderModal(p, formats) {
    var isNew = !p;
    var fSel = '';
    Object.keys(formats || {}).forEach(function (f) {
      var sel = p && p.client_format === f ? ' selected' : (!p && f === 'openai' ? ' selected' : '');
      fSel += '<option value="' + esc(f) + '"' + sel + '>' + esc(formats[f]) + '</option>';
    });
    openModal(isNew ? '新增供应商' : '编辑供应商',
      '<input type="hidden" name="id" value="' + (p ? p.id : 0) + '">'
      + '<div class="full"><label>名称/类型</label><input type="text" name="name" value="' + esc(p ? p.name : '') + '" required placeholder="openai"></div>'
      + '<div class="full"><label>接口格式</label><select name="client_format">' + fSel + '</select></div>'
      + '<div class="full"><label>API URL</label><input type="text" name="base_url" value="' + esc(p ? p.base_url : '') + '" required placeholder="https://api.openai.com"></div>'
      + '<div class="full"><label>状态</label><select name="status">'
      + '<option value="1"' + (p && p.status == 0 ? '' : ' selected') + '>启用</option>'
      + '<option value="0"' + (p && p.status == 0 ? ' selected' : '') + '>停用</option></select></div>'
      + '<div class="full"><p class="hint" style="margin:0">' + (isNew ? '保存后可在下方为该供应商添加上游密钥。' : '修改不影响已存的上游密钥。') + '</p></div>',
      function (body) { submitMutate('providers.save', body); });
  }

  function fLogs(data) {
    var h = '<h1>请求日志</h1><div class="card"><form data-api="logs.list" class="toolbar">'
      + '<input type="number" name="user_id" placeholder="用户ID" style="width:90px">'
      + '<input type="number" name="status" placeholder="状态码" style="width:90px">'
      + '<input type="text" name="error" placeholder="错误关键字" style="width:150px">'
      + '<button type="submit">筛选</button></form></div>';
    if (!(data.items || []).length) { h += '<div class="card"><p class="muted">没有符合条件的日志记录。</p></div>'; return h; }
    h += '<table><tr><th>时间</th><th>用户</th><th>模型</th><th>供应商</th><th>路径</th><th>状态</th><th>延迟</th><th>输入</th><th>输出</th><th>错误</th></tr>';
    data.items.forEach(function (r) {
      var st = r.status; var cls = st >= 500 ? 'off' : (st >= 400 ? 'warn' : 'on');
      h += '<tr><td class="muted">' + fmtTime(r.created_at) + '</td><td>' + esc(r.user_id) + '</td>'
        + '<td>' + esc(r.model) + '</td><td class="muted">' + esc(r.provider) + '</td><td class="muted">' + esc(r.endpoint) + '</td>'
        + '<td><span class="pill ' + cls + '">' + st + '</span></td><td>' + r.latency_ms + ' ms</td>'
        + '<td class="muted">' + r.prompt_tokens + '</td><td class="muted">' + r.completion_tokens + '</td>'
        + '<td class="muted">' + esc((r.error || '').slice(0, 30) || '-') + '</td></tr>';
    });
    h += '</table><div class="toolbar"><span class="muted">共 ' + data.total + ' 条</span></div>';
    return h;
  }

  function fBilling(data) {
    var s = data.summary || {};
    var h = '<h1>账单统计</h1><div class="card"><form data-api="billing.list" class="toolbar">'
      + '<label>范围（天，0=全部）</label><input type="number" name="days" value="' + (data.days || 30) + '" style="width:80px">'
      + '<button type="submit">查看</button></form></div>';
    h += '<div class="stats">' + stat(s.count || 0, '请求次数') + stat('$ ' + (s.cost || 0).toFixed(4), '消费') + stat(s.tokens || 0, 'Token') + '</div>';
    h += '<div class="card"><h3>按密钥</h3>' + (data.by_key || []).length
      ? tableHtml(['密钥前缀', '请求', 'Token', '消费'], data.by_key, function (r) {
          return '<td><code>' + esc(r.key_prefix || '#key') + '</code></td><td>' + r.count + '</td><td>' + r.tokens + '</td><td>$ ' + (+r.cost).toFixed(4) + '</td>';
        })
      : '<p class="muted">暂无数据</p>';
    h += '</div><div class="card"><h3>按模型</h3>' + (data.by_model || []).length
      ? tableHtml(['模型', '请求', '消费'], data.by_model, function (r) {
          return '<td>' + esc(r.model || '-') + '</td><td>' + r.count + '</td><td>$ ' + (+r.cost).toFixed(4) + '</td>';
        })
      : '<p class="muted">暂无数据</p>';
    h += '</div>';
    return h;
  }

  function tableHtml(heads, rows, rowFn) {
    var h = '<table><tr>' + heads.map(function (x) { return '<th>' + esc(x) + '</th>'; }).join('') + '</tr>';
    rows.forEach(function (r) { h += '<tr>' + rowFn(r) + '</tr>'; });
    h += '</table>'; return h;
  }

  function fAudit(data) {
    var rows = data.items || [];
    var h = '<h1>操作审计</h1>';
    if (!rows.length) { h += '<div class="card"><p class="muted">暂无操作记录。</p></div>'; return h; }
    h += tableHtml(['时间', '管理员', '操作', '详情'], rows, function (r) {
      return '<td class="muted">' + fmtTime(r.created_at) + '</td><td>' + esc(r.admin_name) + '</td>'
        + '<td><span class="pill on">' + esc(r.action) + '</span></td><td class="muted"><code>' + esc((r.detail || '').slice(0, 120)) + '</code></td>';
    });
    return h;
  }

  function fMetrics(data) {
    var h = '<h1>运行指标</h1><div class="card"><p class="hint">近 7 日每日请求 / Token 走势。</p><div class="bars">';
    var daily = data.daily || [];
    var max = Math.max.apply(null, daily.map(function (d) { return d.requests; }).concat([1]));
    daily.forEach(function (d) {
      var hh = d.requests > 0 ? Math.max(4, Math.round(d.requests / max * 100)) : 2;
      h += '<div class="bar" style="height:' + hh + '%" title="' + esc(d.day) + ': ' + d.requests + ' 次"><span class="v">' + d.requests + '</span></div>';
    });
    h += '</div></div>';
    var t = data.totals || {};
    h += '<div class="stats">' + stat(t.total || 0, '总请求') + stat(t.success || 0, '成功') + stat(t.tokens || 0, 'Token') + stat('$ ' + (t.cost || 0).toFixed(4), '消费') + '</div>';
    h += '<div class="card"><h3>每日明细</h3>' + (daily.length ? tableHtml(['日期', '请求', 'Token', '消费'], daily, function (d) {
      return '<td>' + esc(d.day) + '</td><td>' + d.requests + '</td><td>' + d.tokens + '</td><td>$ ' + (+d.cost).toFixed(4) + '</td>';
    }) : '<p class="muted">暂无数据</p>') + '</div>';
    return h;
  }

  function fProfile(u) {
    var h = '<h1>账号</h1>';
    if (u.must_change) {
      h += '<div class="card" style="border-color:var(--warn)"><p class="error"><b>安全提醒：</b>当前仍使用初始默认凭据，请立即修改用户名与密码。</p></div>';
    }
    h += '<div class="card"><p class="muted">用户名：<b>' + esc(u.username) + '</b>（ID ' + u.id + '，创建于 ' + fmtTime(u.created_at) + '）</p></div>';
    h += '<div class="card"><h3>修改密码</h3><form data-api="profile.change_password" class="grid">'
      + '<div><label>当前密码</label><input type="password" name="old_password" required></div>'
      + '<div><label>新密码（至少 8 位）</label><input type="password" name="new_password" required minlength="8"></div>'
      + '<div><label>&nbsp;</label><button type="submit">保存</button></div>'
      + '</form></div>';
    return h;
  }

  function fSpeedPanel() {
    return '<h1>一键测速</h1><div class="card"><p class="hint">对每个已启用供应商的每个上游 Key 进行一次轻量探测，记录可用性与延迟。</p>'
      + '<div class="toolbar"><button id="speedBtn" class="success">开始测速</button><span id="speedStatus"></span></div>'
      + '<div id="speedResult"></div></div>';
  }

  function fSpeedResult(results) {
    if (!results || !results.length) { return '<p class="error">无数据</p>'; }
    var rows = results.map(function (d) {
      return '<tr><td>' + esc(d.provider) + '</td><td>#' + d.upstream_key_id + '</td>'
        + '<td>' + (d.ok ? '<span class="pill on">可用</span>' : '<span class="pill off">不可用</span>') + '</td>'
        + '<td>' + (d.latency_ms != null ? d.latency_ms + ' ms' : '-') + '</td><td class="muted">' + esc(d.detail) + '</td></tr>';
    }).join('');
    return '<table><tr><th>供应商</th><th>Key</th><th>状态</th><th>延迟</th><th>详情</th></tr>' + rows + '</table>';
  }

  function render(section, data) {
    var fns = {
      dashboard: fDashboard, keys: fKeys,
      providers: fProviders, logs: fLogs, billing: fBilling, audit: fAudit,
      metrics: fMetrics, profile: fProfile
    };
    var fn = fns[section];
    return fn ? fn(data) : '<div class="card"><p class="muted">未知视图：' + esc(section) + '</p></div>';
  }

  /* ---------- 交互 ---------- */
  function setActive(v) {
    document.querySelectorAll('.sidebar nav a').forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('data-view') === v);
    });
    document.getElementById('viewTitle').textContent = (VIEWS[v] || {}).title || '';
  }

  function loadView(v) {
    current = v;
    setActive(v);
    var c = document.getElementById('content');
    if (v === 'speedtest') { c.innerHTML = fSpeedPanel(); bindSpeed(); return; }
    api(VIEWS[v].action, {}).then(function (j) {
      if (j.ok) {
        c.innerHTML = render(v, j.data);
        bindForms(c);
        bindButtons(c);
      } else {
        toast((j.error && j.error.message) || '加载失败', true);
        if (j.error && j.error.type === 'unauthorized') { location.reload(); }
      }
    });
  }

  function bindForms(root) {
    root.querySelectorAll('form[data-api]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var action = form.getAttribute('data-api');
        var body = {};
        new FormData(form).forEach(function (v, k) { body[k] = v; });
        api(action, body).then(function (j) {
          if (j.ok) {
            toast('操作成功');
            if (action === 'keys.save' && j.data && j.data.raw_key) { lastRawKey = j.data.raw_key; }
            if (action === 'logs.list' || action === 'billing.list') { applyDataToView(action, j.data); }
            else { loadView(current); }
          } else {
            toast((j.error && j.error.message) || '操作失败', true);
          }
        });
      });
    });
  }

  function applyDataToView(action, data) {
    var c = document.getElementById('content');
    if (action === 'logs.list') { c.innerHTML = fLogs(data); bindForms(c); bindButtons(c); }
    if (action === 'billing.list') { c.innerHTML = fBilling(data); bindForms(c); bindButtons(c); }
  }

  function bindButtons(root) {
    root.querySelectorAll('button[data-api-js]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-api-js');
        var body = {};
        if (btn.hasAttribute('data-id')) { body.id = btn.getAttribute('data-id'); }
        if (btn.hasAttribute('data-toggle')) { body.id = btn.getAttribute('data-toggle'); }
        api(action, body).then(afterMutate);
      });
    });
  }

  function afterMutate(j) {
    if (j.ok) { toast('操作成功'); loadView(current); }
    else { toast((j.error && j.error.message) || '操作失败', true); }
  }

  function bindSpeed() {
    var btn = document.getElementById('speedBtn');
    btn.addEventListener('click', function () {
      var st = document.getElementById('speedStatus');
      st.innerHTML = '<span class="spinner"></span> 探测中…';
      document.getElementById('speedResult').innerHTML = '';
      api('speedtest.run', {}).then(function (j) {
        st.textContent = '完成';
        document.getElementById('speedResult').innerHTML = j.ok ? fSpeedResult(j.data.results) : '<p class="error">' + esc(j.error.message) + '</p>';
      }).catch(function () { st.textContent = '测速失败'; });
    });
  }

  /* ---------- 初始化 ---------- */
  function showApp(username) {
    document.getElementById('loginOverlay').classList.remove('show');
    document.getElementById('changeOverlay').classList.remove('show');
    document.getElementById('appShell').classList.remove('hidden');
    document.getElementById('userName').textContent = username || '';
    loadView('dashboard');
  }

  function init() {
    api('app.init', {}).then(function (j) {
      if (j.ok) { showApp(j.data.username); return; }
      if (j.error && j.error.type === 'must_change') {
        document.getElementById('changeOverlay').classList.add('show');
        return;
      }
      document.getElementById('loginOverlay').classList.add('show');
    });
  }

  document.getElementById('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var body = {};
    new FormData(this).forEach(function (v, k) { body[k] = v; });
    api('login', body).then(function (j) {
      if (j.ok) { init(); }
      else { document.getElementById('loginErr').textContent = (j.error && j.error.message) || '登录失败'; }
    });
  });

  document.getElementById('changeForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = this.querySelector('button[type=submit]'); btn.disabled = true;
    var body = {};
    new FormData(this).forEach(function (v, k) { body[k] = v; });
    api('profile.save', body).then(function (j) {
      if (j.ok) { init(); }
      else { btn.disabled = false; document.getElementById('changeErr').textContent = (j.error && j.error.message) || '保存失败'; }
    });
  });

  document.getElementById('logoutBtn').addEventListener('click', function () {
    api('logout', {}).then(function () { location.reload(); });
  });

  document.querySelectorAll('.sidebar nav a').forEach(function (a) {
    a.addEventListener('click', function () { loadView(a.getAttribute('data-view')); });
  });

  init();
})();
JS;
}
