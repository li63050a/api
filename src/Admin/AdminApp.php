<?php
declare(strict_types=1);

namespace App\Admin;

use App\Domain\Auth\AdminAuth;
use App\Http\Request;

require_once __DIR__ . '/View/views.php';

/**
 * 后台 SPA 壳：仅负责拼装文档，视图字符串/片段渲染全部委托给 View/views.php。
 */
final class AdminApp
{
    public function __construct(private AdminAuth $auth) {}

    public function render(Request $request): string
    {
        $nav = [
            'dashboard' => '仪表盘',
            'keys' => '密钥',
            'providers' => '供应商',
            'logs' => '日志',
            'audit' => '操作审计',
            'metrics' => '指标',
            'profile' => '账号',
        ];
        $links = '';
        $icons = views_nav_icons();
        foreach ($nav as $view => $label) {
            $active = $view === 'dashboard' ? ' class="active"' : '';
            $ico = $icons[$view] ?? '';
            $links .= '<a data-view="' . $view . '"' . $active . '><span class="ico">' . $ico . '</span><span class="lbl">' . $label . '</span></a>';
        }

        // heredoc 仅插值变量，不允许函数调用；先求值后经 {$var} 注入
        $css = views_css();
        $loginOverlay = views_login_overlay();
        $mustChangeOverlay = views_must_change_overlay();
        $appJs = views_app_js();

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 中转站 · 管理后台</title>
<style>{$css}</style>
</head>
<body>
{$loginOverlay}
{$mustChangeOverlay}

<div class="app hidden" id="appShell">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><span class="dot"></span><span class="label">API 中转站</span></div>
    <nav>{$links}</nav>
    <div class="foot">v1.0 · 内联 SPA</div>
  </aside>
  <div class="sidebar-mask" id="sidebarMask"></div>
  <div class="main">
    <div class="topbar">
      <button type="button" class="nav-toggle" id="navToggle" aria-label="菜单" title="菜单">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <span class="title" id="viewTitle">仪表盘</span>
      <span class="spacer"></span>
      <span class="user">你好，<span id="userName"></span></span>
      <button id="logoutBtn">退出</button>
    </div>
    <div class="modelbar" id="modelBar"></div>
    <div class="content" id="content"></div>
  </div>
</div>

<div class="toast" id="toast"></div>
<div class="modal-mask" id="modalMask"><div class="modal" id="modalBox"></div></div>

<script>{$appJs}</script>
</body>
</html>
HTML;
    }
}
