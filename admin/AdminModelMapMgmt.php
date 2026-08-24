<?php
/**
 * model_map 管理：增 / 改 / 删。无 namespace；类名=文件名。
 * 片段由 SPA 通过 fetch 加载；所有表单被 SPA 的 JS 拦截为 AJAX。
 * 「编辑」用 data-edit（base64 行数据）回填顶部表单，不再依赖 GET 参数（SPA 下 GET 链接会整页刷新）。
 */
class AdminModelMapMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('模型管理', $this->dispatch($req), 'models');
    }

    public function dispatch(AppRequest $req): string
    {
        if ($req->method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'save_model') {
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    'alias'              => trim((string) ($_POST['alias'] ?? '')),
                    'provider_id'        => (int) ($_POST['provider_id'] ?? 0),
                    'upstream_model'     => trim((string) ($_POST['upstream_model'] ?? '')),
                    'price_input'        => (float) ($_POST['price_input'] ?? 0),
                    'price_output'       => (float) ($_POST['price_output'] ?? 0),
                    'price_per_request'  => (float) ($_POST['price_per_request'] ?? 0),
                    'cacheable'          => isset($_POST['cacheable']) ? 1 : 0,
                    'status'             => (int) ($_POST['status'] ?? 1),
                ];
                if ($data['alias'] !== '' && $data['provider_id'] > 0) {
                    if ($id > 0) {
                        db_update(db(), 'model_map', $data, ['id' => $id]);
                    } else {
                        db_insert(db(), 'model_map', $data);
                    }
                }
            } elseif ($action === 'delete_model') {
                $id = (int) ($_POST['id'] ?? 0);
                db()->prepare("DELETE FROM model_map WHERE id = ?")->execute([$id]);
            } elseif ($action === 'toggle_model') {
                $id = (int) ($_POST['id'] ?? 0);
                $row = db_fetch(db(), "SELECT status FROM model_map WHERE id = ?", [$id]);
                if ($row !== null) {
                    $new = $row['status'] == 1 ? 0 : 1;
                    db_update(db(), 'model_map', ['status' => $new], ['id' => $id]);
                }
            }
        }
        return $this->body();
    }

    public function body(): string
    {
        $db = db();
        $rows = db_fetchall($db, "SELECT m.*, p.name AS provider_name FROM model_map m LEFT JOIN providers p ON p.id = m.provider_id ORDER BY m.id DESC");
        $providers = db_fetchall($db, "SELECT id, name FROM providers ORDER BY id");

        $popts = '';
        foreach ($providers as $p) {
            $popts .= '<option value="' . (int) $p['id'] . '">' . htmlspecialchars($p['name']) . '</option>';
        }

        $body = '<h1>模型管理</h1>';
        $body .= '<div class="card"><form id="modelForm" method="post" action="">
            <input type="hidden" name="action" value="save_model">
            <input type="hidden" name="id" value="0">
            <div class="grid">
                <div><label>别名 (alias)</label><input type="text" name="alias" required></div>
                <div><label>供应商</label><select name="provider_id">' . $popts . '</select></div>
                <div><label>上游模型</label><input type="text" name="upstream_model"></div>
                <div><label>输入价 /1k</label><input type="number" step="0.0001" name="price_input" value="0"></div>
                <div><label>输出价 /1k</label><input type="number" step="0.0001" name="price_output" value="0"></div>
                <div><label>单价 /请求</label><input type="number" step="0.0001" name="price_per_request" value="0"></div>
                <div><label>状态</label><select name="status"><option value="1">启用</option><option value="0">停用</option></select></div>
                <div><label>可缓存</label><input type="checkbox" name="cacheable"></div>
                <div><label>&nbsp;</label><button type="submit">新增 模型</button></div>
            </div>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>别名</th><th>供应商</th><th>上游</th><th>输入</th><th>输出</th><th>/请求</th><th>缓存</th><th>状态</th><th>操作</th></tr>';
        foreach ($rows as $m) {
            $status = $m['status'] == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>';
            $cache = $m['cacheable'] == 1 ? '是' : '否';
            $edit = base64_encode(json_encode($m, JSON_UNESCAPED_UNICODE));
            $body .= '<tr>'
                . '<td>' . (int) $m['id'] . '</td>'
                . '<td>' . htmlspecialchars($m['alias']) . '</td>'
                . '<td>' . htmlspecialchars($m['provider_name'] ?? '?') . '</td>'
                . '<td>' . htmlspecialchars($m['upstream_model']) . '</td>'
                . '<td>' . htmlspecialchars($m['price_input']) . '</td>'
                . '<td>' . htmlspecialchars($m['price_output']) . '</td>'
                . '<td>' . htmlspecialchars($m['price_per_request']) . '</td>'
                . '<td>' . $cache . '</td>'
                . '<td>' . $status . '</td>'
                . '<td>
                    <button type="button" class="btn ghost" data-edit="' . $edit . '" onclick="editRow(this,\'modelForm\')">编辑</button>
                    <form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="delete_model">
                        <input type="hidden" name="id" value="' . (int) $m['id'] . '">
                        <button class="danger" onclick="return confirm(\'确认删除?\')">删</button>
                    </form>
                </td>'
                . '</tr>';
        }
        $body .= '</table>';

        return $body;
    }
}
