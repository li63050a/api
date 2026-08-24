<?php
/**
 * 日志查看：按时间倒序浏览 request_log，支持关键字、用户、状态、时间范围筛选与分页。
 * 片段由 SPA 通过 action=logs 加载；筛选表单被 SPA 的 JS 拦截为 AJAX。
 */
class AdminLogMgmt
{
    public function handle(AppRequest $req): void
    {
        admin_layout('日志', $this->dispatch($req), 'logs');
    }

    public function fragment(): string
    {
        return $this->dispatch(new AppRequest());
    }

    public function dispatch(AppRequest $req): string
    {
        $db = db();

        $q     = trim((string) $this->param('q'));
        $user  = trim((string) $this->param('user'));
        $level = $this->param('level') ?: 'all';
        $days  = (int) ($this->param('days') ?: 7);
        $page  = max(1, (int) ($this->param('page') ?: 1));
        $size  = 50;
        $offset = ($page - 1) * $size;

        $where = [];
        $params = [];
        if ($days > 0) {
            $where[] = 'l.created_at >= ?';
            $params[] = time() - $days * 86400;
        }
        if ($level === 'ok') {
            $where[] = 'l.status_code < 400';
        } elseif ($level === 'err') {
            $where[] = 'l.status_code >= 400';
        }
        if ($q !== '') {
            $where[] = '(u.username LIKE ? OR l.model_alias LIKE ? OR l.path LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($user !== '') {
            $where[] = 'u.username = ?';
            $params[] = $user;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log l LEFT JOIN users u ON u.id = l.user_id {$whereSql}", $params)['n'] ?? 0);
        $errN  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log l LEFT JOIN users u ON u.id = l.user_id " . ($whereSql ? $whereSql . ' AND' : 'WHERE') . " l.status_code >= 400", $params)['n'] ?? 0);
        $avgLat = (float) (db_fetch($db, "SELECT COALESCE(AVG(l.latency_ms),0) AS v FROM request_log l LEFT JOIN users u ON u.id = l.user_id {$whereSql}", $params)['v'] ?? 0);

        $listParams = array_merge($params, [$size, $offset]);
        $rows = db_fetchall($db, "
            SELECT l.*, u.username FROM request_log l
            LEFT JOIN users u ON u.id = l.user_id
            {$whereSql}
            ORDER BY l.id DESC
            LIMIT ? OFFSET ?
        ", $listParams);

        $pages = max(1, (int) ceil($total / $size));

        // 筛选表单
        $filter = '<form method="post" class="toolbar">'
            . '<input type="hidden" name="action" value="logs">'
            . '<input type="text" name="q" placeholder="关键字(模型/路径/用户)" value="' . htmlspecialchars($q) . '" style="min-width:200px">'
            . '<input type="text" name="user" placeholder="指定用户" value="' . htmlspecialchars($user) . '" style="width:120px">'
            . '<select name="level">'
            . $this->opt('all', $level, '全部状态') . $this->opt('ok', $level, '仅成功(<400)') . $this->opt('err', $level, '仅错误(>=400)')
            . '</select>'
            . '<select name="days">' . $this->opt('1', (string) $days, '近1天') . $this->opt('7', (string) $days, '近7天') . $this->opt('14', (string) $days, '近14天') . $this->opt('30', (string) $days, '近30天') . $this->opt('90', (string) $days, '近90天') . '</select>'
            . '<button type="submit">筛选</button>'
            . '<a class="btn ghost" href="#" onclick="loadView(\'logs\');return false;">重置</a>'
            . '</form>';

        $summary = '<div class="stats" style="margin-bottom:16px">'
            . $this->stat($total, '命中记录')
            . $this->stat($errN, '其中错误', $errN > 0 ? 'error' : '')
            . $this->stat(round($avgLat, 1) . ' ms', '平均延迟')
            . $this->stat($page . ' / ' . $pages, '页码')
            . '</div>';

        // 表格
        $body = '';
        if (count($rows) === 0) {
            $body = '<div class="card"><p class="muted">没有符合条件的日志记录。</p></div>';
        } else {
            $body .= '<div class="card" style="padding:0;overflow:auto"><table><tr>'
                . '<th>时间</th><th>用户</th><th>模型</th><th>路径</th><th>状态</th><th>延迟</th><th>输入</th><th>输出</th></tr>';
            foreach ($rows as $r) {
                $st = (int) $r['status_code'];
                $pill = $st >= 500 ? '<span class="pill off">' . $st . '</span>'
                    : ($st >= 400 ? '<span class="pill" style="background:#fef3c7;color:#92400e">' . $st . '</span>'
                    : '<span class="pill on">' . $st . '</span>');
                $body .= '<tr>'
                    . '<td class="muted">' . date('m-d H:i:s', (int) $r['created_at']) . '</td>'
                    . '<td>' . htmlspecialchars($r['username'] ?: '匿名') . '</td>'
                    . '<td>' . htmlspecialchars($r['model_alias'] ?: '-') . '</td>'
                    . '<td class="muted">' . htmlspecialchars($r['path'] ?: '-') . '</td>'
                    . '<td>' . $pill . '</td>'
                    . '<td>' . ((int) $r['latency_ms']) . ' ms</td>'
                    . '<td class="muted">' . ((int) $r['input_tokens']) . '</td>'
                    . '<td class="muted">' . ((int) $r['output_tokens']) . '</td>'
                    . '</tr>';
            }
            $body .= '</table></div>';
        }

        // 分页
        $pager = '<div class="toolbar">';
        if ($page > 1) {
            $pager .= $this->pagerBtn($q, $user, $level, $days, $page - 1, '上一页');
        }
        if ($page < $pages) {
            $pager .= $this->pagerBtn($q, $user, $level, $days, $page + 1, '下一页');
        }
        $pager .= '<span class="muted">共 ' . $total . ' 条</span></div>';

        return '<h1>请求日志</h1>' . $filter . $summary . $body . $pager;
    }

    private function param(string $k)
    {
        return $_POST[$k] ?? $_GET[$k] ?? '';
    }

    private function opt(string $v, string $cur, string $label): string
    {
        return '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . $label . '</option>';
    }

    private function stat(string $v, string $l, string $cls = ''): string
    {
        return '<div class="stat"><div class="v ' . $cls . '">' . htmlspecialchars($v) . '</div><div class="l">' . htmlspecialchars($l) . '</div></div>';
    }

    private function pagerBtn(string $q, string $user, string $level, int $days, int $page, string $label): string
    {
        $h = '<input type="hidden" name="action" value="logs">'
            . '<input type="hidden" name="q" value="' . htmlspecialchars($q) . '">'
            . '<input type="hidden" name="user" value="' . htmlspecialchars($user) . '">'
            . '<input type="hidden" name="level" value="' . htmlspecialchars($level) . '">'
            . '<input type="hidden" name="days" value="' . $days . '">'
            . '<input type="hidden" name="page" value="' . $page . '">';
        return '<form method="post" style="display:inline">' . $h . '<button type="submit">' . $label . '</button></form>';
    }
}
