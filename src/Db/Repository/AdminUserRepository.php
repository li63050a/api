<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class AdminUserRepository
{
    public function __construct(private Database $db) {}

    public function findByUsername(string $username): ?array
    {
        return $this->db->fetchOne('SELECT * FROM admin_users WHERE username = ?', [$username]);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM admin_users ORDER BY id ASC');
    }

    public function create(string $username, string $passwordHash, int $mustChange): int
    {
        $this->db->execute(
            'INSERT INTO admin_users (username, password_hash, must_change, created_at) VALUES (?, ?, ?, ?)',
            [$username, $passwordHash, $mustChange, time()]
        );
        return (int)$this->db->lastInsertId();
    }

    public function updateCredentials(int $id, string $username, string $passwordHash): int
    {
        return $this->db->execute(
            'UPDATE admin_users SET username = ?, password_hash = ? WHERE id = ?',
            [$username, $passwordHash, $id]
        );
    }

    public function setMustChange(int $id, int $mustChange): int
    {
        return $this->db->execute(
            'UPDATE admin_users SET must_change = ? WHERE id = ?',
            [$mustChange, $id]
        );
    }

    public function touchLogin(int $id): int
    {
        return $this->db->execute(
            'UPDATE admin_users SET last_login_at = ? WHERE id = ?',
            [time(), $id]
        );
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM admin_users WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM admin_users');
    }
}
