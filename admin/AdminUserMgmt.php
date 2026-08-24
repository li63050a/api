<?php
/**
 * 用户管理：列表 / 新增 / 启停。无 namespace；类名=文件名。
 */
class AdminUserMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('用户', $this->dispatch($req), 'users');
    }

    public function dispatch(AppRequest $req): string
    {
        if ($req->method === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($action === 'add_user') {
                $username = trim((string) ($_POST['username'] ?? ''));
                if ($username !== '') {
                    try {
                        db_insert(db(), 'users', [
                            'username'   => $username,
                            'status'     => 1,
                            'balance'    => 0,
                            'created_at' => time(),
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
            } elseif ($action === 'toggle_user') {
                $id = (int) ($_POST['id'] ?? 0);
                $row = db_fetch(db(), "SELECT status FROM users WHERE id = ?", [$id]);
                if ($row !== null) {
                    $new = $row['status'] == 1 ? 0 : 1;
                    db_update(db(), 'users', ['status' => $new], ['id' => $id]);
                }
            }
        }
        return $this->body();
    }

    public function body(): string
    {
        $db = db();
        $users = db_fetchall($db, "SELECT * FROM users ORDER BY id DESC");

        $body = '<h1>用户</h1>';
        $body .= '<div class="card"><form method="post" action="" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="action" value="add_user">
            <div><label style="font-size:12px;color:#334155">新用户名</label><br>
            <input type="text" name="username" required></div>
            <button type="submit">新增用户</button>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>用户名</th><th>状态</th><th>余额</th><th>创建时间</th><th>操作</th></tr>';
        foreach ($users as $u) {
            $status = $u['status'] == 1
                ? '<span class="ok">启用</span>'
                : '<span class="error">停用</span>';
            $body .= '<tr>'
                . '<td>' . (int) $u['id'] . '</td>'
                . '<td>' . htmlspecialchars($u['username']) . '</td>'
                . '<td>' . $status . '</td>'
                . '<td>' . htmlspecialchars($u['balance']) . '</td>'
                . '<td class="muted">' . date('Y-m-d', (int) $u['created_at']) . '</td>'
                . '<td><form class="inline" method="post" action="">
                        <input type="hidden" name="action" value="toggle_user">
                        <input type="hidden" name="id" value="' . (int) $u['id'] . '">
                        <button class="ghost">' . ($u['status'] == 1 ? '停用' : '启用') . '</button>
                    </form></td>'
                . '</tr>';
        }
        $body .= '</table>';

        return $body;
    }
}
