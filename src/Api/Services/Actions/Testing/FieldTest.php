<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;

/**
 * Class FieldTest
 * 
 * Тесты для класса Field (Resolver).
 * 
 * Field Resolver — парсер выражений интерпретатора. Отвечает за разрешение:
 * - Полей контекста: field:path.to.value (точечная нотация, любая вложенность)
 * - Цепочек альтернатив: field:A|field:B (первое найденное значение)
 * - Ссылок на функции: func:empty, func:is_numeric (для условий)
 * - Ссылок на методы: method:Class->method (для условий)
 * - Результатов последнего действия: result, result:key
 * - Шаблонов: {{field:x}}, шорткатов {{LEAD.NAME}}, литералов
 * 
 * Лог: Field_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 52
 * 
 * @package Api\Services\Actions\Testing
 */
class FieldTest
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
     * FieldTest constructor.
     * 
     * Создаёт логгер с префиксом 'Field' — все записи идут
     * в файл Field_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Field');
    }

    /**
     * Запускает все тесты Field Resolver
     * 
     * Порядок логический, от простого к сложному:
     * - Базовые field-выражения (1-5)
     * - Типы значений (6-9)
     * - Ссылки на методы и функции (10-13)
     * - Результат последнего действия (14-15)
     * - Цепочки альтернатив (16-19)
     * - Неизвестные префиксы (20)
     * - Шаблоны {{ }} (21-28)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('FieldTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Field Resolver ===');

        // Базовые field-выражения
        $this->testEmptyField();
        $this->testSimpleField();
        $this->testNestedField();
        $this->testFieldNotFound();
        $this->testDeepNestedField();

        // Типы значений
        $this->testIntegerValue();
        $this->testArrayValue();
        $this->testNullValue();
        $this->testBooleanValue();

        // Ссылки на методы и функции
        $this->testMethodReference();
        $this->testMethodReferenceWithParams();
        $this->testFuncEmpty();
        $this->testFuncIsNumeric();

        // Результат последнего действия
        $this->testResult();
        $this->testResultWithKey();

        // Цепочки альтернатив
        $this->testChainAlternatives();
        $this->testChainAllEmpty();
        $this->testChainMixedTypes();
        $this->testNestedChain();

        // Неизвестные префиксы
        $this->testUnknownPrefix();

        // Шаблоны {{ }}
        $this->testTemplateText();
        $this->testTemplatePlus();
        $this->testTemplateNull();
        $this->testTemplateChainInside();
        $this->testTemplateSingleRaw();
        $this->testTemplateBracesLiteral();
        $this->testTemplateBarePathAndNullEmpty();
        $this->testTemplateBarePathSingleRaw();

        // Dynamic key access (v1.7.0)
        $this->testDynamicKeyBasic();
        $this->testDynamicKeyLiteralQuoted();
        $this->testDynamicKeyNumeric();
        $this->testDynamicKeyBareName();
        $this->testDynamicKeyNestedBrackets();
        $this->testDynamicKeyPathAfter();
        $this->testDynamicKeyDotBefore();
        $this->testDynamicKeyMissing();
        $this->testDynamicKeyNullKey();
        $this->testDynamicKeyChainWithBrackets();
        $this->testDynamicKeyInsideTemplate();

        // Рекурсивный резолв и date-выражения (v1.17.0)
        $this->testRecursiveFieldScalar();
        $this->testRecursiveFieldArray();
        $this->testRecursiveCycleProtection();
        $this->testDateExpressionNow();
        $this->testDateExpressionFormat();
        $this->testDateExpressionWithBase();
        $this->testLiteralEscape();
        $this->testDateExpressionBaseOnly();
        $this->testRecursiveArrayKeepsFuncStrings();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "FIELD TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // БАЗОВЫЕ FIELD-ВЫРАЖЕНИЯ (тесты 1-5)
    // ========================================================

    /**
     * Тест 1: Несуществующее поле
     * 
     * Контекст пустой, запрашиваем field:nonexistent.
     * Ожидание: null БЕЗ исключения — это штатная ситуация
     * для цепочек | и условий func:empty.
     * 
     * @return void
     */
    private function testEmptyField(): void
    {
        $this->logger->separator('testEmptyField: Пустое поле');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testEmptyField',
            null,
            $resolver->resolve('field:nonexistent'),
            'Несуществующее поле должно вернуть null без исключения',
            ['expression' => 'field:nonexistent']
        );
    }

    /**
     * Тест 2: Простое поле первого уровня
     * 
     * Контекст: ['name' => 'Иван']. Запрашиваем field:name.
     * Ожидание: 'Иван' — значение берётся напрямую из контекста.
     * 
     * @return void
     */
    private function testSimpleField(): void
    {
        $this->logger->separator('testSimpleField: Простое поле');

        $context = new Context(['name' => 'Иван']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testSimpleField',
            'Иван',
            $resolver->resolve('field:name'),
            'Простое поле должно разрешиться из контекста',
            ['expression' => 'field:name']
        );
    }

    /**
     * Тест 3: Вложенное поле (точечная нотация)
     * 
     * Контекст: ['CONTACT' => ['NAME' => 'Иван']]. Запрашиваем field:CONTACT.NAME.
     * Ожидание: 'Иван' — точка разделяет уровни вложенности.
     * 
     * @return void
     */
    private function testNestedField(): void
    {
        $this->logger->separator('testNestedField: Вложенное поле');

        $context = new Context(['CONTACT' => ['NAME' => 'Иван', 'ID' => 123]]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testNestedField',
            'Иван',
            $resolver->resolve('field:CONTACT.NAME'),
            'Вложенное поле должно разрешиться по точечной нотации',
            ['expression' => 'field:CONTACT.NAME']
        );
    }

    /**
     * Тест 4: Несуществующее вложенное поле
     * 
     * Контекст: CONTACT есть, но без PHONE. Запрашиваем field:CONTACT.PHONE.
     * Ожидание: null — отсутствие вложенного ключа не ошибка.
     * 
     * @return void
     */
    private function testFieldNotFound(): void
    {
        $this->logger->separator('testFieldNotFound: Поле не найдено');

        $context = new Context(['CONTACT' => ['NAME' => 'Иван']]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testFieldNotFound',
            null,
            $resolver->resolve('field:CONTACT.PHONE'),
            'Несуществующее вложенное поле должно вернуть null',
            ['expression' => 'field:CONTACT.PHONE']
        );
    }

    /**
     * Тест 5: Глубокая вложенность (4 уровня)
     * 
     * Контекст: A.B.C.D = 'deep_value'. Запрашиваем field:A.B.C.D.
     * Ожидание: 'deep_value' — вложенность работает на любую глубину.
     * 
     * @return void
     */
    private function testDeepNestedField(): void
    {
        $this->logger->separator('testDeepNestedField: Глубокая вложенность');

        $context = new Context([
            'A' => ['B' => ['C' => ['D' => 'deep_value']]]
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDeepNestedField',
            'deep_value',
            $resolver->resolve('field:A.B.C.D'),
            'Глубокая вложенность должна разрешиться',
            ['expression' => 'field:A.B.C.D']
        );
    }
    
    // ========================================================
    // ТИПЫ ЗНАЧЕНИЙ (тесты 6-9)
    // ========================================================

    /**
     * Тест 6: Целое число как выражение
     * 
     * resolve(42) — не строка.
     * Ожидание: 42 — не-строки возвращаются как есть.
     * 
     * @return void
     */
    private function testIntegerValue(): void
    {
        $this->logger->separator('testIntegerValue: Целое число');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testIntegerValue',
            42,
            $resolver->resolve(42),
            'Целое число должно вернуться как есть',
            ['expression' => 42]
        );
    }

    /**
     * Тест 7: Массив как выражение
     * 
     * resolve([1,2,3]) — не строка.
     * Ожидание: [1,2,3] — массив возвращается как есть.
     * 
     * @return void
     */
    private function testArrayValue(): void
    {
        $this->logger->separator('testArrayValue: Массив');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testArrayValue',
            [1, 2, 3],
            $resolver->resolve([1, 2, 3]),
            'Массив должен вернуться как есть',
            ['expression' => [1, 2, 3]]
        );
    }

    /**
     * Тест 8: null как выражение
     * 
     * resolve(null) — не строка.
     * Ожидание: null возвращается как есть.
     * 
     * @return void
     */
    private function testNullValue(): void
    {
        $this->logger->separator('testNullValue: null');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testNullValue',
            null,
            $resolver->resolve(null),
            'null должен вернуться как есть',
            ['expression' => null]
        );
    }

    /**
     * Тест 9: Boolean как выражение
     * 
     * resolve(true) — не строка.
     * Ожидание: true возвращается как есть.
     * 
     * @return void
     */
    private function testBooleanValue(): void
    {
        $this->logger->separator('testBooleanValue: Boolean');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testBooleanValue',
            true,
            $resolver->resolve(true),
            'Boolean должен вернуться как есть',
            ['expression' => true]
        );
    }
    
    // ========================================================
    // ССЫЛКИ НА МЕТОДЫ И ФУНКЦИИ (тесты 10-13)
    // ========================================================

    /**
     * Тест 10: Ссылка на метод класса (нестатический)
     * 
     * Формат: method:Class->method
     * Ожидание: структура с class и method, без ошибки парсинга.
     * 
     * ВАЖНО: если твой парсер использует разделитель '::' вместо '->',
     * замени в выражении '->' на '::'.
     * 
     * @return void
     */
    private function testMethodReference(): void
    {
        $this->logger->separator('testMethodReference: Ссылка на метод');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $result = $resolver->resolve('method:' . MockService::class . '->instanceTransform');

        $this->assert(
            'testMethodReference_Method',
            'instanceTransform',
            $result['method'] ?? null,
            'Ссылка method:Class->method должна распарсить имя метода',
            ['expression' => 'method:' . MockService::class . '->instanceTransform']
        );

        $this->assert(
            'testMethodReference_Class',
            MockService::class,
            $result['class'] ?? null,
            'Ссылка method:Class->method должна распарсить класс',
            ['check' => 'result.class']
        );
    }

    /**
     * Тест 11: Ссылка на статический метод
     * 
     * Формат: method:Class::method (разделитель :: = статический)
     * Ожидание: static = true.
     * 
     * Конвенция разделителей:
     * - '->' → instance (static: false)
     * - '::' → static (static: true)
     * 
     * @return void
     */
    private function testMethodReferenceWithParams(): void
    {
        $this->logger->separator('testMethodReferenceWithParams: Статический метод');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $result = $resolver->resolve('method:' . MockService::class . '::staticTransform');

        $this->assert(
            'testMethodReferenceWithParams',
            true,
            $result['static'] ?? null,
            'Статический метод (разделитель ::) должен быть определён как static=true',
            ['expression' => 'method:' . MockService::class . '::staticTransform']
        );
    }

    /**
     * Тест 12: func:empty возвращает структуру типа function
     * 
     * func:empty используется в условиях как "проверить через empty()".
     * Ожидание: ['type' => 'function', 'name' => 'empty'].
     * 
     * @return void
     */
    private function testFuncEmpty(): void
    {
        $this->logger->separator('testFuncEmpty: func:empty');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $result = $resolver->resolve('func:empty');

        $this->assert(
            'testFuncEmpty',
            'function',
            $result['type'] ?? null,
            'func: должен вернуть структуру с типом function',
            ['expression' => 'func:empty']
        );
    }

    /**
     * Тест 13: func:is_numeric возвращает структуру типа function
     * 
     * Ожидание: ['type' => 'function', 'name' => 'is_numeric'].
     * 
     * @return void
     */
    private function testFuncIsNumeric(): void
    {
        $this->logger->separator('testFuncIsNumeric: func:is_numeric');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $result = $resolver->resolve('func:is_numeric');

        $this->assert(
            'testFuncIsNumeric',
            'function',
            $result['type'] ?? null,
            'func:is_numeric должен вернуть структуру с типом function',
            ['expression' => 'func:is_numeric']
        );
    }
    
    // ========================================================
    // РЕЗУЛЬТАТ ПОСЛЕДНЕГО ДЕЙСТВИЯ (тесты 14-15)
    // ========================================================

    /**
     * Тест 14: result — последнее действие целиком
     * 
     * Устанавливаем lastResult = ['ID' => 123], запрашиваем 'result'.
     * Ожидание: весь массив результата.
     * 
     * @return void
     */
    private function testResult(): void
    {
        $this->logger->separator('testResult: Результат последнего действия');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);
        $resolver->setLastResult(['ID' => 123]);

        $this->assert(
            'testResult',
            ['ID' => 123],
            $resolver->resolve('result'),
            'result должен вернуть последнее действие целиком',
            ['expression' => 'result']
        );
    }

    /**
     * Тест 15: result:key — конкретное поле из результата
     * 
     * lastResult = ['ID' => 123, 'NAME' => 'Test'], запрашиваем result:ID.
     * Ожидание: 123 — значение по ключу из результата.
     * 
     * @return void
     */
    private function testResultWithKey(): void
    {
        $this->logger->separator('testResultWithKey');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);
        $resolver->setLastResult(['ID' => 123, 'NAME' => 'Test']);

        $this->assert(
            'testResultWithKey',
            123,
            $resolver->resolve('result:ID'),
            'result:key должен вернуть конкретное поле результата',
            ['expression' => 'result:ID']
        );
    }
    
    // ========================================================
    // ЦЕПОЧКИ АЛЬТЕРНАТИВ (тесты 16-19)
    // ========================================================

    /**
     * Тест 16: Цепочка | — первое найденное значение
     * 
     * CONTACT.PHONE есть, COMPANY.PHONE есть.
     * Ожидание: CONTACT.PHONE — первое ненулевое в цепочке.
     * 
     * @return void
     */
    private function testChainAlternatives(): void
    {
        $this->logger->separator('testChainAlternatives: Цепочка альтернатив');

        $context = new Context([
            'CONTACT' => ['PHONE' => '+79991234567'],
            'COMPANY' => ['PHONE' => '+79997654321']
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testChainAlternatives',
            '+79991234567',
            $resolver->resolve('field:CONTACT.PHONE|field:COMPANY.PHONE'),
            'Цепочка должна вернуть первое найденное значение',
            ['expression' => 'field:CONTACT.PHONE|field:COMPANY.PHONE']
        );
    }

    /**
     * Тест 17: Цепочка где все значения null
     * 
     * Контекст пустой, цепочка из трёх полей.
     * Ожидание: null — ничего не найдено.
     * 
     * @return void
     */
    private function testChainAllEmpty(): void
    {
        $this->logger->separator('testChainAllEmpty: Все null');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testChainAllEmpty',
            null,
            $resolver->resolve('field:A|field:B|field:C'),
            'Если все null, цепочка должна вернуть null',
            ['expression' => 'field:A|field:B|field:C']
        );
    }

    /**
     * Тест 18: Цепочка с разными типами значений
     * 
     * num = 42 (число). Цепочка field:num|field:missing.
     * Ожидание: 42 — цепочка работает с любыми типами.
     * 
     * @return void
     */
    private function testChainMixedTypes(): void
    {
        $this->logger->separator('testChainMixedTypes: Разные типы');

        $context = new Context(['num' => 42]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testChainMixedTypes',
            42,
            $resolver->resolve('field:num|field:missing'),
            'Цепочка с разными типами должна работать',
            ['expression' => 'field:num|field:missing']
        );
    }

    /**
     * Тест 19: Вложенная цепочка
     * 
     * A.X = null, B.Y = 'found'. Цепочка field:A.X|field:B.Y.
     * Ожидание: 'found' — доходит до первого ненулевого.
     * 
     * @return void
     */
    private function testNestedChain(): void
    {
        $this->logger->separator('testNestedChain: Вложенная цепочка');

        $context = new Context([
            'A' => ['X' => null],
            'B' => ['Y' => 'found']
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testNestedChain',
            'found',
            $resolver->resolve('field:A.X|field:B.Y'),
            'Вложенная цепочка должна дойти до первого ненулевого',
            ['expression' => 'field:A.X|field:B.Y']
        );
    }
    
    // ========================================================
    // НЕИЗВЕСТНЫЕ ПРЕФИКСЫ (тест 20)
    // ========================================================

    /**
     * Тест 20: Неизвестный префикс
     * 
     * 'unknown:value' — не field:, не func:, не method:, не result.
     * Ожидание: строка возвращается как есть (литерал).
     * 
     * @return void
     */
    private function testUnknownPrefix(): void
    {
        $this->logger->separator('testUnknownPrefix: Неизвестный префикс');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testUnknownPrefix',
            'unknown:value',
            $resolver->resolve('unknown:value'),
            'Неизвестный префикс должен вернуться как строка',
            ['expression' => 'unknown:value']
        );
    }
    
    // ========================================================
    // ШАБЛОНЫ {{ }} (тесты 21-28)
    // ========================================================

    /**
     * Тест 21: Шаблон с текстом вокруг плейсхолдеров
     * 
     * 'Клиент {{field:NAME}}, тел: +{{field:phone}}'.
     * Ожидание: 'Клиент Иван, тел: +79990000000' —
     * плейсхолдеры вставляются, литералы (включая +) сохраняются.
     * 
     * @return void
     */
    private function testTemplateText(): void
    {
        $this->logger->separator('testTemplateText: Текст + {{ }}');

        $context = new Context(['NAME' => 'Иван', 'phone' => '79990000000']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateText',
            'Клиент Иван, тел: +79990000000',
            $resolver->resolve('Клиент {{field:NAME}}, тел: +{{field:phone}}'),
            'Плейсхолдеры и литерал + должны вставиться в текст',
            ['expression' => 'Клиент {{field:NAME}}, тел: +{{field:phone}}']
        );
    }

    /**
     * Тест 22: Плюс как литерал перед плейсхолдером
     * 
     * '+{{field:phone}}' при phone = '79990000000'.
     * Ожидание: '+79990000000' — плюс это обычный текст.
     * 
     * @return void
     */
    private function testTemplatePlus(): void
    {
        $this->logger->separator('testTemplatePlus: +{{field:phone}}');

        $context = new Context(['phone' => '79990000000']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplatePlus',
            '+79990000000',
            $resolver->resolve('+{{field:phone}}'),
            'Плюс — обычный текст, значение подставляется',
            ['expression' => '+{{field:phone}}']
        );
    }

    /**
     * Тест 23: Null в плейсхолдере внутри текста
     * 
     * '+{{field:phone}}' при отсутствии phone.
     * Ожидание: '+' — плейсхолдер с null даёт пустую строку,
     * литералы остаются (семантика Twig/Blade).
     * 
     * @return void
     */
    private function testTemplateNull(): void
    {
        $this->logger->separator('testTemplateNull: null в тексте');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateNull',
            '+',
            $resolver->resolve('+{{field:phone}}'),
            'При null в плейсхолдере шаблон возвращает строку с литералами',
            ['expression' => '+{{field:phone}}']
        );
    }

    /**
     * Тест 24: Цепочка | внутри плейсхолдера
     * 
     * '{{field:CONTACT_PHONE.VALUE|field:COMPANY_PHONE.VALUE}}'
     * при наличии только COMPANY_PHONE.VALUE.
     * Ожидание: '+79997654321' — цепочка работает внутри шаблона.
     * 
     * @return void
     */
    private function testTemplateChainInside(): void
    {
        $this->logger->separator('testTemplateChainInside: Цепочка внутри {{ }}');

        $context = new Context(['COMPANY_PHONE' => ['VALUE' => '+79997654321']]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateChainInside',
            '+79997654321',
            $resolver->resolve('{{field:CONTACT_PHONE.VALUE|field:COMPANY_PHONE.VALUE}}'),
            'Цепочка альтернатив работает внутри плейсхолдера',
            ['expression' => '{{field:CONTACT_PHONE.VALUE|field:COMPANY_PHONE.VALUE}}']
        );
    }

    /**
     * Тест 25: Одиночный плейсхолдер возвращает сырое значение
     * 
     * '{{field:count}}' при count = 5.
     * Ожидание: 5 (int, не строка '5') — тип сохраняется.
     * 
     * @return void
     */
    private function testTemplateSingleRaw(): void
    {
        $this->logger->separator('testTemplateSingleRaw: Одиночный плейсхолдер');

        $context = new Context(['count' => 5]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateSingleRaw',
            5,
            $resolver->resolve('{{field:count}}'),
            'Одиночный плейсхолдер возвращает сырое значение с типом',
            ['expression' => '{{field:count}}']
        );
    }

    /**
     * Тест 26: Скобки {{ }} с содержимым, не похожим на выражение
     * 
     * '{{ 100 }}' — начинается с цифры, не является голым путём
     * и не имеет префикса field:/result: → остаётся литералом.
     * Ожидание: строка не изменяется.
     * 
     * Примечание: '{{ b }}' литералом НЕ остаётся — это валидный
     * шорткат field:b (по спецификации шорткатов).
     * 
     * @return void
     */
    private function testTemplateBracesLiteral(): void
    {
        $this->logger->separator('testTemplateBracesLiteral: Скобки-литералы');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateBracesLiteral',
            'a {{ 100 }} c',
            $resolver->resolve('a {{ 100 }} c'),
            'Скобки с не-путём внутри (цифра) остаются литералом',
            ['expression' => 'a {{ 100 }} c']
        );
    }

    /**
     * Тест 27: Шорткат {{LEAD.X}} без префикса field:
     * 
     * '{{LEAD.LAST_NAME}} {{LEAD.NAME}} {{LEAD.SECOND_NAME}}'
     * при SECOND_NAME = null.
     * Ожидание: 'Иванов Иван ' — шорткат работает, null → ''.
     * 
     * @return void
     */
    private function testTemplateBarePathAndNullEmpty(): void
    {
        $this->logger->separator('testTemplateBarePathAndNullEmpty: Шорткат + null');

        $context = new Context([
            'LEAD' => ['LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'SECOND_NAME' => null]
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateBarePathAndNullEmpty',
            'Иванов Иван ',
            $resolver->resolve('{{LEAD.LAST_NAME}} {{LEAD.NAME}} {{LEAD.SECOND_NAME}}'),
            'Шорткат работает, null в тексте даёт пустую строку',
            ['expression' => '{{LEAD.LAST_NAME}} {{LEAD.NAME}} {{LEAD.SECOND_NAME}}']
        );
    }

    /**
     * Тест 28: Одиночный шорткат {{LEAD.X}}
     * 
     * '{{LEAD.PHONE}}' → '+7999' (сырое значение),
     * '{{LEAD.EMAIL}}' → null (отсутствует).
     * Ожидание: шорткат ведёт себя как field: в одиночном режиме.
     * 
     * @return void
     */
    private function testTemplateBarePathSingleRaw(): void
    {
        $this->logger->separator('testTemplateBarePathSingleRaw: Шорткат одиночный');

        $context = new Context(['LEAD' => ['PHONE' => '+7999']]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testTemplateBarePathSingleRaw_Value',
            '+7999',
            $resolver->resolve('{{LEAD.PHONE}}'),
            'Одиночный шорткат возвращает значение',
            ['expression' => '{{LEAD.PHONE}}']
        );

        $this->assert(
            'testTemplateBarePathSingleRaw_Null',
            null,
            $resolver->resolve('{{LEAD.EMAIL}}'),
            'Одиночный шорткат с null возвращает null',
            ['expression' => '{{LEAD.EMAIL}}']
        );
    }

    // ========================================================
    // DYNAMIC KEY ACCESS (v1.7.0)
    // ========================================================

    /**
     * Тест: базовый динамический ключ field:pr_hl[field:index_code]
     */
    private function testDynamicKeyBasic(): void
    {
        $this->logger->separator('testDynamicKeyBasic');

        $context = new Context([
            'pr_hl' => ['A13' => 'Недавно проигранная сделка', 'B7' => 'Другое'],
            'index_code' => 'A13',
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyBasic',
            'Недавно проигранная сделка',
            $resolver->resolve('field:pr_hl[field:index_code]'),
            'Значение берётся по ключу, вычисленному из другого поля',
            ['expression' => 'field:pr_hl[field:index_code]']
        );
    }

    /**
     * Тест: литерал в кавычках field:arr['NEW_CAR']
     */
    private function testDynamicKeyLiteralQuoted(): void
    {
        $this->logger->separator('testDynamicKeyLiteralQuoted');

        $context = new Context([
            'business_lines' => ['NEW_CAR' => 'Новый автомобиль'],
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyLiteralQuoted',
            'Новый автомобиль',
            $resolver->resolve("field:business_lines['NEW_CAR']"),
            'Литеральный ключ в кавычках работает',
            ['expression' => "field:business_lines['NEW_CAR']"]
        );
    }

    /**
     * Тест: числовой индекс field:list[1]
     */
    private function testDynamicKeyNumeric(): void
    {
        $this->logger->separator('testDynamicKeyNumeric');

        $context = new Context(['list' => [10, 20, 30]]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyNumeric',
            20,
            $resolver->resolve('field:list[1]'),
            'Числовой индекс работает',
            ['expression' => 'field:list[1]']
        );
    }

    /**
     * Тест: голое имя в скобках = литеральный ключ
     */
    private function testDynamicKeyBareName(): void
    {
        $this->logger->separator('testDynamicKeyBareName');

        $context = new Context(['arr' => ['code' => 'x']]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyBareName',
            'x',
            $resolver->resolve('field:arr[code]'),
            'Голое имя в скобках — литеральный ключ',
            ['expression' => 'field:arr[code]']
        );
    }

    /**
     * Тест: вложенные скобки (матрица) field:m[field:r][field:c]
     */
    private function testDynamicKeyNestedBrackets(): void
    {
        $this->logger->separator('testDynamicKeyNestedBrackets');

        $context = new Context([
            'm' => [['a', 'b'], ['c', 'd']],
            'r' => 1,
            'c' => 0,
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyNestedBrackets',
            'c',
            $resolver->resolve('field:m[field:r][field:c]'),
            'Вложенные скобки работают (двумерный доступ)',
            ['expression' => 'field:m[field:r][field:c]']
        );
    }

    /**
     * Тест: точечный путь ПОСЛЕ скобок field:arr[field:key].name
     */
    private function testDynamicKeyPathAfter(): void
    {
        $this->logger->separator('testDynamicKeyPathAfter');

        $context = new Context([
            'arr' => ['k' => ['name' => 'V']],
            'key' => 'k',
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyPathAfter',
            'V',
            $resolver->resolve('field:arr[field:key].name'),
            'Точечный путь после скобок работает',
            ['expression' => 'field:arr[field:key].name']
        );
    }

    /**
     * Тест: точка ПЕРЕД скобками field:a.b[field:key]
     */
    private function testDynamicKeyDotBefore(): void
    {
        $this->logger->separator('testDynamicKeyDotBefore');

        $context = new Context([
            'a' => ['b' => ['k1' => 'v']],
            'key' => 'k1',
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyDotBefore',
            'v',
            $resolver->resolve('field:a.b[field:key]'),
            'Точечный путь перед скобками работает',
            ['expression' => 'field:a.b[field:key]']
        );
    }

    /**
     * Тест: отсутствующий ключ → null
     */
    private function testDynamicKeyMissing(): void
    {
        $this->logger->separator('testDynamicKeyMissing');

        $context = new Context(['arr' => [], 'key' => 'x']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyMissing',
            null,
            $resolver->resolve('field:arr[field:key]'),
            'Отсутствующий ключ возвращает null без исключения',
            ['expression' => 'field:arr[field:key]']
        );
    }

    /**
     * Тест: поле-ключ отсутствует (резолвится в null) → весь путь null
     */
    private function testDynamicKeyNullKey(): void
    {
        $this->logger->separator('testDynamicKeyNullKey');

        $context = new Context(['arr' => ['a' => 1]]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyNullKey',
            null,
            $resolver->resolve('field:arr[field:missing]'),
            'null-ключ возвращает null без fatal',
            ['expression' => 'field:arr[field:missing]']
        );
    }

    /**
     * Тест: цепочка | не ломается на скобках
     */
    private function testDynamicKeyChainWithBrackets(): void
    {
        $this->logger->separator('testDynamicKeyChainWithBrackets');

        $context = new Context([
            'arr' => [],
            'fallback' => 'found',
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyChainWithBrackets',
            'found',
            $resolver->resolve('field:arr[field:missing]|field:fallback'),
            'Цепочка альтернатив работает вместе со скобками',
            ['expression' => 'field:arr[field:missing]|field:fallback']
        );
    }

    /**
     * Тест: скобки внутри шаблонов {{ }} (полная форма и шорткат)
     */
    private function testDynamicKeyInsideTemplate(): void
    {
        $this->logger->separator('testDynamicKeyInsideTemplate');

        $context = new Context([
            'pr_hl' => ['A13' => 'Проигранная'],
            'index_code' => 'A13',
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDynamicKeyInsideTemplate_Full',
            'Проигранная',
            $resolver->resolve('{{field:pr_hl[field:index_code]}}'),
            'Полная форма field: со скобками работает в шаблоне',
            ['expression' => '{{field:pr_hl[field:index_code]}}']
        );

        $this->assert(
            'testDynamicKeyInsideTemplate_Shortcut',
            'Статус: Проигранная',
            $resolver->resolve('Статус: {{pr_hl[field:index_code]}}'),
            'Шорткат со скобками работает в шаблоне',
            ['expression' => 'Статус: {{pr_hl[field:index_code]}}']
        );
    }

    // ========================================================
    // РЕКУРСИВНЫЙ РЕЗОЛВ И DATE-ВЫРАЖЕНИЯ (v1.17.0)
    // ========================================================

    /**
     * Тест: рекурсивный резолв скаляров field: → field: → значение
     */
    private function testRecursiveFieldScalar(): void
    {
        $this->logger->separator('testRecursiveFieldScalar');

        $context = new Context([
            'ref1' => 'field:ref2',
            'ref2' => 'field:final',
            'final' => 42,
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testRecursiveFieldScalar',
            42,
            $resolver->resolve('field:ref1'),
            'field:ref1 → field:ref2 → field:final → 42',
            ['expression' => 'field:ref1']
        );
    }

    /**
     * Тест: рекурсивный резолв в массивах любой глубины
     */
    private function testRecursiveFieldArray(): void
    {
        $this->logger->separator('testRecursiveFieldArray');

        $context = new Context([
            'config' => [
                'name' => 'field:product_name',
                'nested' => ['id' => 'field:product_id'],
            ],
            'product_name' => 'iPhone',
            'product_id' => 123,
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testRecursiveFieldArray',
            ['name' => 'iPhone', 'nested' => ['id' => 123]],
            $resolver->resolve('field:config'),
            'Строки-выражения разрешаются внутри массивов на любой глубине',
            ['expression' => 'field:config']
        );
    }

    /**
     * Тест: циклическая ссылка → null без fatal
     */
    private function testRecursiveCycleProtection(): void
    {
        $this->logger->separator('testRecursiveCycleProtection');

        $context = new Context(['a' => 'field:b', 'b' => 'field:a']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testRecursiveCycleProtection',
            null,
            $resolver->resolve('field:a'),
            'Цикл field:a → field:b → field:a возвращает null без fatal',
            ['expression' => 'field:a']
        );
    }

    /**
     * Тест: date:"+1 day" — now + модификатор, формат по умолчанию
     */
    private function testDateExpressionNow(): void
    {
        $this->logger->separator('testDateExpressionNow');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDateExpressionNow',
            date('d.m.Y H:i:s', strtotime('+1 day')),
            $resolver->resolve('date:"+1 day"'),
            'date:"+1 day" → текущее время + 1 день, формат d.m.Y H:i:s',
            ['expression' => 'date:"+1 day"']
        );
    }

    /**
     * Тест: date с собственным форматом
     */
    private function testDateExpressionFormat(): void
    {
        $this->logger->separator('testDateExpressionFormat');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDateExpressionFormat',
            date('d.m.Y H:i', strtotime('-2 hours')),
            $resolver->resolve('date:"-2 hours; d.m.Y H:i"'),
            'date:"-2 hours; d.m.Y H:i" → свой формат вывода',
            ['expression' => 'date:"-2 hours; d.m.Y H:i"']
        );
    }

    /**
     * Тест: date с базой из поля
     */
    private function testDateExpressionWithBase(): void
    {
        $this->logger->separator('testDateExpressionWithBase');

        $context = new Context(['appointment_at' => '2026-09-01 14:00:00']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDateExpressionWithBase',
            date('d.m.Y H:i', strtotime('+2 hours', strtotime('2026-09-01 14:00:00'))),
            $resolver->resolve('date:"@field:appointment_at +2 hours; d.m.Y H:i"'),
            'База @field: + модификатор + свой формат',
            ['expression' => 'date:"@field:appointment_at +2 hours; d.m.Y H:i"']
        );
    }

    /**
     * Тест: literal: — текст с префиксами остаётся как есть
     */
    private function testLiteralEscape(): void
    {
        $this->logger->separator('testLiteralEscape');

        $context = new Context([]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testLiteralEscape_Field',
            'field:test',
            $resolver->resolve('literal:field:test'),
            'literal:field:test → строка "field:test" без резолва',
            ['expression' => 'literal:field:test']
        );

        $this->assert(
            'testLiteralEscape_Date',
            'date:"+1 day"',
            $resolver->resolve('literal:date:"+1 day"'),
            'literal:date:"..." → строка как есть',
            ['expression' => 'literal:date:"+1 day"']
        );
    }

    /**
     * Тест: база без модификатора — просто форматируем дату поля
     */
    private function testDateExpressionBaseOnly(): void
    {
        $this->logger->separator('testDateExpressionBaseOnly');

        $context = new Context(['test_drive_at' => '2026-09-05 10:00:00']);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testDateExpressionBaseOnly',
            date('d.m.Y', strtotime('2026-09-05 10:00:00')),
            $resolver->resolve('date:"@field:test_drive_at; d.m.Y"'),
            'База без модификатора: дата поля в заданном формате',
            ['expression' => 'date:"@field:test_drive_at; d.m.Y"']
        );
    }

    /**
     * Тест: func:/method:/result в данных НЕ резолвятся рекурсивно
     */
    private function testRecursiveArrayKeepsFuncStrings(): void
    {
        $this->logger->separator('testRecursiveArrayKeepsFuncStrings');

        $context = new Context([
            'data' => ['check' => 'func:empty', 'ref' => 'field:x'],
            'x' => 7,
        ]);
        $context->setTestLogger($this->logger);
        $resolver = new Field($context);

        $this->assert(
            'testRecursiveArrayKeepsFuncStrings',
            ['check' => 'func:empty', 'ref' => 7],
            $resolver->resolve('field:data'),
            'field: в данных резолвится, func: остаётся строкой',
            ['expression' => 'field:data']
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
            $this->logger->success('FieldTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('FieldTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
