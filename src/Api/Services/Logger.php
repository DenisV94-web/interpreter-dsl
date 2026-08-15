<?php

namespace Api\Services;

/**
 * Class Logger
 * 
 * Минимальная standalone-версия: пишет JSON-строки в файл.
 * В продакшене подменяется корпоративным логгером (UF_REQUEST и т.д.).
 * API совпадает с тем, что использует Interpreter.
 * 
 * @package Api\Services
 */
class Logger
{
    private array $context;
    private string $section = '';
    private string $method = '';
    private string $request = '';
    private string $status = '';
    private string $info = '';

    public function __construct(array $context = [])
    {
        $this->context = $context;
    }

    public function setSection(string $section): void
    {
        $this->section = $section;
    }
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }
    public function setRequest(string $request): void
    {
        $this->request = $request;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setInfo(string $info): void
    {
        $this->info = $info;
    }

    /**
     * Пишет запись лога в файл
     * 
     * @return void
     */
    public function log(): void
    {
        $dir = defined('INTERPRETER_LOG_DIR') ? INTERPRETER_LOG_DIR : sys_get_temp_dir();

        $line = json_encode([
            'ts' => date('c'),
            'section' => $this->section,
            'method' => $this->method,
            'status' => $this->status,
            'info' => $this->info,
            'request' => $this->request,
            'context' => $this->context
        ], JSON_UNESCAPED_UNICODE);

        @file_put_contents($dir . '/interpreter.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
