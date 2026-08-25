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

if (!in_array($action, ['login', 'logout', 'setup'], true) && $auth->current() === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// 只读操作无需 CSRF 校验；其余变更操作要求同源 X-Requested-With 头
$readActions = ['login', 'logout', 'setup', 'dashboard', 'logs', 'billing', 'audit', 'profile', 'metrics', 'export_logs', 'export_billing', 'users', 'keys', 'models', 'providers', 'speed_test'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $readActions, true)) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'invalid request']);
        exit;
    }
    // 后台操作审计
    try {
        $detail = $_POST;
        unset($detail['password']);
        db_insert(db(), 'admin_audit', [
            'admin_id'    => (int) ($_SESSION['admin_id'] ?? 0),
            'action'      => $action,
            'detail'      => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at'  => time(),
        ]);
    } catch (\Throwable $e) {
    }
}

switch ($action) {
    case 'login':
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        $ok = $auth->login($u, $p);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => '用户名或密码错误']);
        exit;

    case 'setup':
        if ($auth->hasAnyAdmin()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '管理员已存在']);
            exit;
        }
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || strlen($p) < 8) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '用户名不能为空，密码至少 8 位']);
            exit;
        }
        try {
            $adb = admin_db();
            db_insert($adb, 'admin_users', [
                'username'      => $u,
                'password_hash' => password_hash($p, PASSWORD_DEFAULT),
                'role'          => 'admin',
                'created_at'    => time(),
            ]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $adb->lastInsertId();
            $_SESSION['admin_last'] = time();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '创建失败：' . $e->getMessage()]);
        }
        exit;

    case 'logout':
        $auth->logout();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;

    case 'dashboard':
        echo (new AdminDashboard())->fragment();
        exit;
    case 'logs':
    case 'logs_cleanup':
        echo (new AdminLogMgmt())->fragment();
        exit;
    case 'billing':
        echo (new AdminBillingMgmt())->fragment();
        exit;
    case 'audit':
        echo (new AdminAuditMgmt())->fragment();
        exit;
    case 'profile':
        echo (new AdminProfileMgmt())->fragment();
        exit;
    case 'change_password':
        $old = (string) ($_POST['old_pwd'] ?? '');
        $new = (string) ($_POST['new_pwd'] ?? '');
        $adm = $auth->current();
        if ($adm === null || $new === '' || !password_verify($old, (string) $adm['password_hash'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '当前密码不正确']);
            exit;
        }
        db_update(admin_db(), 'admin_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $adm['id']]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    case 'export_logs':
        export_logs_csv((int) ($_GET['days'] ?? ($_POST['days'] ?? 7)));
        exit;
    case 'export_billing':
        export_billing_csv((int) ($_GET['days'] ?? ($_POST['days'] ?? 30)));
        exit;
    case 'metrics':
        header('Content-Type: text/plain; charset=utf-8');
        echo build_metrics();
        exit;

    case 'users':
    case 'add_user':
    case 'toggle_user':
    case 'add_admin':
    case 'reset_admin':
    case 'delete_admin':
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

/**
 * 导出请求日志为 CSV。
 */
function export_logs_csv(int $days): void
{
    $cut = $days > 0 ? time() - $days * 86400 : 0;
    $w = $days > 0 ? 'WHERE l.created_at >= ' . $cut : '';
    $rows = db_fetchall(db(), "
        SELECT l.*, u.username FROM request_log l
        LEFT JOIN users u ON u.id = l.user_id
        {$w} ORDER BY l.id DESC LIMIT 50000
    ");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="request_log_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['时间', '用户', '模型', '上游', 'IP', '路径', '状态', '延迟(ms)', '输入', '输出', '错误', 'trace_id']);
    foreach ($rows as $r) {
        fputcsv($out, [
            date('Y-m-d H:i:s', (int) $r['created_at']),
            $r['username'] ?: '匿名',
            $r['model_alias'] ?: '',
            $r['upstream_provider'] ?: '',
            $r['ip'] ?: '',
            $r['path'] ?: '',
            $r['status_code'],
            $r['latency_ms'],
            $r['input_tokens'],
            $r['output_tokens'],
            $r['error'] ?: '',
            $r['trace_id'] ?: '',
        ]);
    }
    fclose($out);
    exit;
}

/**
 * 导出账单为 CSV。
 */
function export_billing_csv(int $days): void
{
    $cut = $days > 0 ? time() - $days * 86400 : 0;
    $w = $days > 0 ? 'WHERE b.created_at >= ' . $cut : '';
    $rows = db_fetchall(db(), "
        SELECT b.*, u.username, p.name AS prov FROM billing b
        LEFT JOIN users u ON u.id = b.user_id
        LEFT JOIN providers p ON p.id = b.provider_id
        {$w} ORDER BY b.id DESC LIMIT 50000
    ");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="billing_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['时间', '用户', '供应商', '模型', '请求数', '输入', '输出', '金额']);
    foreach ($rows as $r) {
        fputcsv($out, [
            date('Y-m-d H:i:s', (int) $r['created_at']),
            $r['username'] ?: '',
            $r['prov'] ?: '',
            $r['model_alias'] ?: '',
            $r['request_count'],
            $r['input_tokens'],
            $r['output_tokens'],
            $r['amount'],
        ]);
    }
    fclose($out);
    exit;
}

/**
 * Prometheus 风格指标文本（后台聚合，按需计算）。
 */
function build_metrics(): string
{
    $db = db();
    $day = time() - 86400;
    $req = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ?", [$day])['n'] ?? 0);
    $err = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ? AND status_code >= 400", [$day])['n'] ?? 0);
    $lat = (float) (db_fetch($db, "SELECT COALESCE(AVG(latency_ms),0) AS v FROM request_log WHERE created_at >= ?", [$day])['v'] ?? 0);
    $amt = (float) (db_fetch($db, "SELECT COALESCE(SUM(amount),0) AS v FROM billing WHERE created_at >= ?", [$day])['v'] ?? 0);
    $cooled = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM upstream_keys WHERE cooldown_until > ?", [time()])['n'] ?? 0);

    $lines = [];
    $lines[] = '# HELP api_requests_24h 近24小时请求数';
    $lines[] = '# TYPE api_requests_24h counter';
    $lines[] = 'api_requests_24h ' . $req;
    $lines[] = '# HELP api_errors_24h 近24小时错误数';
    $lines[] = '# TYPE api_errors_24h counter';
    $lines[] = 'api_errors_24h ' . $err;
    $lines[] = '# HELP api_latency_avg_ms 近24小时平均延迟(ms)';
    $lines[] = '# TYPE api_latency_avg_ms gauge';
    $lines[] = 'api_latency_avg_ms ' . round($lat, 2);
    $lines[] = '# HELP billing_amount_24h 近24小时消费总额';
    $lines[] = '# TYPE billing_amount_24h counter';
    $lines[] = 'billing_amount_24h ' . round($amt, 4);
    $lines[] = '# HELP upstream_keys_cooled 当前处于冷却的 upstream key 数';
    $lines[] = '# TYPE upstream_keys_cooled gauge';
    $lines[] = 'upstream_keys_cooled ' . $cooled;

    $byProv = db_fetchall($db, "
        SELECT p.name AS prov, COUNT(*) AS n FROM request_log l
        LEFT JOIN model_map m ON m.alias = l.model_alias
        LEFT JOIN providers p ON p.id = m.provider_id
        WHERE l.created_at >= ? GROUP BY p.name
    ", [$day]);
    $lines[] = '# HELP api_requests_by_provider 各供应商请求数';
    $lines[] = '# TYPE api_requests_by_provider counter';
    foreach ($byProv as $r) {
        $lines[] = 'api_requests_by_provider{provider="' . ($r['prov'] ?: 'unknown') . '"} ' . $r['n'];
    }
    return implode("\n", $lines) . "\n";
}

