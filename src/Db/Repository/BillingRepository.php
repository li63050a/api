<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class BillingRepository
{
    public function __construct(private Database $db) {}

    public function insert(array $data): int
    {
        $data += [
            'provider' => '',
            'model' => '',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0,
            'status' => 1,
        ];
        $this->db->execute(
            'INSERT INTO billing
                (user_id, api_key_id, provider, model, prompt_tokens, completion_tokens, total_tokens, cost, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['api_key_id'],
                $data['provider'],
                $data['model'],
                $data['prompt_tokens'],
                $data['completion_tokens'],
                $data['total_tokens'],
                $data['cost'],
                $data['status'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /**
     * @return array{prompt:int, completion:int, total:int, count:int, cost:float}
     */
    public function sumTokens(int $userId, int $from, int $to): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                COALESCE(SUM(prompt_tokens), 0) AS prompt,
                COALESCE(SUM(completion_tokens), 0) AS completion,
                COALESCE(SUM(total_tokens), 0) AS total,
                COUNT(*) AS count,
                COALESCE(SUM(cost), 0) AS cost
             FROM billing
             WHERE user_id = ? AND status = 1 AND created_at BETWEEN ? AND ?',
            [$userId, $from, $to]
        );
        return [
            'prompt' => (int)$row['prompt'],
            'completion' => (int)$row['completion'],
            'total' => (int)$row['total'],
            'count' => (int)$row['count'],
            'cost' => (float)$row['cost'],
        ];
    }

    /**
     * @return array{prompt:int, completion:int, total:int, count:int, cost:float}
     */
    public function sumTokensByKey(int $apiKeyId, int $from, int $to): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                COALESCE(SUM(prompt_tokens), 0) AS prompt,
                COALESCE(SUM(completion_tokens), 0) AS completion,
                COALESCE(SUM(total_tokens), 0) AS total,
                COUNT(*) AS count,
                COALESCE(SUM(cost), 0) AS cost
             FROM billing
             WHERE api_key_id = ? AND status = 1 AND created_at BETWEEN ? AND ?',
            [$apiKeyId, $from, $to]
        );
        return [
            'prompt' => (int)$row['prompt'],
            'completion' => (int)$row['completion'],
            'total' => (int)$row['total'],
            'count' => (int)$row['count'],
            'cost' => (float)$row['cost'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM billing ORDER BY id DESC LIMIT ?',
            [$limit]
        );
    }
}
