<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class RequestLogRepository
{
    /** search() 允许作为过滤条件的列 */
    private const FILTERABLE = [
        'user_id',
        'api_key_id',
        'provider',
        'model',
        'endpoint',
        'status',
        'ip',
    ];

    public function __construct(private Database $db) {}

    public function insert(array $data): int
    {
        $data += [
            'user_id' => 0,
            'api_key_id' => 0,
            'provider' => '',
            'model' => '',
            'endpoint' => '',
            'client_format' => 'openai',
            'status' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0,
            'latency_ms' => 0,
            'error' => '',
            'ip' => '',
        ];
        $this->db->execute(
            'INSERT INTO request_log
                (user_id, api_key_id, provider, model, endpoint, client_format, status,
                 prompt_tokens, completion_tokens, total_tokens, cost, latency_ms, error, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['api_key_id'],
                $data['provider'],
                $data['model'],
                $data['endpoint'],
                $data['client_format'],
                $data['status'],
                $data['prompt_tokens'],
                $data['completion_tokens'],
                $data['total_tokens'],
                $data['cost'],
                $data['latency_ms'],
                $data['error'],
                $data['ip'],
                $data['created_at'] ?? time(),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /**
     * 按过滤条件分页查询请求日志。
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        foreach (self::FILTERABLE as $col) {
            if (array_key_exists($col, $filters) && $filters[$col] !== '') {
                $where[] = "{$col} = ?";
                $params[] = $filters[$col];
            }
        }
        $sql = 'SELECT * FROM request_log';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function pruneBefore(int $cut): int
    {
        return $this->db->execute(
            'DELETE FROM request_log WHERE created_at < ?',
            [$cut]
        );
    }

    /**
     * @return array{total:int, success:int, failed:int, tokens:int, cost:float}
     */
    public function metrics(int $since): array
    {
        $row = $this->db->fetchOne(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status >= 200 AND status < 300 THEN 1 ELSE 0 END), 0) AS success,
                COALESCE(SUM(CASE WHEN status = 0 OR status >= 400 THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(total_tokens), 0) AS tokens,
                COALESCE(SUM(cost), 0) AS cost
             FROM request_log
             WHERE created_at >= ?',
            [$since]
        );
        return [
            'total' => (int)$row['total'],
            'success' => (int)$row['success'],
            'failed' => (int)$row['failed'],
            'tokens' => (int)$row['tokens'],
            'cost' => (float)$row['cost'],
        ];
    }
}
