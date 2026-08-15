<?php

/**
 * Автономный PSR-4 автозагрузчик (для ПК и тестов без Битрикса).
 * На сервере этот файл НЕ используется — там свой автозагрузчик.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Api\\';
    $baseDir = __DIR__ . '/Api/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
