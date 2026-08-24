<?php
/**
 * 后台 AJAX / POST 处理入口（独立可访问：admin/actions.php）。
 * 接收 POST，action 字段区分操作：
 *  login / logout / dashboard / users / keys / models / providers
 *  add_user / toggle_user / add_key / toggle_key
 *  save_model / delete_model
 *  save_provider / delete_provider / save_upstream_key / toggle_upstream_key / delete_upstream_key
 *  sync_models / speed_test
 * 调用对应 Svc* / Admin* / db_* 逻辑，返回 JSON 或 HTML 片段。
 * 所有 mutation 均返回更新后的 HTML 片段，供 SPA 整段替换。
 */
require_once __DIR__ . '/../core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));
$auth = new AdminAuth();

if (!in_array($action, ['login', 'logout'], true) && $auth->current() === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

switch ($action) {
    case 'login':
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $ok = $auth->login($u, $p);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => '用户名或密码错误']);
        exit;

    case 'logout':
        $auth->logout();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;

    case 'dashboard':
        echo (new AdminDashboard())->fragment();
        exit;

    case 'users':
    case 'add_user':
    case 'toggle_user':
        echo (new AdminUserMgmt())->dispatch(new AppRequest());
        exit;

    case 'keys':
    case 'add_key':
    case 'toggle_key':
        echo (new AdminKeyMgmt())->dispatch(new AppRequest());
        exit;

    case 'models':
    case 'save_model':
    case 'delete_model':
    case 'toggle_model':
        echo (new AdminModelMapMgmt())->dispatch(new AppRequest());
        exit;

    case 'providers':
    case 'save_provider':
    case 'delete_provider':
    case 'save_upstream_key':
    case 'toggle_upstream_key':
    case 'delete_upstream_key':
        echo (new AdminProviderMgmt())->dispatch(new AppRequest());
        exit;

    case 'sync_models':
        echo render_sync((int) ($_POST['provider_id'] ?? 0));
        exit;

    case 'speed_test':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode((new SvcSpeedTest())->testAll());
        exit;

    default:
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unknown action: ' . $action]);
        exit;
}

/**
 * 模型同步结果 HTML 片段。provider_id>0 时只同步该供应商，否则同步全部。
 * 复用 SvcModelSync::syncProvider()。
 */
function render_sync(int $providerId): string
{
    $svc = new SvcModelSync();
    if ($providerId > 0) {
        $providers = [db_fetch(db(), "SELECT * FROM providers WHERE id = ?", [$providerId])];
    } else {
        $providers = db_fetchall(db(), "SELECT * FROM providers ORDER BY id");
    }

    $rows = '';
    $total = 0;
    foreach ($providers as $p) {
        if ($p === null) {
            continue;
        }
        $res = $svc->syncProvider((int) $p['id']);
        if (!empty($res['ok'])) {
            $cnt = (int) ($res['count'] ?? count($res['models'] ?? []));
            $total += $cnt;
            $note = $res['note'] ?? '';
            $detail = $cnt > 0 ? "同步 {$cnt} 个模型" : ($note !== '' ? $note : '无新增（已存在）');
            $rows .= '<tr><td>' . htmlspecialchars($p['name']) . '</td><td class="ok">成功</td><td>' . htmlspecialchars($detail) . '</td></tr>';
        } else {
            $rows .= '<tr><td>' . htmlspecialchars($p['name']) . '</td><td class="error">失败</td><td>' . htmlspecialchars($res['error'] ?? 'unknown') . '</td></tr>';
        }
    }

    return '<h3>模型同步结果</h3>'
        . '<p class="hint">共处理 ' . count(array_filter($providers)) . ' 个供应商，新增/更新 ' . $total . ' 个模型。</p>'
        . '<table><tr><th>供应商</th><th>结果</th><th>说明</th></tr>' . $rows . '</table>';
}
