<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;

/**
 * Class ConditionTest
 * 
 * Тесты для класса Condition (Resolver).
 * 
 * Condition Resolver — вычислитель условий интерпретатора.
 * Используется в extra (conditions), query (conditions), execute (filter)
 * и curl (conditions). Поддерживает:
 * - Простые равенства: 'field:x' => value (нестрогое сравнение ==)
 * - Проверки через PHP-функции: 'field:x' => 'func:empty', 'func:is_numeric'
 * - Проверки через методы классов: 'field:x' => 'method:Class->method'
 * - Отрицание: '!field:x' => 'func:empty'
 * - Логические операторы: 'logic' => 'AND' | 'OR' (по умолчанию AND)
 * - Вложенные условия (массивы с числовыми ключами)
 * 
 * Лог: Condition_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 14
 * 
 * @package Api\Services\Actions\Testing
 */
class ConditionTest
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
     * ConditionTest constructor.
     * 
     * Создаёт логгер с префиксом 'Condition' — все записи идут
     * в файл Condition_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Condition');
    }

    /**
     * Запускает все тесты Condition Resolver
     * 
     * Порядок логический, от простого к сложному:
     * - Пустой фильтр (1)
     * - Простые равенства (2-4)
     * - PHP-функции (5-7)
     * - Отрицание (8)
     * - Логические операторы (9-12)
     * - Вложенные условия (13-14)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('ConditionTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Condition Resolver ===');

        // Пустой фильтр
        $this->testEmptyFilter();

        // Простые равенства
        $this->testSimpleEquality();
        $this->testSimpleEqualityNumeric();
        $this->testSimpleEqualityFalse();

        // PHP-функции
        $this->testFuncEmpty();
        $this->testFuncEmptyWithValue();
        $this->testFuncIsNumeric();

        // Отрицание
        $this->testNegation();

        // Логические операторы
        $this->testLogicAnd();
        $this->testLogicOr();
        $this->testLogicAndAllFalse();
        $this->testLogicOrAllFalse();

        // Вложенные условия
        $this->testNestedConditions();
        $this->testComplexNestedConditions();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "CONDITION TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // ПУСТОЙ ФИЛЬТР (тест 1)
    // ========================================================

    /**
     * Тест 1: Пустой фильтр
     * 
     * evaluate([]) — условий нет.
     * Ожидание: true — отсутствие условий означает "условие выполнено".
     * Это важно для блоков execute без filter и actions без conditions.
     * 
     * @return void
     */
    private function testEmptyFilter(): void
    {
        $this->logger->separator('testEmptyFilter: Пустой фильтр');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testEmptyFilter',
            true,
            $condition->evaluate([]),
            'Пустой фильтр должен вернуть true',
            ['filter' => []]
        );
    }
    
    // ========================================================
    // ПРОСТЫЕ РАВЕНСТВА (тесты 2-4)
    // ========================================================

    /**
     * Тест 2: Простое равенство строк
     * 
     * Контекст: client_type = 'contact'.
     * Условие: field:client_type == 'contact'.
     * Ожидание: true.
     * 
     * @return void
     */
    private function testSimpleEquality(): void
    {
        $this->logger->separator('testSimpleEquality: Простое равенство (строка)');

        $context = new Context(['client_type' => 'contact']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testSimpleEquality',
            true,
            $condition->evaluate(['field:client_type' => 'contact']),
            'Условие field:client_type == "contact" должно быть true',
            ['filter' => ['field:client_type' => 'contact']]
        );
    }

    /**
     * Тест 3: Простое равенство чисел (нестрогое)
     * 
     * Контекст: manager_active_flag = 1 (int).
     * Условие: field:manager_active_flag == 1.
     * Ожидание: true — работает и со строкой '1' благодаря нестрогому ==.
     * 
     * @return void
     */
    private function testSimpleEqualityNumeric(): void
    {
        $this->logger->separator('testSimpleEqualityNumeric: Простое равенство (число)');

        $context = new Context(['manager_active_flag' => 1]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testSimpleEqualityNumeric',
            true,
            $condition->evaluate(['field:manager_active_flag' => 1]),
            'Условие field:manager_active_flag == 1 должно быть true',
            ['filter' => ['field:manager_active_flag' => 1]]
        );
    }

    /**
     * Тест 4: Простое равенство — несовпадение
     * 
     * Контекст: client_type = 'company'.
     * Условие: field:client_type == 'contact'.
     * Ожидание: false.
     * 
     * @return void
     */
    private function testSimpleEqualityFalse(): void
    {
        $this->logger->separator('testSimpleEqualityFalse: Простое равенство (несовпадение)');

        $context = new Context(['client_type' => 'company']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testSimpleEqualityFalse',
            false,
            $condition->evaluate(['field:client_type' => 'contact']),
            'Условие field:client_type == "contact" должно быть false когда client_type = "company"',
            ['filter' => ['field:client_type' => 'contact']]
        );
    }
    
    // ========================================================
    // PHP-ФУНКЦИИ (тесты 5-7)
    // ========================================================

    /**
     * Тест 5: func:empty на пустом значении
     * 
     * Контекст: unified_client_id = ''.
     * Условие: field:unified_client_id => func:empty.
     * Ожидание: true — empty('') = true.
     * 
     * @return void
     */
    private function testFuncEmpty(): void
    {
        $this->logger->separator('testFuncEmpty: func:empty на пустом значении');

        $context = new Context(['unified_client_id' => '']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testFuncEmpty',
            true,
            $condition->evaluate(['field:unified_client_id' => 'func:empty']),
            'empty("") должно быть true',
            ['filter' => ['field:unified_client_id' => 'func:empty']]
        );
    }

    /**
     * Тест 6: func:empty на непустом значении
     * 
     * Контекст: unified_client_id = 'abc123'.
     * Условие: field:unified_client_id => func:empty.
     * Ожидание: false — empty('abc123') = false.
     * 
     * @return void
     */
    private function testFuncEmptyWithValue(): void
    {
        $this->logger->separator('testFuncEmptyWithValue: func:empty на непустом');

        $context = new Context(['unified_client_id' => 'abc123']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testFuncEmptyWithValue',
            false,
            $condition->evaluate(['field:unified_client_id' => 'func:empty']),
            'empty("abc123") должно быть false',
            ['filter' => ['field:unified_client_id' => 'func:empty']]
        );
    }

    /**
     * Тест 7: func:is_numeric на числовой строке
     * 
     * Контекст: dealer_center_id = '42' (строка).
     * Условие: field:dealer_center_id => func:is_numeric.
     * Ожидание: true — is_numeric('42') = true.
     * 
     * @return void
     */
    private function testFuncIsNumeric(): void
    {
        $this->logger->separator('testFuncIsNumeric: func:is_numeric');

        $context = new Context(['dealer_center_id' => '42']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testFuncIsNumeric',
            true,
            $condition->evaluate(['field:dealer_center_id' => 'func:is_numeric']),
            'is_numeric("42") должно быть true',
            ['filter' => ['field:dealer_center_id' => 'func:is_numeric']]
        );
    }
    
    // ========================================================
    // ОТРИЦАНИЕ (тест 8)
    // ========================================================

    /**
     * Тест 8: Отрицание !field:...
     * 
     * Контекст: LEAD.ID = 999.
     * Условие: !field:LEAD.ID => func:empty.
     * Ожидание: true — !empty(999) = true.
     * Используется в execute для проверки "лид уже существует".
     * 
     * @return void
     */
    private function testNegation(): void
    {
        $this->logger->separator('testNegation: Отрицание !');

        $context = new Context(['LEAD' => ['ID' => 999]]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testNegation',
            true,
            $condition->evaluate(['!field:LEAD.ID' => 'func:empty']),
            '!empty(999) должно быть true',
            ['filter' => ['!field:LEAD.ID' => 'func:empty']]
        );
    }
    
    // ========================================================
    // ЛОГИЧЕСКИЕ ОПЕРАТОРЫ (тесты 9-12)
    // ========================================================

    /**
     * Тест 9: Логика AND — все условия true
     * 
     * Контекст: a = 1, b = 2.
     * Условие: logic AND, field:a == 1, field:b == 2.
     * Ожидание: true — все условия выполнены.
     * 
     * @return void
     */
    private function testLogicAnd(): void
    {
        $this->logger->separator('testLogicAnd: AND (все true)');

        $context = new Context(['a' => 1, 'b' => 2]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testLogicAnd',
            true,
            $condition->evaluate([
                'logic' => 'AND',
                'field:a' => 1,
                'field:b' => 2
            ]),
            'a=1 AND b=2 должно быть true',
            ['filter' => ['logic' => 'AND', 'field:a' => 1, 'field:b' => 2]]
        );
    }

    /**
     * Тест 10: Логика OR — одно условие true
     * 
     * Контекст: a = 1, b = 0.
     * Условие: logic OR, field:a == 1, field:b == 2.
     * Ожидание: true — достаточно одного истинного условия.
     * 
     * @return void
     */
    private function testLogicOr(): void
    {
        $this->logger->separator('testLogicOr: OR (одно true)');

        $context = new Context(['a' => 1, 'b' => 0]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testLogicOr',
            true,
            $condition->evaluate([
                'logic' => 'OR',
                'field:a' => 1,
                'field:b' => 2
            ]),
            'a=1 OR b=2 (при b=0) должно быть true',
            ['filter' => ['logic' => 'OR', 'field:a' => 1, 'field:b' => 2]]
        );
    }

    /**
     * Тест 11: Логика AND — все условия false
     * 
     * Контекст: a = 0, b = 0.
     * Условие: logic AND, field:a == 1, field:b == 2.
     * Ожидание: false — для AND нужно чтобы все были true.
     * 
     * @return void
     */
    private function testLogicAndAllFalse(): void
    {
        $this->logger->separator('testLogicAndAllFalse: AND (все false)');

        $context = new Context(['a' => 0, 'b' => 0]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testLogicAndAllFalse',
            false,
            $condition->evaluate([
                'logic' => 'AND',
                'field:a' => 1,
                'field:b' => 2
            ]),
            'a=1 AND b=2 (при a=0, b=0) должно быть false',
            ['filter' => ['logic' => 'AND', 'field:a' => 1, 'field:b' => 2]]
        );
    }

    /**
     * Тест 12: Логика OR — все условия false
     * 
     * Контекст: unified_client_id = 'abc', segment_code = 'ABC'.
     * Условие: logic OR, оба func:empty.
     * Ожидание: false — для OR нужно хотя бы одно true.
     * Это реальный кейс из create_lead (первый if-блок).
     * 
     * @return void
     */
    private function testLogicOrAllFalse(): void
    {
        $this->logger->separator('testLogicOrAllFalse: OR (все false)');

        $context = new Context(['unified_client_id' => 'abc', 'segment_code' => 'ABC']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testLogicOrAllFalse',
            false,
            $condition->evaluate([
                'logic' => 'OR',
                'field:unified_client_id' => 'func:empty',
                'field:segment_code' => 'func:empty'
            ]),
            'empty("abc") || empty("ABC") должно быть false',
            ['filter' => [
                'logic' => 'OR',
                'field:unified_client_id' => 'func:empty',
                'field:segment_code' => 'func:empty'
            ]]
        );
    }
    
    // ========================================================
    // ВЛОЖЕННЫЕ УСЛОВИЯ (тесты 13-14)
    // ========================================================

    /**
     * Тест 13: Вложенные условия — внешнее OR истинно
     * 
     * Контекст: unified_client_id = '', segment_code = 'ABC'.
     * Условие: OR( empty(unified), AND( !empty(unified), empty(segment) ) ).
     * Ожидание: true — первое условие OR истинно (unified пустой).
     * 
     * @return void
     */
    private function testNestedConditions(): void
    {
        $this->logger->separator('testNestedConditions: Вложенные условия');

        $context = new Context(['unified_client_id' => '', 'segment_code' => 'ABC']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testNestedConditions',
            true,
            $condition->evaluate([
                'logic' => 'OR',
                'field:unified_client_id' => 'func:empty',
                0 => [
                    'logic' => 'AND',
                    '!field:unified_client_id' => 'func:empty',
                    'field:segment_code' => 'func:empty'
                ]
            ]),
            'Вложенное условие с OR должно вернуть true',
            ['filter' => 'OR(empty(unified), AND(!empty(unified), empty(segment)))']
        );
    }

    /**
     * Тест 14: Вложенные условия — оба блока false
     * 
     * Контекст: unified_client_id = 'abc123', segment_code = 'ABC'.
     * Условие: OR( empty(unified), AND( !empty(unified), empty(segment) ) ).
     * Ожидание: false — первое false, вложенный AND тоже false
     * (segment не пустой). Реальный кейс из create_lead.
     * 
     * @return void
     */
    private function testComplexNestedConditions(): void
    {
        $this->logger->separator('testComplexNestedConditions: Сложные вложенные');

        $context = new Context(['unified_client_id' => 'abc123', 'segment_code' => 'ABC']);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $condition = new Condition($context, $field);

        $this->assert(
            'testComplexNestedConditions',
            false,
            $condition->evaluate([
                'logic' => 'OR',
                'field:unified_client_id' => 'func:empty',
                0 => [
                    'logic' => 'AND',
                    '!field:unified_client_id' => 'func:empty',
                    'field:segment_code' => 'func:empty'
                ]
            ]),
            'Сложное вложенное условие должно вернуть false',
            ['filter' => 'OR(empty(unified), AND(!empty(unified), empty(segment)))']
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
            $this->logger->success('ConditionTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('ConditionTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
