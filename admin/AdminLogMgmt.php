<?php
/**
 * 日志查看：按时间倒序浏览 request_log，支持关键字、用户、上游、状态、时间范围筛选与 keyset 分页。
 * 片段由 SPA 通过 action=logs 加载；筛选/分页/清理表单被 SPA 的 JS 拦截为 AJAX。
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

        // 清理（保留 N 天前）
        if (($this->param('action') ?: ($_POST['action'] ?? '')) === 'logs_cleanup') {
            $days = (int) ($this->param('days') ?: config('log_retention_days', 30));
            prune_request_logs($db, $days);
        }

        $q     = trim((string) $this->param('q'));
        $user  = trim((string) $this->param('user'));
        $prov  = trim((string) $this->param('prov'));
        $level = $this->param('level') ?: 'all';
        $days  = (int) ($this->param('days') ?: 7);
        $size  = 50;
        $cursor = (int) ($this->param('cursor') ?: 0);
        $dir   = $this->param('dir') === 'prev' ? 'prev' : 'next';

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
        if ($prov !== '') {
            $where[] = 'l.upstream_provider = ?';
            $params[] = $prov;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log l LEFT JOIN users u ON u.id = l.user_id {$whereSql}", $params)['n'] ?? 0);
        $errN  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log l LEFT JOIN users u ON u.id = l.user_id " . ($whereSql ? $whereSql . ' AND' : 'WHERE') . " l.status_code >= 400", $params)['n'] ?? 0);
        $avgLat = (float) (db_fetch($db, "SELECT COALESCE(AVG(l.latency_ms),0) AS v FROM request_log l LEFT JOIN users u ON u.id = l.user_id {$whereSql}", $params)['v'] ?? 0);

        // keyset 分页：多取 1 条判断是否还有下一页
        $sqlWhere = $whereSql;
        $sqlParams = $params;
        if ($cursor > 0) {
            $op = $dir === 'prev' ? '>' : '<';
            $sqlWhere = ($sqlWhere ? $sqlWhere . ' AND' : 'WHERE') . " l.id {$op} ?";
            $sqlParams[] = $cursor;
        }
        $order = $dir === 'prev' ? 'ASC' : 'DESC';
        $rows = db_fetchall($db, "
            SELECT l.*, u.username FROM request_log l
            LEFT JOIN users u ON u.id = l.user_id
            {$sqlWhere}
            ORDER BY l.id {$order}
            LIMIT ?
        ", array_merge($sqlParams, [$size + 1]));

        $hasNext = count($rows) > $size;
        if ($hasNext) {
            array_pop($rows);
        }
        if ($dir === 'prev') {
            $rows = array_reverse($rows);
        }
        $minId = null; $maxId = null;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($minId === null || $id < $minId) { $minId = $id; }
            if ($maxId === null || $id > $maxId) { $maxId = $id; }
        }
        $hasPrev = $cursor > 0;

        // 筛选表单
        $filter = '<form method="post" class="toolbar">'
            . '<input type="hidden" name="action" value="logs">'
            . '<input type="text" name="q" placeholder="关键字(模型/路径/用户)" value="' . htmlspecialchars($q) . '" style="min-width:170px">'
            . '<input type="text" name="user" placeholder="指定用户" value="' . htmlspecialchars($user) . '" style="width:110px">'
            . '<input type="text" name="prov" placeholder="上游" value="' . htmlspecialchars($prov) . '" style="width:90px">'
            . '<select name="level">' . $this->opt('all', $level, '全部状态') . $this->opt('ok', $level, '仅成功(<400)') . $this->opt('err', $level, '仅错误(>=400)') . '</select>'
            . '<select name="days">' . $this->opt('1', (string) $days, '近1天') . $this->opt('7', (string) $days, '近7天') . $this->opt('14', (string) $days, '近14天') . $this->opt('30', (string) $days, '近30天') . $this->opt('90', (string) $days, '近90天') . '</select>'
            . '<button type="submit">筛选</button>'
            . '<a class="btn ghost" href="#" onclick="loadView(\'logs\');return false;">重置</a>'
            . '</form>';

        // 清理表单
        $retention = (int) config('log_retention_days', 30);
        $cleanup = '<form method="post" class="toolbar" style="margin-top:-6px">'
            . '<input type="hidden" name="action" value="logs_cleanup">'
            . '<span class="muted">保留</span>'
            . '<input type="number" name="days" value="' . $retention . '" min="0" style="width:70px">'
            . '<span class="muted">天前的日志（0=不清理）</span>'
            . '<button type="submit" class="danger">清理旧日志</button>'
            . '</form>'
            . '<div class="toolbar" style="margin-top:-10px"><button type="button" class="btn" onclick="window.location=ACTIONS+\'?action=export_logs&days=' . $days . '\'">导出 CSV（近 ' . $days . ' 天）</button></div>';

        $summary = '<div class="stats" style="margin-bottom:16px">'
            . $this->stat($total, '命中记录')
            . $this->stat($errN, '其中错误', $errN > 0 ? 'error' : '')
            . $this->stat(round($avgLat, 1) . ' ms', '平均延迟')
            . $this->stat(($hasPrev || $hasNext) ? '有翻页' : '单页', '分页')
            . '</div>';

        // 表格
        $body = '';
        if (count($rows) === 0) {
            $body = '<div class="card"><p class="muted">没有符合条件的日志记录。</p></div>';
        } else {
            $body .= '<div class="card" style="padding:0;overflow:auto"><table><tr>'
                . '<th>时间</th><th>用户</th><th>模型</th><th>上游</th><th>IP</th><th>路径</th><th>状态</th><th>延迟</th><th>输入</th><th>输出</th><th>错误</th></tr>';
            foreach ($rows as $r) {
                $st = (int) $r['status_code'];
                $pill = $st >= 500 ? '<span class="pill off">' . $st . '</span>'
                    : ($st >= 400 ? '<span class="pill" style="background:#fef3c7;color:#92400e">' . $st . '</span>'
                    : '<span class="pill on">' . $st . '</span>');
                $err = trim((string) ($r['error'] ?? ''));
                $body .= '<tr>'
                    . '<td class="muted">' . date('m-d H:i:s', (int) $r['created_at']) . '</td>'
                    . '<td>' . htmlspecialchars($r['username'] ?: '匿名') . '</td>'
                    . '<td>' . htmlspecialchars($r['model_alias'] ?: '-') . '</td>'
                    . '<td class="muted">' . htmlspecialchars($r['upstream_provider'] ?: '-') . '</td>'
                    . '<td class="muted">' . htmlspecialchars($r['ip'] ?: '-') . '</td>'
                    . '<td class="muted">' . htmlspecialchars($r['path'] ?: '-') . '</td>'
                    . '<td>' . $pill . '</td>'
                    . '<td>' . ((int) $r['latency_ms']) . ' ms</td>'
                    . '<td class="muted">' . ((int) $r['input_tokens']) . '</td>'
                    . '<td class="muted">' . ((int) $r['output_tokens']) . '</td>'
                    . '<td class="muted" title="' . htmlspecialchars($err) . '">' . ($err !== '' ? htmlspecialchars(mb_substr($err, 0, 40)) : '-') . '</td>'
                    . '</tr>';
            }
            $body .= '</table></div>';
        }

        // 分页
        $pager = '<div class="toolbar">';
        if ($hasPrev && $maxId !== null) {
            $pager .= $this->pagerBtn($q, $user, $prov, $level, $days, $maxId, 'prev', '上一页');
        }
        if ($hasNext && $minId !== null) {
            $pager .= $this->pagerBtn($q, $user, $prov, $level, $days, $minId, 'next', '下一页');
        }
        $pager .= '<span class="muted">共 ' . $total . ' 条</span></div>';

        return '<h1>请求日志</h1>' . $filter . $cleanup . $summary . $body . $pager;
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

    private function pagerBtn(string $q, string $user, string $prov, string $level, int $days, int $cursor, string $dir, string $label): string
    {
        $h = '<input type="hidden" name="action" value="logs">'
            . '<input type="hidden" name="q" value="' . htmlspecialchars($q) . '">'
            . '<input type="hidden" name="user" value="' . htmlspecialchars($user) . '">'
            . '<input type="hidden" name="prov" value="' . htmlspecialchars($prov) . '">'
            . '<input type="hidden" name="level" value="' . htmlspecialchars($level) . '">'
            . '<input type="hidden" name="days" value="' . $days . '">'
            . '<input type="hidden" name="cursor" value="' . $cursor . '">'
            . '<input type="hidden" name="dir" value="' . $dir . '">';
        return '<form method="post" style="display:inline">' . $h . '<button type="submit">' . $label . '</button></form>';
    }
}
