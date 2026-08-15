<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Executor\Curl;

/**
 * Class CurlTest
 * 
 * Тесты для класса Curl (Executor).
 * 
 * Curl Executor — исполнитель cURL-запросов через \Api\ICurlLogic.
 * Используется в request.curl (получение данных) и execute actions (отправка).
 * 
 * Что проверяется:
 * - GET-запрос с шаблонами {{params.*}} в url и headers
 * - POST-запрос с field-параметрами в теле
 * - Обработка ERROR с on_error (fallback без ошибки контекста)
 * - Обработка ERROR без on_error (ошибка в контекст с путём)
 * - conditions: при ложном условии запрос не выполняется (SKIPPED)
 * 
 * Тесты идут БЕЗ реальных сетевых запросов — через MockCurlLogic,
 * который реализует \Api\ICurlLogic и возвращает ответы из очереди.
 * 
 * Лог: Curl_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 5
 * 
 * @package Api\Services\Actions\Testing
 */
class CurlTest
{
    /**
     * Логгер тестов (файловый)
     * 
     * @var TestLogger
     */
    private TestLogger $logger;

    /**
     * Счётчик успешных тестов
     * 
     * @var int
     */
    private int $passed = 0;

    /**
     * Счётчик проваленных тестов
     * 
     * @var int
     */
    private int $failed = 0;

    /**
     * CurlTest constructor.
     * 
     * Создаёт логгер с префиксом 'Curl' — все записи идут
     * в файл Curl_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Curl');
    }

    /**
     * Запускает все тесты Curl Executor
     * 
     * Порядок логический:
     * - GET с шаблонами (1)
     * - ERROR + on_error (2)
     * - ERROR без on_error (3)
     * - conditions false (4)
     * - POST с field-параметрами (5)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('CurlTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Curl ===');

        $this->testGetSuccessWithPlaceholders();
        $this->testGetErrorWithOnError();
        $this->testGetErrorWithoutOnError();
        $this->testConditionsFalseSkips();
        $this->testPostParamsResolved();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "CURL TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // GET С ШАБЛОНАМИ (тест 1)
    // ========================================================

    /**
     * Тест 1: GET-запрос с шаблонами {{params.*}} в url и headers
     * 
     * Контекст: params = {api_base_url, api_token}.
     * Конфиг: url = '{{params.api_base_url}}/tasks/new',
     * headers = ['Authorization: {{params.api_token}}'].
     * Мок возвращает SUCCESS с data.
     * Ожидание:
     * - url в вызове = 'https://api.test/tasks/new' (шаблон разрешён)
     * - headers = ['Authorization: Bearer xyz'] (шаблон разрешён)
     * - результат записан в контекст (tasks.data)
     * 
     * @return void
     */
    private function testGetSuccessWithPlaceholders(): void
    {
        $this->logger->separator('testGetSuccessWithPlaceholders');

        $mock = new MockCurlLogic();
        $mock->queue[] = [
            'status' => ['code' => 'SUCCESS', 'message' => ''],
            'data' => [['id' => 1, 'name' => 'Task 1']]
        ];

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $context->setParams([
            'api_base_url' => 'https://api.test',
            'api_token' => 'Bearer xyz'
        ]);

        $field = new Field($context);
        $condition = new Condition($context, $field);
        $curl = new Curl($context, $field, $condition, $mock);

        $curl->execute([
            'tasks' => [
                'url' => '{{params.api_base_url}}/tasks/new',
                'method' => 'GET',
                'headers' => ['Authorization: {{params.api_token}}']
            ]
        ]);

        $this->assert(
            'testGet_Url',
            'https://api.test/tasks/new',
            $mock->calls[0]['url'] ?? null,
            'URL должен собраться из шаблона с params',
            ['url' => '{{params.api_base_url}}/tasks/new']
        );

        $this->assert(
            'testGet_Header',
            ['Authorization: Bearer xyz'],
            $mock->calls[0]['headers'] ?? null,
            'Headers должны быть разрешены из шаблонов',
            ['headers' => ['Authorization: {{params.api_token}}']]
        );

        $this->assert(
            'testGet_Data',
            [['id' => 1, 'name' => 'Task 1']],
            ($context->get('tasks') ?? [])['data'] ?? null,
            'Результат запроса должен быть записан в контекст',
            ['check' => "context.get('tasks').data"]
        );
    }
    
    // ========================================================
    // ERROR + ON_ERROR (тест 2)
    // ========================================================

    /**
     * Тест 2: ERROR + on_error — fallback, ошибки в контексте НЕТ
     * 
     * Мок возвращает ERROR (имитация Connection timeout).
     * Конфиг: on_error = {status: ERROR, data: []}.
     * Ожидание:
     * - context.hasError() = false (on_error подавил ошибку)
     * - tasks.data = [] (применён fallback)
     * Итерация продолжается в режиме partial.
     * 
     * @return void
     */
    private function testGetErrorWithOnError(): void
    {
        $this->logger->separator('testGetErrorWithOnError');

        $mock = new MockCurlLogic();
        $mock->queue[] = [
            'status' => ['code' => 'ERROR', 'message' => 'Connection timeout'],
            'data' => []
        ];

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);
        $curl = new Curl($context, $field, $condition, $mock);

        $curl->execute([
            'tasks' => [
                'url' => 'https://api.test/tasks',
                'on_error' => ['status' => ['code' => 'ERROR', 'message' => ''], 'data' => []]
            ]
        ]);

        $this->assert(
            'testOnError_NoContextError',
            false,
            $context->hasError(),
            'Ошибки в контексте быть не должно при on_error',
            ['check' => 'hasError() === false']
        );

        $this->assert(
            'testOnError_Fallback',
            [],
            ($context->get('tasks') ?? [])['data'] ?? null,
            'Должен примениться fallback из on_error',
            ['on_error' => 'data: []']
        );
    }
    
    // ========================================================
    // ERROR БЕЗ ON_ERROR (тест 3)
    // ========================================================

    /**
     * Тест 3: ERROR без on_error — ошибка записывается в контекст
     * 
     * Мок возвращает ERROR, on_error в конфиге нет.
     * Ожидание:
     * - context.hasError() = true
     * - путь ошибки = 'request.curl.tasks' (для точной диагностики)
     * В одиночном режиме это глобальная ошибка, в итерационном —
     * ошибка конкретной итерации.
     * 
     * @return void
     */
    private function testGetErrorWithoutOnError(): void
    {
        $this->logger->separator('testGetErrorWithoutOnError');

        $mock = new MockCurlLogic();
        $mock->queue[] = [
            'status' => ['code' => 'ERROR', 'message' => 'Connection timeout'],
            'data' => []
        ];

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);
        $curl = new Curl($context, $field, $condition, $mock);

        $curl->execute(['tasks' => ['url' => 'https://api.test/tasks']], 'request.curl');

        $this->assert(
            'testError_HasError',
            true,
            $context->hasError(),
            'Должна быть ошибка контекста без on_error',
            ['check' => 'hasError() === true']
        );

        $this->assert(
            'testError_Path',
            'request.curl.tasks',
            $context->error['config_path'] ?? null,
            'Путь ошибки должен быть request.curl.tasks',
            ['check' => "error.config_path"]
        );
    }
    
    // ========================================================
    // CONDITIONS FALSE (тест 4)
    // ========================================================

    /**
     * Тест 4: conditions false → SKIPPED, запрос не выполняется
     * 
     * Контекст: flag = 0.
     * Конфиг: conditions = [field:flag => 1] (ложно).
     * Ожидание:
     * - MockCurlLogic не делает ни одного вызова (calls = 0)
     * - tasks.status.code = 'SKIPPED'
     * 
     * @return void
     */
    private function testConditionsFalseSkips(): void
    {
        $this->logger->separator('testConditionsFalseSkips');

        $mock = new MockCurlLogic();

        $context = new Context(['flag' => 0]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);
        $curl = new Curl($context, $field, $condition, $mock);

        $curl->execute([
            'tasks' => [
                'url' => 'https://api.test/tasks',
                'conditions' => ['field:flag' => 1]
            ]
        ]);

        $this->assert(
            'testSkip_NoCalls',
            0,
            count($mock->calls),
            'Запрос не должен выполняться при ложном условии',
            ['check' => 'count(mock.calls) === 0']
        );

        $this->assert(
            'testSkip_Status',
            'SKIPPED',
            ($context->get('tasks') ?? [])['status']['code'] ?? null,
            'Статус должен быть SKIPPED',
            ['check' => "tasks.status.code === 'SKIPPED'"]
        );
    }
    
    // ========================================================
    // POST С FIELD-ПАРАМЕТРАМИ (тест 5)
    // ========================================================

    /**
     * Тест 5: POST с field-параметрами в теле
     * 
     * Контекст: client_name = 'Иван', dep_id = 453.
     * Конфиг: method POST, params = {client: field:client_name,
     * department: field:dep_id}.
     * Ожидание:
     * - метод вызова = POST
     * - fields = {client: 'Иван', department: 453} (field: разрешены)
     * 
     * @return void
     */
    private function testPostParamsResolved(): void
    {
        $this->logger->separator('testPostParamsResolved');

        $mock = new MockCurlLogic();
        $mock->queue[] = [
            'status' => ['code' => 'SUCCESS', 'message' => ''],
            'data' => ['id' => 777]
        ];

        $context = new Context(['client_name' => 'Иван', 'dep_id' => 453]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);
        $curl = new Curl($context, $field, $condition, $mock);

        $curl->execute([
            'create_task' => [
                'url' => 'https://api.test/tasks',
                'method' => 'POST',
                'params' => [
                    'client' => 'field:client_name',
                    'department' => 'field:dep_id'
                ]
            ]
        ]);

        $this->assert(
            'testPost_Method',
            'POST',
            $mock->calls[0]['method'] ?? null,
            'Метод вызова должен быть POST',
            ['check' => "calls[0].method === 'POST'"]
        );

        $this->assert(
            'testPost_Fields',
            ['client' => 'Иван', 'department' => 453],
            $mock->calls[0]['fields'] ?? null,
            'Параметры тела должны быть разрешены из контекста',
            ['params' => 'field:client_name, field:dep_id']
        );
    }
    
    // ========================================================
    // УТИЛИТА ПРОВЕРКИ
    // ========================================================

    /**
     * Утилита для проверки результатов тестов
     * 
     * Сравнивает expected и actual строгим сравнением (===).
     * При совпадении: passed++, SUCCESS в лог.
     * При несовпадении: failed++, ERROR в лог.
     * 
     * @param string $testName Имя теста (для идентификации в логе)
     * @param mixed $expected Ожидаемое значение
     * @param mixed $actual Фактическое значение
     * @param string $message Человекочитаемое описание проверки
     * @param array $contextData Дополнительные данные для лога
     * @return void
     */
    private function assert(
        string $testName,
        $expected,
        $actual,
        string $message,
        array $contextData = []
    ): void {
        if ($expected === $actual) {
            $this->passed++;
            $this->logger->success('CurlTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('CurlTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}

// ============================================================
// МОК CURL (реализует \Api\ICurlLogic)
// ============================================================

/**
 * Class MockCurlLogic
 * 
 * Мок для тестов Curl Executor БЕЗ реальных сетевых запросов.
 * Реализует интерфейс \Api\ICurlLogic (сигнатуры совместимы
 * с реальной реализацией \Api\CurlLogic).
 * 
 * Принцип работы:
 * - Все вызовы записываются в $calls (method, url, headers, fields)
 * - Ответы берутся из очереди $queue (FIFO); если очередь пуста —
 *   возвращается SUCCESS с пустой data
 * 
 * @package Api\Services\Actions\Testing
 */
class MockCurlLogic extends \Api\CurlLogic
{
    /**
     * Запись всех вызовов (для проверок url/headers/fields)
     * 
     * @var array
     */
    public array $calls = [];

    /**
     * Очередь заготовленных ответов (FIFO)
     * 
     * @var array
     */
    public array $queue = [];

    /**
     * Имитация GET-запроса
     * 
     * @param string $url URL запроса
     * @param array $headers Заголовки
     * @param int $timeout Таймаут
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Ответ из очереди
     */
    public function curlGet($url, $headers, $timeout, $headerInOut = false, $returnErrorResult = false)
    {
        return $this->record('GET', $url, $headers, null);
    }

    /**
     * Имитация POST-запроса
     * 
     * @param string $url URL запроса
     * @param array $headers Заголовки
     * @param int $timeout Таймаут
     * @param mixed $fields Данные тела
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Ответ из очереди
     */
    public function curlPost($url, $headers, $timeout, $fields, $headerInOut = false, $returnErrorResult = false)
    {
        return $this->record('POST', $url, $headers, $fields);
    }

    /**
     * Имитация PUT-запроса
     * 
     * @param string $url URL запроса
     * @param array $headers Заголовки
     * @param int $timeout Таймаут
     * @param mixed $fields Данные тела
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Ответ из очереди
     */
    public function curlPut($url, $headers, $timeout, $fields, $headerInOut = false, $returnErrorResult = false)
    {
        return $this->record('PUT', $url, $headers, $fields);
    }

    /**
     * Имитация DELETE-запроса
     * 
     * @param string $url URL запроса
     * @param array $headers Заголовки
     * @param int $timeout Таймаут
     * @param mixed $fields Данные тела
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Ответ из очереди
     */
    public function curlDelete($url, $headers, $timeout, $fields = null, $headerInOut = false, $returnErrorResult = false)
    {
        return $this->record('DELETE', $url, $headers, $fields);
    }

    /**
     * Имитация REST-запроса к CRM
     * 
     * @param string $code Код метода
     * @param string $method Название метода
     * @param array $fields Параметры запроса
     * @return array Ответ из очереди
     */
    public function executeREST($code, $method, $fields, $baseUrl = 'https://crm.example.ru/rest/1/')
    {
        return $this->record('REST', $code . '/' . $method, [], $fields);
    }

    /**
     * Записывает вызов и возвращает следующий ответ из очереди
     * 
     * @param string $method HTTP-метод (GET/POST/PUT/DELETE/REST)
     * @param string $url URL
     * @param array $headers Заголовки
     * @param mixed $fields Данные тела
     * @return array Ответ из очереди или SUCCESS по умолчанию
     */
    private function record(string $method, string $url, array $headers, $fields): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'fields' => $fields
        ];

        $next = array_shift($this->queue);

        return $next ?? [
            'status' => ['code' => 'SUCCESS', 'message' => ''],
            'data' => []
        ];
    }
}
