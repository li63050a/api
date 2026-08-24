<?php
/**
 * 请求日志服务：写 request_log 表。
 */
class SvcLogger
{
    public function log(array $data): void
    {
        $db = db();
        $fields = [
            'user_id' => $data['user_id'] ?? null,
            'api_key_id' => $data['api_key_id'] ?? null,
            'path' => $data['path'] ?? null,
            'model_alias' => $data['model_alias'] ?? null,
            'status_code' => $data['status_code'] ?? 0,
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0,
            'latency_ms' => $data['latency_ms'] ?? 0,
            'created_at' => time(),
        ];
        db_insert($db, 'request_log', $fields);
    }
}
