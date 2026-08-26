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
 * Всего тестов: 69
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

        // Умное логирование (v1.4.0)
        $this->testErrorContextCapturedInLog();
        $this->testMaxLogSizeTruncatesComputed();
        $this->testLogResponseAutoForIterative();
        $this->testLogResponseAutoFalseForSingle();
        $this->testLogResponseExplicitTrue();
        $this->testSnapshotModeErrorsOnly();
        $this->testLogResponseOnErrorAlways();

        // switch (v1.9.0)
        $this->testSwitchBasicMatch();
        $this->testSwitchDefault();
        $this->testSwitchNoMatchChainContinues();
        $this->testSwitchConsumesChain();
        $this->testSwitchLooseComparison();

        // независимые if и вложенность (v1.10.0)
        $this->testIndependentIfsBothRun();
        $this->testIndependentDoesNotBreakChain();
        $this->testNestedExecuteInActions();
        $this->testSwitchCaseNestedIfElse();
        $this->testDeepNesting();

        // set (v1.12.0)
        $this->testSetActionWritesContext();
        $this->testSetActionConditional();

        // семантика цепочек (v1.13.0)
        $this->testSequentialIfsRunInOrder();
        $this->testChainIfElseifElse();
        $this->testSwitchAfterIfExecutes();
        $this->testElseBindsToSwitch();

        // строка в conditions (v1.15.0)
        $this->testStringConditionsTrue();
        $this->testStringConditionsFalse();
        $this->testStringConditionsNull();

        // Автоматический merge response в одиночном режиме (v1.16.0 - v1.16.1)
        $this->testMergeResponseInSingleMode();
        $this->testSeparateResponsePerIteration();
        $this->testMergeResponseInNestedExecute();

        // бизнес-ошибки в execute (v1.16.2)
        $this->testExecuteActionError();
        $this->testExecuteActionErrorWithConditions();


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
    // УМНОЕ ЛОГИРОВАНИЕ (v1.4.0)
    // ========================================================

    /**
     * Тест: Error Context — resolved_params упавшего вызова попадают в лог
     * 
     * Кейс из get_task_card: query с method, бросающим TypeError
     * при невалидных аргументах. В логе должно быть
     * error.error_context с class/method/resolved_params.
     * 
     * @return void
     */
    private function testErrorContextCapturedInLog(): void
    {
        $this->logger->separator('testErrorContextCapturedInLog');

        $interpreter = new Interpreter([
            'ctx_test' => [
                'request' => [
                    'main' => 'post',
                    'query' => [
                        'RESULT' => [
                            'method' => 'mustBeString',
                            'class' => MockErrorService::class,
                            'params' => [
                                'field:client_id',
                                'field:client_type',
                                'field:phone',
                                ['field:USER.UF_DEPARTMENT'],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('ctx_test', 'test', [
            'client_id' => '555',
            'client_type' => 'contact',
            'phone' => null,            // ← TypeError в mustBeString
            'USER' => ['UF_DEPARTMENT' => [12]],
        ]);

        $logResponse = $interpreter->buildLogResponse();

        // Проверка 1: error должен содержать error_context
        $this->assert(
            'testErrorContext_HasContext',
            true,
            isset($logResponse['error_context']),
            'Строка ответа при ошибке содержит error_context',
            ['log_response' => $logResponse]
        );

        // Проверка 2: class и method записаны
        $this->assert(
            'testErrorContext_Method',
            'mustBeString',
            $logResponse['error_context']['method'] ?? null,
            'error_context.method — имя упавшего метода',
            []
        );

        $this->assert(
            'testErrorContext_Class',
            MockErrorService::class,
            $logResponse['error_context']['class'] ?? null,
            'error_context.class — имя класса',
            []
        );

        // Проверка 3: resolved_params[2] (phone) = null — именно это убило метод.
        // ВАЖНО: нельзя использовать `??` — он не отличает «ключа нет» от «значение null».
        $resolvedParams = $logResponse['error_context']['resolved_params'] ?? [];
        $this->assert(
            'testErrorContext_Params',
            null,
            array_key_exists(2, $resolvedParams) ? $resolvedParams[2] : '__missing__',
            'resolved_params[2] = null — аргумент, убивший метод',
            ['resolved_params' => $resolvedParams]
        );
    }

    /**
     * Тест: max_log_size — при переполнении computed обрезается
     * 
     * @return void
     */
    private function testMaxLogSizeTruncatesComputed(): void
    {
        $this->logger->separator('testMaxLogSizeTruncatesComputed');

        $interpreter = new Interpreter([
            'big_log' => [
                'max_log_size' => 500,   // намеренно мало
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                    'extra' => [
                        'big' => ['method' => 'str_repeat', 'params' => ['x', 1000]],
                    ],
                ],
                'transaction' => ['enabled' => true, 'mode' => 'partial'],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('big_log', 'test', [
            'items' => [['a' => 1], ['a' => 2], ['a' => 3]],
        ]);

        $logRequest = $interpreter->buildLogRequest();
        $encoded = json_encode($logRequest, JSON_UNESCAPED_UNICODE);

        // Проверка 1: размер ограничен
        $this->assert(
            'testMaxLogSize_Size',
            true,
            strlen($encoded) <= 600,     // 500 + небольшой запас на _truncated
            'Лог должен быть обрезан до max_log_size',
            ['size' => strlen($encoded)]
        );

        // Проверка 2: флаг _truncated на месте
        $this->assert(
            'testMaxLogSize_HasFlag',
            true,
            isset($logRequest['_truncated']),
            'Лог должен содержать флаг _truncated',
            ['log_keys' => array_keys($logRequest)]
        );

        // Проверка 3: status/error/params не тронуты
        $this->assert(
            'testMaxLogSize_DataPreserved',
            true,
            isset($logRequest['data']),
            'data не должен быть удалён при деградации computed',
            ['log_keys' => array_keys($logRequest)]
        );
    }

    /**
     * Тест: log_response auto=true для итерационных
     * 
     * Без явного log_response, с request.array — response попадает в лог.
     * 
     * @return void
     */
    private function testLogResponseAutoForIterative(): void
    {
        $this->logger->separator('testLogResponseAutoForIterative');

        $interpreter = new Interpreter([
            'iter_resp' => [
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                ],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [[
                            'response' => ['id' => 'field:id'],
                        ]],
                    ],
                ],
                'transaction' => ['enabled' => true, 'mode' => 'partial'],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('iter_resp', 'test', [
            'items' => [['id' => 1], ['id' => 2]],
        ]);

        $logResponse = $interpreter->buildLogResponse();

        $this->assert(
            'testLogResponseAutoForIterative',
            [['id' => 1], ['id' => 2]],
            $logResponse,
            'Итерации: строка ответа = массив записей response',
            ['log_response' => $logResponse]
        );
    }

    /**
     * Тест: log_response auto=false для одиночных
     * 
     * Без явного log_response, без request.array — response НЕ попадает в лог.
     * 
     * @return void
     */
    private function testLogResponseAutoFalseForSingle(): void
    {
        $this->logger->separator('testLogResponseAutoFalseForSingle');

        $interpreter = new Interpreter([
            'single_resp' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [[
                            'response' => ['id' => 'field:id'],
                        ]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('single_resp', 'test', ['id' => 1]);

        $logResponse = $interpreter->buildLogResponse();

        $this->assert(
            'testLogResponseAutoFalseForSingle',
            ['status' => 'SUCCESS'],
            $logResponse,
            'Одиночный успех без log_response: компактная строка ответа',
            ['log_response' => $logResponse]
        );
    }

    /**
     * Тест: log_response=true явно для одиночного (переопределение)
     * 
     * @return void
     */
    private function testLogResponseExplicitTrue(): void
    {
        $this->logger->separator('testLogResponseExplicitTrue');

        $interpreter = new Interpreter([
            'single_explicit' => [
                'log_response' => true,
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [[
                            'response' => ['id' => 'field:id'],
                        ]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('single_explicit', 'test', ['id' => 42]);

        $logResponse = $interpreter->buildLogResponse();

        $this->assert(
            'testLogResponseExplicitTrue',
            [['id' => 42]],
            $logResponse,
            'log_response=true: полный response в строке ответа',
            ['log_response' => $logResponse]
        );
    }

    /**
     * Тест: snapshot_mode=errors_only — снимки только упавших итераций
     * 
     * @return void
     */
    private function testSnapshotModeErrorsOnly(): void
    {
        $this->logger->separator('testSnapshotModeErrorsOnly');

        $interpreter = new Interpreter([
            'snap_mode' => [
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                    'query' => [
                        'CONTACT' => [
                            'method' => 'throwingGetById',
                            'class' => MockQueryService::class,
                            'params' => ['field:client_id'],
                            // только итерация 1 упадёт (id=999)
                            'conditions' => ['field:client_id' => 999],
                        ],
                    ],
                ],
                'transaction' => [
                    'enabled' => true,
                    'mode' => 'partial',
                    'snapshot_mode' => 'errors_only',
                ],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('snap_mode', 'test', [
            'items' => [
                ['client_id' => 1],    // итерация 0: успех (query пропущен по conditions)
                ['client_id' => 999],  // итерация 1: ошибка
                ['client_id' => 2],    // итерация 2: успех
            ],
        ]);

        $logRequest = $interpreter->buildLogRequest();

        // В computed должна быть только iteration_1 (с ошибкой)
        $iterationsInLog = array_keys($logRequest['computed'] ?? []);

        $this->assert(
            'testSnapshotModeErrorsOnly_Count',
            1,
            count($iterationsInLog),
            'snapshot_mode=errors_only: в логе только упавшая итерация',
            ['iterations' => $iterationsInLog]
        );

        $this->assert(
            'testSnapshotModeErrorsOnly_WhichOne',
            ['iteration_1'],
            $iterationsInLog,
            'В логе должна быть именно iteration_1 (с ошибкой)',
            ['iterations' => $iterationsInLog]
        );
    }

    /**
     * Тест: при ERROR-исходе строка ответа ВСЕГДА содержит саму ошибку —
     * даже для одиночного эндпоинта с logging=false
     * 
     * @return void
     */
    private function testLogResponseOnErrorAlways(): void
    {
        $this->logger->separator('testLogResponseOnErrorAlways');

        $interpreter = new Interpreter([
            'err_resp' => [
                'logging' => false,
                'request' => [
                    'main' => 'post',
                    'query' => [
                        'CONTACT' => [
                            'method' => 'throwingGetById',
                            'class' => MockQueryService::class,
                            'params' => ['field:client_id'],
                        ],
                    ],
                ],
                'action_logic' => ['request'],
            ],
        ]);

        $interpreter->run('err_resp', 'test', ['client_id' => 1]);

        $logResponse = $interpreter->buildLogResponse();

        $this->assert(
            'testLogResponseOnErrorAlways_Path',
            'request.query.CONTACT',
            $logResponse['config_path'] ?? null,
            'При ERROR строка ответа = сама ошибка',
            ['log_response' => $logResponse]
        );

        $this->assert(
            'testLogResponseOnErrorAlways_Context',
            true,
            isset($logResponse['error_context']),
            'Ошибка в строке ответа несёт error_context',
            []
        );
    }

    // ========================================================
    // SWITCH (v1.9.0)
    // ========================================================

    /**
     * Тест: switch — совпадение case
     * 
     * @return void
     */
    private function testSwitchBasicMatch(): void
    {
        $this->logger->separator('testSwitchBasicMatch');

        $interpreter = new Interpreter([
            'switch_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:client_type',
                        'cases' => [
                            'contact' => ['actions' => [['response' => ['branch' => 'contact']]]],
                            'company' => ['actions' => [['response' => ['branch' => 'company']]]],
                            'default' => ['actions' => [['response' => ['branch' => 'default']]]],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_action', 'test', ['client_type' => 'contact']);

        $this->assert(
            'testSwitchBasicMatch',
            [['branch' => 'contact']],
            $responseHandler->response,
            'Switch должен выполнить actions совпавшего case',
            ['expression' => 'field:client_type', 'value' => 'contact']
        );
    }

    /**
     * Тест: switch — ни один case не совпал → default
     * 
     * @return void
     */
    private function testSwitchDefault(): void
    {
        $this->logger->separator('testSwitchDefault');

        $interpreter = new Interpreter([
            'switch_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:client_type',
                        'cases' => [
                            'contact' => ['actions' => [['response' => ['branch' => 'contact']]]],
                            'default' => ['actions' => [['response' => ['branch' => 'default']]]],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_action', 'test', ['client_type' => 'other']);

        $this->assert(
            'testSwitchDefault',
            [['branch' => 'default']],
            $responseHandler->response,
            'Switch без совпадений должен выполнить default',
            ['value' => 'other']
        );
    }

    /**
     * Тест: switch без совпадения и без default НЕ съедает цепочку —
     * следующий else выполняется
     * 
     * @return void
     */
    private function testSwitchNoMatchChainContinues(): void
    {
        $this->logger->separator('testSwitchNoMatchChainContinues');

        $interpreter = new Interpreter([
            'switch_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:client_type',
                        'cases' => [
                            'contact' => ['actions' => [['response' => ['branch' => 'contact']]]],
                        ],
                    ],
                    [
                        'check' => 'else',
                        'actions' => [['response' => ['branch' => 'else_after_switch']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_action', 'test', ['client_type' => 'other']);

        $this->assert(
            'testSwitchNoMatchChainContinues',
            [['branch' => 'else_after_switch']],
            $responseHandler->response,
            'Несработавший switch не должен прерывать цепочку if/elseif/else',
            ['value' => 'other', 'default' => 'не задан']
        );
    }

    /**
     * Тест: сработавший switch съедает цепочку — else не выполняется
     * 
     * @return void
     */
    private function testSwitchConsumesChain(): void
    {
        $this->logger->separator('testSwitchConsumesChain');

        $interpreter = new Interpreter([
            'switch_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:x' => 1],
                        'actions' => [['response' => ['branch' => 'if']]],
                    ],
                    [
                        'check' => 'switch',
                        'expression' => 'field:client_type',
                        'cases' => [
                            'contact' => ['actions' => [['response' => ['branch' => 'switch']]]],
                        ],
                    ],
                    [
                        'check' => 'else',
                        'actions' => [['response' => ['branch' => 'else']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_action', 'test', ['x' => 0, 'client_type' => 'contact']);

        $this->assert(
            'testSwitchConsumesChain',
            [['branch' => 'switch']],
            $responseHandler->response,
            'Сработавший switch должен прерывать цепочку (else не выполняется)',
            ['chain' => 'if(false) → switch(match) → else']
        );
    }

    /**
     * Тест: слабое сравнение — int 1 совпадает с case '1'
     * 
     * @return void
     */
    private function testSwitchLooseComparison(): void
    {
        $this->logger->separator('testSwitchLooseComparison');

        $interpreter = new Interpreter([
            'switch_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:code',
                        'cases' => [
                            '1' => ['actions' => [['response' => ['branch' => 'one']]]],
                            'default' => ['actions' => [['response' => ['branch' => 'default']]]],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_action', 'test', ['code' => 1]);

        $this->assert(
            'testSwitchLooseComparison',
            [['branch' => 'one']],
            $responseHandler->response,
            'Switch сравнивает слабо: int 1 совпадает с case "1"',
            ['value' => 1, 'case' => "'1'"]
        );
    }

    // ========================================================
    // НЕЗАВИСИМЫЕ IF И ВЛОЖЕННОСТЬ (v1.10.0)
    // ========================================================

    /**
     * Тест: два независимых if — оба срабатывают
     * 
     * @return void
     */
    private function testIndependentIfsBothRun(): void
    {
        $this->logger->separator('testIndependentIfsBothRun');

        $interpreter = new Interpreter([
            'ind_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'independent' => true,
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'first']]],
                    ],
                    [
                        'check' => 'if',
                        'independent' => true,
                        'filter' => ['field:b' => 1],
                        'actions' => [['response' => ['hit' => 'second']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('ind_action', 'test', ['a' => 1, 'b' => 1]);

        $this->assert(
            'testIndependentIfsBothRun',
            [['hit' => 'first'], ['hit' => 'second']],
            $responseHandler->response,
            'Оба независимых if должны сработать',
            ['independent' => true]
        );
    }

    /**
     * Тест: независимый if не разрывает цепочку if/else
     * 
     * @return void
     */
    private function testIndependentDoesNotBreakChain(): void
    {
        $this->logger->separator('testIndependentDoesNotBreakChain');

        $interpreter = new Interpreter([
            'ind_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'independent' => true,
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'ind']]],
                    ],
                    [
                        'check' => 'if',
                        'filter' => ['field:x' => 1],
                        'actions' => [['response' => ['hit' => 'chain_if']]],
                    ],
                    [
                        'check' => 'else',
                        'actions' => [['response' => ['hit' => 'chain_else']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('ind_action', 'test', ['a' => 1, 'x' => 0]);

        $this->assert(
            'testIndependentDoesNotBreakChain',
            [['hit' => 'ind'], ['hit' => 'chain_else']],
            $responseHandler->response,
            'Независимый if сработал, цепочка if/else продолжилась до else',
            ['chain' => 'independent → if(false) → else']
        );
    }

    /**
     * Тест: вложенный execute внутри actions
     * 
     * @return void
     */
    private function testNestedExecuteInActions(): void
    {
        $this->logger->separator('testNestedExecuteInActions');

        $interpreter = new Interpreter([
            'nested_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:a' => 1],
                        'actions' => [
                            ['response' => ['level' => 1]],
                            [
                                'execute' => [
                                    [
                                        'check' => 'if',
                                        'filter' => ['field:b' => 1],
                                        'actions' => [['response' => ['level' => 2]]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('nested_action', 'test', ['a' => 1, 'b' => 1]);

        $this->assert(
            'testNestedExecuteInActions',
            [['level' => 1], ['level' => 2]],
            $responseHandler->response,
            'Вложенный execute выполняется внутри actions',
            ['depth' => 2]
        );
    }

    /**
     * Тест: switch → case → вложенный if/elseif/else
     * 
     * @return void
     */
    private function testSwitchCaseNestedIfElse(): void
    {
        $this->logger->separator('testSwitchCaseNestedIfElse');

        $interpreter = new Interpreter([
            'switch_nested' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:client_type',
                        'cases' => [
                            'contact' => [
                                'actions' => [
                                    [
                                        'execute' => [
                                            [
                                                'check' => 'if',
                                                'filter' => ['field:vip' => 1],
                                                'actions' => [['response' => ['branch' => 'vip_contact']]],
                                            ],
                                            [
                                                'check' => 'else',
                                                'actions' => [['response' => ['branch' => 'regular_contact']]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'default' => [
                                'actions' => [['response' => ['branch' => 'default']]],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('switch_nested', 'test', ['client_type' => 'contact', 'vip' => 0]);

        $this->assert(
            'testSwitchCaseNestedIfElse',
            [['branch' => 'regular_contact']],
            $responseHandler->response,
            'switch → case → вложенный if/else: сработала ветка else',
            ['path' => 'switch(contact) → if(vip=0) → else']
        );
    }

    /**
     * Тест: три уровня вложенности (switch → if → if)
     * 
     * @return void
     */
    private function testDeepNesting(): void
    {
        $this->logger->separator('testDeepNesting');

        $interpreter = new Interpreter([
            'deep_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'switch',
                        'expression' => 'field:l1',
                        'cases' => [
                            'a' => [
                                'actions' => [
                                    [
                                        'execute' => [
                                            [
                                                'check' => 'if',
                                                'filter' => ['field:l2' => 1],
                                                'actions' => [
                                                    [
                                                        'execute' => [
                                                            [
                                                                'check' => 'if',
                                                                'filter' => ['field:l3' => 1],
                                                                'actions' => [['response' => ['deep' => true]]],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('deep_action', 'test', ['l1' => 'a', 'l2' => 1, 'l3' => 1]);

        $this->assert(
            'testDeepNesting',
            [['deep' => true]],
            $responseHandler->response,
            'Три уровня вложенности проходят до конца',
            ['depth' => 3]
        );
    }

    // ========================================================
    // SET (v1.12.0)
    // ========================================================

    /**
     * Тест: set пишет в контекст, значение видно в следующих блоках
     * 
     * @return void
     */
    private function testSetActionWritesContext(): void
    {
        $this->logger->separator('testSetActionWritesContext');

        $interpreter = new Interpreter([
            'set_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            // 1. Сначала пишем в контекст
                            ['set' => ['final_id' => 'field:a.ID|field:b.ID']],
                            // 2. Затем используем — с условиями
                            [
                                'conditions' => ['!field:final_id' => 'func:empty'],
                                'response'   => ['final' => 'field:final_id'],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('set_action', 'test', [
            'a' => [],
            'b' => ['ID' => 77],
        ]);

        $this->assert(
            'testSetActionWritesContext_Value',
            77,
            $responseHandler->response[0]['final'] ?? null,
            'set записал в контекст, следующее действие его прочитало и записало в response',
            ['set' => 'field:a.ID|field:b.ID']
        );
    }

    /**
     * Тест: set с ложным conditions НЕ пишет в контекст
     * 
     * @return void
     */
    private function testSetActionConditional(): void
    {
        $this->logger->separator('testSetActionConditional');

        $interpreter = new Interpreter([
            'set_cond' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'conditions' => ['field:flag' => 1],
                                'set' => ['x' => 1],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('set_cond', 'test', ['flag' => 0]);

        $this->assert(
            'testSetActionConditional',
            null,
            $interpreter->getContext()->get('x'),
            'set с ложным conditions не пишет в контекст',
            ['flag' => 0]
        );
    }

    // ========================================================
    // СЕМАНТИКА ЦЕПОЧЕК (v1.13.0)
    // ========================================================

    /**
     * Тест: два if подряд — оба выполняются по очереди
     * 
     * @return void
     */
    private function testSequentialIfsRunInOrder(): void
    {
        $this->logger->separator('testSequentialIfsRunInOrder');

        $interpreter = new Interpreter([
            'seq_ifs' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'first_if']]],
                    ],
                    [
                        'check' => 'if',
                        'filter' => ['field:b' => 1],
                        'actions' => [['response' => ['hit' => 'second_if']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('seq_ifs', 'test', ['a' => 1, 'b' => 1]);

        $this->assert(
            'testSequentialIfsRunInOrder',
            [['hit' => 'first_if'], ['hit' => 'second_if']],
            $responseHandler->response,
            'Два if подряд выполняются по очереди (v1.13.0)',
            ['blocks' => 'if → if']
        );
    }

    /**
     * Тест: if → elseif → else — внутри цепочки срабатывает один блок
     * 
     * @return void
     */
    private function testChainIfElseifElse(): void
    {
        $this->logger->separator('testChainIfElseifElse');

        $interpreter = new Interpreter([
            'chain' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'if']]],
                    ],
                    [
                        'check' => 'elseif',
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'elseif']]],
                    ],
                    [
                        'check' => 'else',
                        'actions' => [['response' => ['hit' => 'else']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('chain', 'test', ['a' => 1]);

        $this->assert(
            'testChainIfElseifElse',
            [['hit' => 'if']],
            $responseHandler->response,
            'В цепочке if/elseif/else срабатывает ровно один блок',
            ['blocks' => 'if(true) → elseif(true) → else']
        );
    }

    /**
     * Тест: switch после if выполняется (не пропускается)
     * 
     * @return void
     */
    private function testSwitchAfterIfExecutes(): void
    {
        $this->logger->separator('testSwitchAfterIfExecutes');

        $interpreter = new Interpreter([
            'if_then_switch' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'if']]],
                    ],
                    [
                        'check' => 'switch',
                        'expression' => 'field:type',
                        'cases' => [
                            'x' => ['actions' => [['response' => ['hit' => 'switch']]]],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('if_then_switch', 'test', ['a' => 1, 'type' => 'x']);

        $this->assert(
            'testSwitchAfterIfExecutes',
            [['hit' => 'if'], ['hit' => 'switch']],
            $responseHandler->response,
            'switch после сработавшего if выполняется как новый оператор',
            ['blocks' => 'if(true) → switch(match)']
        );
    }

    /**
     * Тест: else привязывается к ближайшей цепочке (switch),
     * а не к предыдущему if
     * 
     * @return void
     */
    private function testElseBindsToSwitch(): void
    {
        $this->logger->separator('testElseBindsToSwitch');

        $interpreter = new Interpreter([
            'else_bind' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:a' => 1],
                        'actions' => [['response' => ['hit' => 'if']]],
                    ],
                    [
                        'check' => 'switch',
                        'expression' => 'field:type',
                        'cases' => [
                            'y' => ['actions' => [['response' => ['hit' => 'switch']]]],
                        ],
                    ],
                    [
                        'check' => 'else',
                        'actions' => [['response' => ['hit' => 'else']]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('else_bind', 'test', ['a' => 1, 'type' => 'x']);

        $this->assert(
            'testElseBindsToSwitch',
            [['hit' => 'if'], ['hit' => 'else']],
            $responseHandler->response,
            'else относится к ближайшей цепочке: switch не совпал → else сработал',
            ['blocks' => 'if(true) → switch(no match) → else']
        );
    }

    // ========================================================
    // СТРОКА В CONDITIONS (v1.15.0)
    // ========================================================

    /**
     * Тест: conditions-строка истинна → set записывает значение
     * 
     * @return void
     */
    private function testStringConditionsTrue(): void
    {
        $this->logger->separator('testStringConditionsTrue');

        $interpreter = new Interpreter([
            'str_cond' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'conditions' => 'field:cond',
                                'set' => ['gate' => 'passed'],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('str_cond', 'test', [
            'max' => 3,
            'count' => 5,
            'cond' => ['<=field:max' => 'field:count'],
        ]);

        $context = $interpreter->getContext();

        $this->assert(
            'testStringConditionsTrue',
            'passed',
            $context ? $context->get('gate') : null,
            'conditions-строка резолвится в массив и вычисляется (3 <= 5 → true)',
            ['conditions' => 'field:cond']
        );
    }

    /**
     * Тест: conditions-строка ложна → set не выполняется
     * 
     * @return void
     */
    private function testStringConditionsFalse(): void
    {
        $this->logger->separator('testStringConditionsFalse');

        $interpreter = new Interpreter([
            'str_cond' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'conditions' => 'field:cond',
                                'set' => ['gate' => 'passed'],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('str_cond', 'test', [
            'max' => 3,
            'count' => 1,
            'cond' => ['<=field:max' => 'field:count'],
        ]);

        $context = $interpreter->getContext();

        $this->assert(
            'testStringConditionsFalse',
            null,
            $context ? $context->get('gate') : 'NOT_NULL',
            'Условие из данных ложно (3 <= 1 → false) → set не выполняется, gate = null',
            ['conditions' => 'field:cond']
        );
    }

    /**
     * Тест: conditions-строка резолвится в null (поля нет) → true, set выполняется
     * 
     * @return void
     */
    private function testStringConditionsNull(): void
    {
        $this->logger->separator('testStringConditionsNull');

        $interpreter = new Interpreter([
            'str_cond' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'conditions' => 'field:missing',
                                'set' => ['gate' => 'passed'],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $interpreter->run('str_cond', 'test', []);

        $context = $interpreter->getContext();

        $this->assert(
            'testStringConditionsNull',
            'passed',
            $context ? $context->get('gate') : null,
            'null вместо массива условий = условий нет = выполняем',
            ['conditions' => 'field:missing']
        );
    }

    /**
     * Тест: одиночный режим — все response мержатся в одну запись
     */
    private function testMergeResponseInSingleMode(): void
    {
        $this->logger->separator('testMergeResponseInSingleMode');

        $interpreter = new Interpreter([
            'merge_test' => [
                'merge_response' => true,
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [['response' => ['a' => 1]]],
                    ],
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [['response' => ['b' => 2]]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('merge_test', 'test', []);

        $this->assert(
            'testMergeResponseInSingleMode',
            [['a' => 1, 'b' => 2]],
            $responseHandler->response,
            'В одиночном режиме все response мержатся в одну запись',
            []
        );
    }

    /**
     * Тест: итерационный режим — каждая итерация = своя запись
     */
    private function testSeparateResponsePerIteration(): void
    {
        $this->logger->separator('testSeparateResponsePerIteration');

        $interpreter = new Interpreter([
            'iter_merge' => [
                'merge_response' => true,
                'request' => [
                    'main' => 'post',
                    'array' => 'items',
                ],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            ['response' => ['id' => 'field:id']],
                            ['response' => ['name' => 'field:name']],
                        ],
                    ],
                ],
                'transaction' => ['enabled' => true, 'mode' => 'partial'],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('iter_merge', 'test', [
            'items' => [
                ['id' => 1, 'name' => 'first'],
                ['id' => 2, 'name' => 'second'],
            ],
        ]);

        $this->assert(
            'testSeparateResponsePerIteration',
            [
                ['id' => 1, 'name' => 'first'],
                ['id' => 2, 'name' => 'second'],
            ],
            $responseHandler->response,
            'В итерационном режиме каждая итерация = своя запись (response внутри итерации мержатся)',
            []
        );
    }

    /**
     * Тест: merge_response работает во вложенном execute
     * 
     * @return void
     */
    private function testMergeResponseInNestedExecute(): void
    {
        $this->logger->separator('testMergeResponseInNestedExecute');

        $interpreter = new Interpreter([
            'nested_merge' => [
                'merge_response' => true,
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'execute' => [
                                    [
                                        'check' => 'if',
                                        'filter' => [],
                                        'actions' => [['response' => ['a' => 1]]],
                                    ],
                                    [
                                        'check' => 'if',
                                        'filter' => [],
                                        'actions' => [['response' => ['b' => 2]]],
                                    ],
                                    [
                                        'check' => 'if',
                                        'filter' => [],
                                        'actions' => [['response' => ['c' => 3]]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('nested_merge', 'test', []);

        $this->assert(
            'testMergeResponseInNestedExecute',
            [['a' => 1, 'b' => 2, 'c' => 3]],
            $responseHandler->response,
            'merge_response=true должен работать и во вложенных execute',
            ['depth' => 2, 'responses_merged' => 3]
        );
    }

    /**
     * Тест: 'error' в execute-action останавливает выполнение и возвращает ERROR
     * 
     * @return void
     */
    private function testExecuteActionError(): void
    {
        $this->logger->separator('testExecuteActionError');

        $interpreter = new Interpreter([
            'err_action' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => ['field:trigger' => 1],
                        'actions' => [
                            ['error' => 'Бизнес-ошибка: нельзя выполнить действие'],
                        ],
                    ],
                    // Этот блок НЕ должен выполниться из-за error выше
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [['response' => ['should_not_run' => true]]],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('err_action', 'test', ['trigger' => 1]);

        $this->assert(
            'testExecuteActionError_Status',
            'ERROR',
            $responseHandler->status,
            'error в action → статус ERROR',
            []
        );

        $logResponse = $interpreter->buildLogResponse();

        $this->assert(
            'testExecuteActionError_Message',
            true,
            str_contains(json_encode($logResponse, JSON_UNESCAPED_UNICODE), 'Бизнес-ошибка'),
            'Текст бизнес-ошибки присутствует в buildLogResponse',
            ['log_response' => $logResponse]
        );

        // Блок после error не выполнился
        $hasUnexpectedResponse = false;
        foreach ($responseHandler->response as $entry) {
            if (isset($entry['should_not_run'])) {
                $hasUnexpectedResponse = true;
                break;
            }
        }

        $this->assert(
            'testExecuteActionError_NoNextBlocks',
            false,
            $hasUnexpectedResponse,
            'Блоки после error не выполняются',
            []
        );
    }

    /**
     * Тест: 'error' с conditions=false не срабатывает
     * 
     * @return void
     */
    private function testExecuteActionErrorWithConditions(): void
    {
        $this->logger->separator('testExecuteActionErrorWithConditions');

        $interpreter = new Interpreter([
            'err_cond' => [
                'request' => ['main' => 'post'],
                'execute' => [
                    [
                        'check' => 'if',
                        'filter' => [],
                        'actions' => [
                            [
                                'conditions' => ['field:trigger' => 0],
                                'error' => 'Эта ошибка не должна сработать',
                            ],
                            ['response' => ['ok' => true]],
                        ],
                    ],
                ],
                'action_logic' => ['request', 'execute'],
            ],
        ]);

        $responseHandler = $interpreter->run('err_cond', 'test', ['trigger' => 1]);

        $this->assert(
            'testExecuteActionErrorWithConditions_Status',
            'SUCCESS',
            $responseHandler->status,
            'conditions=false → error не бросается, выполнение продолжается',
            []
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

// ============================================================
// МОК-КЛАСС ДЛЯ ERROR CONTEXT (v1.4.0)
// ============================================================

/**
 * Class MockErrorService
 * 
 * Мок-класс для тестирования error_context.
 * Методы с строгой типизацией, бросающие TypeError при невалидных аргументах.
 * 
 * @package Api\Services\Actions\Testing
 */
class MockErrorService
{
    /**
     * Метод, требующий string в третьем аргументе.
     * Имитация DesktopManager\Main::getEntities.
     * 
     * @param string $clientId
     * @param string $clientType
     * @param string $phone
     * @param array $department
     * @return array
     */
    public function mustBeString(string $clientId, string $clientType, string $phone, array $department): array
    {
        return [];
    }
}
