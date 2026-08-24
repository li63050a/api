<?php
/**
 * 供应商与上游密钥管理。上游 Key 用 crypto_encrypt 存储、展示脱敏。
 * 无 namespace；类名=文件名。
 */
class AdminProviderMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('供应商', $this->dispatch($req), 'providers');
    }

    public function dispatch(AppRequest $req): string
    {
        if ($req->method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'save_provider') {
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    'name'        => trim((string) ($_POST['name'] ?? '')),
                    'base_url'    => trim((string) ($_POST['base_url'] ?? '')),
                    'auth_scheme' => trim((string) ($_POST['auth_scheme'] ?? 'bearer')),
                    'auth_header' => trim((string) ($_POST['auth_header'] ?? 'Authorization')),
                    'status'      => (int) ($_POST['status'] ?? 1),
                ];
                if ($data['name'] !== '' && $data['base_url'] !== '') {
                    if ($id > 0) {
                        db_update(db(), 'providers', $data, ['id' => $id]);
                    } else {
                        db_insert(db(), 'providers', $data);
                    }
                }
            } elseif ($action === 'delete_provider') {
                $id = (int) ($_POST['id'] ?? 0);
                db()->prepare("DELETE FROM providers WHERE id = ?")->execute([$id]);
            } elseif ($action === 'save_upstream_key') {
                $id = (int) ($_POST['id'] ?? 0);
                $providerId = (int) ($_POST['provider_id'] ?? 0);
                $value = trim((string) ($_POST['key_value'] ?? ''));
                $data = [
                    'provider_id' => $providerId,
                    'status'      => (int) ($_POST['status'] ?? 1),
                    'weight'      => (int) ($_POST['weight'] ?? 1),
                ];
                if ($providerId > 0 && $value !== '') {
                    $data['key_value'] = crypto_encrypt($value);
                    if ($id > 0) {
                        db_update(db(), 'upstream_keys', $data, ['id' => $id]);
                    } else {
                        db_insert(db(), 'upstream_keys', $data);
                    }
                }
            } elseif ($action === 'toggle_upstream_key') {
                $id = (int) ($_POST['id'] ?? 0);
                $row = db_fetch(db(), "SELECT status FROM upstream_keys WHERE id = ?", [$id]);
                if ($row !== null) {
                    $new = $row['status'] == 1 ? 0 : 1;
                    db_update(db(), 'upstream_keys', ['status' => $new], ['id' => $id]);
                }
            } elseif ($action === 'delete_upstream_key') {
                $id = (int) ($_POST['id'] ?? 0);
                db()->prepare("DELETE FROM upstream_keys WHERE id = ?")->execute([$id]);
            }
        }
        return $this->body();
    }

    public function body(): string
    {
        $db = db();
        $editProvider = null;
        if (isset($_GET['edit_provider'])) {
            $editProvider = db_fetch($db, "SELECT * FROM providers WHERE id = ?", [(int) $_GET['edit_provider']]);
        }
        $providers = db_fetchall($db, "SELECT * FROM providers ORDER BY id");
        $keys = db_fetchall($db, "
            SELECT k.*, p.name AS provider_name FROM upstream_keys k
            LEFT JOIN providers p ON p.id = k.provider_id
            ORDER BY k.id DESC
        ");

        $popts = '';
        foreach ($providers as $p) {
            $popts .= '<option value="' . (int) $p['id'] . '">' . htmlspecialchars($p['name']) . '</option>';
        }
        $ep = $editProvider ?? ['id' => 0, 'name' => '', 'base_url' => '', 'auth_scheme' => 'bearer', 'auth_header' => 'Authorization', 'status' => 1];

        $body = '<h1>供应商与上游密钥</h1>';
        $body .= '<div class="card"><h3>供应商</h3><form method="post" action="" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;align-items:end">
            <input type="hidden" name="action" value="save_provider">
            <input type="hidden" name="id" value="' . (int) $ep['id'] . '">
            <div><label style="font-size:12px">名称</label><br><input type="text" name="name" value="' . htmlspecialchars($ep['name']) . '" required></div>
            <div><label style="font-size:12px">Base URL</label><br><input type="text" name="base_url" value="' . htmlspecialchars($ep['base_url']) . '" required></div>
            <div><label style="font-size:12px">Auth Scheme</label><br><input type="text" name="auth_scheme" value="' . htmlspecialchars($ep['auth_scheme']) . '"></div>
            <div><label style="font-size:12px">Auth Header</label><br><input type="text" name="auth_header" value="' . htmlspecialchars($ep['auth_header']) . '"></div>
            <div><label style="font-size:12px">状态</label><br><select name="status"><option value="1"' . ($ep['status'] == 1 ? ' selected' : '') . '>启用</option><option value="0"' . ($ep['status'] == 0 ? ' selected' : '') . '>停用</option></select></div>
            <div><button type="submit">' . ($ep['id'] > 0 ? '更新' : '新增') . ' 供应商</button></div>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>名称</th><th>Base URL</th><th>Scheme</th><th>状态</th><th>操作</th></tr>';
        foreach ($providers as $p) {
            $status = $p['status'] == 1 ? '<span class="ok">启用</span>' : '<span class="error">停用</span>';
            $body .= '<tr>'
                . '<td>' . (int) $p['id'] . '</td>'
                . '<td>' . htmlspecialchars($p['name']) . '</td>'
                . '<td>' . htmlspecialchars($p['base_url']) . '</td>'
                . '<td>' . htmlspecialchars($p['auth_scheme']) . '</td>'
                . '<td>' . $status . '</td>'
                . '<td>
                    <a class="btn ghost" href="?edit_provider=' . (int) $p['id'] . '">编辑</a>
                    <form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="delete_provider">
                        <input type="hidden" name="id" value="' . (int) $p['id'] . '">
                        <button class="danger" onclick="return confirm(\'确认删除?\')">删</button>
                    </form>
                </td>'
                . '</tr>';
        }
        $body .= '</table>';

        $body .= '<div class="card"><h3>上游密钥（加密存储）</h3><form method="post" action="" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end">
            <input type="hidden" name="action" value="save_upstream_key">
            <input type="hidden" name="id" value="0">
            <div><label style="font-size:12px">供应商</label><br><select name="provider_id">' . $popts . '</select></div>
            <div><label style="font-size:12px">密钥值</label><br><input type="text" name="key_value" required></div>
            <div><label style="font-size:12px">权重</label><br><input type="number" name="weight" value="1"></div>
            <div><label style="font-size:12px">状态</label><br><select name="status"><option value="1">启用</option><option value="0">停用</option></select></div>
            <div><button type="submit">新增密钥</button></div>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>供应商</th><th>密钥（脱敏）</th><th>权重</th><th>状态</th><th>最近错误</th><th>操作</th></tr>';
        foreach ($keys as $k) {
            $status = $k['status'] == 1 ? '<span class="ok">启用</span>' : '<span class="error">停用</span>';
            $masked = $this->mask((string) $k['key_value']);
            $err = !empty($k['last_error_at']) ? date('Y-m-d H:i', (int) $k['last_error_at']) : '-';
            $body .= '<tr>'
                . '<td>' . (int) $k['id'] . '</td>'
                . '<td>' . htmlspecialchars($k['provider_name'] ?? '?') . '</td>'
                . '<td><code>' . htmlspecialchars($masked) . '</code></td>'
                . '<td>' . (int) $k['weight'] . '</td>'
                . '<td>' . $status . '</td>'
                . '<td class="muted">' . $err . '</td>'
                . '<td>
                    <form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="toggle_upstream_key">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <button class="ghost">' . ($k['status'] == 1 ? '停用' : '启用') . '</button>
                    </form>
                    <form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="delete_upstream_key">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <button class="danger" onclick="return confirm(\'确认删除?\')">删</button>
                    </form>
                </td>'
                . '</tr>';
        }
        $body .= '</table>';

        return $body;
    }

    private function mask(string $stored): string
    {
        try {
            $plain = crypto_decrypt($stored);
        } catch (\Throwable $e) {
            $plain = $stored;
        }
        if ($plain === '' || $plain === false) {
            return '****';
        }
        $len = strlen($plain);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($plain, 0, 4) . '…' . substr($plain, -4);
    }
}
