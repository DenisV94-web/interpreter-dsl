<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Interpreter;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Resolver\Method;
use Api\Services\Actions\Executor\Request;

/**
 * Class RequestTest
 * 
 * Тесты для класса Request (Executor).
 * 
 * Request Executor — обработчик блока request конфигурации.
 * Отвечает за подготовку данных ДО mapping/execute:
 * - Получение данных из php://input (JSON) или суперглобальных массивов
 * - extra: вычисляемые поля (method/conditions/check_true/check_false),
 *   с поддержкой цепочек зависимостей между полями
 * - query: запросы к БД/внешним системам (getList, getById) с conditions
 * - on_error: fallback при исключении в query (иначе — ошибка итерации)
 * - no_log: флаг скрытия больших массивов из log.computed (v1.3.0)
 * - Итерационный режим: request.array — обработка массива объектов
 * 
 * Лог: Request_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 11
 * 
 * @package Api\Services\Actions\Testing
 */
class RequestTest
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
     * RequestTest constructor.
     * 
     * Создаёт логгер с префиксом 'Request' — все записи идут
     * в файл Request_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Request');
    }

    /**
     * Создаёт связку Context + резолверы + Request для тестов
     * 
     * @param array $data Данные для контекста
     * @return array [Request, Context]
     */
    private function createRequest(array $data = []): array
    {
        $context = new Context($data);
        $context->setTestLogger($this->logger);
        $fieldResolver = new Field($context);
        $conditionResolver = new Condition($context, $fieldResolver);
        $methodResolver = new Method($context, $fieldResolver);
        $request = new Request($context, $fieldResolver, $conditionResolver, $methodResolver);
        return [$request, $context];
    }

    /**
     * Запускает все тесты Request Executor
     * 
     * Порядок логический, от простого к сложному:
     * - extra (1-3)
     * - query с conditions (4-5)
     * - Итерационный режим (6)
     * - on_error в query (7-8)
     * - no_log флаг (9-11)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('RequestTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Request Executor ===');

        // extra
        $this->testExtraSimpleMethod();
        $this->testExtraWithConditions();
        $this->testExtraChainedDependency();

        // query с conditions
        $this->testQueryWithConditions();
        $this->testQueryConditionFalse();

        // Итерационный режим
        $this->testIterativeMode();

        // on_error в query
        $this->testQueryWithOnError();
        $this->testQueryErrorWithoutOnError();

        // no_log флаг (v1.3.0)
        $this->testNoLogHidesFromComputed();
        $this->testNoLogAbsentFieldVisible();
        $this->testNoLogWorksInCompose();

        // construct (v1.6.0)
        $this->testConstructScalarArgs();
        $this->testConstructNestedFourLevels();
        $this->testConstructMissingRequired();
        $this->testConstructBackCompat();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "REQUEST TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // EXTRA (тесты 1-3)
    // ========================================================

    /**
     * Тест 1: Extra поле с простым методом (date)
     * 
     * Входные данные: unified_client_id = 'test123'.
     * Конфиг extra: date_now = date('d.m.Y').
     * Ожидание: context.date_now = текущая дата в формате d.m.Y.
     * 
     * @return void
     */
    private function testExtraSimpleMethod(): void
    {
        $this->logger->separator('testExtraSimpleMethod');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'extra' => [
                'date_now' => ['method' => 'date', 'params' => ['d.m.Y']]
            ]
        ], ['unified_client_id' => 'test123']);

        $this->assert(
            'testExtraSimpleMethod',
            date('d.m.Y'),
            $context->get('date_now'),
            'Extra поле date_now должно содержать текущую дату',
            ['extra' => ['date_now' => 'date("d.m.Y")']]
        );
    }

    /**
     * Тест 2: Extra с conditions (условие истинно)
     * 
     * Входные данные: client_type = 'phone', client_id = 555.
     * Конфиг extra: phone = conditions(client_type == phone)
     *   ? check_true field:client_id : check_false null.
     * Ожидание: context.phone = 555 — сработала ветка check_true.
     * Реальный кейс из create_lead.
     * 
     * @return void
     */
    private function testExtraWithConditions(): void
    {
        $this->logger->separator('testExtraWithConditions');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'extra' => [
                'phone' => [
                    'conditions' => ['field:client_type' => 'phone'],
                    'check_true' => 'field:client_id',
                    'check_false' => null
                ]
            ]
        ], ['client_type' => 'phone', 'client_id' => 555]);

        $this->assert(
            'testExtraWithConditions',
            555,
            $context->get('phone'),
            'При истинном условии должно вернуться check_true значение',
            ['extra' => 'phone с conditions']
        );
    }

    /**
     * Тест 3: Цепочка зависимостей extra-полей
     * 
     * Входные данные: client_string = 'contact_555'.
     * Конфиг extra: client_id = explode[1], client_type = explode[0].
     * Ожидание: client_id = '555', client_type = 'contact'.
     * Второе поле зависит от первого (оба читают field:client_string).
     * Реальный кейс из create_lead.
     * 
     * @return void
     */
    private function testExtraChainedDependency(): void
    {
        $this->logger->separator('testExtraChainedDependency');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'extra' => [
                'client_id' => [
                    'method' => 'explode',
                    'params' => ['_', 'field:client_string'],
                    'element' => 1
                ],
                'client_type' => [
                    'method' => 'explode',
                    'params' => ['_', 'field:client_string'],
                    'element' => 0
                ]
            ]
        ], ['client_string' => 'contact_555']);

        $this->assert(
            'testExtraChainedDependency_ID',
            '555',
            $context->get('client_id'),
            'client_id должен быть "555" (element=1)',
            ['extra' => 'client_id = explode[1]']
        );

        $this->assert(
            'testExtraChainedDependency_Type',
            'contact',
            $context->get('client_type'),
            'client_type должен быть "contact" (element=0)',
            ['extra' => 'client_type = explode[0]']
        );
    }
    
    // ========================================================
    // QUERY С CONDITIONS (тесты 4-5)
    // ========================================================

    /**
     * Тест 4: Query с conditions (условие истинно)
     * 
     * Входные данные: client_type = 'contact', client_id = 123.
     * Конфиг query: CONTACT = getMockContact(field:client_id)
     *   при условии client_type == contact.
     * Ожидание: context.CONTACT = ['ID' => 123, 'NAME' => 'Test Contact'].
     * 
     * @return void
     */
    private function testQueryWithConditions(): void
    {
        $this->logger->separator('testQueryWithConditions');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'CONTACT' => [
                    'method' => 'getMockContact',
                    'class' => MockQueryService::class,
                    'params' => ['field:client_id'],
                    'conditions' => ['field:client_type' => 'contact']
                ]
            ]
        ], ['client_type' => 'contact', 'client_id' => 123]);

        $this->assert(
            'testQueryWithConditions',
            ['ID' => 123, 'NAME' => 'Test Contact'],
            $context->get('CONTACT'),
            'Query должен выполниться при истинном условии',
            ['query' => 'CONTACT с conditions=true']
        );
    }

    /**
     * Тест 5: Query с conditions (условие ложно)
     * 
     * Входные данные: client_type = 'company' (условие contact не выполнено).
     * Ожидание: context.CONTACT = [] — запрос пропущен,
     * записан пустой массив (чтобы field:CONTACT.X давал null, а не ошибку).
     * 
     * @return void
     */
    private function testQueryConditionFalse(): void
    {
        $this->logger->separator('testQueryConditionFalse');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'CONTACT' => [
                    'method' => 'getMockContact',
                    'class' => MockQueryService::class,
                    'params' => ['field:client_id'],
                    'conditions' => ['field:client_type' => 'contact']
                ]
            ]
        ], ['client_type' => 'company', 'client_id' => 456]);

        $this->assert(
            'testQueryConditionFalse',
            [],
            $context->get('CONTACT'),
            'Query должен быть пропущен при ложном условии, записан пустой массив',
            ['query' => 'CONTACT с conditions=false']
        );
    }
    
    // ========================================================
    // ИТЕРАЦИОННЫЙ РЕЖИМ (тест 6)
    // ========================================================

    /**
     * Тест 6: Итерационный режим (request.array)
     * 
     * Входные данные: lead = массив из 3 итераций.
     * Конфиг: array = 'lead', extra processed_at = date('d.m.Y H:i:s').
     * Ожидание: после цикла в контексте данные ПОСЛЕДНЕЙ итерации
     * (client_3) и вычисленное поле processed_at.
     * 
     * @return void
     */
    private function testIterativeMode(): void
    {
        $this->logger->separator('testIterativeMode');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'array' => 'lead',
            'extra' => [
                'processed_at' => ['method' => 'date', 'params' => ['d.m.Y H:i:s']]
            ]
        ], [
            'lead' => [
                ['unified_client_id' => 'client_1'],
                ['unified_client_id' => 'client_2'],
                ['unified_client_id' => 'client_3']
            ]
        ]);

        $this->assert(
            'testIterativeMode_ClientId',
            'client_3',
            $context->get('unified_client_id'),
            'Последняя итерация должна обработать client_3',
            ['array' => 'lead', 'iterations' => 3]
        );

        $this->assert(
            'testIterativeMode_ProcessedAt',
            date('d.m.Y H:i:s'),
            $context->get('processed_at'),
            'Поле processed_at должно быть вычислено для итераций',
            ['extra' => 'processed_at']
        );
    }
    
    // ========================================================
    // ON_ERROR В QUERY (тесты 7-8)
    // ========================================================

    /**
     * Тест 7: Query с on_error — исключение подавлено
     * 
     * Метод throwingGetById бросает TypeError (имитация ORM getById
     * с возвратом false при сигнатуре ?array).
     * Конфиг: on_error = [].
     * Ожидание: context.CONTACT = [] (fallback), ошибки в контексте НЕТ.
     * Итерация продолжается в режиме partial.
     * 
     * @return void
     */
    private function testQueryWithOnError(): void
    {
        $this->logger->separator('testQueryWithOnError');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'CONTACT' => [
                    'method' => 'throwingGetById',
                    'class' => MockQueryService::class,
                    'params' => ['field:client_id'],
                    'on_error' => []
                ]
            ]
        ], ['client_id' => 999]);

        $this->assert(
            'testQueryWithOnError_CONTACT',
            [],
            $context->get('CONTACT'),
            'Query с on_error должен записать fallback вместо ошибки',
            ['query' => 'CONTACT с on_error=[]']
        );

        $this->assert(
            'testQueryWithOnError_NoError',
            false,
            $context->hasError(),
            'Контекст не должен содержать ошибку при on_error',
            ['check' => 'hasError() === false']
        );
    }

    /**
     * Тест 8: Query ошибка БЕЗ on_error
     * 
     * Тот же throwingGetById, но без on_error.
     * Ожидание: context.hasError() = true — ошибка записывается
     * в контекст (итерационная ошибка, прерывает итерацию).
     * 
     * @return void
     */
    private function testQueryErrorWithoutOnError(): void
    {
        $this->logger->separator('testQueryErrorWithoutOnError');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'CONTACT' => [
                    'method' => 'throwingGetById',
                    'class' => MockQueryService::class,
                    'params' => ['field:client_id']
                ]
            ]
        ], ['client_id' => 999]);

        $this->assert(
            'testQueryError_HasError',
            true,
            $context->hasError(),
            'Query без on_error должен установить ошибку в контекст',
            ['check' => 'hasError() === true']
        );
    }

    // ========================================================
    // NO_LOG ФЛАГ (тесты 9-11, v1.3.0)
    // ========================================================

    /**
     * Тест 9: no_log=true скрывает поле из computed
     * 
     * Входные данные: tasks = список из 2 задач.
     * Конфиг extra: enriched_tasks = enrichTasks(tasks) с no_log=true.
     * Ожидание:
     * - context.enriched_tasks доступен и содержит обогащённые данные
     * - getComputedData() НЕ содержит ключ enriched_tasks
     * 
     * @return void
     */
    private function testNoLogHidesFromComputed(): void
    {
        $this->logger->separator('testNoLogHidesFromComputed');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'extra' => [
                'enriched_tasks' => [
                    'method' => 'enrichTasks',
                    'class' => MockEnrichmentService::class,
                    'params' => ['field:tasks'],
                    'no_log' => true,
                ]
            ]
        ], [
            'tasks' => [
                ['id' => 1, 'unified_client_id' => 'client_100', 'id_user' => 42],
                ['id' => 2, 'unified_client_id' => 'client_200', 'id_user' => 7],
            ]
        ]);

        // Проверка 1: значение доступно в контексте
        $enrichedTasks = $context->get('enriched_tasks');
        $this->assert(
            'testNoLogHidesFromComputed_Available',
            true,
            is_array($enrichedTasks) && count($enrichedTasks) === 2,
            'enriched_tasks должен быть доступен через field:',
            ['check' => 'context.get("enriched_tasks") is array']
        );

        // Проверка 2: обогащение сработало
        $this->assert(
            'testNoLogHidesFromComputed_Enriched',
            'Иванов Иван',
            $enrichedTasks[0]['client_name'] ?? null,
            'Первая задача должна содержать client_name после обогащения',
            ['check' => 'enriched_tasks[0].client_name']
        );

        // Проверка 3: поле скрыто из computed
        $computed = $context->getComputedData();
        $this->assert(
            'testNoLogHidesFromComputed_Hidden',
            false,
            array_key_exists('enriched_tasks', $computed),
            'enriched_tasks НЕ должен попадать в getComputedData()',
            ['check' => '!array_key_exists("enriched_tasks", computed)']
        );
    }

    /**
     * Тест 10: Без no_log поле попадает в computed (обычное поведение)
     * 
     * Тот же enrichTasks, но без флага no_log.
     * Ожидание: enriched_tasks ЕСТЬ в getComputedData().
     * 
     * @return void
     */
    private function testNoLogAbsentFieldVisible(): void
    {
        $this->logger->separator('testNoLogAbsentFieldVisible');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'extra' => [
                'enriched_tasks' => [
                    'method' => 'enrichTasks',
                    'class' => MockEnrichmentService::class,
                    'params' => ['field:tasks'],
                    // no_log отсутствует
                ]
            ]
        ], [
            'tasks' => [
                ['id' => 1, 'unified_client_id' => 'client_100', 'id_user' => 42],
            ]
        ]);

        $computed = $context->getComputedData();
        $this->assert(
            'testNoLogAbsentFieldVisible',
            true,
            array_key_exists('enriched_tasks', $computed),
            'Без no_log поле должно попадать в computed (обратная совместимость)',
            ['check' => 'array_key_exists("enriched_tasks", computed)']
        );
    }

    /**
     * Тест 11: no_log поле работает в compose (интеграционный тест)
     * 
     * Конфиг: extra с no_log → compose берёт field:enriched_tasks.
     * Ожидание: ответ содержит обогащённые данные (no_log не ломает работу).
     * 
     * @return void
     */
    private function testNoLogWorksInCompose(): void
    {
        $this->logger->separator('testNoLogWorksInCompose');

        $config = [
            'test_no_log_compose' => [
                'request' => [
                    'main' => 'post',
                    'extra' => [
                        'enriched_tasks' => [
                            'method' => 'enrichTasks',
                            'class' => MockEnrichmentService::class,
                            'params' => ['field:tasks'],
                            'no_log' => true,
                        ]
                    ]
                ],
                'compose' => [
                    'tasks' => 'field:enriched_tasks',
                ],
                'action_logic' => ['request', 'compose'],
            ],
        ];

        $interpreter = new Interpreter($config);
        $response = $interpreter->run('test_no_log_compose', 'test', [
            'tasks' => [
                ['id' => 1, 'unified_client_id' => 'client_100', 'id_user' => 42],
            ]
        ]);

        $this->assert(
            'testNoLogWorksInCompose',
            'Иванов Иван',
            $response->response['tasks'][0]['client_name'] ?? null,
            'no_log поле должно работать в compose (не ломает ответ)',
            ['check' => 'response.tasks[0].client_name']
        );
    }

        // ========================================================
    // CONSTRUCT (v1.6.0)
    // ========================================================

    /**
     * Тест: construct со скалярными аргументами (field: + литерал)
     * 
     * @return void
     */
    private function testConstructScalarArgs(): void
    {
        $this->logger->separator('testConstructScalarArgs');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'RESULT' => [
                    'method' => 'tag',
                    'class' => MockCtorService::class,
                    'construct' => ['field:block_name', 5],
                ],
            ],
        ], ['block_name' => 'leads']);

        $this->assert(
            'testConstructScalarArgs',
            'leads:5',
            $context->get('RESULT'),
            'construct: аргументы конструктора резолвятся из field: и литералов',
            ['construct' => ['field:block_name', 5]]
        );
    }

    /**
     * Тест: 4 уровня вложенных экземпляров через new
     * 
     * @return void
     */
    private function testConstructNestedFourLevels(): void
    {
        $this->logger->separator('testConstructNestedFourLevels');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'RESULT' => [
                    'method' => 'run',
                    'class' => MockCtorLevel4Root::class,
                    'construct' => [
                        [   // уровень 2
                            'new' => MockCtorLevel3::class,
                            'construct' => [
                                [   // уровень 3
                                    'new' => MockCtorNested::class,
                                    'construct' => [
                                        [   // уровень 4
                                            'new' => MockCtorService::class,
                                            'construct' => ['field:block_name', 7],
                                        ],
                                        'field:suffix',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['block_name' => 'tasks', 'suffix' => 'ok']);

        $this->assert(
            'testConstructNestedFourLevels',
            'L4:L3:tasks:7/ok',
            $context->get('RESULT'),
            'construct: 4 уровня вложенности строятся изнутри наружу',
            ['levels' => 4]
        );
    }

    /**
     * Тест: нет construct + обязательные параметры конструктора
     * → понятная ошибка с подсказкой
     * 
     * @return void
     */
    private function testConstructMissingRequired(): void
    {
        $this->logger->separator('testConstructMissingRequired');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'RESULT' => [
                    'method' => 'tag',
                    'class' => MockCtorService::class,
                    // construct НЕ указан
                ],
            ],
        ], []);

        $this->assert(
            'testConstructMissingRequired_HasError',
            true,
            $context->hasError(),
            'Без construct обязательный параметр конструктора = ошибка',
            ['error' => $context->error]
        );

        $this->assert(
            'testConstructMissingRequired_Hint',
            true,
            strpos($context->error['detailed_message'] ?? '', 'construct') !== false,
            'В ошибке — подсказка про construct и список параметров',
            ['detailed' => $context->error['detailed_message'] ?? null]
        );
    }

    /**
     * Тест: обратная совместимость — класс без обязательного
     * конструктора работает без construct как раньше
     * 
     * @return void
     */
    private function testConstructBackCompat(): void
    {
        $this->logger->separator('testConstructBackCompat');

        [$request, $context] = $this->createRequest([]);

        $request->execute([
            'main' => 'post',
            'query' => [
                'CONTACT' => [
                    'method' => 'getMockContact',
                    'class' => MockQueryService::class,
                    'params' => ['field:client_id'],
                ],
            ],
        ], ['client_id' => 123]);

        $this->assert(
            'testConstructBackCompat',
            ['ID' => 123, 'NAME' => 'Test Contact'],
            $context->get('CONTACT'),
            'Без construct поведение не изменилось (new Class())',
            ['class' => 'MockQueryService']
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
            $this->logger->success('RequestTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('RequestTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}

// ============================================================
// МОК-КЛАСС ДЛЯ QUERY
// ============================================================

/**
 * Class MockQueryService
 * 
 * Мок-класс для тестирования query запросов Request Executor.
 * Имитирует getById/getList сущностей Битрикс.
 * 
 * @package Api\Services\Actions\Testing
 */
class MockQueryService
{
    /**
     * Имитация getById контакта
     * 
     * @param int $contactId ID контакта
     * @return array Данные контакта
     */
    public function getMockContact(int $contactId): array
    {
        return ['ID' => $contactId, 'NAME' => 'Test Contact'];
    }

    /**
     * Имитация getById компании
     * 
     * @param int $companyId ID компании
     * @return array Данные компании
     */
    public function getMockCompany(int $companyId): array
    {
        return ['ID' => $companyId, 'TITLE' => 'Test Company'];
    }

    /**
     * Имитация ошибки ORM: getById возвращает false при сигнатуре ?array
     * (PHP бросает TypeError — реальный кейс из продакшена)
     * 
     * @param int $contactId ID контакта
     * @return array Никогда не возвращается — бросает исключение
     * @throws \TypeError Всегда
     */
    public function throwingGetById(int $contactId): array
    {
        throw new \TypeError('Return value must be of type ?array, false returned');
    }
}

// ============================================================
// МОК-КЛАСС ДЛЯ ENRICHMENT (v1.3.0)
// ============================================================

/**
 * Class MockEnrichmentService
 * 
 * Мок-класс для тестирования флага no_log.
 * Имитирует обогащение списка задач именами клиентов и менеджеров.
 * 
 * @package Api\Services\Actions\Testing
 */
class MockEnrichmentService
{
    /**
     * Имитация enrichTasks: добавляет client_name и assigned_name
     * 
     * @param array $tasks Список задач
     * @return array Обогащённый список задач
     */
    public function enrichTasks(array $tasks): array
    {
        foreach ($tasks as &$task) {
            // Имитация batch-запроса: в реальности здесь были бы
            // Contact::getByIds() и User::getByIds()
            $task['client_name'] = 'Иванов Иван';
            $task['assigned_name'] = 'Петров Пётр';
        }
        unset($task);

        return $tasks;
    }
}

// ============================================================
// МОКИ ДЛЯ CONSTRUCT (v1.6.0) — цепочка из 4 уровней
// ============================================================

/**
 * Уровень 4 (лист): конструктор со скалярами
 */
class MockCtorService
{
    public function __construct(
        public string $block,
        public int $limit = 10
    ) {}

    public function tag(): string
    {
        return $this->block . ':' . $this->limit;
    }
}

/**
 * Уровень 3: принимает экземпляр MockCtorService
 */
class MockCtorNested
{
    public function __construct(
        public MockCtorService $inner,
        public string $suffix
    ) {}

    public function deep(): string
    {
        return $this->inner->tag() . '/' . $this->suffix;
    }
}

/**
 * Уровень 2: принимает экземпляр MockCtorNested
 */
class MockCtorLevel3
{
    public function __construct(public MockCtorNested $mid) {}

    public function top(): string
    {
        return 'L3:' . $this->mid->deep();
    }
}

/**
 * Уровень 1 (корень): принимает экземпляр MockCtorLevel3
 */
class MockCtorLevel4Root
{
    public function __construct(public MockCtorLevel3 $l3) {}

    public function run(): string
    {
        return 'L4:' . $this->l3->top();
    }
}
