<?php
/**
 * 管理员账号：修改自身密码；检测是否仍使用默认种子密码。
 */
class AdminProfileMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('账号', $this->dispatch($req), 'profile');
    }

    public function fragment(): string
    {
        return $this->dispatch(new AppRequest());
    }

    public function dispatch(AppRequest $req): string
    {
        $admin = (new AdminAuth())->current();
        if ($admin === null) {
            return '<p class="error">未登录</p>';
        }
        $defaultPwd = (string) ((config('admin_seed') ?? [])['password'] ?? '');
        $isDefault = $defaultPwd !== '' && password_verify($defaultPwd, (string) $admin['password_hash']);

        $warn = $isDefault
            ? '<div class="card" style="border:1px solid #fca5a5;background:#fef2f2"><p class="error"><b>安全提醒：</b>当前管理员密码仍为初始默认密码，请立即修改！</p></div>'
            : '';

        $form = '<div class="card"><h3>修改密码</h3>'
            . '<form method="post" class="grid">'
            . '<input type="hidden" name="action" value="change_password">'
            . '<div><label>当前密码</label><input type="password" name="old_pwd" required></div>'
            . '<div><label>新密码</label><input type="password" name="new_pwd" required minlength="8"></div>'
            . '<div><label>&nbsp;</label><button type="submit">保存</button></div>'
            . '</form></div>';

        return '<h1>账号</h1>' . $warn . $form;
    }
}
