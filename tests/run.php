<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/Test/*.php') as $file) {
    require $file;
}

exit(Tests\Framework::summary() === 0 ? 0 : 1);
