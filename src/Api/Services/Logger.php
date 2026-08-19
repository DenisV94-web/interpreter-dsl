<?php

namespace Api\Services;

/**
 * Class Logger
 * 
 * Standalone-версия корпоративного Logger для тестов на ПК.
 * 
 * Контракт (совпадает с корпоративным Api\Services\Logger):
 * - setRequest(string)  → строка запроса (JSON из buildLogRequest)
 * - setInfo(string)     → строка ответа (JSON из buildLogResponse)
 * - setStatus(string)   → SUCCESS / ERROR
 * - setSection(string)  → секция
 * - setMethod(string)   → метод
 * - log()               → запись (в файл в standalone)
 * 
 * @package Api\Services
 */
class Logger
{
    private string $section = '';
    private string $method = '';
    private string $request = '';
    private string $info = '';
    private string $status = 'SUCCESS';

    public function __construct(array $logData = [])
    {
        // Совместимость сигнатуры с корпоративным Logger
    }

    public function setSection(string $section): self
    {
        $this->section = $section;
        return $this;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function setRequest(string $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setInfo(string $info): self
    {
        $this->info = $info;
        return $this;
    }

    public function log(): void
    {
        // Standalone: пишем в файл или никуда — главное, сигнатура совпадает
        // В тестах реальная запись не нужна (SpyInterpreter перехватывает emitLog)
    }

    /**
     * Получить состояние — для отладки/тестов
     */
    public function getLogData(): array
    {
        return [
            'section' => $this->section,
            'method'  => $this->method,
            'request' => $this->request,
            'info'    => $this->info,
            'status'  => $this->status,
        ];
    }
}
