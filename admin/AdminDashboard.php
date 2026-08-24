<?php
/**
 * 仪表盘：丰富的运营概览。
 * 无 namespace；类名=文件名。方法 fragment() 供单页 AJAX 调用。
 */
class AdminDashboard
{
    public function handle(AppRequest $req): void
    {
        admin_layout('仪表盘', $this->fragment(), '');
    }

    public function fragment(): string
    {
        $cacheKey = 'dashboard_v1';
        $cached = cache_get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $db = db();
        $today = strtotime('today');
        $month = strtotime('first day of this month');

        $userCount = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM users")['n'] ?? 0);
        $todayReq  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ?", [$today])['n'] ?? 0);
        $todayRev  = (float) (db_fetch($db, "SELECT COALESCE(SUM(amount),0) AS s FROM billing WHERE created_at >= ?", [$today])['s'] ?? 0);
        $monthRev  = (float) (db_fetch($db, "SELECT COALESCE(SUM(amount),0) AS s FROM billing WHERE created_at >= ?", [$month])['s'] ?? 0);
        $errCount  = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ? AND status_code >= 400", [$today])['n'] ?? 0);
        $errRate   = $todayReq > 0 ? round($errCount / $todayReq * 100, 2) : 0;
        $avgLat    = (float) (db_fetch($db, "SELECT COALESCE(AVG(latency_ms),0) AS v FROM request_log WHERE created_at >= ?", [$today])['v'] ?? 0);
        $enabledModels = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM model_map WHERE status = 1")['n'] ?? 0);
        $keys = db_fetch($db, "SELECT COUNT(*) AS t, COALESCE(SUM(status=1),0) AS a FROM upstream_keys");
        $keyTotal = (int) ($keys['t'] ?? 0);
        $keyActive = (int) ($keys['a'] ?? 0);

        // 近 14 天请求趋势
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $s = strtotime("-$i days", $today);
            $e = $s + 86400;
            $n = (int) (db_fetch($db, "SELECT COUNT(*) AS n FROM request_log WHERE created_at >= ? AND created_at < ?", [$s, $e])['n'] ?? 0);
            $series[] = ['d' => $s, 'n' => $n];
        }
        $maxN = max(array_column($series, 'n')) ?: 1;

        $bars = '';
        foreach ($series as $p) {
            $h = $p['n'] > 0 ? max(4, round($p['n'] / $maxN * 100)) : 2;
            $bars .= '<div class="bar" style="height:' . $h . '%" title="' . date('m-d', $p['d']) . ': ' . $p['n'] . ' 次">'
                . ($p['n'] > 0 ? '<span class="v">' . $p['n'] . '</span>' : '') . '</div>';
        }

        // 供应商健康
        $provs = db_fetchall($db, "SELECT * FROM providers ORDER BY id");
        $provHtml = '';
        foreach ($provs as $p) {
            $pid = (int) $p['id'];
            $kc = db_fetch($db, "SELECT COUNT(*) AS t, COALESCE(SUM(status=1),0) AS a FROM upstream_keys WHERE provider_id = ?", [$pid]);
            $test = db_fetch($db, "SELECT ok, latency_ms, detail FROM speedtest_log WHERE provider_id = ? ORDER BY id DESC LIMIT 1", [$pid]);
            $kStat = ($kc['a'] ?? 0) . '/' . ($kc['t'] ?? 0);
            if ($test === null) {
                $health = '<span class="pill off">未测</span>';
            } elseif ($test['ok']) {
                $health = '<span class="pill on">正常 ' . (int) $test['latency_ms'] . 'ms</span>';
            } else {
                $health = '<span class="pill off">异常</span>';
            }
            $pstat = $p['status'] == 1 ? '<span class="pill on">启用</span>' : '<span class="pill off">停用</span>';
            $provHtml .= '<tr>'
                . '<td>' . htmlspecialchars($p['name']) . '</td>'
                . '<td>' . htmlspecialchars($p['type']) . '</td>'
                . '<td>' . $pstat . '</td>'
                . '<td>Key ' . $kStat . '</td>'
                . '<td>' . $health . '</td>'
                . '</tr>';
        }

        // Top 模型
        $models = db_fetchall($db, "SELECT model_alias AS m, COALESCE(SUM(request_count),0) AS cnt, COALESCE(SUM(amount),0) AS amt FROM billing GROUP BY model_alias ORDER BY cnt DESC LIMIT 8");
        $modelHtml = '';
        if (count($models) > 0) {
            $modelHtml .= '<table><tr><th>模型</th><th>请求数</th><th>收入</th></tr>';
            foreach ($models as $m) {
                $modelHtml .= '<tr><td>' . htmlspecialchars($m['m'] ?: '(未知)') . '</td>'
                    . '<td>' . (int) $m['cnt'] . '</td>'
                    . '<td>$ ' . htmlspecialchars($m['amt']) . '</td></tr>';
            }
            $modelHtml .= '</table>';
        } else {
            $modelHtml = '<p class="muted">暂无计费数据</p>';
        }

        // 消费 Top 用户
        $users = db_fetchall($db, "
            SELECT u.username, COUNT(k.id) AS keys_n, COALESCE(SUM(b.amount),0) AS spent
            FROM users u
            LEFT JOIN api_keys k ON k.user_id = u.id
            LEFT JOIN billing b ON b.user_id = u.id
            GROUP BY u.id ORDER BY spent DESC LIMIT 8
        ");
        $userHtml = '';
        if (count($users) > 0) {
            $userHtml .= '<table><tr><th>用户</th><th>密钥数</th><th>累计消费</th></tr>';
            foreach ($users as $r) {
                $userHtml .= '<tr><td>' . htmlspecialchars($r['username']) . '</td>'
                    . '<td>' . (int) $r['keys_n'] . '</td>'
                    . '<td>$ ' . htmlspecialchars($r['spent']) . '</td></tr>';
            }
            $userHtml .= '</table>';
        } else {
            $userHtml = '<p class="muted">暂无用户</p>';
        }

        // 最新请求
        $recent = db_fetchall($db, "
            SELECT l.*, u.username FROM request_log l
            LEFT JOIN users u ON u.id = l.user_id
            ORDER BY l.id DESC LIMIT 10
        ");
        $recentHtml = '';
        if (count($recent) > 0) {
            $recentHtml .= '<table><tr><th>时间</th><th>用户</th><th>模型</th><th>状态</th><th>延迟</th></tr>';
            foreach ($recent as $r) {
                $st = (int) $r['status_code'];
                $stPill = $st >= 400 ? '<span class="pill off">' . $st . '</span>' : '<span class="pill on">' . $st . '</span>';
                $recentHtml .= '<tr>'
                    . '<td class="muted">' . date('m-d H:i', (int) $r['created_at']) . '</td>'
                    . '<td>' . htmlspecialchars($r['username'] ?? '?') . '</td>'
                    . '<td>' . htmlspecialchars($r['model_alias'] ?? '') . '</td>'
                    . '<td>' . $stPill . '</td>'
                    . '<td class="muted">' . (int) $r['latency_ms'] . 'ms</td>'
                    . '</tr>';
            }
            $recentHtml .= '</table>';
        } else {
            $recentHtml = '<p class="muted">暂无请求日志</p>';
        }

        $html = <<<HTML
<style>
  .dash-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
  @media (max-width: 900px){ .dash-grid { grid-template-columns: 1fr; } }
  .bars { display: flex; align-items: flex-end; gap: 6px; height: 130px; padding: 14px 4px 4px; }
  .bar { flex: 1; background: linear-gradient(180deg,#bcd0e6,#8fa9c9); border-radius: 6px 6px 0 0; position: relative; min-height: 2px; transition: background .15s; }
  .bar:hover { background: #6f8bb0; }
  .bar .v { position: absolute; top: -17px; left: 50%; transform: translateX(-50%); font-size: 11px; color: #5a636b; white-space: nowrap; }
  .xlab { display: flex; gap: 6px; margin: 0 4px 4px; }
  .xlab span { flex: 1; text-align: center; font-size: 10px; color: #8b949c; }
  .sub2 { color: #8b949c; font-size: 12px; margin: -8px 0 14px; }
</style>
<h1>仪表盘</h1>
<div class="stats">
  <div class="stat"><div class="v">{$userCount}</div><div class="l">用户总数</div></div>
  <div class="stat"><div class="v">{$todayReq}</div><div class="l">今日请求</div></div>
  <div class="stat"><div class="v">\$ {$todayRev}</div><div class="l">今日收入</div></div>
  <div class="stat"><div class="v">{$errRate}%</div><div class="l">今日错误率</div></div>
  <div class="stat"><div class="v">{$monthRev}</div><div class="l">本月收入</div></div>
  <div class="stat"><div class="v">{$avgLat}ms</div><div class="l">平均延迟</div></div>
  <div class="stat"><div class="v">{$enabledModels}</div><div class="l">启用模型</div></div>
  <div class="stat"><div class="v">{$keyActive}/{$keyTotal}</div><div class="l">活跃上游Key</div></div>
</div>

<div class="dash-grid">
  <div>
    <div class="card">
      <h3>近 14 天请求量</h3>
      <div class="bars">{$bars}</div>
      <div class="xlab"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
    </div>
    <div class="card">
      <h3>供应商与上游 Key 健康</h3>
      <table><tr><th>供应商</th><th>类型</th><th>状态</th><th>Key(活跃/总)</th><th>探测</th></tr>{$provHtml}</table>
    </div>
    <div class="card">
      <h3>最新请求</h3>
      {$recentHtml}
    </div>
  </div>
  <div>
    <div class="card">
      <h3>Top 模型</h3>
      {$modelHtml}
    </div>
    <div class="card">
      <h3>消费 Top 用户</h3>
      {$userHtml}
    </div>
  </div>
 </div>
HTML;
        cache_set($cacheKey, $html, (int) config('cache_ttl_seconds', 15));
        return $html;
    }
}
