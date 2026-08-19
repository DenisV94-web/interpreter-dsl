<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Interpreter;

/**
 * Class InterpreterTest
 * 
 * Интеграционные тесты для Interpreter — главного оркестратора.
 * 
 * Interpreter связывает все компоненты в единый конвейер:
 * request → mapping → execute (или compose), управляет контекстом,
 * итерациями, params, логированием и формированием ResponseHandler.
 * 
 * Что проверяется:
 * - Одиночный режим (request → mapping → execute)
 * - Итерационный режим (request.array) с накоплением response
 * - Итерационные ошибки (partial): цикл продолжается, ошибка в response[i]
 * - Глобальные ошибки (ConfigException): статус ERROR
 * - Конвертация имён: endpoint/actionName snake_case → CamelCase для логгера
 * - buildLogRequest: data + params + computed (всегда) + error (при ошибках)
 * - params: доступны во всех блоках через field:params.x / {{params.x}}
 * 
 * Лог: Interpreter_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 11
 * 
 * @package Api\Services\Actions\Testing
 */
class InterpreterTest
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
     * InterpreterTest constructor.
     * 
     * Создаёт логгер с префиксом 'Interpreter' — все записи идут
     * в файл Interpreter_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Interpreter');
    }

    /**
     * Запускает все тесты Interpreter
     * 
     * Порядок логический, от простого к сложному:
     * - Одиночный режим (1-2)
     * - Итерационный режим (3-4)
     * - Глобальные ошибки и skip (5-6)
     * - Конвертация имён (7)
     * - Логирование buildLogRequest (8-9)
     * - params (10)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('InterpreterTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Interpreter ===');

        // Одиночный режим
        $this->testSingleModeSimple();
        $this->testSingleModeWithMappingAndExecute();

        // Итерационный режим
        $this->testIterativeMode();
        $this->testIterativeModeWithError();

        // Глобальные ошибки и skip
        $this->testActionNotFound();
        $this->testSkipInExecute();

        // Конвертация имён
        $this->testEndpointAndMethodNameConversion();

        // Логирование
        $this->testLogRequestOnSuccess();
        $this->testLogRequestIterativeOnSuccess();
        $this->testStaticNotInLogComputed();

        // logging и критические ошибки (v1.3.1)
        $this->testLoggingFalseSilencesSuccess();
        $this->testLoggingFalseStillLogsCritical();
        $this->testLoggingTrueLogsCriticalOnce();
        $this->testLoggingTrueLogsSuccess();

        // params
        $this->testParamsAvailableEverywhere();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "INTERPRETER TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // ОДИНОЧНЫЙ РЕЖИМ (тесты 1-2)
    // ========================================================

    /**
     * Тест 1: Одиночный режим — простая обработка extra
     * 
     * Конфиг: request с extra (strtoupper от field:input_value).
     * Вход: input_value = 'hello world'.
     * Ожидание: context.processed_value = 'HELLO WORLD'.
     * 
     * @return void
     */
    private function testSingleModeSimple(): void
    {
        $this->logger->separator('testSingleModeSimple');

        $config = [
            'test_action' => [
                'request' => [
                    'main' => 'post',
                    'extra' => [
                        'processed_value' => [
                            'method' => 'strtoupper',
                            'params' => ['field:input_value']
                        ]
                    ]
                ],
                'action_logic' => ['request']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['input_value' => 'hello world']);
        $interpreter->run('test_action', 'desktop_manager');

        $this->assert(
            'testSingleModeSimple',
            'HELLO WORLD',
            $interpreter->getContext()->get('processed_value'),
            'Extra поле должно быть вычислено в одиночном режиме',
            ['action' => 'test_action']
        );
    }

    /**
     * Тест 2: Полный цикл request → mapping → execute
     * 
     * Конфиг: extra(date_now) → mapping(NAME/DATE/STATUS) →
     * execute(if !empty(client_name) → getMappingData с params ['mapping']).
     * Ожидание: response = [{created_data: {NAME, DATE, STATUS}}] —
     * весь маппинг передан в execute через 'mapping' placeholder.
     * 
     * @return void
     */
    private function testSingleModeWithMappingAndExecute(): void
    {
        $this->logger->separator('testSingleModeWithMappingAndExecute');

        $config = [
            'full_action' => [
                'request' => [
                    'main' => 'post',
                    'extra' => [
                        'date_now' => ['method' => 'date', 'params' => ['d.m.Y']]
                    ]
                ],
                'mapping' => [
                    'NAME' => 'field:client_name',
                    'DATE' => 'field:date_now',
                    'STATUS' => 'NEW'
                ],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['!field:client_name' => 'func:empty'],
                        'actions' => [
                            [
                                'method' => 'getMappingData',
                                'class' => MockInterpreterService::class,
                                'params' => ['mapping'],
                                'response' => ['created_data' => 'result']
                            ]
                        ]
                    ]
                ],
                'action_logic' => ['request', 'mapping', 'execute']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['client_name' => 'Test Client']);
        $responseHandler = $interpreter->run('full_action', 'desktop_manager');

        $expected = [[
            'created_data' => [
                'NAME' => 'Test Client',
                'DATE' => date('d.m.Y'),
                'STATUS' => 'NEW'
            ]
        ]];

        $this->assert(
            'testSingleModeWithMappingAndExecute',
            $expected,
            $responseHandler->response,
            'Полный цикл request → mapping → execute должен работать',
            ['action_logic' => 'request, mapping, execute']
        );
    }
    
    // ========================================================
    // ИТЕРАЦИОННЫЙ РЕЖИМ (тесты 3-4)
    // ========================================================

    /**
     * Тест 3: Итерационный режим (request.array)
     * 
     * Конфиг: array = 'items', extra upper_value, execute → response.
     * Вход: 3 итерации (first, second, third).
     * Ожидание: response = 3 записи INSTANCE:FIRST/SECOND/THIRD —
     * каждая итерация обработана, response накоплен.
     * 
     * @return void
     */
    private function testIterativeMode(): void
    {
        $this->logger->separator('testIterativeMode');

        $config = [
            'iterative_action' => [
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                    'extra' => [
                        'upper_value' => [
                            'method' => 'strtoupper',
                            'params' => ['field:value']
                        ]
                    ]
                ],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'method' => 'instanceTransform',
                                'class' => MockInterpreterService::class,
                                'params' => ['field:upper_value'],
                                'response' => ['result_value' => 'result']
                            ]
                        ]
                    ]
                ],
                'transaction' => ['enabled' => true, 'mode' => 'partial'],
                'action_logic' => ['request', 'execute']
            ]
        ];

        $inputData = [
            'items' => [
                ['value' => 'first'],
                ['value' => 'second'],
                ['value' => 'third']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode($inputData);
        $responseHandler = $interpreter->run('iterative_action', 'desktop_manager');

        $expected = [
            ['result_value' => 'INSTANCE:FIRST'],
            ['result_value' => 'INSTANCE:SECOND'],
            ['result_value' => 'INSTANCE:THIRD']
        ];

        $this->assert(
            'testIterativeMode',
            $expected,
            $responseHandler->response,
            'Каждая итерация должна быть обработана, response накоплен',
            ['iterations' => 3, 'mode' => 'partial']
        );
    }

    /**
     * Тест 4: Итерационный режим с ошибкой в одной итерации
     * 
     * Конфиг: if should_fail == 1 → nonExistentMethod (падает);
     * else → instanceTransform. Вход: 3 итерации, вторая с should_fail = 1.
     * Ожидание (режим partial):
     * - status = SUCCESS (итерационные ошибки не глобальные)
     * - 3 записи в response
     * - response[1].status = ERROR
     * - response[0] и response[2] = SUCCESS
     * Цикл НЕ прерывается на ошибке итерации.
     * 
     * @return void
     */
    private function testIterativeModeWithError(): void
    {
        $this->logger->separator('testIterativeModeWithError');

        $config = [
            'error_action' => [
                'request' => [
                    'main' => 'post',
                    'array' => 'items'
                ],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:should_fail' => 1],
                        'actions' => [
                            [
                                'method' => 'nonExistentMethod',
                                'class' => MockInterpreterService::class,
                                'params' => [],
                                'response' => ['result' => 'result']
                            ]
                        ]
                    ],
                    [
                        'check' => 'else',
                        'actions' => [
                            [
                                'method' => 'instanceTransform',
                                'class' => MockInterpreterService::class,
                                'params' => ['field:value'],
                                'response' => ['result_value' => 'result']
                            ]
                        ]
                    ]
                ],
                'transaction' => ['mode' => 'partial'],
                'action_logic' => ['request', 'execute']
            ]
        ];

        $inputData = [
            'items' => [
                ['value' => 'first', 'should_fail' => 0],
                ['value' => 'second', 'should_fail' => 1],
                ['value' => 'third', 'should_fail' => 0]
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode($inputData);
        $responseHandler = $interpreter->run('error_action', 'desktop_manager');

        $this->assert(
            'testIterativeModeWithError_Status',
            'SUCCESS',
            $responseHandler->status,
            'ResponseHandler должен быть SUCCESS (итерационные ошибки не глобальные)',
            ['check' => 'status === SUCCESS']
        );

        $this->assert(
            'testIterativeModeWithError_Count',
            3,
            count($responseHandler->response),
            'Должно быть 3 записи в response',
            ['check' => 'count(response) === 3']
        );

        $this->assert(
            'testIterativeModeWithError_SecondError',
            'ERROR',
            $responseHandler->response[1]['status'] ?? '',
            'Вторая итерация должна быть ERROR',
            ['check' => 'response[1].status === ERROR']
        );

        $this->assert(
            'testIterativeModeWithError_FirstSuccess',
            'INSTANCE:first',
            $responseHandler->response[0]['result_value'] ?? '',
            'Первая итерация должна быть SUCCESS',
            ['check' => 'response[0]']
        );

        $this->assert(
            'testIterativeModeWithError_ThirdSuccess',
            'INSTANCE:third',
            $responseHandler->response[2]['result_value'] ?? '',
            'Третья итерация должна быть SUCCESS (цикл не прервался)',
            ['check' => 'response[2]']
        );
    }
    
    // ========================================================
    // ГЛОБАЛЬНЫЕ ОШИБКИ И SKIP (тесты 5-6)
    // ========================================================

    /**
     * Тест 5: Действие не найдено в конфиге
     * 
     * Конфиг содержит только existing_action, запускаем non_existent_action.
     * Ожидание: status = ERROR — ConfigException перехвачена,
     * ResponseHandler с ошибкой, без фатального падения.
     * 
     * @return void
     */
    private function testActionNotFound(): void
    {
        $this->logger->separator('testActionNotFound');

        $config = [
            'existing_action' => [
                'request' => ['main' => 'post'],
                'action_logic' => ['request']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode([]);
        $responseHandler = $interpreter->run('non_existent_action', 'desktop_manager');

        $this->assert(
            'testActionNotFound',
            'ERROR',
            $responseHandler->status,
            'Несуществующее действие должно вернуть ERROR (ConfigException)',
            ['action' => 'non_existent_action']
        );
    }

    /**
     * Тест 6: Skip в execute (одиночный режим)
     * 
     * Конфиг: if skip_flag == 1 → skip.
     * Вход: skip_flag = 1.
     * Ожидание: response = [{status: SKIPPED, iteration: null}].
     * 
     * @return void
     */
    private function testSkipInExecute(): void
    {
        $this->logger->separator('testSkipInExecute');

        $config = [
            'skip_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:skip_flag' => 1],
                        'actions' => [['skip' => true]]
                    ]
                ],
                'action_logic' => ['request', 'execute']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['skip_flag' => 1]);
        $responseHandler = $interpreter->run('skip_action', 'desktop_manager');

        $expected = [['status' => 'SKIPPED', 'iteration' => null]];

        $this->assert(
            'testSkipInExecute',
            $expected,
            $responseHandler->response,
            'Skip должен записать SKIPPED в response',
            ['actions' => 'skip: true']
        );
    }
    
    // ========================================================
    // КОНВЕРТАЦИЯ ИМЁН (тест 7)
    // ========================================================

    /**
     * Тест 7: Конвертация endpoint и actionName (snake_case → CamelCase)
     * 
     * Запуск: run('create_lead', 'desktop_manager').
     * Ожидание: действие выполняется, контекст создан —
     * имена конвертируются для логгера (Interpreter:DesktopManager / CreateLead)
     * без падения.
     * 
     * @return void
     */
    private function testEndpointAndMethodNameConversion(): void
    {
        $this->logger->separator('testEndpointAndMethodNameConversion');

        $config = [
            'create_lead' => [
                'request' => ['main' => 'post'],
                'action_logic' => ['request']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode([]);
        $interpreter->run('create_lead', 'desktop_manager');

        $this->assert(
            'testEndpointAndMethodNameConversion',
            true,
            $interpreter->getContext() !== null,
            'Действие должно выполниться с конвертацией имён (desktop_manager → DesktopManager, create_lead → CreateLead)',
            ['endpoint' => 'desktop_manager', 'action' => 'create_lead']
        );
    }
    
    // ========================================================
    // ЛОГИРОВАНИЕ buildLogRequest (тесты 8-9)
    // ========================================================

    /**
     * Тест 8: buildLogRequest на SUCCESS
     * 
     * Конфиг: extra processed_value = strtoupper(input_value).
     * Ожидание в логе:
     * - data = сырой запрос
     * - computed = вычисленные поля (даже на SUCCESS)
     * - error отсутствует
     * 
     * @return void
     */
    private function testLogRequestOnSuccess(): void
    {
        $this->logger->separator('testLogRequestOnSuccess');

        $config = [
            'log_action' => [
                'request' => [
                    'main' => 'post',
                    'extra' => [
                        'processed_value' => [
                            'method' => 'strtoupper',
                            'params' => ['field:input_value']
                        ]
                    ]
                ],
                'action_logic' => ['request']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['input_value' => 'hello']);
        $interpreter->run('log_action', 'desktop_manager');

        $log = $interpreter->buildLogRequest();

        $this->assert(
            'testLogRequestOnSuccess_Data',
            ['input_value' => 'hello'],
            $log['data'] ?? null,
            'data должен содержать сырой запрос',
            ['check' => 'log.data']
        );

        $this->assert(
            'testLogRequestOnSuccess_Computed',
            ['processed_value' => 'HELLO'],
            $log['computed'] ?? null,
            'computed должен содержать вычисленные поля даже на SUCCESS',
            ['check' => 'log.computed']
        );

        $this->assert(
            'testLogRequestOnSuccess_NoError',
            false,
            isset($log['error']),
            'Ключа error не должно быть при успешном выполнении',
            ['check' => 'isset(log.error) === false']
        );
    }

    /**
     * Тест 9: buildLogRequest в итерационном режиме
     * 
     * Конфиг: array = 'items', extra upper_value.
     * Ожидание в логе:
     * - computed содержит снимки ВСЕХ итераций (iteration_0, iteration_1)
     * - снимок итерации содержит вычисленные поля
     * 
     * @return void
     */
    private function testLogRequestIterativeOnSuccess(): void
    {
        $this->logger->separator('testLogRequestIterativeOnSuccess');

        $config = [
            'iter_log_action' => [
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                    'extra' => [
                        'upper_value' => [
                            'method' => 'strtoupper',
                            'params' => ['field:value']
                        ]
                    ]
                ],
                'action_logic' => ['request']
            ]
        ];

        $inputData = [
            'items' => [
                ['value' => 'first'],
                ['value' => 'second']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode($inputData);
        $interpreter->run('iter_log_action', 'desktop_manager');

        $log = $interpreter->buildLogRequest();

        $this->assert(
            'testLogRequestIterativeOnSuccess_Iterations',
            ['iteration_0', 'iteration_1'],
            array_keys($log['computed'] ?? []),
            'computed должен содержать снимки всех итераций',
            ['check' => 'array_keys(log.computed)']
        );

        $this->assert(
            'testLogRequestIterativeOnSuccess_Value',
            'FIRST',
            $log['computed']['iteration_0']['upper_value'] ?? null,
            'Снимок итерации должен содержать вычисленные поля',
            ['check' => 'log.computed.iteration_0.upper_value']
        );
    }

    // ========================================================
    // LOGGING И КРИТИЧЕСКИЕ ОШИБКИ (v1.3.1)
    // ========================================================

    /**
     * Тест: logging=false + успех — в Logger тишина
     * 
     * @return void
     */
    private function testLoggingFalseSilencesSuccess(): void
    {
        $this->logger->separator('testLoggingFalseSilencesSuccess');

        $interpreter = new SpyInterpreter([
            'quiet_ok' => [
                'logging' => false,
                'request' => ['main' => 'post'],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('quiet_ok', 'test', ['a' => 1]);

        $this->assert(
            'testLoggingFalseSilencesSuccess',
            0,
            count($interpreter->logJournal),
            'logging=false: штатный успех не логируется',
            ['journal' => $interpreter->logJournal]
        );
    }

    /**
     * Тест: logging=false + критическая ошибка — Logger пишет ВСЕГДА
     * 
     * action_logic ссылается на отсутствующий блок mapping
     * → ConfigException в глобальном catch.
     * 
     * @return void
     */
    private function testLoggingFalseStillLogsCritical(): void
    {
        $this->logger->separator('testLoggingFalseStillLogsCritical');

        $interpreter = new SpyInterpreter([
            'quiet_bad' => [
                'logging' => false,
                'request' => ['main' => 'post'],
                'action_logic' => ['request', 'mapping'],   // mapping нет → ConfigException
            ],
        ]);

        $response = $interpreter->run('quiet_bad', 'test', []);

        $this->assert(
            'testLoggingFalseStillLogsCritical_Count',
            1,
            count($interpreter->logJournal),
            'logging=false: критическая ошибка залогирована ровно один раз',
            ['journal' => $interpreter->logJournal]
        );

        $this->assert(
            'testLoggingFalseStillLogsCritical_Status',
            'ERROR',
            $interpreter->logJournal[0]['status'] ?? null,
            'Критическая запись имеет статус ERROR',
            ['journal' => $interpreter->logJournal]
        );

        $this->assert(
            'testLoggingFalseStillLogsCritical_Response',
            'ERROR',
            $response->status,
            'Ответ клиенту тоже ERROR (поведение не изменилось)',
            ['status' => $response->status]
        );
    }

    /**
     * Тест: logging=true + критическая ошибка — ровно ОДНА запись
     * (раньше было две: logGlobalError + logResult)
     * 
     * @return void
     */
    private function testLoggingTrueLogsCriticalOnce(): void
    {
        $this->logger->separator('testLoggingTrueLogsCriticalOnce');

        $interpreter = new SpyInterpreter([
            'loud_bad' => [
                'request' => ['main' => 'post'],
                'action_logic' => ['request', 'mapping'],
            ],
        ]);

        $interpreter->run('loud_bad', 'test', []);

        $this->assert(
            'testLoggingTrueLogsCriticalOnce',
            1,
            count($interpreter->logJournal),
            'logging=true: критическая ошибка не дублируется',
            ['journal' => $interpreter->logJournal]
        );
    }

    /**
     * Тест: logging=true (по умолчанию) + успех — одна запись SUCCESS
     * 
     * @return void
     */
    private function testLoggingTrueLogsSuccess(): void
    {
        $this->logger->separator('testLoggingTrueLogsSuccess');

        $interpreter = new SpyInterpreter([
            'loud_ok' => [
                'request' => ['main' => 'post'],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('loud_ok', 'test', ['a' => 1]);

        $this->assert(
            'testLoggingTrueLogsSuccess_Count',
            1,
            count($interpreter->logJournal),
            'logging=true: штатный успех логируется одной записью',
            ['journal' => $interpreter->logJournal]
        );

        $this->assert(
            'testLoggingTrueLogsSuccess_Status',
            'SUCCESS',
            $interpreter->logJournal[0]['status'] ?? null,
            'Запись имеет статус SUCCESS',
            ['journal' => $interpreter->logJournal]
        );
    }

    /**
     * Тест: static не попадает в log.computed
     */
    private function testStaticNotInLogComputed(): void
    {
        $this->logger->separator('testStaticNotInLogComputed');

        $config = [
            'static_action' => [
                'request' => [
                    'main' => 'post',
                    'static' => [
                        'business_lines' => ['NEW_CAR' => 'Новый авто']
                    ],
                    'extra' => [
                        'some_value' => ['method' => 'strtoupper', 'params' => ['hello']]
                    ]
                ],
                'action_logic' => ['request']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['input' => 'x']);
        $interpreter->run('static_action', 'desktop_manager');

        $log = $interpreter->buildLogRequest();
        $computed = $log['computed'] ?? [];

        $this->assert(
            'testStatic_Excluded',
            false,
            array_key_exists('business_lines', $computed),
            'Static данные не должны попадать в computed',
            ['check' => 'computed.business_lines отсутствует']
        );

        $this->assert(
            'testStatic_ExtraIncluded',
            'HELLO',
            $computed['some_value'] ?? null,
            'Extra данные должны оставаться в computed',
            ['check' => 'computed.some_value']
        );

        $this->assert(
            'testStatic_AvailableInContext',
            ['NEW_CAR' => 'Новый авто'],
            $interpreter->getContext()->get('business_lines'),
            'Static данные доступны в контексте через field:',
            ['check' => 'context.business_lines']
        );
    }
    
    // ========================================================
    // PARAMS (тест 10)
    // ========================================================

    /**
     * Тест 10: params доступны во всех блоках
     * 
     * Запуск: run(..., null, ['prefix' => 'api_v2', 'token' => 'Bearer xyz']).
     * Конфиг: extra full_url = strtoupper(field:params.prefix),
     * mapping token_echo = field:params.token, url = {{params.prefix}}/tasks.
     * Ожидание:
     * - params работают в extra (field:params.x)
     * - params работают в mapping (field: и {{ }})
     * - params видны в buildLogRequest().params
     * 
     * @return void
     */
    private function testParamsAvailableEverywhere(): void
    {
        $this->logger->separator('testParamsAvailableEverywhere');

        $config = [
            'params_action' => [
                'request' => [
                    'main' => 'post',
                    'extra' => [
                        'full_url' => [
                            'method' => 'strtoupper',
                            'params' => ['field:params.prefix']
                        ]
                    ]
                ],
                'mapping' => [
                    'token_echo' => 'field:params.token',
                    'url' => '{{params.prefix}}/tasks'
                ],
                'action_logic' => ['request', 'mapping']
            ]
        ];

        $interpreter = new Interpreter($config);
        $interpreter->setTestMode(['input' => 'x']);
        $interpreter->run('params_action', 'desktop_manager', null, [
            'prefix' => 'api_v2',
            'token' => 'Bearer xyz'
        ]);

        $context = $interpreter->getContext();

        $this->assert(
            'testParams_Extra',
            'API_V2',
            $context->get('full_url'),
            'params должны быть доступны в extra (field:params.prefix)',
            ['check' => 'context.full_url']
        );

        $this->assert(
            'testParams_Mapping',
            'Bearer xyz',
            $context->get('token_echo'),
            'params должны быть доступны в mapping (field:params.token)',
            ['check' => 'context.token_echo']
        );

        $log = $interpreter->buildLogRequest();
        $this->assert(
            'testParams_Log',
            ['prefix' => 'api_v2', 'token' => 'Bearer xyz'],
            $log['params'] ?? null,
            'params должны быть видны в логе buildLogRequest',
            ['check' => 'log.params']
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
            $this->logger->success('InterpreterTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('InterpreterTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}

// ============================================================
// МОК-КЛАСС ДЛЯ INTERPRETER
// ============================================================

/**
 * Class MockInterpreterService
 * 
 * Мок-класс для интеграционных тестов Interpreter.
 * Используется в execute actions (instanceTransform) и
 * для передачи маппинга (getMappingData).
 * 
 * @package Api\Services\Actions\Testing
 */
class MockInterpreterService
{
    /**
     * Нестатический метод для actions (трансформация строки)
     * 
     * @param string $value Входное значение
     * @return string 'INSTANCE:' + значение
     */
    public function instanceTransform(string $value): string
    {
        return 'INSTANCE:' . $value;
    }

    /**
     * Возвращает маппинг как есть (для теста полного цикла)
     * 
     * @param array $mappingData Массив маппинга
     * @return array Тот же массив
     */
    public function getMappingData(array $mappingData): array
    {
        return $mappingData;
    }
}

// ============================================================
// ШПИОН ДЛЯ ЛОГИРОВАНИЯ (v1.3.1)
// ============================================================

/**
 * Class SpyInterpreter
 * 
 * Interpreter с журналом вместо реального Logger:
 * переопределяет emitLog и копит вызовы, ничего не записывая.
 * Работает одинаково на ПК и сервере (не зависит от реализации Logger).
 * 
 * @package Api\Services\Actions\Testing
 */
class SpyInterpreter extends Interpreter
{
    /**
     * Журнал вызовов emitLog: [['status' => ..., 'info' => ...], ...]
     * 
     * @var array
     */
    public array $logJournal = [];

    /**
     * Вместо записи в Logger — запись в журнал
     * 
     * @param string $status Статус
     * @param string $info Сообщение
     * @return void
     */
    protected function emitLog(string $status, string $info): void
    {
        $this->logJournal[] = ['status' => $status, 'info' => $info];
    }
}
