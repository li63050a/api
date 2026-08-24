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
            'upstream_provider' => $data['upstream_provider'] ?? '',
            'ip' => $data['ip'] ?? $this->clientIp(),
            'status_code' => $data['status_code'] ?? 0,
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0,
            'latency_ms' => $data['latency_ms'] ?? 0,
            'error' => $data['error'] ?? '',
            'created_at' => time(),
        ];
        db_insert($db, 'request_log', $fields);
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($ip !== '') {
            $ip = explode(',', $ip)[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        return trim((string) $ip);
    }
}
