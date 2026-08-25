<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Framework.php';

// 所有测试在临时目录内运行，避免污染仓库
define('TESTS_TMP', sys_get_temp_dir() . '/aiapi_tests_' . getmypid());
if (!is_dir(TESTS_TMP) && !mkdir(TESTS_TMP, 0777, true) && !is_dir(TESTS_TMP)) {
    throw new RuntimeException('cannot create tests tmp dir');
}
