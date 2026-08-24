<?php
/**
 * 账单视图：按用户 / 供应商 / 模型聚合消费，支持时间范围筛选。
 */
class AdminBillingMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('账单', $this->dispatch($req), 'billing');
    }

    public function fragment(): string
    {
        return $this->dispatch(new AppRequest());
    }

    public function dispatch(AppRequest $req): string
    {
        $db = db();
        $days = (int) ($this->param('days') ?: 30);
        $cacheKey = 'billing_' . $days;
        $cached = cache_get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $cut = $days > 0 ? time() - $days * 86400 : 0;
        $w = $days > 0 ? 'WHERE b.created_at >= ?' : '';
        $wp = $days > 0 ? [$cut] : [];

        $tot = db_fetch($db, "SELECT COALESCE(SUM(b.amount),0) AS amt, COALESCE(SUM(b.request_count),0) AS cnt FROM billing b {$w}", $wp);
        $topUsers = db_fetchall($db, "
            SELECT u.username, COUNT(*) AS cnt, COALESCE(SUM(b.amount),0) AS amt, COALESCE(SUM(b.input_tokens+b.output_tokens),0) AS toks
            FROM billing b JOIN users u ON u.id = b.user_id {$w}
            GROUP BY u.id ORDER BY amt DESC LIMIT 10
        ", $wp);
        $topProv = db_fetchall($db, "
            SELECT p.name, COUNT(*) AS cnt, COALESCE(SUM(b.amount),0) AS amt
            FROM billing b JOIN providers p ON p.id = b.provider_id {$w}
            GROUP BY p.id ORDER BY amt DESC LIMIT 10
        ", $wp);
        $topModel = db_fetchall($db, "
            SELECT model_alias, COALESCE(SUM(amount),0) AS amt, COALESCE(SUM(request_count),0) AS cnt
            FROM billing b {$w}
            GROUP BY model_alias ORDER BY amt DESC LIMIT 10
        ", $wp);

        $filter = '<form method="post" class="toolbar">'
            . '<input type="hidden" name="action" value="billing">'
            . '<select name="days">' . $this->opt('7', (string) $days, '近7天') . $this->opt('30', (string) $days, '近30天') . $this->opt('90', (string) $days, '近90天') . $this->opt('0', (string) $days, '全部') . '</select>'
            . '<button type="submit">查看</button></form>';

        $summary = '<div class="stats" style="margin-bottom:16px">'
            . $this->stat('¥' . sprintf('%.2f', (float) ($tot['amt'] ?? 0)), '区间消费')
            . $this->stat((int) ($tot['cnt'] ?? 0), '请求次数')
            . $this->stat(count($topUsers), '活跃用户')
            . $this->stat(count($topProv), '活跃供应商')
            . '</div>';

        $body = $filter . $summary;
        $body .= '<div class="toolbar" style="margin-top:-10px"><button type="button" class="btn" onclick="window.location=ACTIONS+\'?action=export_billing&days=' . $days . '\'">导出 CSV（近 ' . $days . ' 天）</button></div>';
        $body .= $this->table('按用户', ['用户', '请求', 'Token', '消费'], $topUsers, function ($r) {
            return '<td>' . htmlspecialchars($r['username']) . '</td><td>' . (int) $r['cnt'] . '</td><td>' . (int) $r['toks'] . '</td><td>¥' . sprintf('%.2f', (float) $r['amt']) . '</td>';
        });
        $body .= $this->table('按供应商', ['供应商', '请求', '消费'], $topProv, function ($r) {
            return '<td>' . htmlspecialchars($r['name']) . '</td><td>' . (int) $r['cnt'] . '</td><td>¥' . sprintf('%.2f', (float) $r['amt']) . '</td>';
        });
        $body .= $this->table('按模型', ['模型', '请求', '消费'], $topModel, function ($r) {
            return '<td>' . htmlspecialchars($r['model_alias'] ?: '-') . '</td><td>' . (int) $r['cnt'] . '</td><td>¥' . sprintf('%.2f', (float) $r['amt']) . '</td>';
        });

        $html = '<h1>账单统计</h1>' . $body;
        cache_set($cacheKey, $html, (int) config('cache_ttl_seconds', 15));
        return $html;
    }

    private function param(string $k)
    {
        return $_POST[$k] ?? $_GET[$k] ?? '';
    }

    private function opt(string $v, string $cur, string $label): string
    {
        return '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . $label . '</option>';
    }

    private function stat(string $v, string $l): string
    {
        return '<div class="stat"><div class="v">' . htmlspecialchars($v) . '</div><div class="l">' . htmlspecialchars($l) . '</div></div>';
    }

    private function table(string $title, array $heads, array $rows, callable $rowFn): string
    {
        if (count($rows) === 0) {
            return '<div class="card"><h3>' . htmlspecialchars($title) . '</h3><p class="muted">暂无数据</p></div>';
        }
        $h = '<tr>' . implode('', array_map(fn ($x) => '<th>' . htmlspecialchars($x) . '</th>', $heads)) . '</tr>';
        $b = '';
        foreach ($rows as $r) {
            $b .= '<tr>' . $rowFn($r) . '</tr>';
        }
        return '<div class="card"><h3>' . htmlspecialchars($title) . '</h3><table>' . $h . $b . '</table></div>';
    }
}
