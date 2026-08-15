<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Executor\Mapping;

/**
 * Class MappingTest
 * 
 * Тесты для класса Mapping (Executor).
 * 
 * Mapping Executor — формирователь итоговых данных из контекста.
 * Поддерживает три режима работы (определяются автоматически):
 * 
 * 1. ОДИНОЧНЫЙ (create_lead): плоский массив целевое_поле => выражение.
 *    Результат пишется в context.mapping (для 'params' => ['mapping'] в execute).
 * 
 * 2. ИМЕНОВАННЫЕ СПИСОЧНЫЕ МАППИНГИ (get_dashboard_data): каждый элемент
 *    имеет свой 'source' и опциональный 'mapping'. Результаты пишутся
 *    в контекст под своими именами (для compose).
 * 
 * 3. SOURCE БЕЗ MAPPING: возвращается сырой список без трансформации.
 * 
 * Особенности:
 * - Вложенные массивы (множественные поля Битрикс типа PHONE)
 *   разрешаются РЕКУРСИВНО через resolveParams
 * - Цепочки | работают и внутри вложенных массивов
 * - В списочном режиме на время обработки строки контекстный ключ
 *   источника подменяется строкой (field:LEAD.X указывает на поля строки)
 * 
 * Лог: Mapping_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 9
 * 
 * @package Api\Services\Actions\Testing
 */
class MappingTest
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
     * MappingTest constructor.
     * 
     * Создаёт логгер с префиксом 'Mapping' — все записи идут
     * в файл Mapping_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Mapping');
    }

    /**
     * Создаёт связку Context + Field + Condition + Mapping для тестов
     * 
     * @param array $data Данные для контекста
     * @return array [Mapping, Context, Field]
     */
    private function createMapping(array $data = []): array
    {
        $context = new Context($data);
        $context->setTestLogger($this->logger);
        $fieldResolver = new Field($context);
        $conditionResolver = new Condition($context, $fieldResolver);
        $mapping = new Mapping($context, $fieldResolver, $conditionResolver);
        return [$mapping, $context, $fieldResolver];
    }

    /**
     * Запускает все тесты Mapping Executor
     * 
     * Порядок логический, от простого к сложному:
     * - Одиночный режим (1-5)
     * - Вложенные массивы (6-7)
     * - Именованные списочные маппинги (8-9)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('MappingTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Mapping Executor ===');

        // Одиночный режим
        $this->testSimpleMapping();
        $this->testFieldReferences();
        $this->testChainAlternatives();
        $this->testMixedValues();
        $this->testMappingStoredInContext();

        // Вложенные массивы
        $this->testNestedArrayMapping();
        $this->testNestedArrayMappingFallback();

        // Именованные списочные маппинги
        $this->testNamedListMappings();
        $this->testSourceOnlyWithoutMapping();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "MAPPING TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // ОДИНОЧНЫЙ РЕЖИМ (тесты 1-5)
    // ========================================================

    /**
     * Тест 1: Простой маппинг с литералами
     * 
     * Конфиг: только литералы (строки, числа).
     * Ожидание: значения записываются как есть, без изменений.
     * 
     * @return void
     */
    private function testSimpleMapping(): void
    {
        $this->logger->separator('testSimpleMapping');

        [$mapping] = $this->createMapping([]);

        $config = [
            'STATUS_ID' => 'UC_MTFIW2',
            'TITLE' => 'New Lead',
            'PRIORITY' => 1
        ];

        $this->assert(
            'testSimpleMapping',
            $config,
            $mapping->execute($config),
            'Простые значения должны записываться как есть',
            ['config' => $config]
        );
    }

    /**
     * Тест 2: Field-ссылки из контекста
     * 
     * Контекст: unified_client_id, dealer_center_id, CONTACT.*.
     * Конфиг: field:unified_client_id, field:CONTACT.LAST_NAME и т.д.
     * Ожидание: значения берутся из контекста через field:.
     * 
     * @return void
     */
    private function testFieldReferences(): void
    {
        $this->logger->separator('testFieldReferences');

        [$mapping] = $this->createMapping([
            'unified_client_id' => 'abc123',
            'dealer_center_id' => 42,
            'CONTACT' => ['LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'SECOND_NAME' => 'Иванович']
        ]);

        $config = [
            'UF_UNIFIED_CLIENT_ID' => 'field:unified_client_id',
            'UF_DEALER_CENTER_ID' => 'field:dealer_center_id',
            'LAST_NAME' => 'field:CONTACT.LAST_NAME',
            'NAME' => 'field:CONTACT.NAME'
        ];

        $expected = [
            'UF_UNIFIED_CLIENT_ID' => 'abc123',
            'UF_DEALER_CENTER_ID' => 42,
            'LAST_NAME' => 'Иванов',
            'NAME' => 'Иван'
        ];

        $this->assert(
            'testFieldReferences',
            $expected,
            $mapping->execute($config),
            'Field-ссылки должны быть разрешены из контекста',
            ['config' => $config]
        );
    }

    /**
     * Тест 3: Цепочка альтернатив | в маппинге
     * 
     * Контекст: CONTACT пустой, COMPANY.PHONE есть.
     * Конфиг: PHONE = field:CONTACT.PHONE|field:COMPANY.PHONE|field:phone.
     * Ожидание: '+79997654321' — первое найденное в цепочке.
     * Реальный кейс из create_lead (PHONE).
     * 
     * @return void
     */
    private function testChainAlternatives(): void
    {
        $this->logger->separator('testChainAlternatives');

        [$mapping] = $this->createMapping([
            'CONTACT' => [],
            'COMPANY' => ['PHONE' => '+79997654321'],
            'phone' => '+79990000000'
        ]);

        $config = [
            'PHONE' => 'field:CONTACT.PHONE|field:COMPANY.PHONE|field:phone'
        ];

        $expected = ['PHONE' => '+79997654321'];

        $this->assert(
            'testChainAlternatives',
            $expected,
            $mapping->execute($config),
            'Цепочка должна вернуть первое найденное значение',
            ['config' => $config]
        );
    }

    /**
     * Тест 4: Смешанные значения (field + литералы)
     * 
     * Конфиг: часть полей field:, часть литералы.
     * Ожидание: оба типа разрешаются корректно в одном маппинге.
     * 
     * @return void
     */
    private function testMixedValues(): void
    {
        $this->logger->separator('testMixedValues');

        [$mapping] = $this->createMapping([
            'unified_client_id' => 'test456',
            'assigned_by' => 112
        ]);

        $config = [
            'UF_UNIFIED_CLIENT_ID' => 'field:unified_client_id',
            'STATUS_ID' => 'UC_MTFIW2',
            'ASSIGNED_BY_ID' => 'field:assigned_by',
            'OPEN' => 1
        ];

        $expected = [
            'UF_UNIFIED_CLIENT_ID' => 'test456',
            'STATUS_ID' => 'UC_MTFIW2',
            'ASSIGNED_BY_ID' => 112,
            'OPEN' => 1
        ];

        $this->assert(
            'testMixedValues',
            $expected,
            $mapping->execute($config),
            'Смешанные значения должны разрешаться корректно',
            ['config' => $config]
        );
    }

    /**
     * Тест 5: Mapping сохраняется в контексте под ключом 'mapping'
     * 
     * После execute() весь маппинг доступен через context.get('mapping').
     * Ожидание: массив результата равен маппингу.
     * Это нужно для execute, где 'params' => ['mapping'].
     * 
     * @return void
     */
    private function testMappingStoredInContext(): void
    {
        $this->logger->separator('testMappingStoredInContext');

        [$mapping, $context] = $this->createMapping(['unified_client_id' => 'ctx_test']);

        $config = [
            'UF_UNIFIED_CLIENT_ID' => 'field:unified_client_id',
            'STATUS_ID' => 'UC_MTFIW2'
        ];

        $mapping->execute($config);

        $expected = [
            'UF_UNIFIED_CLIENT_ID' => 'ctx_test',
            'STATUS_ID' => 'UC_MTFIW2'
        ];

        $this->assert(
            'testMappingStoredInContext',
            $expected,
            $context->get('mapping'),
            'Mapping должен быть доступен через context.get("mapping")',
            ['check' => "context.get('mapping')"]
        );
    }
    
    // ========================================================
    // ВЛОЖЕННЫЕ МАССИВЫ (тесты 6-7)
    // ========================================================

    /**
     * Тест 6: Вложенный массив (множественное поле PHONE)
     * 
     * Контекст: CONTACT_PHONE.VALUE есть.
     * Конфиг: PHONE = вложенный массив ['n0' => ['VALUE_TYPE', 'VALUE' => field:...]].
     * Ожидание: field:... внутри массива разрешаются РЕКУРСИВНО.
     * Реальный кейс из create_lead (FM.PHONE для Битрикс).
     * 
     * @return void
     */
    private function testNestedArrayMapping(): void
    {
        $this->logger->separator('testNestedArrayMapping');

        [$mapping] = $this->createMapping([
            'CONTACT_PHONE' => ['VALUE' => '+79991234567'],
            'phone' => '+79990000000'
        ]);

        $config = [
            'PHONE' => [
                'n0' => [
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => 'field:CONTACT_PHONE.VALUE|field:COMPANY_PHONE.VALUE|field:phone'
                ]
            ],
            'STATUS_ID' => 'UC_MTFIW2'
        ];

        $expected = [
            'PHONE' => [
                'n0' => [
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => '+79991234567'
                ]
            ],
            'STATUS_ID' => 'UC_MTFIW2'
        ];

        $this->assert(
            'testNestedArrayMapping',
            $expected,
            $mapping->execute($config),
            'Вложенные массивы должны разрешаться рекурсивно',
            ['config' => 'PHONE = вложенный массив']
        );
    }

    /**
     * Тест 7: Fallback внутри вложенного массива
     * 
     * Контекст: только phone (CONTACT_PHONE и COMPANY_PHONE отсутствуют).
     * Ожидание: цепочка внутри массива доходит до field:phone.
     * 
     * @return void
     */
    private function testNestedArrayMappingFallback(): void
    {
        $this->logger->separator('testNestedArrayMappingFallback');

        [$mapping] = $this->createMapping(['phone' => '+79990000000']);

        $config = [
            'PHONE' => [
                'n0' => [
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => 'field:CONTACT_PHONE.VALUE|field:COMPANY_PHONE.VALUE|field:phone'
                ]
            ]
        ];

        $expected = [
            'PHONE' => [
                'n0' => [
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => '+79990000000'
                ]
            ]
        ];

        $this->assert(
            'testNestedArrayMappingFallback',
            $expected,
            $mapping->execute($config),
            'Цепочка внутри массива должна дойти до field:phone',
            ['config' => 'PHONE с fallback']
        );
    }
    
    // ========================================================
    // ИМЕНОВАННЫЕ СПИСОЧНЫЕ МАППИНГИ (тесты 8-9)
    // ========================================================

    /**
     * Тест 8: Именованные списочные маппинги (get_dashboard_data кейс)
     * 
     * Контекст: tabs_new.data, tabs_open.data, stats.total.
     * Конфиг:
     * - mapped_new: source + mapping (трансформация каждой строки)
     * - mapped_open: только source (сырой список)
     * - total: одиночное поле field:stats.total
     * Ожидание: каждый результат записан в контекст под своим именем
     * и доступен через field:mapped_new и т.д. (для compose).
     * 
     * @return void
     */
    private function testNamedListMappings(): void
    {
        $this->logger->separator('testNamedListMappings');

        [$mapping, $context] = $this->createMapping([
            'tabs_new' => ['data' => [
                ['ID' => 1, 'NAME' => 'First'],
                ['ID' => 2, 'NAME' => 'Second']
            ]],
            'tabs_open' => ['data' => [
                ['ID' => 3, 'CODE' => 'abc']
            ]],
            'stats' => ['total' => 42]
        ]);

        $config = [
            'mapped_new' => [
                'source' => 'tabs_new.data',
                'mapping' => [
                    'id' => 'field:tabs_new.data.ID',
                    'title' => 'field:tabs_new.data.NAME'
                ]
            ],
            'mapped_open' => [
                'source' => 'tabs_open.data'
            ],
            'total' => 'field:stats.total'
        ];

        $result = $mapping->execute($config);

        $expectedNew = [
            ['id' => 1, 'title' => 'First'],
            ['id' => 2, 'title' => 'Second']
        ];

        $expectedOpen = [
            ['ID' => 3, 'CODE' => 'abc']
        ];

        $this->assert(
            'testNamed_New',
            $expectedNew,
            $result['mapped_new'],
            'mapped_new должен быть замапплен по правилам',
            ['source' => 'tabs_new.data']
        );

        $this->assert(
            'testNamed_Open',
            $expectedOpen,
            $result['mapped_open'],
            'mapped_open (только source) должен вернуть сырой список',
            ['source' => 'tabs_open.data']
        );

        $this->assert(
            'testNamed_Total',
            42,
            $result['total'],
            'total должен разрешиться как одиночное поле',
            ['field' => 'stats.total']
        );

        $this->assert(
            'testNamed_Context_New',
            $expectedNew,
            $context->get('mapped_new'),
            'mapped_new должен быть записан в контекст',
            ['check' => "context.get('mapped_new')"]
        );

        $this->assert(
            'testNamed_Context_Open',
            $expectedOpen,
            $context->get('mapped_open'),
            'mapped_open должен быть записан в контекст',
            ['check' => "context.get('mapped_open')"]
        );
    }

    /**
     * Тест 9: Source без mapping (только source)
     * 
     * Контекст: raw_list = список из 2 строк.
     * Конфиг: output = ['source' => 'raw_list'] без mapping.
     * Ожидание: сырой список возвращается как есть, без трансформации.
     * 
     * @return void
     */
    private function testSourceOnlyWithoutMapping(): void
    {
        $this->logger->separator('testSourceOnlyWithoutMapping');

        [$mapping] = $this->createMapping([
            'raw_list' => [['ID' => 1], ['ID' => 2]]
        ]);

        $config = [
            'output' => ['source' => 'raw_list']
        ];

        $expected = [
            'output' => [['ID' => 1], ['ID' => 2]]
        ];

        $this->assert(
            'testSourceOnly',
            $expected,
            $mapping->execute($config),
            'source без mapping должен вернуть сырой список',
            ['config' => "output = ['source' => 'raw_list']"]
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
            $this->logger->success('MappingTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('MappingTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
