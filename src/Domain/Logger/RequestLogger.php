<?php
declare(strict_types=1);

namespace App\Domain\Logger;

use App\Db\Repository\RequestLogRepository;

final class RequestLogger
{
    public function __construct(private RequestLogRepository $logs) {}

    /** @param array<string, mixed> $data */
    public function record(array $data): void
    {
        $this->logs->insert([
            'user_id' => (int)($data['user_id'] ?? 0),
            'api_key_id' => (int)($data['api_key_id'] ?? 0),
            'provider' => (string)($data['provider'] ?? ''),
            'model' => (string)($data['model'] ?? ''),
            'endpoint' => (string)($data['endpoint'] ?? ''),
            'client_format' => (string)($data['client_format'] ?? 'openai'),
            'status' => (int)($data['status'] ?? 0),
            'prompt_tokens' => (int)($data['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($data['completion_tokens'] ?? 0),
            'total_tokens' => (int)($data['total_tokens'] ?? 0),
            'cost' => (float)($data['cost'] ?? 0),
            'latency_ms' => (int)($data['latency_ms'] ?? 0),
            'error' => (string)($data['error'] ?? ''),
            'ip' => (string)($data['ip'] ?? ''),
            'created_at' => time(),
        ]);
    }

    public function pruneBefore(int $cut): int
    {
        return $this->logs->pruneBefore($cut);
    }
}
