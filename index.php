<?php
/**
 * 入口（兼容无重写的虚拟主机；base_url = https://host/index.php）
 */
require_once __DIR__ . '/core.php';

$req = new AppRequest();

try {
    db()->query("SELECT 1 FROM model_map LIMIT 1");
} catch (\Throwable $e) {
    require_once __DIR__ . '/schema.php';
    install_schema(db());
}

$routes = require __DIR__ . '/routes.php';
$router = new AppRouter($routes);
$router->dispatch($req);
