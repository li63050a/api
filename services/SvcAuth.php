<?php
/**
 * 鉴权服务：API Key 校验、哈希与生成。
 */
class SvcAuth
{
    /**
     * 校验原始 Key，返回 ['user'=>row,'key'=>row] 或 null。
     */
    public function authenticate(string $rawKey): ?array
    {
        $db = db();
        $keys = db_fetchall($db, "SELECT * FROM api_keys WHERE status = 1");

        foreach ($keys as $row) {
            if (!password_verify($rawKey, (string) $row['key_hash'])) {
                continue;
            }
            if (!empty($row['expires_at']) && (int) $row['expires_at'] < time()) {
                continue;
            }
            $user = db_fetch(
                $db,
                "SELECT id, username, email, quota_daily, quota_monthly, balance, status, created_at
                 FROM users WHERE id = ?",
                [$row['user_id']]
            );
            return ['user' => $user ?: [], 'key' => $row];
        }

        return null;
    }

    public static function hashKey(string $raw): string
    {
        return password_hash($raw, PASSWORD_DEFAULT);
    }

    public static function generateKey(): string
    {
        return 'sk-' . bin2hex(random_bytes(16));
    }
}
