<?php
/**
 * 用户管理：终端用户列表 / 新增 / 启停；以及管理员账号管理（管理员存于专用 admin.db）。
 * 片段由 SPA 通过 fetch 加载；表单被 SPA 的 JS 拦截为 AJAX。
 */
class AdminUserMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('用户', $this->dispatch($req), 'users');
    }

    public function fragment(): string
    {
        return $this->dispatch(new AppRequest());
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
            } elseif ($action === 'add_admin') {
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                if ($username !== '' && strlen($password) >= 8) {
                    try {
                        db_insert(admin_db(), 'admin_users', [
                            'username'      => $username,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'role'          => 'admin',
                            'created_at'    => time(),
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
            } elseif ($action === 'reset_admin') {
                $id = (int) ($_POST['id'] ?? 0);
                $password = (string) ($_POST['password'] ?? '');
                if ($id > 0 && strlen($password) >= 8) {
                    try {
                        db_update(admin_db(), 'admin_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
                    } catch (\Throwable $e) {
                    }
                }
            } elseif ($action === 'delete_admin') {
                $id = (int) ($_POST['id'] ?? 0);
                try {
                    $all = db_fetchall(admin_db(), "SELECT id FROM admin_users");
                    if (count($all) > 1) {
                        admin_db()->exec('DELETE FROM admin_users WHERE id = ' . (int) $id);
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        return $this->body();
    }

    public function body(): string
    {
        return $this->adminBlock() . $this->userBlock();
    }

    private function adminBlock(): string
    {
        try {
            $admins = db_fetchall(admin_db(), "SELECT * FROM admin_users ORDER BY id DESC");
        } catch (\Throwable $e) {
            return '<div class="card"><h3>管理员账号</h3><p class="error">无法加载管理员：' . htmlspecialchars($e->getMessage()) . '</p></div>';
        }
        $onlyOne = count($admins) <= 1;
        $h = '<div class="card"><h3>管理员账号</h3>'
            . '<form method="post" action="" class="grid" style="margin-bottom:14px">'
            . '<input type="hidden" name="action" value="add_admin">'
            . '<div><label>新管理员用户名</label><input type="text" name="username" required></div>'
            . '<div><label>密码（≥8 位）</label><input type="password" name="password" required></div>'
            . '<div><label>&nbsp;</label><button type="submit">新增管理员</button></div>'
            . '</form>';
        $h .= '<table><tr><th>ID</th><th>用户名</th><th>创建时间</th><th>操作</th></tr>';
        foreach ($admins as $a) {
            $h .= '<tr>'
                . '<td>' . (int) $a['id'] . '</td>'
                . '<td>' . htmlspecialchars($a['username']) . '</td>'
                . '<td class="muted">' . date('Y-m-d H:i', (int) $a['created_at']) . '</td>'
                . '<td>'
                . '<form class="inline" method="post" action=""><input type="hidden" name="action" value="reset_admin"><input type="hidden" name="id" value="' . (int) $a['id'] . '"><input type="password" name="password" placeholder="新密码≥8位" required style="width:130px;padding:6px 8px"><button class="ghost" type="submit">重置密码</button></form>'
                . ' <form class="inline" method="post" action=""><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="id" value="' . (int) $a['id'] . '"><button class="danger" type="submit"' . ($onlyOne ? ' disabled title="至少保留一个管理员"' : '') . '>删除</button></form>'
                . '</td></tr>';
        }
        $h .= '</table></div>';
        return $h;
    }

    private function userBlock(): string
    {
        $db = db();
        $users = db_fetchall($db, "SELECT * FROM users ORDER BY id DESC");

        $body = '<h1>用户</h1>';
        $body .= '<div class="card"><form method="post" action="">
            <input type="hidden" name="action" value="add_user">
            <div class="row">
                <div><label>新用户名</label><input type="text" name="username" required></div>
                <div><label>&nbsp;</label><button type="submit">新增用户</button></div>
            </div>
        </form></div>';

        $body .= '<table><tr><th>ID</th><th>用户名</th><th>状态</th><th>余额</th><th>创建时间</th><th>操作</th></tr>';
        foreach ($users as $u) {
            $status = $u['status'] == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>';
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
