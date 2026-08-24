<?php
/**
 * 配置（返回数组）。虚拟主机请把 data/ 放在 web 根之外更安全。
 */
return [
    'db_path' => __DIR__ . '/data/app.db',
    'log_dir' => __DIR__ . '/logs',
    'debug'   => true,

    'upstream_timeout' => 120,
    'upstream_retry'   => 2,
    'rate_limit_per_minute' => 60,

    // 下游客户端接口格式（多模式）
    'client_formats' => ['openai', 'anthropic', 'gemini'],
    'default_client_format' => 'openai',

    'admin_seed' => [
        'username' => 'admin',
        'password' => 'change_me_now',
    ],
];
