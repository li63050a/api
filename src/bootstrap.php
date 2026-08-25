<?php
declare(strict_types=1);

namespace App;

use App\Support\Config;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class Bootstrap
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $configData = require dirname(__DIR__) . '/config.php';
        $config = new Config($configData);
        $container = new Container();
        $container->set(Config::class, $config);
        self::$container = $container;
        return $container;
    }

    /** 测试用：清空并重建容器 */
    public static function reset(): void
    {
        self::$container = null;
    }
}
