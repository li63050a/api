<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class ModelChannelRepository
{
    public function __construct(private Database $db) {}

    /** 某模型的渠道（按优先级升序、权重降序） */
    public function byModel(int $modelId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM model_channels WHERE model_id = ? ORDER BY priority ASC, weight DESC, id ASC',
            [$modelId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM model_channels ORDER BY id ASC');
    }

    /** 增改（同模型+供应商唯一） */
    public function upsert(int $modelId, int $providerId, int $priority, int $weight, int $status): int
    {
        $this->db->execute(
            'INSERT INTO model_channels (model_id, provider_id, priority, weight, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(model_id, provider_id) DO UPDATE SET
                priority = excluded.priority,
                weight = excluded.weight,
                status = excluded.status',
            [$modelId, $providerId, $priority, $weight, $status, time()]
        );
        $row = $this->db->fetchOne(
            'SELECT id FROM model_channels WHERE model_id = ? AND provider_id = ? LIMIT 1',
            [$modelId, $providerId]
        );
        return (int)($row['id'] ?? 0);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM model_channels WHERE id = ?', [$id]);
    }

    public function deleteByModel(int $modelId): int
    {
        return $this->db->execute('DELETE FROM model_channels WHERE model_id = ?', [$modelId]);
    }
}