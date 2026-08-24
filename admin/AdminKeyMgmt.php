<?php
/**
 * API 密钥管理：列表 / 为用户生成 Key（明文仅展示一次）/ 启停。
 * 生成使用 SvcAuth::generateKey() / SvcAuth::hashKey()，key_prefix 存前 8 位。
 * 无 namespace；类名=文件名。
 */
class AdminKeyMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('密钥', $this->dispatch($req), 'keys');
    }

    public function dispatch(AppRequest $req): string
    {
        $generatedRaw = null;
        $message = '';

        if ($req->method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'add_key') {
                $userId = (int) ($_POST['user_id'] ?? 0);
                $user = db_fetch(db(), "SELECT id FROM users WHERE id = ?", [$userId]);
                if ($user !== null) {
                    $raw = SvcAuth::generateKey();
                    $hash = SvcAuth::hashKey($raw);
                    $prefix = substr($raw, 0, 8);
                    db_insert(db(), 'api_keys', [
                        'user_id'    => $userId,
                        'key_hash'   => $hash,
                        'key_prefix' => $prefix,
                        'status'     => 1,
                        'created_at' => time(),
                        'expires_at' => 0,
                    ]);
                    $generatedRaw = $raw;
                    $message = 'API Key 已生成（仅显示一次）：';
                } else {
                    $message = '用户不存在。';
                }
            } elseif ($action === 'toggle_key') {
                $id = (int) ($_POST['id'] ?? 0);
                $row = db_fetch(db(), "SELECT status FROM api_keys WHERE id = ?", [$id]);
                if ($row !== null) {
                    $new = $row['status'] == 1 ? 0 : 1;
                    db_update(db(), 'api_keys', ['status' => $new], ['id' => $id]);
                }
            }
        }

        return $this->body($generatedRaw, $message);
    }

    public function body(?string $generatedRaw = null, string $message = ''): string
    {
        $db = db();
        $keys = db_fetchall($db, "
            SELECT k.*, u.username FROM api_keys k
            LEFT JOIN users u ON u.id = k.user_id
            ORDER BY k.id DESC
        ");
        $users = db_fetchall($db, "SELECT id, username FROM users ORDER BY id DESC");

        $body = '<h1>API 密钥</h1>';

        if ($generatedRaw !== null) {
            $body .= '<div class="card"><p class="ok">' . htmlspecialchars($message) . '</p>'
                . '<div class="key-box">' . htmlspecialchars($generatedRaw) . '</div>'
                . '<p class="muted" style="font-size:12px">请立即复制保存，之后无法再次查看。</p></div>';
        } elseif ($message !== '') {
            $body .= '<div class="card"><p class="error">' . htmlspecialchars($message) . '</p></div>';
        }

        $opts = '';
        foreach ($users as $u) {
            $opts .= '<option value="' . (int) $u['id'] . '">' . htmlspecialchars($u['username']) . '</option>';
        }
        $body .= '<div class="card"><form method="post" action="" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="action" value="add_key">
            <div><label style="font-size:12px;color:#334155">用户</label><br><select name="user_id">' . $opts . '</select></div>
            <button type="submit">生成密钥</button>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>前缀</th><th>用户</th><th>状态</th><th>创建时间</th><th>操作</th></tr>';
        foreach ($keys as $k) {
            $status = $k['status'] == 1 ? '<span class="ok">启用</span>' : '<span class="error">停用</span>';
            $body .= '<tr>'
                . '<td>' . (int) $k['id'] . '</td>'
                . '<td><code>' . htmlspecialchars($k['key_prefix'] ?? '') . '…</code></td>'
                . '<td>' . htmlspecialchars($k['username'] ?? '(已删除)') . '</td>'
                . '<td>' . $status . '</td>'
                . '<td class="muted">' . date('Y-m-d', (int) $k['created_at']) . '</td>'
                . '<td><form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="toggle_key">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <button class="ghost">' . ($k['status'] == 1 ? '停用' : '启用') . '</button>
                    </form></td>'
                . '</tr>';
        }
        $body .= '</table>';

        return $body;
    }
}
