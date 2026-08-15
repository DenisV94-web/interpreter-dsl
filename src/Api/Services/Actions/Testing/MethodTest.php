<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Method;

/**
 * Class MethodTest
 * 
 * Тесты для класса Method (Resolver).
 * 
 * Method Resolver — исполнитель методов и функций интерпретатора.
 * Отвечает за:
 * - Вызов PHP-функций: explode, date, strtoupper, array_merge и т.д.
 * - Вызов методов классов (статических и нестатических) через Reflection
 * - Разрешение параметров через Field Resolver (field:, шаблоны)
 * - Извлечение элементов: element (индекс, ключ, многоуровневый путь "0.NAME")
 * - Mapping placeholder: строка 'mapping' в params подставляется массивом
 * - on_error: fallback при исключении или возврате false
 * - change_values: постобработка полей результата
 *   (class 'self' = метод на самом объекте, name = не-деструктивная трансформация)
 * 
 * Лог: Method_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 21
 * 
 * @package Api\Services\Actions\Testing
 */
class MethodTest
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
     * MethodTest constructor.
     * 
     * Создаёт логгер с префиксом 'Method' — все записи идут
     * в файл Method_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Method');
    }

    /**
     * Создаёт связку Context + Field + Method для тестов
     * 
     * @param array $data Данные для контекста
     * @return array [Method, Field, Context]
     */
    private function createMethod(array $data = []): array
    {
        $context = new Context($data);
        $context->setTestLogger($this->logger);
        $fieldResolver = new Field($context);
        $method = new Method($context, $fieldResolver);
        return [$method, $fieldResolver, $context];
    }

    /**
     * Запускает все тесты Method Resolver
     * 
     * Порядок логический, от простого к сложному:
     * - PHP-функции (1-6)
     * - Методы классов (7-10)
     * - Извлечение элементов (11-13)
     * - Mapping placeholder (14-16)
     * - change_values (17-21)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('MethodTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Method Resolver ===');

        // PHP-функции
        $this->testPhpFunctionExplode();
        $this->testPhpFunctionExplodeWithElement();
        $this->testPhpFunctionDate();
        $this->testPhpFunctionStrtoupper();
        $this->testPhpFunctionArrayMerge();
        $this->testPhpFunctionNotFound();

        // Методы классов
        $this->testClassMethodStatic();
        $this->testClassMethodInstance();
        $this->testClassMethodWithFieldParams();
        $this->testClassMethodNotFound();

        // Извлечение элементов
        $this->testElementExtraction();
        $this->testElementNotFound();
        $this->testElementMultiLevel();

        // Mapping placeholder
        $this->testMappingPlaceholder();
        $this->testMappingPlaceholderEmpty();
        $this->testMappingPlaceholderNested();

        // change_values
        $this->testChangeValuesSelf();
        $this->testChangeValuesWithName();
        $this->testChangeValuesPhpFunction();
        $this->testChangeValuesOnList();
        $this->testChangeValuesNullSource();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "METHOD TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // PHP-ФУНКЦИИ (тесты 1-6)
    // ========================================================

    /**
     * Тест 1: PHP-функция explode
     * 
     * Контекст: client_string = 'contact_555'.
     * Конфиг: method explode, params ['_', 'field:client_string'].
     * Ожидание: ['contact', '555'] — параметр field: резолвится из контекста.
     * 
     * @return void
     */
    private function testPhpFunctionExplode(): void
    {
        $this->logger->separator('testPhpFunctionExplode');

        [$method] = $this->createMethod(['client_string' => 'contact_555']);

        $this->assert(
            'testPhpFunctionExplode',
            ['contact', '555'],
            $method->execute([
                'method' => 'explode',
                'params' => ['_', 'field:client_string']
            ]),
            'explode("_", "contact_555") должно вернуть массив',
            ['config' => ['method' => 'explode', 'params' => ['_', 'field:client_string']]]
        );
    }

    /**
     * Тест 2: explode с извлечением элемента
     * 
     * Тот же explode, но с element = 1.
     * Ожидание: '555' — второй элемент массива.
     * Реальный кейс из create_lead: client_id из client_string.
     * 
     * @return void
     */
    private function testPhpFunctionExplodeWithElement(): void
    {
        $this->logger->separator('testPhpFunctionExplodeWithElement');

        [$method] = $this->createMethod(['client_string' => 'contact_555']);

        $this->assert(
            'testPhpFunctionExplodeWithElement',
            '555',
            $method->execute([
                'method' => 'explode',
                'params' => ['_', 'field:client_string'],
                'element' => 1
            ]),
            'explode + element=1 должно вернуть "555"',
            ['element' => 1]
        );
    }

    /**
     * Тест 3: PHP-функция date
     * 
     * Конфиг: method date, params ['d.m.Y'].
     * Ожидание: текущая дата в формате d.m.Y.
     * Реальный кейс из create_lead: date_now.
     * 
     * @return void
     */
    private function testPhpFunctionDate(): void
    {
        $this->logger->separator('testPhpFunctionDate');

        [$method] = $this->createMethod([]);

        $this->assert(
            'testPhpFunctionDate',
            date('d.m.Y'),
            $method->execute(['method' => 'date', 'params' => ['d.m.Y']]),
            'date("d.m.Y") должно вернуть текущую дату',
            ['config' => ['method' => 'date', 'params' => ['d.m.Y']]]
        );
    }

    /**
     * Тест 4: PHP-функция strtoupper с field-параметром
     * 
     * Контекст: status = 'active'.
     * Ожидание: 'ACTIVE'.
     * 
     * @return void
     */
    private function testPhpFunctionStrtoupper(): void
    {
        $this->logger->separator('testPhpFunctionStrtoupper');

        [$method] = $this->createMethod(['status' => 'active']);

        $this->assert(
            'testPhpFunctionStrtoupper',
            'ACTIVE',
            $method->execute([
                'method' => 'strtoupper',
                'params' => ['field:status']
            ]),
            'strtoupper("active") должно вернуть "ACTIVE"',
            ['config' => ['method' => 'strtoupper', 'params' => ['field:status']]]
        );
    }

    /**
     * Тест 5: PHP-функция array_merge
     * 
     * Конфиг: два массива в params.
     * Ожидание: объединённый массив ['a'=>1,'b'=>2,'c'=>3].
     * 
     * @return void
     */
    private function testPhpFunctionArrayMerge(): void
    {
        $this->logger->separator('testPhpFunctionArrayMerge');

        [$method] = $this->createMethod([]);

        $this->assert(
            'testPhpFunctionArrayMerge',
            ['a' => 1, 'b' => 2, 'c' => 3],
            $method->execute([
                'method' => 'array_merge',
                'params' => [['a' => 1, 'b' => 2], ['c' => 3]]
            ]),
            'array_merge должно объединить массивы',
            ['config' => ['method' => 'array_merge']]
        );
    }

    /**
     * Тест 6: PHP-функция не найдена
     * 
     * Конфиг: method = 'non_existent_function_xyz'.
     * Ожидание: RuntimeException — несуществующая функция это ошибка.
     * 
     * @return void
     */
    private function testPhpFunctionNotFound(): void
    {
        $this->logger->separator('testPhpFunctionNotFound');

        [$method] = $this->createMethod([]);

        $thrown = false;
        try {
            $method->execute(['method' => 'non_existent_function_xyz']);
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        $this->assert(
            'testPhpFunctionNotFound',
            true,
            $thrown,
            'Несуществующая функция должна выбросить RuntimeException',
            ['config' => ['method' => 'non_existent_function_xyz']]
        );
    }
    
    // ========================================================
    // МЕТОДЫ КЛАССОВ (тесты 7-10)
    // ========================================================

    /**
     * Тест 7: Статический метод класса
     * 
     * Конфиг: class MockService, method staticTransform.
     * Ожидание: 'STATIC:hello' — статика вызывается без new (Reflection).
     * 
     * @return void
     */
    private function testClassMethodStatic(): void
    {
        $this->logger->separator('testClassMethodStatic');

        [$method] = $this->createMethod(['value' => 'hello']);

        $this->assert(
            'testClassMethodStatic',
            'STATIC:hello',
            $method->execute([
                'method' => 'staticTransform',
                'class' => MockService::class,
                'params' => ['field:value']
            ]),
            'Статический метод должен быть вызван без создания экземпляра',
            ['config' => ['method' => 'staticTransform', 'class' => 'MockService']]
        );
    }

    /**
     * Тест 8: Нестатический метод класса
     * 
     * Конфиг: class MockService, method instanceTransform.
     * Ожидание: 'INSTANCE:world' — вызов через new (Reflection).
     * 
     * @return void
     */
    private function testClassMethodInstance(): void
    {
        $this->logger->separator('testClassMethodInstance');

        [$method] = $this->createMethod(['value' => 'world']);

        $this->assert(
            'testClassMethodInstance',
            'INSTANCE:world',
            $method->execute([
                'method' => 'instanceTransform',
                'class' => MockService::class,
                'params' => ['field:value']
            ]),
            'Нестатический метод должен быть вызван через new',
            ['config' => ['method' => 'instanceTransform', 'class' => 'MockService']]
        );
    }

    /**
     * Тест 9: Метод с несколькими field-параметрами
     * 
     * Контекст: first_name = 'Иван', last_name = 'Петров'.
     * Конфиг: concatenate с тремя params.
     * Ожидание: 'Иван Петров' — все field: резолвятся из контекста.
     * 
     * @return void
     */
    private function testClassMethodWithFieldParams(): void
    {
        $this->logger->separator('testClassMethodWithFieldParams');

        [$method] = $this->createMethod(['first_name' => 'Иван', 'last_name' => 'Петров']);

        $this->assert(
            'testClassMethodWithFieldParams',
            'Иван Петров',
            $method->execute([
                'method' => 'concatenate',
                'class' => MockService::class,
                'params' => ['field:first_name', 'field:last_name', ' ']
            ]),
            'Параметры field: должны быть разрешены из контекста',
            ['config' => ['method' => 'concatenate']]
        );
    }

    /**
     * Тест 10: Класс не найден
     * 
     * Конфиг: class = '\NonExistent\Class\Name'.
     * Ожидание: RuntimeException — несуществующий класс это ошибка.
     * 
     * @return void
     */
    private function testClassMethodNotFound(): void
    {
        $this->logger->separator('testClassMethodNotFound');

        [$method] = $this->createMethod([]);

        $thrown = false;
        try {
            $method->execute([
                'method' => 'someMethod',
                'class' => '\NonExistent\Class\Name'
            ]);
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        $this->assert(
            'testClassMethodNotFound',
            true,
            $thrown,
            'Несуществующий класс должен выбросить RuntimeException',
            ['config' => ['class' => '\NonExistent\Class\Name']]
        );
    }
    
    // ========================================================
    // ИЗВЛЕЧЕНИЕ ЭЛЕМЕНТОВ (тесты 11-13)
    // ========================================================

    /**
     * Тест 11: Извлечение элемента по индексу 0
     * 
     * explode('phone_12345') + element = 0.
     * Ожидание: 'phone' — первый элемент.
     * 
     * @return void
     */
    private function testElementExtraction(): void
    {
        $this->logger->separator('testElementExtraction');

        [$method] = $this->createMethod(['client_string' => 'phone_12345']);

        $this->assert(
            'testElementExtraction',
            'phone',
            $method->execute([
                'method' => 'explode',
                'params' => ['_', 'field:client_string'],
                'element' => 0
            ]),
            'element=0 должно вернуть первый элемент',
            ['element' => 0]
        );
    }

    /**
     * Тест 12: Элемент не найден
     * 
     * explode('phone') даёт 1 элемент, element = 5.
     * Ожидание: null — отсутствие элемента не ошибка.
     * 
     * @return void
     */
    private function testElementNotFound(): void
    {
        $this->logger->separator('testElementNotFound');

        [$method] = $this->createMethod(['client_string' => 'phone']);

        $this->assert(
            'testElementNotFound',
            null,
            $method->execute([
                'method' => 'explode',
                'params' => ['_', 'field:client_string'],
                'element' => 5
            ]),
            'element=5 на массиве из 1 элемента должно вернуть null',
            ['element' => 5]
        );
    }

    /**
     * Тест 13: Многоуровневый element (точечная нотация)
     * 
     * Результат getTwoRows: [['CODE'=>'first'],['CODE'=>'second']].
     * element = '1.CODE'.
     * Ожидание: 'second' — путь распаковывается как $result[1]['CODE'].
     * 
     * @return void
     */
    private function testElementMultiLevel(): void
    {
        $this->logger->separator('testElementMultiLevel');

        [$method] = $this->createMethod([]);

        $this->assert(
            'testElementMultiLevel',
            'second',
            $method->execute([
                'method' => 'getTwoRows',
                'class' => MockService::class,
                'params' => [],
                'element' => '1.CODE'
            ]),
            'Многоуровневый путь должен распаковаться по точечной нотации',
            ['element' => '1.CODE']
        );
    }
    
    // ========================================================
    // MAPPING PLACEHOLDER (тесты 14-16)
    // ========================================================

    /**
     * Тест 14: Mapping placeholder — подстановка массива
     * 
     * Контекст: mapping = массив полей.
     * Конфиг: params = ['mapping'].
     * Ожидание: строка 'mapping' заменяется массивом из контекста.
     * Реальный кейс из create_lead: add с params ['mapping'].
     * 
     * @return void
     */
    private function testMappingPlaceholder(): void
    {
        $this->logger->separator('testMappingPlaceholder');

        $mappingData = [
            'UF_UNIFIED_CLIENT_ID' => 'test_mapping_123',
            'STATUS_ID' => 'UC_MTFIW2',
            'ASSIGNED_BY_ID' => 112
        ];

        [$method] = $this->createMethod(['mapping' => $mappingData]);

        $this->assert(
            'testMappingPlaceholder',
            $mappingData,
            $method->execute([
                'method' => 'getMappingData',
                'class' => MockService::class,
                'params' => ['mapping']
            ]),
            'Строка "mapping" в params должна быть заменена на массив',
            ['params' => ['mapping']]
        );
    }

    /**
     * Тест 15: Mapping placeholder при отсутствии mapping
     * 
     * Контекст без mapping.
     * Ожидание: пустой массив [] — плейсхолдер не найден, не ошибка.
     * 
     * @return void
     */
    private function testMappingPlaceholderEmpty(): void
    {
        $this->logger->separator('testMappingPlaceholderEmpty');

        [$method] = $this->createMethod([]);

        $this->assert(
            'testMappingPlaceholderEmpty',
            [],
            $method->execute([
                'method' => 'getMappingData',
                'class' => MockService::class,
                'params' => ['mapping']
            ]),
            'Если mapping не установлен, должна вернуться пустой массив',
            ['params' => ['mapping']]
        );
    }

    /**
     * Тест 16: Mapping placeholder во вложенных параметрах
     * 
     * Контекст: mapping + entity_id.
     * Конфиг: params с вложенным массивом ['fields' => 'mapping', ...].
     * Ожидание: 'mapping' подставляется рекурсивно на любой глубине.
     * 
     * @return void
     */
    private function testMappingPlaceholderNested(): void
    {
        $this->logger->separator('testMappingPlaceholderNested');

        $mappingData = ['NAME' => 'Test', 'TITLE' => 'Test Title'];

        [$method] = $this->createMethod(['mapping' => $mappingData, 'entity_id' => 999]);

        $expected = [
            999,
            ['fields' => $mappingData, 'params' => ['REGISTER_SONET_EVENT' => 'Y']]
        ];

        $this->assert(
            'testMappingPlaceholderNested',
            $expected,
            $method->execute([
                'method' => 'getNestedParams',
                'class' => MockService::class,
                'params' => [
                    'field:entity_id',
                    ['fields' => 'mapping', 'params' => ['REGISTER_SONET_EVENT' => 'Y']]
                ]
            ]),
            'Mapping должен подставляться внутри вложенных параметров',
            ['params' => 'вложенный массив с mapping']
        );
    }
    
    // ========================================================
    // CHANGE_VALUES (тесты 17-21)
    // ========================================================

    /**
     * Тест 17: change_values с class 'self'
     * 
     * getLeadWithDate возвращает строку с DATE_CREATE = MockDateObject.
     * change_values: DATE_CREATE → format('d.m.Y H:i:s') на самом объекте.
     * Ожидание: DATE_CREATE = 'FORMATTED_d.m.Y H:i:s' (строка вместо объекта).
     * Реальный кейс: форматирование Bitrix DateTime из getList.
     * 
     * @return void
     */
    private function testChangeValuesSelf(): void
    {
        $this->logger->separator('testChangeValuesSelf');

        [$method] = $this->createMethod([]);

        $expected = [
            'ID' => 55,
            'DATE_CREATE' => 'FORMATTED_d.m.Y H:i:s',
            'CODE' => 'abc'
        ];

        $this->assert(
            'testChangeValuesSelf',
            $expected,
            $method->execute([
                'method' => 'getLeadWithDate',
                'class' => MockService::class,
                'params' => [],
                'change_values' => [
                    'DATE_CREATE' => [
                        'method' => 'format',
                        'class' => 'self',
                        'params' => ['d.m.Y H:i:s']
                    ]
                ],
                'element' => 0
            ]),
            'Объект DATE_CREATE должен быть заменён результатом format()',
            ['change_values' => 'DATE_CREATE self format']
        );
    }

    /**
     * Тест 18: change_values с name (не-деструктивная трансформация)
     * 
     * change_values: DATE_CREATE_FORMATTED ← name DATE_CREATE.
     * Ожидание: новое поле содержит форматированное значение,
     * а оригинальный DATE_CREATE остаётся объектом.
     * 
     * @return void
     */
    private function testChangeValuesWithName(): void
    {
        $this->logger->separator('testChangeValuesWithName');

        [$method] = $this->createMethod([]);

        $actual = $method->execute([
            'method' => 'getLeadWithDate',
            'class' => MockService::class,
            'params' => [],
            'change_values' => [
                'DATE_CREATE_FORMATTED' => [
                    'name' => 'DATE_CREATE',
                    'method' => 'format',
                    'class' => 'self',
                    'params' => ['d.m.Y']
                ]
            ],
            'element' => 0
        ]);

        $this->assert(
            'testChangeValuesWithName_New',
            'FORMATTED_d.m.Y',
            $actual['DATE_CREATE_FORMATTED'] ?? null,
            'Новое поле должно содержать форматированное значение',
            ['change_values' => 'DATE_CREATE_FORMATTED name=DATE_CREATE']
        );

        $this->assert(
            'testChangeValuesWithName_Original',
            true,
            ($actual['DATE_CREATE'] ?? null) instanceof MockDateObject,
            'Оригинальный DATE_CREATE должен остаться объектом',
            ['check' => 'instanceof MockDateObject']
        );
    }

    /**
     * Тест 19: change_values через PHP-функцию
     * 
     * change_values: CODE → strtoupper (без class).
     * Ожидание: 'abc' → 'ABC'.
     * 
     * @return void
     */
    private function testChangeValuesPhpFunction(): void
    {
        $this->logger->separator('testChangeValuesPhpFunction');

        [$method] = $this->createMethod([]);

        $actual = $method->execute([
            'method' => 'getLeadWithDate',
            'class' => MockService::class,
            'params' => [],
            'change_values' => [
                'CODE' => ['method' => 'strtoupper']
            ],
            'element' => 0
        ]);

        $this->assert(
            'testChangeValuesPhpFunction',
            'ABC',
            $actual['CODE'] ?? null,
            'PHP-функция должна примениться к значению поля',
            ['change_values' => 'CODE strtoupper']
        );
    }

    /**
     * Тест 20: change_values к списку строк (без element)
     * 
     * getTwoRows возвращает список из 2 строк.
     * change_values: CODE → strtoupper.
     * Ожидание: трансформирована КАЖДАЯ строка списка.
     * 
     * @return void
     */
    private function testChangeValuesOnList(): void
    {
        $this->logger->separator('testChangeValuesOnList');

        [$method] = $this->createMethod([]);

        $expected = [
            ['CODE' => 'FIRST'],
            ['CODE' => 'SECOND']
        ];

        $this->assert(
            'testChangeValuesOnList',
            $expected,
            $method->execute([
                'method' => 'getTwoRows',
                'class' => MockService::class,
                'params' => [],
                'change_values' => [
                    'CODE' => ['method' => 'strtoupper']
                ]
            ]),
            'Каждая строка списка должна быть трансформирована',
            ['change_values' => 'CODE strtoupper на списке']
        );
    }

    /**
     * Тест 21: change_values с отсутствующим источником
     * 
     * change_values: MISSING_FIELD → format self.
     * Поля MISSING_FIELD в строке нет.
     * Ожидание: ключ записывается в строку со значением null,
     * без исключения (источник null → вызов не делается).
     * 
     * ВАЖНО: используем array_key_exists, а не ??,
     * т.к. ?? не отличает "ключ с null" от "ключа нет".
     * 
     * @return void
     */
    private function testChangeValuesNullSource(): void
    {
        $this->logger->separator('testChangeValuesNullSource');

        [$method] = $this->createMethod([]);

        $actual = $method->execute([
            'method' => 'getLeadWithDate',
            'class' => MockService::class,
            'params' => [],
            'change_values' => [
                'MISSING_FIELD' => [
                    'method' => 'format',
                    'class' => 'self',
                    'params' => ['d.m.Y']
                ]
            ],
            'element' => 0
        ]);

        // Ключ должен присутствовать в строке (записан change_values)
        $this->assert(
            'testChangeValuesNullSource_KeyExists',
            true,
            array_key_exists('MISSING_FIELD', $actual),
            'Ключ MISSING_FIELD должен быть записан в строку',
            ['check' => 'array_key_exists($actual)']
        );

        // И его значение должно быть null (источник отсутствует → вызова не было)
        $this->assert(
            'testChangeValuesNullSource_Value',
            null,
            $actual['MISSING_FIELD'] ?? null,
            'Отсутствующий источник должен дать null без падения',
            ['change_values' => 'MISSING_FIELD (нет в строке)']
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
            $this->logger->success('MethodTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('MethodTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}

// ============================================================
// МОК-КЛАССЫ ДЛЯ ТЕСТИРОВАНИЯ
// ============================================================

/**
 * Class MockService
 * 
 * Мок-класс для тестирования Method Resolver.
 * Содержит статические и нестатические методы, а также методы,
 * имитирующие запросы к БД (getList с объектами дат, список строк).
 * 
 * @package Api\Services\Actions\Testing
 */
class MockService
{
    /**
     * Статический метод — вызов без создания экземпляра
     * 
     * @param string $value Входное значение
     * @return string 'STATIC:' + значение
     */
    public static function staticTransform(string $value): string
    {
        return 'STATIC:' . $value;
    }

    /**
     * Нестатический метод — вызов через new
     * 
     * @param string $value Входное значение
     * @return string 'INSTANCE:' + значение
     */
    public function instanceTransform(string $value): string
    {
        return 'INSTANCE:' . $value;
    }

    /**
     * Метод с несколькими параметрами (конкатенация)
     * 
     * @param string $first Первая строка
     * @param string $second Вторая строка
     * @param string $separator Разделитель
     * @return string Объединённая строка
     */
    public function concatenate(string $first, string $second, string $separator = ' '): string
    {
        return $first . $separator . $second;
    }

    /**
     * Возвращает mapping как есть (для тестов mapping placeholder)
     * 
     * @param array $mappingData Массив mapping
     * @return array Тот же массив
     */
    public function getMappingData(array $mappingData): array
    {
        return $mappingData;
    }

    /**
     * Возвращает вложенные параметры как есть
     * (для теста mapping placeholder во вложенных params)
     * 
     * @param int $entityId ID сущности
     * @param array $params Массив параметров
     * @return array [entityId, params]
     */
    public function getNestedParams(int $entityId, array $params): array
    {
        return [$entityId, $params];
    }

    /**
     * Имитация getList с объектом даты в строке
     * (для тестов change_values self — как Bitrix DateTime)
     * 
     * @return array Список из одной строки с DATE_CREATE-объектом
     */
    public function getLeadWithDate(): array
    {
        return [
            ['ID' => 55, 'DATE_CREATE' => new MockDateObject(), 'CODE' => 'abc']
        ];
    }

    /**
     * Имитация getList, возвращающего 2 строки
     * (для тестов change_values на списке и многоуровневого element)
     * 
     * @return array Список из двух строк
     */
    public function getTwoRows(): array
    {
        return [
            ['CODE' => 'first'],
            ['CODE' => 'second']
        ];
    }
}

/**
 * Class MockDateObject
 * 
 * Имитация объекта даты Битрикса (\Bitrix\Main\Type\DateTime).
 * Метод format() возвращает детерминированную строку для тестов.
 * 
 * @package Api\Services\Actions\Testing
 */
class MockDateObject
{
    /**
     * Исходное значение даты
     * 
     * @var string
     */
    private string $value;

    /**
     * MockDateObject constructor.
     * 
     * @param string $value Исходное значение даты
     */
    public function __construct(string $value = '2026-08-07 10:00:00')
    {
        $this->value = $value;
    }

    /**
     * Имитация format() от DateTime
     * 
     * @param string $pattern Паттерн форматирования
     * @return string 'FORMATTED_' + паттерн (детерминированно)
     */
    public function format(string $pattern): string
    {
        return 'FORMATTED_' . $pattern;
    }
}
