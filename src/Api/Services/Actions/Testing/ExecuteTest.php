<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Resolver\Method;
use Api\Services\Actions\Executor\Execute;

/**
 * Class ExecuteTest
 * 
 * Тесты для класса Execute (Executor).
 * 
 * Execute Executor — исполнитель бизнес-логики (блок execute).
 * Обрабатывает цепочку if/elseif/else:
 * - check: 'if' / 'elseif' / 'else'
 * - Условия через filter (Condition Resolver) или method+class (вызов метода)
 * - Выполняется ПЕРВЫЙ истинный блок, остальные пропускаются
 * - actions: skip (пропуск итерации), method (вызов с response),
 *   actions с собственными conditions
 * - response: сбор данных в context.response через result / result:key / field:
 * 
 * Лог: Execute_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 7
 * 
 * @package Api\Services\Actions\Testing
 */
class ExecuteTest
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
     * ExecuteTest constructor.
     * 
     * Создаёт логгер с префиксом 'Execute' — все записи идут
     * в файл Execute_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Execute');
    }

    /**
     * Создаёт связку Context + резолверы + Execute для тестов
     * 
     * @param array $data Данные для контекста
     * @return array [Execute, Context]
     */
    private function createExecute(array $data = []): array
    {
        $context = new Context($data);
        $context->setTestLogger($this->logger);
        $fieldResolver = new Field($context);
        $conditionResolver = new Condition($context, $fieldResolver);
        $methodResolver = new Method($context, $fieldResolver);
        $execute = new Execute($context, $fieldResolver, $conditionResolver, $methodResolver);
        return [$execute, $context];
    }

    /**
     * Запускает все тесты Execute Executor
     * 
     * Порядок логический, от простого к сложному:
     * - if/else (1-2)
     * - skip (3)
     * - method с response (4)
     * - conditions в actions (5)
     * - elseif-цепочка (6)
     * - else-блок (7)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('ExecuteTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Execute Executor ===');

        // if/else
        $this->testIfConditionTrue();
        $this->testIfConditionFalseElseExecutes();

        // skip
        $this->testSkipAction();

        // method с response
        $this->testMethodActionWithResponse();

        // conditions в actions
        $this->testActionWithConditions();

        // elseif-цепочка и else
        $this->testElseifChain();
        $this->testElseBlock();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "EXECUTE TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // IF / ELSE (тесты 1-2)
    // ========================================================

    /**
     * Тест 1: IF условие истинно — выполняется skip, else пропускается
     * 
     * Контекст: unified_client_id = '' (пустой).
     * Конфиг: if OR(empty(unified), empty(segment)) → skip; else → method.
     * Ожидание: response = [{status: SKIPPED}] — сработал if,
     * else-блок НЕ выполнен (логика if/elseif/else).
     * Реальный кейс из create_lead (первый if-блок).
     * 
     * @return void
     */
    private function testIfConditionTrue(): void
    {
        $this->logger->separator('testIfConditionTrue');

        [$execute, $context] = $this->createExecute([
            'unified_client_id' => '',
            'segment_code' => 'ABC'
        ]);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => [
                    'logic' => 'OR',
                    'field:unified_client_id' => 'func:empty',
                    'field:segment_code' => 'func:empty'
                ],
                'actions' => [['skip' => true]]
            ],
            [
                'check' => 'else',
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['should_not_execute']
                    ]
                ]
            ]
        ]);

        $expected = [['status' => 'SKIPPED', 'iteration' => null]];

        $this->assert(
            'testIfConditionTrue',
            $expected,
            $context->response,
            'При истинном IF должен выполниться skip, else пропущен',
            ['check' => 'if true → skip']
        );
    }

    /**
     * Тест 2: IF условие ложно — выполняется ELSE
     * 
     * Контекст: unified_client_id = 'abc123', segment_code = 'ABC'.
     * Конфиг: if (оба не пустые → false) → skip; else → method с response.
     * Ожидание: response = [{result_value: 'INSTANCE:else_executed'}] —
     * if пропущен, выполнен else.
     * 
     * @return void
     */
    private function testIfConditionFalseElseExecutes(): void
    {
        $this->logger->separator('testIfConditionFalseElseExecutes');

        [$execute, $context] = $this->createExecute([
            'unified_client_id' => 'abc123',
            'segment_code' => 'ABC'
        ]);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => [
                    'logic' => 'OR',
                    'field:unified_client_id' => 'func:empty',
                    'field:segment_code' => 'func:empty'
                ],
                'actions' => [['skip' => true]]
            ],
            [
                'check' => 'else',
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['else_executed'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ]
        ]);

        $expected = [['result_value' => 'INSTANCE:else_executed']];

        $this->assert(
            'testIfConditionFalseElseExecutes',
            $expected,
            $context->response,
            'При ложном IF должен выполниться ELSE',
            ['check' => 'if false → else']
        );
    }
    
    // ========================================================
    // SKIP (тест 3)
    // ========================================================

    /**
     * Тест 3: Skip action записывает SKIPPED в response
     * 
     * Контекст: dealer_center_id = 999.
     * Конфиг: if field:dealer_center_id == 999 → skip.
     * Ожидание: response = [{status: SKIPPED, iteration: null}].
     * 
     * @return void
     */
    private function testSkipAction(): void
    {
        $this->logger->separator('testSkipAction');

        [$execute, $context] = $this->createExecute(['dealer_center_id' => 999]);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => ['field:dealer_center_id' => 999],
                'actions' => [['skip' => true]]
            ]
        ]);

        $expected = [['status' => 'SKIPPED', 'iteration' => null]];

        $this->assert(
            'testSkipAction',
            $expected,
            $context->response,
            'Skip должен записать SKIPPED в response',
            ['actions' => 'skip: true']
        );
    }
    
    // ========================================================
    // METHOD С RESPONSE (тест 4)
    // ========================================================

    /**
     * Тест 4: Method action с response (result целиком)
     * 
     * Контекст: LEAD.ID = 789.
     * Конфиг: if !empty(LEAD.ID) → update с response lead_id = result.
     * Ожидание: response = [{lead_id: {ID: 789, UPDATED: true}}] —
     * результат метода записан через 'result'.
     * Реальный кейс из create_lead (elseif update лида).
     * 
     * @return void
     */
    private function testMethodActionWithResponse(): void
    {
        $this->logger->separator('testMethodActionWithResponse');

        [$execute, $context] = $this->createExecute(['LEAD' => ['ID' => 789]]);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => ['!field:LEAD.ID' => 'func:empty'],
                'actions' => [
                    [
                        'method' => 'getMockUpdateResult',
                        'class' => MockExecuteService::class,
                        'params' => ['field:LEAD.ID'],
                        'response' => ['lead_id' => 'result']
                    ]
                ]
            ]
        ]);

        $expected = [['lead_id' => ['ID' => 789, 'UPDATED' => true]]];

        $this->assert(
            'testMethodActionWithResponse',
            $expected,
            $context->response,
            'Метод должен выполниться и result записаться в response',
            ['response' => "lead_id = 'result'"]
        );
    }
    
    // ========================================================
    // CONDITIONS В ACTIONS (тест 5)
    // ========================================================

    /**
     * Тест 5: Action с conditions (условие ложно) — action пропущен
     * 
     * Контекст: do_not_contact_flag = 0.
     * Конфиг: if (без filter → true) → action с conditions(flag == 1).
     * Ожидание: response = [] — action пропущен из-за ложного conditions.
     * Реальный кейс из create_lead (update контакта при do_not_contact).
     * 
     * @return void
     */
    private function testActionWithConditions(): void
    {
        $this->logger->separator('testActionWithConditions');

        [$execute, $context] = $this->createExecute(['do_not_contact_flag' => 0]);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => [],
                'actions' => [
                    [
                        'conditions' => ['field:do_not_contact_flag' => 1],
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['should_not_execute'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ]
        ]);

        $this->assert(
            'testActionWithConditions',
            [],
            $context->response,
            'Action с ложным conditions должен быть пропущен',
            ['conditions' => 'do_not_contact_flag == 1 (false)']
        );
    }
    
    // ========================================================
    // ELSEIF-ЦЕПОЧКА И ELSE (тесты 6-7)
    // ========================================================

    /**
     * Тест 6: Elseif-цепочка — выполняется первый истинный блок
     * 
     * Контекст: value = 'second'.
     * Конфиг: if(first) → elseif(second) → elseif(third).
     * Ожидание: response = [{result_value: 'INSTANCE:second'}] —
     * выполнен только второй блок, третий пропущен.
     * 
     * @return void
     */
    private function testElseifChain(): void
    {
        $this->logger->separator('testElseifChain');

        [$execute, $context] = $this->createExecute(['value' => 'second']);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => ['field:value' => 'first'],
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['first'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ],
            [
                'check' => 'elseif',
                'filter' => ['field:value' => 'second'],
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['second'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ],
            [
                'check' => 'elseif',
                'filter' => ['field:value' => 'third'],
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['third'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ]
        ]);

        $expected = [['result_value' => 'INSTANCE:second']];

        $this->assert(
            'testElseifChain',
            $expected,
            $context->response,
            'Должен выполниться первый истинный elseif (second)',
            ['check' => 'if false → elseif true → elseif skipped']
        );
    }

    /**
     * Тест 7: Else-блок — все if/elseif ложны
     * 
     * Контекст: value = 'unknown'.
     * Конфиг: if(first) → elseif(second) → else.
     * Ожидание: response = [{result_value: 'INSTANCE:else_fallback'}] —
     * выполнен else как fallback.
     * 
     * @return void
     */
    private function testElseBlock(): void
    {
        $this->logger->separator('testElseBlock');

        [$execute, $context] = $this->createExecute(['value' => 'unknown']);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => ['field:value' => 'first'],
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['first'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ],
            [
                'check' => 'elseif',
                'filter' => ['field:value' => 'second'],
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['second'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ],
            [
                'check' => 'else',
                'actions' => [
                    [
                        'method' => 'instanceTransform',
                        'class' => MockExecuteService::class,
                        'params' => ['else_fallback'],
                        'response' => ['result_value' => 'result']
                    ]
                ]
            ]
        ]);

        $expected = [['result_value' => 'INSTANCE:else_fallback']];

        $this->assert(
            'testElseBlock',
            $expected,
            $context->response,
            'Когда все if/elseif ложны, должен выполниться else',
            ['check' => 'if false → elseif false → else']
        );
    }

    /**
     * Тест 8: Действие только с response (без method)
     * 
     * Конфиг: if (всегда true) → action с response {task_id, error}.
     * Ожидание: запись появляется в response без вызова метода —
     * иначе такие действия молча терялись (баг task_id 8).
     * 
     * @return void
     */
    private function testResponseOnlyAction(): void
    {
        $this->logger->separator('testResponseOnlyAction: response без method');

        [$execute, $context] = $this->createExecute(['task_id' => '8']);

        $execute->execute([
            [
                'check' => 'if',
                'filter' => [],
                'actions' => [
                    [
                        'response' => [
                            'task_id' => 'field:task_id',
                            'error' => 'Номер телефона не валиден'
                        ]
                    ]
                ]
            ]
        ]);

        $expected = [[
            'task_id' => '8',
            'error' => 'Номер телефона не валиден'
        ]];

        $this->assert(
            'testResponseOnlyAction',
            $expected,
            $context->response,
            'Response-only действие должно записать запись без method',
            ['actions' => 'response без method']
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
            $this->logger->success('ExecuteTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('ExecuteTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}

// ============================================================
// МОК-КЛАСС ДЛЯ EXECUTE
// ============================================================

/**
 * Class MockExecuteService
 * 
 * Мок-класс для тестирования Execute Executor.
 * Содержит методы для actions и метод-условие isUnactive.
 * 
 * @package Api\Services\Actions\Testing
 */
class MockExecuteService
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
     * Имитация update лида (возвращает массив результата)
     * 
     * @param int $leadId ID лида
     * @return array ['ID' => leadId, 'UPDATED' => true]
     */
    public function getMockUpdateResult(int $leadId): array
    {
        return ['ID' => $leadId, 'UPDATED' => true];
    }

    /**
     * Статический метод-условие: активность дилерского центра
     * (используется в elseif-блоке create_lead)
     * 
     * @param int $dealerCenterId ID дилерского центра
     * @return bool true если центр неактивен (> 1000)
     */
    public static function isUnactive(int $dealerCenterId): bool
    {
        return $dealerCenterId > 1000;
    }
}
