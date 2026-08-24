<?php
/**
 * model_map 管理：增 / 改 / 删。无 namespace；类名=文件名。
 */
class AdminModelMapMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('模型映射', $this->dispatch($req), 'models');
    }

    public function dispatch(AppRequest $req): string
    {
        if ($req->method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'save_model') {
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    'alias'             => trim((string) ($_POST['alias'] ?? '')),
                    'provider_id'       => (int) ($_POST['provider_id'] ?? 0),
                    'upstream_model'    => trim((string) ($_POST['upstream_model'] ?? '')),
                    'price_input'       => (float) ($_POST['price_input'] ?? 0),
                    'price_output'      => (float) ($_POST['price_output'] ?? 0),
                    'price_per_request' => (float) ($_POST['price_per_request'] ?? 0),
                    'cacheable'         => isset($_POST['cacheable']) ? 1 : 0,
                    'status'            => (int) ($_POST['status'] ?? 1),
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
            }
        }
        return $this->body();
    }

    public function body(): string
    {
        $db = db();
        $editing = null;
        if (isset($_GET['edit'])) {
            $editing = db_fetch($db, "SELECT * FROM model_map WHERE id = ?", [(int) $_GET['edit']]);
        }
        $rows = db_fetchall($db, "SELECT m.*, p.name AS provider_name FROM model_map m LEFT JOIN providers p ON p.id = m.provider_id ORDER BY m.id DESC");
        $providers = db_fetchall($db, "SELECT id, name FROM providers ORDER BY id");

        $popts = '';
        foreach ($providers as $p) {
            $sel = $editing && $editing['provider_id'] == $p['id'] ? ' selected' : '';
            $popts .= '<option value="' . (int) $p['id'] . '"' . $sel . '>' . htmlspecialchars($p['name']) . '</option>';
        }
        $e = $editing ?? [
            'id' => 0, 'alias' => '', 'provider_id' => 0, 'upstream_model' => '',
            'price_input' => 0, 'price_output' => 0, 'price_per_request' => 0,
            'cacheable' => 0, 'status' => 1,
        ];
        $chkCache = !empty($e['cacheable']) ? ' checked' : '';
        $body = '<h1>模型映射</h1>';
        $body .= '<div class="card"><form method="post" action="" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;align-items:end">
            <input type="hidden" name="action" value="save_model">
            <input type="hidden" name="id" value="' . (int) $e['id'] . '">
            <div><label style="font-size:12px">别名 (alias)</label><br><input type="text" name="alias" value="' . htmlspecialchars($e['alias']) . '" required></div>
            <div><label style="font-size:12px">供应商</label><br><select name="provider_id">' . $popts . '</select></div>
            <div><label style="font-size:12px">上游模型</label><br><input type="text" name="upstream_model" value="' . htmlspecialchars($e['upstream_model']) . '"></div>
            <div><label style="font-size:12px">输入价 /1k</label><br><input type="number" step="0.0001" name="price_input" value="' . htmlspecialchars($e['price_input']) . '"></div>
            <div><label style="font-size:12px">输出价 /1k</label><br><input type="number" step="0.0001" name="price_output" value="' . htmlspecialchars($e['price_output']) . '"></div>
            <div><label style="font-size:12px">单价 /请求</label><br><input type="number" step="0.0001" name="price_per_request" value="' . htmlspecialchars($e['price_per_request']) . '"></div>
            <div><label style="font-size:12px">状态</label><br><select name="status"><option value="1"' . ($e['status'] == 1 ? ' selected' : '') . '>启用</option><option value="0"' . ($e['status'] == 0 ? ' selected' : '') . '>停用</option></select></div>
            <div><label style="font-size:12px">可缓存</label><br><input type="checkbox" name="cacheable"' . $chkCache . '></div>
            <div><button type="submit">' . ($e['id'] > 0 ? '更新' : '新增') . ' 模型</button></div>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>别名</th><th>供应商</th><th>上游</th><th>输入</th><th>输出</th><th>/请求</th><th>缓存</th><th>状态</th><th>操作</th></tr>';
        foreach ($rows as $m) {
            $status = $m['status'] == 1 ? '<span class="ok">启用</span>' : '<span class="error">停用</span>';
            $cache = $m['cacheable'] == 1 ? '是' : '否';
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
                    <a class="btn ghost" href="?edit=' . (int) $m['id'] . '">编辑</a>
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
