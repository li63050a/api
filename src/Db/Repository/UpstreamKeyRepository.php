<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class UpstreamKeyRepository
{
    public function __construct(private Database $db) {}

    public function insert(array $data): int
    {
        $data += [
            'status' => 1,
            'weight' => 1,
            'fail_count' => 0,
            'consecutive_failures' => 0,
            'last_used_at' => null,
            'disabled_at' => null,
        ];
        $this->db->execute(
            'INSERT INTO upstream_keys
                (provider_id, key_value, status, weight, fail_count, consecutive_failures, last_used_at, disabled_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['provider_id'],
                $data['key_value'],
                $data['status'],
                $data['weight'],
                $data['fail_count'],
                $data['consecutive_failures'],
                $data['last_used_at'],
                $data['disabled_at'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /** 允许通过 update() 修改的列 */
    private const UPDATABLE = ['key_value', 'status', 'weight'];

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
        return $this->db->execute('UPDATE upstream_keys SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM upstream_keys WHERE id = ?', [$id]);
    }

    public function deleteByProvider(int $providerId): int
    {
        return $this->db->execute('DELETE FROM upstream_keys WHERE provider_id = ?', [$providerId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM upstream_keys WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function byProvider(int $providerId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM upstream_keys
             WHERE provider_id = ? AND status = 1 AND disabled_at IS NULL',
            [$providerId]
        );
    }

    public function markFail(int $id): int
    {
        return $this->db->execute(
            'UPDATE upstream_keys
             SET fail_count = fail_count + 1, consecutive_failures = consecutive_failures + 1, last_used_at = ?
             WHERE id = ?',
            [time(), $id]
        );
    }

    public function markSuccess(int $id): int
    {
        return $this->db->execute(
            'UPDATE upstream_keys
             SET fail_count = 0, consecutive_failures = 0, last_used_at = ?
             WHERE id = ?',
            [time(), $id]
        );
    }

    public function disable(int $id): int
    {
        return $this->db->execute(
            'UPDATE upstream_keys SET status = 0, disabled_at = ? WHERE id = ?',
            [time(), $id]
        );
    }

    public function resetFailures(int $id): int
    {
        return $this->db->execute(
            'UPDATE upstream_keys SET fail_count = 0, consecutive_failures = 0 WHERE id = ?',
            [$id]
        );
    }
}
