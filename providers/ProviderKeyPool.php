<?php
/**
 * 上游密钥池：按权重随机选取并解密 key_value。无命名空间。
 */
class ProviderKeyPool
{
    public function next(int $providerId): ?array
    {
        $rows = db_fetchall(db(), 'SELECT * FROM upstream_keys WHERE provider_id = ? AND status = 1', [$providerId]);
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
        db_update(db(), 'upstream_keys', ['last_error_at' => time()], ['id' => $keyId]);
    }
}
