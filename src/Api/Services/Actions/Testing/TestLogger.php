<?php

namespace Api\Services\Actions\Testing;

/**
 * Class TestLogger
 * 
 * Логгер для тестирования интерпретатора.
 * Записывает каждый шаг выполнения в файл с временными метками.
 * 
 * Путь к логам: /local/local_logs/interpreter_tests/{TestName}_{date}.log
 * 
 * Формат записи:
 * [2026-08-07 14:30:00.123456] [INFO] [ClassName::methodName] Сообщение
 *     Data: {"key": "value"}
 * 
 * ВНИМАНИЕ: Этот логгер работает только в режиме тестирования.
 * В продакшене интерпретатор использует Api\Services\Logger
 * 
 * @package Api\Services\Actions\Testing
 */
class TestLogger
{
    /**
     * Путь к директории с логами
     * 
     * @var string
     */
    private string $logDir;

    /**
     * Путь к текущему лог-файлу
     * 
     * @var string
     */
    private string $logFile;

    /**
     * Флаг включения/отключения логирования
     * 
     * @var bool
     */
    private bool $enabled;

    /**
     * Счётчик записей
     * 
     * @var int
     */
    private int $entryCount = 0;

    /**
     * Максимальное количество записей в одном файле
     * Защита от переполнения при рекурсивных вызовах
     * 
     * @var int
     */
    private int $maxEntries = 10000;

    /**
     * TestLogger constructor.
     * 
     * Работает в обоих окружениях:
     * - Standalone (ПК): путь из константы INTERPRETER_TESTS_LOG_DIR
     * - Bitrix (сервер): путь из $_SERVER['DOCUMENT_ROOT']
     * - Фолбэк: временная директория ОС
     * 
     * @param string $testName Название теста (префикс имени файла)
     * @param bool $enabled Включено ли логирование
     */
    public function __construct(string $testName = 'default', bool $enabled = true)
    {
        $this->enabled = $enabled;

        // ГАРАНТИРОВАННАЯ инициализация пути в любом окружении
        if (defined('INTERPRETER_TESTS_LOG_DIR')) {
            $this->logDir = INTERPRETER_TESTS_LOG_DIR;
        } elseif (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            $this->logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/local_logs/interpreter_tests/';
        } else {
            $this->logDir = sys_get_temp_dir() . '/interpreter_tests';
        }

        // Нормализуем слэш и создаём директорию
        $this->logDir = rtrim(str_replace('\\', '/', $this->logDir), '/') . '/';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        // Имя файла: Field_2026-08-15_18-05-32.log
        $this->logFile = $this->logDir . $testName . '_' . date('Y-m-d_H-i-s') . '.log';

        $this->writeHeader($testName);
    }

    /**
     * Записывает заголовок лог-файла
     * 
     * @param string $testName Название теста
     * @return void
     */
    private function writeHeader(string $testName): void
    {
        if (!$this->enabled) return;

        $header = str_repeat('=', 80) . "\n";
        $header .= "INTERPRETER TEST LOG\n";
        $header .= "Test Name:     {$testName}\n";
        $header .= "Start Time:    " . date('Y-m-d H:i:s') . "\n";
        $header .= "PHP Version:   " . PHP_VERSION . "\n";
        $header .= "Memory Limit:  " . ini_get('memory_limit') . "\n";
        $header .= "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
        $header .= str_repeat('=', 80) . "\n\n";

        file_put_contents($this->logFile, $header, FILE_APPEND | LOCK_EX);
    }

    /**
     * Записывает информационное сообщение
     * 
     * @param string $class Имя класса (Field, Context, Condition)
     * @param string $method Имя метода (resolve, get, evaluate)
     * @param string $message Сообщение
     * @param mixed $data Дополнительные данные (JSON)
     * @return void
     */
    public function info(string $class, string $method, string $message, $data = null): void
    {
        $this->write('INFO', $class, $method, $message, $data);
    }

    /**
     * Записывает сообщение об ошибке
     * 
     * @param string $class Имя класса
     * @param string $method Имя метода
     * @param string $message Сообщение об ошибке
     * @param mixed $data Дополнительные данные
     * @return void
     */
    public function error(string $class, string $method, string $message, $data = null): void
    {
        $this->write('ERROR', $class, $method, $message, $data);
    }

    /**
     * Записывает успешный результат
     * 
     * @param string $class Имя класса
     * @param string $method Имя метода
     * @param string $message Сообщение
     * @param mixed $data Результат
     * @return void
     */
    public function success(string $class, string $method, string $message, $data = null): void
    {
        $this->write('SUCCESS', $class, $method, $message, $data);
    }

    /**
     * Записывает разделитель между тестами
     * 
     * @param string $testName Название теста
     * @return void
     */
    public function separator(string $testName): void
    {
        if (!$this->enabled) return;

        $sep = "\n" . str_repeat('-', 60) . "\n";
        $sep .= "TEST: {$testName}\n";
        $sep .= str_repeat('-', 60) . "\n";

        file_put_contents($this->logFile, $sep, FILE_APPEND | LOCK_EX);
    }

    /**
     * Внутренний метод записи в лог
     * 
     * @param string $level Уровень (INFO, ERROR, SUCCESS)
     * @param string $class Класс
     * @param string $method Метод
     * @param string $message Сообщение
     * @param mixed $data Данные для логирования
     * @return void
     */
    private function write(string $level, string $class, string $method, string $message, $data = null): void
    {
        if (!$this->enabled) return;

        // Защита от переполнения
        if ($this->entryCount >= $this->maxEntries) {
            return;
        }

        $this->entryCount++;

        // Временная метка с микросекундами
        $timestamp = date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 6);

        // Формируем строку лога
        $location = "[{$class}::{$method}]";
        $line = "[{$timestamp}] [{$level}] {$location} {$message}\n";

        // Если есть данные, форматируем в JSON с отступами
        if ($data !== null) {
            $json = $this->formatData($data);
            $jsonLines = explode("\n", $json);
            foreach ($jsonLines as $jsonLine) {
                $line .= "    {$jsonLine}\n";
            }
        }

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Форматирует данные для вывода в лог
     * 
     * @param mixed $data Данные для форматирования
     * @return string JSON-представление данных
     */
    private function formatData($data): string
    {
        if (is_string($data)) {
            return '"' . $data . '"';
        }

        if (is_null($data)) {
            return 'null';
        }

        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        if (is_object($data)) {
            return '[OBJECT: ' . get_class($data) . ']';
        }

        if (is_resource($data)) {
            return '[RESOURCE: ' . get_resource_type($data) . ']';
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false) {
            return '[UNSERIALIZABLE: ' . gettype($data) . ']';
        }

        return $json;
    }

    /**
     * Возвращает путь к лог-файлу
     * 
     * @return string Абсолютный путь к лог-файлу
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Возвращает количество записанных строк
     * 
     * @return int
     */
    public function getEntryCount(): int
    {
        return $this->entryCount;
    }

    /**
     * Записывает итоговый результат тестирования
     * 
     * @param int $passed Количество успешных тестов
     * @param int $failed Количество проваленных тестов
     * @return void
     */
    public function summary(int $passed, int $failed): void
    {
        if (!$this->enabled) return;

        $total = $passed + $failed;
        $percent = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

        $summary = "\n" . str_repeat('=', 80) . "\n";
        $summary .= "TEST SUMMARY\n";
        $summary .= str_repeat('-', 40) . "\n";
        $summary .= "Passed:  {$passed}\n";
        $summary .= "Failed:  {$failed}\n";
        $summary .= "Total:   {$total}\n";
        $summary .= "Result:  {$percent}%\n";
        $summary .= "Entries: {$this->entryCount}\n";
        $summary .= str_repeat('=', 80) . "\n";

        file_put_contents($this->logFile, $summary, FILE_APPEND | LOCK_EX);
    }

    /**
     * Деструктор — записывает время завершения
     */
    public function __destruct()
    {
        if ($this->enabled) {
            $footer = "\nLog closed at: " . date('Y-m-d H:i:s') . "\n";
            file_put_contents($this->logFile, $footer, FILE_APPEND | LOCK_EX);
        }
    }
}
