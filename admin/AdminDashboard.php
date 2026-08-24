<?php
/**
 * 仪表盘：统计用户数 / 今日请求 / 今日收入 / 错误率，以及按消费 Top 用户。
 * 无 namespace；类名=文件名。普通类，方法有 handle()（服务端页）与 fragment()（供单页 AJAX）。
 */
class AdminDashboard
{
    public function handle(AppRequest $req): void
    {
        admin_layout('仪表盘', $this->fragment(), '');
    }

    public function fragment(): string
    {
        $db = db();
        $todayStart = strtotime('today');

        $userCount = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM users")['n'] ?? 0);
        $todayReq  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ?", [$todayStart])['n'] ?? 0);
        $todayRev  = (float) (db_fetch($db, "SELECT COALESCE(SUM(amount),0) AS s FROM billing WHERE created_at >= ?", [$todayStart])['s'] ?? 0);
        $errCount  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ? AND status_code >= 400", [$todayStart])['n'] ?? 0);
        $errRate   = $todayReq > 0 ? round($errCount / $todayReq * 100, 2) : 0;

        $body = <<<HTML
<h1>仪表盘</h1>
<div class="stats">
  <div class="stat"><div class="v">{$userCount}</div><div class="l">用户总数</div></div>
  <div class="stat"><div class="v">{$todayReq}</div><div class="l">今日请求</div></div>
  <div class="stat"><div class="v">\$ {$todayRev}</div><div class="l">今日收入</div></div>
  <div class="stat"><div class="v">{$errRate}%</div><div class="l">今日错误率</div></div>
</div>
HTML;

        $rows = db_fetchall($db, "
            SELECT u.username, COUNT(k.id) AS keys_n, COALESCE(SUM(b.amount),0) AS spent
            FROM users u
            LEFT JOIN api_keys k ON k.user_id = u.id
            LEFT JOIN billing b ON b.user_id = u.id
            GROUP BY u.id
            ORDER BY spent DESC LIMIT 10
        ");
        if (count($rows) > 0) {
            $body .= '<div class="card"><h3>消费 Top 10 用户</h3><table>'
                . '<tr><th>用户</th><th>密钥数</th><th>累计消费</th></tr>';
            foreach ($rows as $r) {
                $body .= '<tr><td>' . htmlspecialchars($r['username']) . '</td>'
                    . '<td>' . (int) $r['keys_n'] . '</td>'
                    . '<td>$ ' . htmlspecialchars($r['spent']) . '</td></tr>';
            }
            $body .= '</table></div>';
        }

        return $body;
    }
}
