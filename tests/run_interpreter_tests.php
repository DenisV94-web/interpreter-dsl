<?php

/**
 * Runner тестов DSL-интерпретатора
 * 
 * Работает в двух режимах (автоопределение):
 *   1. Автономный (на ПК, без Битрикс): 
 *      php tests/run_interpreter_tests.php
 *      Использует tests/bootstrap.php + локальный autoload.
 *   
 *   2. В составе Битрикс24 (на сервере):
 *      php local/tests/run_interpreter_tests.php
 *      Подключает prolog_before.php, использует корпоративный autoload.
 * 
 * Лог-файлы:
 *   - Автономный:  tests/logs/
 *   - Битрикс:    /local/local_logs/interpreter_tests/
 * 
 * @package Api\Services\Actions\Testing
 */

// ============================================================
// 1. АВТООПРЕДЕЛЕНИЕ ОКРУЖЕНИЯ
// ============================================================

// Корень проекта — на уровень выше tests/
$projectRoot = realpath(__DIR__ . '/..');

// Признак Битрикс-окружения: есть ядро и локальный корпоративный autoload
$isBitrix = file_exists($projectRoot . '/bitrix/modules/main/include/prolog_before.php')
    && file_exists($projectRoot . '/local/src/autoload.php');

// ============================================================
// 2. BOOTSTRAP (разные пути для разных окружений)
// ============================================================

if ($isBitrix) {
    // Битрикс: константы ускорения + ядро
    define('NO_KEEP_STATISTIC', true);
    define('NOT_CHECK_PERMISSIONS', true);
    define('BX_NO_ACCELERATOR', true);
    define('NO_AGENT_CHECKS', true);
    define('BX_EVENT_LOG', false);
    define('STOP_STATISTICS', true);

    $_SERVER['DOCUMENT_ROOT'] = $projectRoot;
    require_once $projectRoot . '/bitrix/modules/main/include/prolog_before.php';

    // Путь для TestLogger на сервере
    $logDir = $projectRoot . '/local/local_logs/interpreter_tests';

    // Принудительная подгрузка (интерфейс ICurlLogic и класс CurlLogic в одном файле)
    $curlLogicPath = $projectRoot . '/local/src/Api/CurlLogic.php';
    if (file_exists($curlLogicPath)) {
        require_once $curlLogicPath;
    }

    // Фоллбэк: если корпоративный autoload не подключился
    if (!class_exists('Api\Services\Actions\Testing\TestLogger', false)) {
        $autoloadPath = $projectRoot . '/local/src/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
    }

    $bitrixVersion = defined('SM_VERSION') ? SM_VERSION : 'N/A';
} else {
    // Автономный режим: минимальный bootstrap
    $bootstrapPath = __DIR__ . '/bootstrap.php';
    if (file_exists($bootstrapPath)) {
        require_once $bootstrapPath;
    } else {
        die("ERROR: bootstrap.php не найден в " . __DIR__ . "\n");
    }

    $logDir = __DIR__ . '/logs';
    $bitrixVersion = 'N/A (standalone)';
}

// ============================================================
// 3. ПОДГОТОВКА ДИРЕКТОРИИ ЛОГОВ
// ============================================================

if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0777, true)) {
        die("ERROR: Не удалось создать директорию: {$logDir}\n");
    }
}

if (!is_writable($logDir)) {
    die("ERROR: Директория не доступна для записи: {$logDir}\n");
}

// ============================================================
// 4. КОНФИГУРАЦИЯ ТЕСТОВ (единый список, без дублирования)
// ============================================================

use Api\Services\Actions\Testing\FieldTest;
use Api\Services\Actions\Testing\ConditionTest;
use Api\Services\Actions\Testing\MethodTest;
use Api\Services\Actions\Testing\RequestTest;
use Api\Services\Actions\Testing\MappingTest;
use Api\Services\Actions\Testing\ExecuteTest;
use Api\Services\Actions\Testing\InterpreterTest;
use Api\Services\Actions\Testing\CurlTest;
use Api\Services\Actions\Testing\ComposeTest;
use Api\Services\Actions\Testing\ArrayFilterTest;

$testClasses = [
    'Field Resolver'       => FieldTest::class,
    'Condition Resolver'   => ConditionTest::class,
    'Method Resolver'      => MethodTest::class,
    'Request Executor'     => RequestTest::class,
    'Mapping Executor'     => MappingTest::class,
    'Execute Executor'     => ExecuteTest::class,
    'Interpreter (integr.)' => InterpreterTest::class,
    'Curl Executor'        => CurlTest::class,
    'Compose Executor'     => ComposeTest::class,
    'Array Filter Service' => ArrayFilterTest::class
];

// ============================================================
// 5. ШАПКА ВЫВОДА
// ============================================================

echo "\n";
echo "==================================================\n";
echo "  INTERPRETER TESTS RUNNER\n";
echo "==================================================\n";
echo "Date:            " . date('Y-m-d H:i:s') . "\n";
echo "Mode:            " . ($isBitrix ? 'Bitrix' : 'Standalone') . "\n";
echo "PHP Version:     " . PHP_VERSION . "\n";
echo "Bitrix Version:  " . $bitrixVersion . "\n";
echo "Project Root:    " . $projectRoot . "\n";
echo "Log Directory:   " . $logDir . "\n";
echo "Memory Usage:    " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Tests to run:    " . count($testClasses) . "\n";
echo "==================================================\n\n";

// ============================================================
// 6. ЗАПУСК ТЕСТОВ С АГРЕГАЦИЕЙ
// ============================================================

$totalPassed = 0;
$totalFailed = 0;
$summary = [];

try {
    $index = 0;
    $total = count($testClasses);

    foreach ($testClasses as $title => $className) {
        $index++;
        echo "[{$index}/{$total}] Running {$title} tests...\n";

        if (!class_exists($className)) {
            echo "  ✗ Class not found: {$className}\n\n";
            $summary[] = ['title' => $title, 'passed' => 0, 'failed' => 1];
            $totalFailed++;
            continue;
        }

        $test = new $className();
        $test->runAll();

        // Читаем private-счётчики через Reflection (свойства в тестах private)
        $readStat = static function (object $object, string $property): int {
            if (!property_exists($object, $property)) {
                return 0;
            }
            $reflection = new \ReflectionProperty($object, $property);
            $reflection->setAccessible(true);
            return (int) $reflection->getValue($object);
        };

        $passed = $readStat($test, 'passed');
        $failed = $readStat($test, 'failed');

        $totalPassed += $passed;
        $totalFailed += $failed;

        $summary[] = [
            'title'  => $title,
            'passed' => $passed,
            'failed' => $failed,
        ];

        echo "\n";
    }
} catch (\Throwable $e) {
    echo "\n!!! FATAL ERROR !!!\n";
    echo "Class:   " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File:    " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// ============================================================
// 7. ИТОГОВАЯ СВОДКА
// ============================================================

echo "==================================================\n";
echo "  SUMMARY\n";
echo "==================================================\n";
echo str_pad('Test group', 30) . str_pad('Passed', 10) . str_pad('Failed', 10) . "\n";
echo "--------------------------------------------------\n";

foreach ($summary as $row) {
    echo str_pad($row['title'], 30);
    echo str_pad((string)$row['passed'], 10);
    echo str_pad((string)$row['failed'], 10);
    echo "\n";
}

echo "--------------------------------------------------\n";
echo str_pad('TOTAL', 30);
echo str_pad((string)$totalPassed, 10);
echo str_pad((string)$totalFailed, 10);
echo "\n";
echo "--------------------------------------------------\n";
echo "  Memory Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "  Log dir:     " . $logDir . "\n";
echo "==================================================\n\n";

// Код возврата для CI
exit($totalFailed > 0 ? 1 : 0);
