<?php

namespace Api\Services\Actions\Testing;

use Api\Services\ArrayTransformer;

/**
 * Class ArrayTransformerTest
 * 
 * Тесты сервиса ArrayTransformer: перегруппировка списков,
 * переименование полей, декларативные трансформации через fn+args.
 * 
 * Всего тестов: 7
 * 
 * @package Api\Services\Actions\Testing
 */
class ArrayTransformerTest
{
    private TestLogger $logger;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->logger = new TestLogger('ArrayTransformer');
    }

    public function runAll(): void
    {
        $this->logger->info('ArrayTransformerTest', 'runAll', '=== ЗАПУСК ТЕСТОВ ArrayTransformer ===');

        $this->testToSelectOptionsBasic();
        $this->testToSelectOptionsWithJsonDecode();
        $this->testToSelectOptionsMissingField();
        $this->testToSelectOptionsInvalidJson();
        $this->testRenameKeys();
        $this->testToSelectOptionsWithTransform();
        $this->testToSelectOptionsChained();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n========================================\n";
        echo "ARRAYTRANSFORMER TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }

    /**
     * Базовый маппинг: value + label (прямой доступ по имени поля)
     */
    private function testToSelectOptionsBasic(): void
    {
        $this->logger->separator('testToSelectOptionsBasic');

        $t = new ArrayTransformer();
        $items = [
            ['UF_CODE' => 'A', 'UF_NAME' => 'Alpha'],
            ['UF_CODE' => 'B', 'UF_NAME' => 'Beta'],
        ];

        $result = $t->toSelectOptions($items, [
            'value' => 'UF_CODE',
            'label' => 'UF_NAME',
        ]);

        $this->assert(
            'testToSelectOptionsBasic',
            [
                ['value' => 'A', 'label' => 'Alpha'],
                ['value' => 'B', 'label' => 'Beta'],
            ],
            $result,
            'Базовый маппинг: value и label из полей источника',
            []
        );
    }

    /**
     * Декларативный json_decode через fn+args
     */
    private function testToSelectOptionsWithJsonDecode(): void
    {
        $this->logger->separator('testToSelectOptionsWithJsonDecode');

        $t = new ArrayTransformer();
        $items = [
            [
                'UF_CODE' => 'NO_ANSWER',
                'UF_NAME' => 'Нет ответа',
                'UF_SHOW_IF' => '{"field":"contact_occurred","operator":"eq","value":"0"}',
            ],
        ];

        $result = $t->toSelectOptions($items, [
            'value'  => 'UF_CODE',
            'label'  => 'UF_NAME',
            'showIf' => ['fn' => 'json_decode', 'args' => ['item:UF_SHOW_IF', true]],
        ]);

        $this->assert(
            'testToSelectOptionsWithJsonDecode_Value',
            'NO_ANSWER',
            $result[0]['value'] ?? null,
            'value берётся из UF_CODE',
            []
        );

        $this->assert(
            'testToSelectOptionsWithJsonDecode_ShowIf',
            ['field' => 'contact_occurred', 'operator' => 'eq', 'value' => '0'],
            $result[0]['showIf'] ?? null,
            'showIf декодируется из JSON-строки через fn+args',
            []
        );
    }

    /**
     * Отсутствующее поле → null, без исключения
     */
    private function testToSelectOptionsMissingField(): void
    {
        $this->logger->separator('testToSelectOptionsMissingField');

        $t = new ArrayTransformer();
        $items = [['UF_CODE' => 'A']];

        $result = $t->toSelectOptions($items, [
            'value' => 'UF_CODE',
            'label' => 'MISSING',
        ]);

        // Проверка через array_key_exists — оператор ?? не отличает «нет ключа» от «ключ = null»
        $this->assert(
            'testToSelectOptionsMissingField',
            true,
            array_key_exists('label', $result[0]) && $result[0]['label'] === null,
            'Отсутствующее поле-источник → null без ошибки',
            ['result' => $result]
        );
    }

    /**
     * Невалидный JSON в fn+args → null (исключение перехвачено), без падения списка
     */
    private function testToSelectOptionsInvalidJson(): void
    {
        $this->logger->separator('testToSelectOptionsInvalidJson');

        $t = new ArrayTransformer();
        $items = [['UF_CODE' => 'A', 'UF_SHOW_IF' => 'not-a-json{{']];

        $result = $t->toSelectOptions($items, [
            'value'  => 'UF_CODE',
            'showIf' => ['fn' => 'json_decode', 'args' => ['item:UF_SHOW_IF', true]],
        ]);

        $this->assert(
            'testToSelectOptionsInvalidJson',
            true,
            array_key_exists('showIf', $result[0]) && $result[0]['showIf'] === null,
            'Невалидный JSON в fn+args → null, список не падает',
            ['result' => $result]
        );
    }

    /**
     * Переименование ключей
     */
    private function testRenameKeys(): void
    {
        $this->logger->separator('testRenameKeys');

        $t = new ArrayTransformer();
        $items = [['ID' => 1, 'NAME' => 'Иван'], ['ID' => 2, 'NAME' => 'Пётр']];

        $result = $t->renameKeys($items, ['ID' => 'id', 'NAME' => 'name']);

        $this->assert(
            'testRenameKeys',
            [['id' => 1, 'name' => 'Иван'], ['id' => 2, 'name' => 'Пётр']],
            $result,
            'renameKeys переименовывает ключи, сохраняя остальные',
            []
        );
    }

    /**
     * Декларативная трансформация через fn+args:
     * strtoupper для value, json_decode для showIf, прямая копия для label
     */
    private function testToSelectOptionsWithTransform(): void
    {
        $this->logger->separator('testToSelectOptionsWithTransform');

        $t = new ArrayTransformer();
        $items = [
            [
                'UF_CODE' => 'no_answer',
                'UF_NAME' => 'Нет ответа',
                'UF_SHOW_IF' => '{"field":"contact_occurred","operator":"eq","value":"0"}',
            ],
        ];

        $result = $t->toSelectOptions($items, [
            'value'  => ['fn' => 'strtoupper', 'args' => ['item:UF_CODE']],
            'label'  => 'UF_NAME',
            'showIf' => ['fn' => 'json_decode', 'args' => ['item:UF_SHOW_IF', true]],
        ]);

        $this->assert(
            'testToSelectOptionsWithTransform_Value',
            'NO_ANSWER',
            $result[0]['value'] ?? null,
            'strtoupper применился к полю UF_CODE',
            []
        );

        $this->assert(
            'testToSelectOptionsWithTransform_ShowIf',
            ['field' => 'contact_occurred', 'operator' => 'eq', 'value' => '0'],
            $result[0]['showIf'] ?? null,
            'json_decode превратил строку в ассоциативный массив',
            []
        );

        $this->assert(
            'testToSelectOptionsWithTransform_Label',
            'Нет ответа',
            $result[0]['label'] ?? null,
            'Прямой доступ к полю по-прежнему работает',
            []
        );
    }

    /**
     * Цепочка трансформаций: trim(strtoupper(x))
     */
    private function testToSelectOptionsChained(): void
    {
        $this->logger->separator('testToSelectOptionsChained');

        $t = new ArrayTransformer();
        $items = [['NAME' => '  hello  ']];

        $result = $t->toSelectOptions($items, [
            'clean' => [
                'fn' => 'trim',
                'args' => [
                    ['fn' => 'strtoupper', 'args' => ['item:NAME']],
                ],
            ],
        ]);

        $this->assert(
            'testToSelectOptionsChained',
            'HELLO',
            $result[0]['clean'] ?? null,
            'Вложенные fn-правила работают рекурсивно',
            []
        );
    }

    private function assert(string $testName, $expected, $actual, string $message, array $context = []): void
    {
        if ($expected === $actual) {
            $this->passed++;
            $this->logger->success('ArrayTransformerTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $context,
            ]);
        } else {
            $this->failed++;
            $this->logger->error('ArrayTransformerTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $context,
            ]);
        }
    }
}
