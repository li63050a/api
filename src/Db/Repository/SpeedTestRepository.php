<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class SpeedTestRepository
{
    public function __construct(private Database $db) {}

    public function insert(array $data): int
    {
        $data += [
            'provider_id' => 0,
            'model' => '',
            'endpoint' => '',
            'latency_ms' => 0,
            'success' => 0,
            'error' => '',
        ];
        $this->db->execute(
            'INSERT INTO speedtest_log (provider_id, model, endpoint, latency_ms, success, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['provider_id'],
                $data['model'],
                $data['endpoint'],
                $data['latency_ms'],
                $data['success'],
                $data['error'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM speedtest_log ORDER BY id DESC LIMIT ?',
            [$limit]
        );
    }

    public function bestForProvider(int $providerId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM speedtest_log WHERE provider_id = ? ORDER BY latency_ms ASC LIMIT 1',
            [$providerId]
        );
    }
}
