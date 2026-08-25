<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class ModelMapRepository
{
    /** 允许通过 update() 修改的列 */
    private const UPDATABLE = [
        'alias',
        'provider',
        'key_id',
        'upstream_model',
        'client_format',
        'enabled',
    ];

    public function __construct(private Database $db) {}

    public function create(array $data): int
    {
        $data += [
            'key_id' => 0,
            'client_format' => 'openai',
            'enabled' => 1,
        ];
        $this->db->execute(
            'INSERT INTO model_map (alias, provider, key_id, upstream_model, client_format, enabled, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['alias'],
                $data['provider'],
                (int)$data['key_id'],
                $data['upstream_model'],
                $data['client_format'],
                $data['enabled'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /** 同一模型名 + 同一密钥唯一；不存在返回 null。 */
    public function findByAliasAndKeyId(string $alias, int $keyId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM model_map WHERE alias = ? AND key_id = ?',
            [$alias, $keyId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM model_map WHERE id = ?', [$id]);
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
        return $this->db->execute('UPDATE model_map SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM model_map WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM model_map ORDER BY id ASC');
    }

    /** @return array<int, array<string, mixed>> */
    public function allEnabled(): array
    {
        return $this->db->fetchAll('SELECT * FROM model_map WHERE enabled = 1 ORDER BY id ASC');
    }

    /** 指定密钥下的全部模型行。 */
    public function allByKeyId(int $keyId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM model_map WHERE key_id = ? ORDER BY id ASC',
            [$keyId]
        );
    }

    /** 指定模型名关联的全部启用行（同一模型名可挂在多把密钥下）。 */
    public function findAllEnabledByAlias(string $alias): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM model_map WHERE alias = ? AND enabled = 1 ORDER BY id ASC',
            [$alias]
        );
    }
}
