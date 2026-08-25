<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$kernel = \App\Bootstrap::kernel();
$kernel->handle(\App\Http\Request::fromGlobals())->send();