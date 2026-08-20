<?php

namespace Api\Services\Actions\Testing;

use Api\Services\ArrayFilter;

/**
 * Class ArrayFilterTest
 * 
 * Тесты для сервиса ArrayFilter — фильтрация списков объектов
 * по фильтру в стиле Битрикс (=, !, !=, >, <, >=, <=, %, @, !@).
 * 
 * Лог: ArrayFilter_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 9
 * 
 * @package Api\Services\Actions\Testing
 */
class ArrayFilterTest
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
     * ArrayFilterTest constructor.
     * 
     * Создаёт логгер с префиксом 'ArrayFilter'
     */
    public function __construct()
    {
        $this->logger = new TestLogger('ArrayFilter');
    }

    /**
     * Запускает все тесты ArrayFilter
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('ArrayFilterTest', 'runAll', '=== ЗАПУСК ТЕСТОВ ArrayFilter ===');

        $this->testExactMatch();
        $this->testNotEqual();
        $this->testComparisonOperators();
        $this->testLikeOperator();
        $this->testInOperators();
        $this->testCombinedAnd();
        $this->testMissingField();
        $this->testEmptyFilter();
        $this->testRealCasePrimaryResults();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "ARRAYFILTER TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }

    /**
     * Тестовые данные: 3 строки с разными статусами/счётчиками/тегами
     * 
     * @return array
     */
    private function sampleItems(): array
    {
        return [
            ['ID' => 1, 'STATUS' => 'NEW',  'COUNT' => 10, 'TAG' => 'Important call'],
            ['ID' => 2, 'STATUS' => 'JUNK', 'COUNT' => 5,  'TAG' => 'spam'],
            ['ID' => 3, 'STATUS' => 'NEW',  'COUNT' => 20, 'TAG' => 'important'],
        ];
    }

    /**
     * Тест 1: точное совпадение (без оператора)
     * 
     * @return void
     */
    private function testExactMatch(): void
    {
        $this->logger->separator('testExactMatch');

        $filter = new ArrayFilter();
        $result = $filter->filter($this->sampleItems(), ['STATUS' => 'NEW']);

        $this->assert(
            'testExactMatch',
            [1, 3],
            array_column($result, 'ID'),
            'Точное совпадение возвращает строки 1 и 3',
            ['filter' => ['STATUS' => 'NEW']]
        );
    }

    /**
     * Тест 2: не равно — оба варианта оператора (! и !=)
     * 
     * @return void
     */
    private function testNotEqual(): void
    {
        $this->logger->separator('testNotEqual');

        $filter = new ArrayFilter();

        $this->assert(
            'testNotEqual_Bang',
            [1, 3],
            array_column($filter->filter($this->sampleItems(), ['!STATUS' => 'JUNK']), 'ID'),
            'Оператор ! возвращает строки, где STATUS != JUNK',
            ['filter' => ['!STATUS' => 'JUNK']]
        );

        $this->assert(
            'testNotEqual_BangEq',
            [2],
            array_column($filter->filter($this->sampleItems(), ['!=STATUS' => 'NEW']), 'ID'),
            'Оператор != работает как алиас !',
            ['filter' => ['!=STATUS' => 'NEW']]
        );
    }

    /**
     * Тест 3: операторы сравнения >, <, >=, <=
     * 
     * @return void
     */
    private function testComparisonOperators(): void
    {
        $this->logger->separator('testComparisonOperators');

        $filter = new ArrayFilter();
        $items = $this->sampleItems();

        $this->assert(
            'testComparison_Greater',
            [3],
            array_column($filter->filter($items, ['>COUNT' => 10]), 'ID'),
            '>COUNT возвращает строку 3',
            []
        );

        $this->assert(
            'testComparison_Less',
            [2],
            array_column($filter->filter($items, ['<COUNT' => 10]), 'ID'),
            '<COUNT возвращает строку 2',
            []
        );

        $this->assert(
            'testComparison_GreaterOrEqual',
            [1, 3],
            array_column($filter->filter($items, ['>=COUNT' => 10]), 'ID'),
            '>=COUNT возвращает строки 1 и 3',
            []
        );

        $this->assert(
            'testComparison_LessOrEqual',
            [2],
            array_column($filter->filter($items, ['<=COUNT' => 5]), 'ID'),
            '<=COUNT возвращает строку 2',
            []
        );
    }

    /**
     * Тест 4: LIKE (%) — подстрока, регистронезависимо
     * 
     * @return void
     */
    private function testLikeOperator(): void
    {
        $this->logger->separator('testLikeOperator');

        $filter = new ArrayFilter();

        $this->assert(
            'testLikeOperator',
            [1, 3],
            array_column($filter->filter($this->sampleItems(), ['%TAG' => 'IMPORTANT']), 'ID'),
            '%TAG находит подстроку без учёта регистра',
            ['filter' => ['%TAG' => 'IMPORTANT']]
        );
    }

    /**
     * Тест 5: IN (@) и NOT IN (!@)
     * 
     * @return void
     */
    private function testInOperators(): void
    {
        $this->logger->separator('testInOperators');

        $filter = new ArrayFilter();
        $items = $this->sampleItems();

        $this->assert(
            'testIn',
            [1, 2, 3],
            array_column($filter->filter($items, ['@STATUS' => ['NEW', 'JUNK']]), 'ID'),
            '@STATUS IN [NEW, JUNK] возвращает все строки',
            []
        );

        $this->assert(
            'testNotIn',
            [1, 3],
            array_column($filter->filter($items, ['!@STATUS' => ['JUNK']]), 'ID'),
            '!@STATUS NOT IN [JUNK] возвращает строки 1 и 3',
            []
        );
    }

    /**
     * Тест 6: комбинация условий через AND
     * 
     * @return void
     */
    private function testCombinedAnd(): void
    {
        $this->logger->separator('testCombinedAnd');

        $filter = new ArrayFilter();

        $this->assert(
            'testCombinedAnd',
            [3],
            array_column(
                $filter->filter($this->sampleItems(), ['STATUS' => 'NEW', '>COUNT' => 15]),
                'ID'
            ),
            'AND-комбинация: STATUS=NEW и COUNT>15 → строка 3',
            ['filter' => ['STATUS' => 'NEW', '>COUNT' => 15]]
        );
    }

    /**
     * Тест 7: отсутствующее поле → строка не попадает в результат
     * 
     * @return void
     */
    private function testMissingField(): void
    {
        $this->logger->separator('testMissingField');

        $filter = new ArrayFilter();

        $this->assert(
            'testMissingField',
            [],
            $filter->filter($this->sampleItems(), ['MISSING_FIELD' => 1]),
            'Строки без поля из фильтра исключаются',
            ['filter' => ['MISSING_FIELD' => 1]]
        );
    }

    /**
     * Тест 8: пустой фильтр возвращает исходный список
     * 
     * @return void
     */
    private function testEmptyFilter(): void
    {
        $this->logger->separator('testEmptyFilter');

        $filter = new ArrayFilter();

        $this->assert(
            'testEmptyFilter',
            3,
            count($filter->filter($this->sampleItems(), [])),
            'Пустой фильтр возвращает все строки',
            []
        );
    }

    /**
     * Тест 9: реальный кейс — справочник результатов обзвона
     * 
     * Фильтр как в Битрикс: !UF_CHECK_DEPARTMENT = 1,
     * UF_REASON_REFUSAL = 0, UF_LEAD_STATUS = UC_MTFIW2.
     * Строки со строковыми значениями ("0") сравниваются слабо с числами.
     * 
     * @return void
     */
    private function testRealCasePrimaryResults(): void
    {
        $this->logger->separator('testRealCasePrimaryResults');

        $filter = new ArrayFilter();

        $items = [
            ['ID' => '3',  'UF_CODE' => 'QUALIFIED_TRADE_IN',  'UF_CHECK_DEPARTMENT' => '0', 'UF_REASON_REFUSAL' => '0',    'UF_LEAD_STATUS' => 'CONVERTED'],
            ['ID' => '9',  'UF_CODE' => 'NOT_INTERESTED',      'UF_CHECK_DEPARTMENT' => '0', 'UF_REASON_REFUSAL' => '2570', 'UF_LEAD_STATUS' => 'JUNK'],
            ['ID' => '14', 'UF_CODE' => 'NO_ANSWER',           'UF_CHECK_DEPARTMENT' => '0', 'UF_REASON_REFUSAL' => '0',    'UF_LEAD_STATUS' => 'UC_MTFIW2'],
            ['ID' => '15', 'UF_CODE' => 'CONTACTED_NOT_NOW',   'UF_CHECK_DEPARTMENT' => '0', 'UF_REASON_REFUSAL' => '0',    'UF_LEAD_STATUS' => 'UC_MTFIW2'],
            ['ID' => '16', 'UF_CODE' => 'BUSY',                'UF_CHECK_DEPARTMENT' => '0', 'UF_REASON_REFUSAL' => '0',    'UF_LEAD_STATUS' => 'UC_MTFIW2'],
        ];

        $result = $filter->filter($items, [
            '!UF_CHECK_DEPARTMENT' => 1,
            'UF_REASON_REFUSAL' => 0,
            'UF_LEAD_STATUS' => 'UC_MTFIW2',
        ]);

        $this->assert(
            'testRealCasePrimaryResults',
            ['14', '15', '16'],
            array_column($result, 'ID'),
            'Реальный кейс: слабое сравнение строк и чисел, все условия AND',
            ['filter' => ['!UF_CHECK_DEPARTMENT' => 1, 'UF_REASON_REFUSAL' => 0, 'UF_LEAD_STATUS' => 'UC_MTFIW2']]
        );
    }

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
            $this->logger->success('ArrayFilterTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('ArrayFilterTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
