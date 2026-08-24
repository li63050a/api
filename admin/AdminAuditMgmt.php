<?php
/**
 * 后台操作审计：展示 admin_audit 记录（增删改等操作留痕）。
 */
class AdminAuditMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('操作审计', $this->dispatch($req), 'audit');
    }

    public function fragment(): string
    {
        return $this->dispatch(new AppRequest());
    }

    public function dispatch(AppRequest $req): string
    {
        $db = db();
        $rows = db_fetchall($db, "
            SELECT a.*, u.username FROM admin_audit a
            LEFT JOIN admin_users u ON u.id = a.admin_id
            ORDER BY a.id DESC LIMIT 200
        ");

        if (count($rows) === 0) {
            return '<h1>操作审计</h1><div class="card"><p class="muted">暂无操作记录。</p></div>';
        }

        $body = '<div class="card" style="padding:0;overflow:auto"><table><tr>'
            . '<th>时间</th><th>管理员</th><th>操作</th><th>详情</th></tr>';
        foreach ($rows as $r) {
            $detail = (string) ($r['detail'] ?? '');
            $body .= '<tr>'
                . '<td class="muted">' . date('Y-m-d H:i:s', (int) $r['created_at']) . '</td>'
                . '<td>' . htmlspecialchars($r['username'] ?: '未知') . '</td>'
                . '<td><span class="pill on">' . htmlspecialchars($r['action']) . '</span></td>'
                . '<td class="muted"><code>' . htmlspecialchars(mb_substr($detail, 0, 120)) . '</code></td>'
                . '</tr>';
        }
        $body .= '</table></div>';

        return '<h1>操作审计</h1>' . $body;
    }
}
