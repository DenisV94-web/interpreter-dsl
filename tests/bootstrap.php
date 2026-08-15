<?php

/**
 * Bootstrap для запуска тестов БЕЗ Битрикса (на ПК)
 * 
 * Все define защищены — файл можно подключать повторно
 * и из раннера, и из примеров.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('INTERPRETER_CLI')) {
    define('INTERPRETER_CLI', true);
}

// Логи тестов и интерпретатора — в tests/logs/
if (!defined('INTERPRETER_TESTS_LOG_DIR')) {
    define('INTERPRETER_TESTS_LOG_DIR', __DIR__ . '/logs');
}

if (!defined('INTERPRETER_LOG_DIR')) {
    define('INTERPRETER_LOG_DIR', __DIR__ . '/logs');
}

require_once __DIR__ . '/../src/autoload.php';
