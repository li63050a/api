<?php
/**
 * 配置（返回数组）。虚拟主机请把 data/ 放在 web 根之外更安全。
 */
return [
    'db_path' => __DIR__ . '/data/app.db',
    'log_dir' => __DIR__ . '/logs',
    'debug'   => false,

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

    // 性能与可观测性
    'cache_dir'               => __DIR__ . '/data/cache',
    'cache_ttl_seconds'       => 15,      // 聚合查询缓存时长
    'request_log_sample_rate' => 1.0,     // 请求日志采样率 0~1（1=全量）
    'request_log_errors_only' => false,   // true=仅记录错误(>=400)与异常请求
    'metrics_enabled'         => true,

    // 上游 Key 熔断
    'key_cooldown_seconds'    => 300,     // 连续失败后冷却时长（自动恢复）

    // 告警与访问控制
    'alert_webhook'           => '',       // 失败告警 Webhook（留空关闭）
    'admin_allowed_ips'       => '',       // 允许访问后台的 IP 白名单（逗号分隔，空=不限制）
    'trusted_proxies'         => '',       // 可信反向代理 IP（逗号分隔）；仅这些 IP 连接时才解析 X-Forwarded-For
];
