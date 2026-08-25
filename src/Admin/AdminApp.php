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
            'models' => '模型管理',
            'providers' => '供应商',
            'logs' => '日志',
            'billing' => '账单',
            'audit' => '操作审计',
            'metrics' => '指标',
            'profile' => '账号',
            'speedtest' => '测速',
        ];
        $links = '';
        foreach ($nav as $view => $label) {
            $active = $view === 'dashboard' ? ' class="active"' : '';
            $links .= '<a data-view="' . $view . '"' . $active . '><span>' . $label . '</span></a>';
        }

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 中转站 · 管理后台</title>
<style>{views_css()}</style>
</head>
<body>
{views_login_overlay()}
{views_must_change_overlay()}

<div class="app hidden" id="appShell">
  <aside class="sidebar">
    <div class="brand"><span class="dot"></span>API 中转站</div>
    <nav>{$links}</nav>
    <div class="foot">v1.0 · 内联 SPA</div>
  </aside>
  <div class="main">
    <div class="topbar">
      <span class="title" id="viewTitle">仪表盘</span>
      <span class="spacer"></span>
      <span class="user">你好，<span id="userName"></span></span>
      <button id="logoutBtn">退出</button>
    </div>
    <div class="content" id="content"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>{views_app_js()}</script>
</body>
</html>
HTML;
    }
}
