<?php
/**
 * 缓存服务：基于 SQLite 小表的简单 KV 缓存。
 */
class SvcCache
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = db();
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT,
                expires_at INTEGER
            )"
        );
    }

    public function get(string $key): ?string
    {
        $k = md5($key);
        $row = $this->db->prepare("SELECT value, expires_at FROM cache WHERE key = ?");
        $row->execute([$k]);
        $data = $row->fetch();
        if ($data === false) {
            return null;
        }
        if ((int) $data['expires_at'] > 0 && (int) $data['expires_at'] < time()) {
            $this->db->prepare("DELETE FROM cache WHERE key = ?")->execute([$k]);
            return null;
        }
        return (string) $data['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $k = md5($key);
        $expires = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
        $this->db->prepare(
            "INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at"
        )->execute([$k, $value, $expires]);
    }
}
