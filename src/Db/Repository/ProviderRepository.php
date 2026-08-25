<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class ProviderRepository
{
    /** 允许通过 update() 修改的列 */
    private const UPDATABLE = [
        'name',
        'base_url',
        'client_format',
        'status',
        'priority',
        'timeout',
        'max_retries',
        'notes',
    ];

    public function __construct(private Database $db) {}

    public function create(array $data): int
    {
        $data += [
            'base_url' => '',
            'client_format' => 'openai',
            'status' => 1,
            'priority' => 100,
            'timeout' => 60,
            'max_retries' => 1,
            'notes' => '',
        ];
        $this->db->execute(
            'INSERT INTO providers (name, base_url, client_format, status, priority, timeout, max_retries, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['base_url'],
                $data['client_format'],
                $data['status'],
                $data['priority'],
                $data['timeout'],
                $data['max_retries'],
                $data['notes'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM providers WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->db->fetchOne('SELECT * FROM providers WHERE name = ?', [$name]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM providers ORDER BY id ASC');
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
        return $this->db->execute('UPDATE providers SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM providers WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findEnabledSorted(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM providers WHERE status = 1 ORDER BY priority ASC'
        );
    }
}
