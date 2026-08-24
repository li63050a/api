<?php
/**
 * 供应商与上游密钥管理（SPA 片段）。
 *  - 顶部：新增供应商表单 + 「同步全部模型」按钮。
 *  - 每个供应商一块：编辑 / 删除 / 「同步模型」(按 provider_id)，并内嵌该供应商的上游密钥列表与新增密钥表单。
 * 上游 Key 用 crypto_encrypt 存储、展示脱敏。业务逻辑（db_* 与 crypto_*）保持与现有一致，仅改呈现与交互。
 * 所有表单由 SPA 的 JS 拦截为 AJAX；「同步模型」按钮由 SPA 的 .js-sync 委托处理。
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
                    'name'         => trim((string) ($_POST['name'] ?? '')),
                    'base_url'     => trim((string) ($_POST['base_url'] ?? '')),
                    'auth_scheme'  => trim((string) ($_POST['auth_scheme'] ?? 'bearer')),
                    'auth_header'  => trim((string) ($_POST['auth_header'] ?? 'Authorization')),
                    'status'       => (int) ($_POST['status'] ?? 1),
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
        $providers = db_fetchall($db, "SELECT * FROM providers ORDER BY id");
        $allKeys = db_fetchall($db, "
            SELECT k.*, p.name AS provider_name FROM upstream_keys k
            LEFT JOIN providers p ON p.id = k.provider_id
            ORDER BY k.id DESC
        ");
        $keysByProvider = [];
        foreach ($allKeys as $k) {
            $keysByProvider[(int) $k['provider_id']][] = $k;
        }

        $body = '<h1>供应商</h1>';

        // 顶部：新增供应商 + 同步全部
        $body .= '<div class="card"><form id="providerForm" method="post" action="">
            <input type="hidden" name="action" value="save_provider">
            <input type="hidden" name="id" value="0">
            <div class="grid">
                <div><label>名称</label><input type="text" name="name" required></div>
                <div><label>Base URL</label><input type="text" name="base_url" required></div>
                <div><label>Auth Scheme</label><input type="text" name="auth_scheme" value="bearer"></div>
                <div><label>Auth Header</label><input type="text" name="auth_header" value="Authorization"></div>
                <div><label>状态</label><select name="status"><option value="1">启用</option><option value="0">停用</option></select></div>
                <div><label>&nbsp;</label><button type="submit">新增 供应商</button></div>
            </div>
        </form>
        <div class="toolbar" style="margin-top:14px">
            <button class="js-sync-all success" type="button">同步全部模型</button>
            <span class="muted">从各供应商「列出模型」接口拉取并写入 model_map（默认停用，source=auto）。</span>
        </div>
        <div id="provResult"></div></div>';

        // 每个供应商一块
        if (count($providers) === 0) {
            $body .= '<p class="muted">尚未配置供应商。</p>';
        }
        foreach ($providers as $p) {
            $pid = (int) $p['id'];
            $status = $p['status'] == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>';
            $edit = base64_encode(json_encode($p, JSON_UNESCAPED_UNICODE));

            $body .= '<div class="prov-block">';
            $body .= '<div class="prov-head">
                <span class="pname">' . htmlspecialchars($p['name']) . '</span>
                <span class="purl">' . htmlspecialchars($p['base_url']) . '</span>
                ' . $status . '
                <button type="button" class="btn ghost" data-edit="' . $edit . '" onclick="editRow(this,\'providerForm\')">编辑</button>
                <form class="inline" method="post" action="">
                    <input type="hidden" name="action" value="delete_provider">
                    <input type="hidden" name="id" value="' . $pid . '">
                    <button class="danger" onclick="return confirm(\'确认删除该供应商?\')">删除</button>
                </form>
                <button type="button" class="js-sync success" data-pid="' . $pid . '">同步模型</button>
            </div>';

            $body .= '<div class="prov-body">';
            $body .= '<p class="sub-t">上游密钥（加密存储）</p>';

            // 该供应商的上游密钥列表
            $keys = $keysByProvider[$pid] ?? [];
            if (count($keys) > 0) {
                $body .= '<table><tr><th>ID</th><th>密钥（脱敏）</th><th>权重</th><th>状态</th><th>最近错误</th><th>操作</th></tr>';
                foreach ($keys as $k) {
                    $kstatus = $k['status'] == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>';
                    $masked = $this->mask((string) $k['key_value']);
                    $err = !empty($k['last_error_at']) ? date('Y-m-d H:i', (int) $k['last_error_at']) : '-';
                    $body .= '<tr>'
                        . '<td>' . (int) $k['id'] . '</td>'
                        . '<td><code>' . htmlspecialchars($masked) . '</code></td>'
                        . '<td>' . (int) $k['weight'] . '</td>'
                        . '<td>' . $kstatus . '</td>'
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
            } else {
                $body .= '<p class="muted" style="margin:0 0 12px">该供应商暂无上游密钥。</p>';
            }

            // 新增上游密钥表单（预设 provider_id）
            $body .= '<form method="post" action="" style="margin-top:10px">
                <input type="hidden" name="action" value="save_upstream_key">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="provider_id" value="' . $pid . '">
                <div class="row">
                    <div><label>密钥值</label><input type="text" name="key_value" required></div>
                    <div><label>权重</label><input type="number" name="weight" value="1" style="width:90px"></div>
                    <div><label>状态</label><select name="status"><option value="1">启用</option><option value="0">停用</option></select></div>
                    <div><label>&nbsp;</label><button type="submit">新增密钥</button></div>
                </div>
            </form>';

            $body .= '</div></div>';
        }

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
