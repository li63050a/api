<?php
/**
 * 路由表（扁平类名；客户端 base_url = https://host/index.php）
 * 下游多格式：调用方用 X-Client-Format 头指定 openai/anthropic/gemini
 */
return [
    [
        'prefix'   => '/v1/models',
        'handler'  => 'HdlModelList',
        'middleware' => ['MwClientFormat', 'MwAuth', 'MwRateLimit'],
    ],
    [
        'prefix'   => '/v1/chat/completions',
        'handler'  => 'HdlChat',
        'middleware' => ['MwClientFormat', 'MwAuth', 'MwRateLimit', 'MwModelAlias'],
    ],
    [
        'prefix'   => '/v1/embeddings',
        'handler'  => 'HdlEmbed',
        'middleware' => ['MwClientFormat', 'MwAuth', 'MwRateLimit', 'MwModelAlias'],
    ],
    [
        'prefix'   => '/admin',
        'handler'  => 'AdminDispatcher',
        'middleware' => ['MwAdminAuth'],
    ],
];
