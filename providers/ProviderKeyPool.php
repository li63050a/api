<?php
/**
 * 上游密钥池：按权重随机选取并解密 key_value；失败自动熔断冷却，成功自动恢复。
 */
class ProviderKeyPool
{
    public function next(int $providerId): ?array
    {
        $now = time();
        $rows = db_fetchall(db(), 'SELECT * FROM upstream_keys WHERE provider_id = ? AND status = 1 AND (cooldown_until IS NULL OR cooldown_until <= ?)', [$providerId, $now]);
        if (empty($rows)) {
            return null;
        }
        $total = 0;
        foreach ($rows as $r) {
            $total += max(1, (int) ($r['weight'] ?? 1));
        }
        $pick = random_int(0, $total - 1);
        $acc = 0;
        $chosen = null;
        foreach ($rows as $r) {
            $acc += max(1, (int) ($r['weight'] ?? 1));
            if ($pick < $acc) {
                $chosen = $r;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $rows[count($rows) - 1];
        }
        $chosen['key_value'] = crypto_decrypt($chosen['key_value']);
        return $chosen;
    }

    public function markError(int $keyId): void
    {
        $now = time();
        $row = db_fetch(db(), 'SELECT * FROM upstream_keys WHERE id = ?', [$keyId]);
        $recent = $row !== null && ($now - (int) ($row['last_error_at'] ?? 0)) < (int) config('key_cooldown_seconds', 300);
        db_update(db(), 'upstream_keys', ['last_error_at' => $now], ['id' => $keyId]);
        if ($recent && $row !== null && (int) ($row['cooldown_until'] ?? 0) <= $now) {
            $cd = $now + (int) config('key_cooldown_seconds', 300);
            db_update(db(), 'upstream_keys', ['cooldown_until' => $cd], ['id' => $keyId]);
            $prov = db_fetch(db(), 'SELECT name FROM providers WHERE id = ?', [$row['provider_id'] ?? 0]);
            notify_alert('上游 Key 进入冷却', '供应商 ' . ($prov['name'] ?? '?') . ' 的 Key #' . $keyId . ' 连续失败，已冷却至 ' . date('H:i:s', $cd));
        }
    }

    public function markSuccess(int $keyId): void
    {
        db()->exec('UPDATE upstream_keys SET cooldown_until = 0 WHERE id = ' . (int) $keyId . ' AND cooldown_until > 0');
    }
}
