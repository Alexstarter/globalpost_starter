<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlobalPostShipping\\';
    $baseDir = __DIR__ . '/../modules/globalpostshipping/src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
