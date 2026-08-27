<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class UserRepository
{
    /** 允许通过 update() 修改的列 */
    private const UPDATABLE = ['username', 'password_hash', 'status', 'balance', 'quota_daily', 'quota_monthly'];

    public function __construct(private Database $db) {}

    public function create(array $data): int
    {
        $data += [
            'password_hash' => '',
            'status' => 1,
            'balance' => 0,
            'quota_daily' => 0,
            'quota_monthly' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        $this->db->execute(
            'INSERT INTO users (username, password_hash, status, balance, quota_daily, quota_monthly, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['username'],
                $data['password_hash'],
                $data['status'],
                $data['balance'],
                $data['quota_daily'],
                $data['quota_monthly'],
                $data['created_at'],
                $data['updated_at'],
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        return $this->db->fetchAll(
            'SELECT * FROM users ORDER BY id DESC LIMIT ? OFFSET ?',
            [$perPage, $offset]
        );
    }

    public function count(): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM users');
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
        $sets[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $id;
        return $this->db->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function addBalance(int $id, float $amount): int
    {
        return $this->db->execute(
            'UPDATE users SET balance = balance + ?, updated_at = ? WHERE id = ?',
            [$amount, time(), $id]
        );
    }

    public function setStatus(int $id, int $status): int
    {
        return $this->db->execute(
            'UPDATE users SET status = ?, updated_at = ? WHERE id = ?',
            [$status, time(), $id]
        );
    }
}
