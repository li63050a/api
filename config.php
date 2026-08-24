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

    // 后台安全与运维
    'admin_session_lifetime'   => 7200,   // 管理员会话有效期（秒），超时需重新登录
    'admin_login_max_attempts' => 5,      // 登录失败次数上限
    'admin_login_lock_seconds' => 300,    // 触发上限后的锁定时长（秒）
    'dashboard_refresh_seconds' => 30,    // 仪表盘自动刷新间隔（0 = 关闭）
    'log_retention_days'       => 30,     // 请求日志保留天数（0 = 不自动清理）
];
