<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class ApiKeyRepository
{
    /** 允许通过 update() 修改的列 */
    private const UPDATABLE = [
        'user_id',
        'key_prefix',
        'key_hash',
        'key_sha256',
        'name',
        'status',
        'allowed_models',
        'ip_whitelist',
        'expires_at',
        'last_used_at',
    ];

    public function __construct(private Database $db) {}

    public function create(array $data): int
    {
        $data += [
            'key_prefix' => '',
            'key_sha256' => null,
            'name' => '',
            'status' => 1,
            'allowed_models' => '',
            'ip_whitelist' => '',
            'expires_at' => null,
            'last_used_at' => null,
        ];
        $this->db->execute(
            'INSERT INTO api_keys
                (user_id, key_prefix, key_hash, key_sha256, name, status, allowed_models, ip_whitelist, created_at, expires_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['key_prefix'],
                $data['key_hash'],
                $data['key_sha256'],
                $data['name'],
                $data['status'],
                $data['allowed_models'],
                $data['ip_whitelist'],
                $data['created_at'] ?? time(),
                $data['expires_at'],
                $data['last_used_at'],
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM api_keys WHERE id = ?', [$id]);
    }

    public function findByTokenSha(string $sha): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM api_keys WHERE key_sha256 = ? AND status = 1',
            [$sha]
        );
    }

    /**
     * 按前缀取候选 key；兼容旧库无 sha 的 key（调用方用 bcrypt 逐个校验）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findCandidatesByPrefix(string $prefix): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM api_keys
             WHERE key_prefix = ? AND status = 1',
            [$prefix]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function findByUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
    }

    public function update(int $id, array $data): int
    {
        $sets = [];
        $params = [];
        foreach (self::UPDATABLE as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = ?";
                $params[] = $data[$col];
            }
        }
        if ($sets === []) {
            return 0;
        }
        $params[] = $id;
        return $this->db->execute('UPDATE api_keys SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM api_keys WHERE id = ?', [$id]);
    }

    public function touchUsed(int $id): int
    {
        return $this->db->execute(
            'UPDATE api_keys SET last_used_at = ? WHERE id = ?',
            [time(), $id]
        );
    }

    public function count(): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM api_keys');
    }
}
