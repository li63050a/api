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
        'upstream_model',
        'client_format',
        'enabled',
        'prompt_price',
        'completion_price',
    ];

    public function __construct(private Database $db) {}

    public function create(array $data): int
    {
        $data += [
            'client_format' => 'openai',
            'enabled' => 1,
            'prompt_price' => 0,
            'completion_price' => 0,
        ];
        $this->db->execute(
            'INSERT INTO model_map (alias, provider, upstream_model, client_format, enabled, prompt_price, completion_price, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['alias'],
                $data['provider'],
                $data['upstream_model'],
                $data['client_format'],
                $data['enabled'],
                $data['prompt_price'],
                $data['completion_price'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function findByAlias(string $alias): ?array
    {
        return $this->db->fetchOne('SELECT * FROM model_map WHERE alias = ?', [$alias]);
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

    /** 指定供应商的启用模型 */
    public function allEnabledByProvider(string $provider): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM model_map WHERE enabled = 1 AND provider = ? ORDER BY id ASC',
            [$provider]
        );
    }

    /** 按 供应商+上游模型 精确查启用行（provider/model 路由用） */
    public function findEnabledByProviderAndModel(string $provider, string $upstreamModel): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM model_map WHERE provider = ? AND upstream_model = ? AND enabled = 1 LIMIT 1',
            [$provider, $upstreamModel]
        );
    }

    public function findEnabledByAlias(string $alias): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM model_map WHERE alias = ? AND enabled = 1',
            [$alias]
        );
    }
}
