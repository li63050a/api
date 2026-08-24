<?php
// 测试引导：用临时库与缓存目录隔离，避免污染真实数据
putenv('AI_API_DB_PATH=' . sys_get_temp_dir() . '/aiapi_test.db');
putenv('AI_API_CACHE_DIR=' . sys_get_temp_dir() . '/aiapi_cache');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../core.php';
