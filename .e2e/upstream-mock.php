<?php
// 本地 mock 上游：模拟 OpenAI 兼容 /v1/chat/completions
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/upstream/v1/chat/completions') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $model = $in['model'] ?? 'mock-chat';
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'chatcmpl-mock',
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'mock reply from upstream'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12],
    ]);
    exit;
}
http_response_code(404);
echo '{}';
