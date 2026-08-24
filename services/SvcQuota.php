<?php
/**
 * 配额服务：按日/按月统计 billing 累计用量是否超限。
 */
class SvcQuota
{
    public function check(int $userId): bool
    {
        $db = db();
        $user = db_fetch($db, "SELECT quota_daily, quota_monthly FROM users WHERE id = ?", [$userId]);
        if ($user === null) {
            return true;
        }

        $dayStart = strtotime(date('Y-m-d 00:00:00'));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        if ((int) ($user['quota_daily'] ?? 0) > 0) {
            $dayCount = db_fetch(
                $db,
                "SELECT COALESCE(SUM(request_count),0) AS cnt FROM billing
                 WHERE user_id = ? AND created_at >= ?",
                [$userId, $dayStart]
            );
            if ((int) ($dayCount['cnt'] ?? 0) >= (int) $user['quota_daily']) {
                return false;
            }
        }

        if ((int) ($user['quota_monthly'] ?? 0) > 0) {
            $monthCount = db_fetch(
                $db,
                "SELECT COALESCE(SUM(request_count),0) AS cnt FROM billing
                 WHERE user_id = ? AND created_at >= ?",
                [$userId, $monthStart]
            );
            if ((int) ($monthCount['cnt'] ?? 0) >= (int) $user['quota_monthly']) {
                return false;
            }
        }

        return true;
    }
}
