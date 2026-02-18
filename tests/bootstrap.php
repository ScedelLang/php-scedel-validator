<?php

declare(strict_types=1);

$workspaceRoot = dirname(__DIR__, 2);
require $workspaceRoot . '/scedel/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($workspaceRoot): void {
    $prefixes = [
        'Scedel\\Schema\\' => $workspaceRoot . '/scedel-schema/src/',
        'Scedel\\Validator\\' => $workspaceRoot . '/scedel-validator/src/',
    ];

    foreach ($prefixes as $prefix => $basePath) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $basePath . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }

        return;
    }
});
