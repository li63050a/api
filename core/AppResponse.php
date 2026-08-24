<?php
/**
 * 响应封装
 */
class AppResponse
{
    public static function status(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }
    }

    public static function header(string $k, string $v): void
    {
        if (!headers_sent()) {
            header($k . ': ' . $v);
        }
    }

    public static function json($data, int $code = 200): void
    {
        self::status($code);
        self::header('Content-Type', 'application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function sse(string $data, ?string $event = null): void
    {
        if ($event !== null) {
            echo "event: {$event}\n";
        }
        foreach (explode("\n", $data) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
        self::flush();
    }

    public static function sendChunk(string $s): void
    {
        echo $s;
        self::flush();
    }

    public static function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    public static function error(string $message, int $code = 400, string $type = 'invalid_request_error'): void
    {
        self::json(['error' => ['message' => $message, 'type' => $type]], $code);
    }
}
