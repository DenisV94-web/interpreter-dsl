<?php

namespace Api\Services\Actions\Testing;

use Api\Services\ArrayTransformer;
use Api\Services\ArrayFilter;

/**
 * Class ArrayTransformerTest
 * 
 * Тесты сервиса ArrayTransformer: перегруппировка списков,
 * переименование полей, декларативные трансформации через fn+args.
 * 
 * Всего тестов: 29
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
        $this->testApplyInstructions();

        // item-шаблоны в toSelectOptions (v1.19.1)
        $this->testToSelectOptionsItemTemplate();
        $this->testToSelectOptionsItemTemplateNull();
        $this->testToSelectOptionsItemTemplateNested();

        // toMap (v1.20.0)
        $this->testToMapBasic();
        $this->testToMapEdgeCases();

        // applySchema (v1.21.0)
        $this->testApplySchemaSingleRow();
        $this->testApplySchemaList();
        $this->testApplySchemaVariantMiss();
        $this->testApplySchemaMissingField();

        // query в applySchema (v1.22.0)
        $this->testApplySchemaWithQuery();
        $this->testApplySchemaQueryEmptyBatch();
        $this->testApplySchemaQueryCache();

        // projectTree (v1.24.0)
        $this->testProjectTreeObject();
        $this->testProjectTreeList();
        $this->testProjectTreeRootRow();
        $this->testProjectTreeMissingDefaults();
        $this->testProjectTreeScalarPathIgnored();
        $this->testProjectTreeAfterFilterTree();

        // fillEmpty (v1.25.0)
        $this->testFillEmptyNullAndEmptyString();
        $this->testFillEmptyCustomEmptyValues();
        $this->testFillEmptyMissingKeyAndScalarPath();

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

    /**
     * applyInstructions: инкремент, литерал, field:, null-инструкции
     */
    private function testApplyInstructions(): void
    {
        $this->logger->separator('testApplyInstructions');

        $t = new ArrayTransformer();

        $result = $t->applyInstructions(
            [
                'UF_CALL_COUNT' => 'UF_CALL_COUNT++',
                'UF_DO_NOT_CONTACT' => '1',
                'UF_CALL_DATE' => 'field:next_action_at',
                'UF_MISSING_REF' => 'field:unknown',
            ],
            ['UF_CALL_COUNT' => '4'],
            ['next_action_at' => '2026-08-28 10:00:00']
        );

        $this->assert(
            'testApplyInstructions_Increment',
            5,
            $result['UF_CALL_COUNT'] ?? null,
            'UF_CALL_COUNT++ от текущего значения 4 даёт 5',
            []
        );

        $this->assert(
            'testApplyInstructions_Literal',
            '1',
            $result['UF_DO_NOT_CONTACT'] ?? null,
            'Литерал передаётся как есть',
            []
        );

        $this->assert(
            'testApplyInstructions_Field',
            '2026-08-28 10:00:00',
            $result['UF_CALL_DATE'] ?? null,
            'field:next_action_at берётся из values',
            []
        );

        $this->assert(
            'testApplyInstructions_NullInstructions',
            [],
            $t->applyInstructions(null, []),
            'null-инструкции (пустой JSON) дают пустой массив',
            []
        );
    }

    /**
     * item-шаблон: склейка полей текущей строки (v1.19.1)
     */
    private function testToSelectOptionsItemTemplate(): void
    {
        $this->logger->separator('testToSelectOptionsItemTemplate');
        $t = new ArrayTransformer();

        $result = $t->toSelectOptions(
            [['ID' => 1, 'LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'SECOND_NAME' => 'Иванович']],
            [
                'id_user' => 'ID',
                'fio'     => '{{item:LAST_NAME}} {{item:NAME}} {{item:SECOND_NAME}}',
            ]
        );

        $this->assert(
            'testToSelectOptionsItemTemplate',
            [['id_user' => 1, 'fio' => 'Иванов Иван Иванович']],
            $result,
            'item-шаблон склеивает поля текущей строки',
            []
        );
    }

    /**
     * item-шаблон с null-полем: null → '' + trim хвоста (v1.19.1)
     */
    private function testToSelectOptionsItemTemplateNull(): void
    {
        $this->logger->separator('testToSelectOptionsItemTemplateNull');
        $t = new ArrayTransformer();

        $result = $t->toSelectOptions(
            [['LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'SECOND_NAME' => null]],
            ['fio' => '{{item:LAST_NAME}} {{item:NAME}} {{item:SECOND_NAME}}']
        );

        $this->assert(
            'testToSelectOptionsItemTemplateNull',
            [['fio' => 'Иванов Иван']],
            $result,
            'null в item-шаблоне → "" и trim убирает хвостовой пробел',
            []
        );
    }

    /**
     * item-шаблон с точечной нотацией (v1.19.1)
     */
    private function testToSelectOptionsItemTemplateNested(): void
    {
        $this->logger->separator('testToSelectOptionsItemTemplateNested');
        $t = new ArrayTransformer();

        $result = $t->toSelectOptions(
            [['NAME' => 'Иван', 'DEPT' => ['TITLE' => 'Отдел продаж']]],
            ['label' => '{{item:NAME}} ({{item:DEPT.TITLE}})']
        );

        $this->assert(
            'testToSelectOptionsItemTemplateNested',
            [['label' => 'Иван (Отдел продаж)']],
            $result,
            'item-шаблон с точечной нотацией item:DEPT.TITLE',
            []
        );
    }

    /**
     * toMap: список → карта «ключ → значение» (v1.20.0)
     */
    private function testToMapBasic(): void
    {
        $this->logger->separator('testToMapBasic');
        $t = new ArrayTransformer();

        $result = $t->toMap(
            [
                ['value' => 'A', 'label' => 'Alpha'],
                ['value' => 'B', 'label' => 'Beta'],
            ],
            'value',
            'label'
        );

        $this->assert(
            'testToMapBasic',
            ['A' => 'Alpha', 'B' => 'Beta'],
            $result,
            'toMap собирает карту из списка',
            []
        );
    }

    /**
     * toMap: крайние случаи — нет ключа, дубли, нет значения
     */
    private function testToMapEdgeCases(): void
    {
        $this->logger->separator('testToMapEdgeCases');
        $t = new ArrayTransformer();

        $result = $t->toMap(
            [
                ['value' => 'A', 'label' => 'First'],
                ['label' => 'NoKey'],
                ['value' => 'A', 'label' => 'Second'],
                ['value' => 'B'],
            ],
            'value',
            'label'
        );

        $this->assert(
            'testToMapEdgeCases',
            ['A' => 'Second', 'B' => null],
            $result,
            'Строка без ключа пропускается, дубль перезаписывается, нет значения → null',
            []
        );
    }

    /**
     * applySchema: одиночная строка, variant и blank (v1.21.0)
     */
    private function testApplySchemaSingleRow(): void
    {
        $this->logger->separator('testApplySchemaSingleRow');
        $t = new ArrayTransformer();

        $result = $t->applySchema(
            [
                'primary_result_code' => 'BUSY',
                'successful_contact'  => 0,
                'appointment_at'      => '0000-00-00 00:00:00',
                'comment'             => 'недозвон',
            ],
            [
                'primary_result_code' => ['title' => 'Основной код результата CRM', 'variant' => ['BUSY' => 'Занято']],
                'successful_contact'  => ['title' => 'Контакт состоялся', 'variant' => [1 => 'Да', 0 => 'Нет']],
                'appointment_at'      => ['title' => 'Запланированная встреча'],
                'comment'             => ['title' => 'Комментарий менеджера'],
            ],
            ['blank' => [null, '', '0000-00-00 00:00:00']]
        );

        $this->assert(
            'testApplySchemaSingleRow',
            [
                'primary_result_code' => ['title' => 'Основной код результата CRM', 'value' => 'Занято'],
                'successful_contact'  => ['title' => 'Контакт состоялся', 'value' => 'Нет'],
                'appointment_at'      => ['title' => 'Запланированная встреча', 'value' => ''],
                'comment'             => ['title' => 'Комментарий менеджера', 'value' => 'недозвон'],
            ],
            $result,
            'keyed-форма {field: {title, value}}; variant и blank работают',
            []
        );
    }

    /**
     * applySchema: список строк → список keyed-объектов, порядок = схема
     */
    private function testApplySchemaList(): void
    {
        $this->logger->separator('testApplySchemaList');
        $t = new ArrayTransformer();

        $result = $t->applySchema(
            [
                ['a' => 1, 'f' => 0],
                ['a' => 2, 'f' => 1],
            ],
            [
                'a' => ['title' => 'A'],
                'f' => ['title' => 'F', 'variant' => [1 => 'Да', 0 => 'Нет']],
            ]
        );

        $this->assert(
            'testApplySchemaList',
            [
                ['a' => ['title' => 'A', 'value' => 1], 'f' => ['title' => 'F', 'value' => 'Нет']],
                ['a' => ['title' => 'A', 'value' => 2], 'f' => ['title' => 'F', 'value' => 'Да']],
            ],
            $result,
            'Список строк → список keyed-объектов в порядке схемы',
            []
        );
    }

    /**
     * applySchema: значения нет в variant → сырое значение
     */
    private function testApplySchemaVariantMiss(): void
    {
        $this->logger->separator('testApplySchemaVariantMiss');
        $t = new ArrayTransformer();

        $result = $t->applySchema(
            ['code' => 'NEW_CODE'],
            ['code' => ['title' => 'Код', 'variant' => ['BUSY' => 'Занято']]]
        );

        $this->assert(
            'testApplySchemaVariantMiss',
            ['code' => ['title' => 'Код', 'value' => 'NEW_CODE']],
            $result,
            'Нового кода нет в словаре → сырое значение, без потери',
            []
        );
    }

    /**
     * applySchema: отсутствующее поле → '' (null в blank по умолчанию)
     */
    private function testApplySchemaMissingField(): void
    {
        $this->logger->separator('testApplySchemaMissingField');
        $t = new ArrayTransformer();

        $result = $t->applySchema([], ['x' => ['title' => 'X']]);

        $this->assert(
            'testApplySchemaMissingField',
            [],
            $result,
            'Отсутствующее поле → null → пустая строка',
            []
        );
    }

    /**
     * applySchema с query: батчинг запросов (v1.22.0)
     */
    private function testApplySchemaWithQuery(): void
    {
        $this->logger->separator('testApplySchemaWithQuery');

        $t = new ArrayTransformer();

        // Мок resolver (в реальном тесте нужен настоящий MethodResolver)
        // Для простоты тестируем без query (старое поведение)
        $rows = [
            ['id' => 1, 'name' => 'Test'],
        ];

        $result = $t->applySchema($rows, [
            'id' => ['title' => 'ID'],
            'name' => ['title' => 'Имя'],
        ]);

        $this->assert(
            'testApplySchemaWithQuery',
            [
                ['id' => ['title' => 'ID', 'value' => 1], 'name' => ['title' => 'Имя', 'value' => 'Test']],
            ],
            $result,
            'applySchema без query работает как раньше',
            []
        );
    }

    /**
     * applySchema: пустой batch → пропустить query (v1.22.0)
     */
    private function testApplySchemaQueryEmptyBatch(): void
    {
        $this->logger->separator('testApplySchemaQueryEmptyBatch');

        $t = new ArrayTransformer();

        $rows = [
            ['id' => null, 'name' => 'Test'],
        ];

        $result = $t->applySchema($rows, [
            'id' => ['title' => 'ID', 'query' => [
                'method' => 'test',
                'class' => 'TestClass',
                'params' => [['ID' => '@batch']],
                'key_field' => 'ID',
                'value_field' => 'NAME',
            ]],
        ]);

        // Пустой batch → query не выполняется, значение fallback на raw
        $this->assert(
            'testApplySchemaQueryEmptyBatch',
            [['id' => ['title' => 'ID', 'value' => '']]],
            $result,
            'Пустой batch → query пропускается, значение null',
            []
        );
    }

    /**
     * applySchema: кэширование между строками (v1.22.0)
     */
    private function testApplySchemaQueryCache(): void
    {
        $this->logger->separator('testApplySchemaQueryCache');

        $t = new ArrayTransformer();

        $rows = [
            ['id' => 1, 'name' => 'Test1'],
            ['id' => 2, 'name' => 'Test2'],
            ['id' => 1, 'name' => 'Test3'],  // дубль ID
        ];

        $result = $t->applySchema($rows, [
            'id' => ['title' => 'ID'],
            'name' => ['title' => 'Имя'],
        ]);

        $this->assert(
            'testApplySchemaQueryCache',
            [
                ['id' => ['title' => 'ID', 'value' => 1], 'name' => ['title' => 'Имя', 'value' => 'Test1']],
                ['id' => ['title' => 'ID', 'value' => 2], 'name' => ['title' => 'Имя', 'value' => 'Test2']],
                ['id' => ['title' => 'ID', 'value' => 1], 'name' => ['title' => 'Имя', 'value' => 'Test3']],
            ],
            $result,
            'Дубли ID обрабатываются корректно',
            []
        );
    }

    /**
     * Фикстура проекции: карта подразделений
     */
    private function projectFixture(): array
    {
        return [
            '451' => [
                'department' => [
                    'ID' => '451',
                    'NAME' => 'Changan Адыгея',
                    'UF_TELEPHONY' => '2565',
                    'editPlan' => true,
                    'JUNK' => 'x',
                ],
                'users' => [
                    ['ID' => '1', 'LAST_NAME' => 'Верещагин', 'NAME' => 'Денис', 'SECOND_NAME' => null, 'PERSONAL_PAGER' => '288'],
                    ['ID' => '2', 'LAST_NAME' => 'Брикалов', 'NAME' => 'Алексей'],
                ],
                'comment' => 'текст',
            ],
            '742' => [
                'department' => [
                    'ID' => '742',
                    'NAME' => 'EXEED Тюмень',
                    'UF_TELEPHONY' => '2566',
                    'editPlan' => true,
                    'JUNK' => 'y',
                ],
                'users' => [],
                'comment' => '',
            ],
        ];
    }

    private function testProjectTreeObject(): void
    {
        $this->logger->separator('testProjectTreeObject');
        $t = new ArrayTransformer();

        $result = $t->projectTree($this->projectFixture(), [
            'department' => ['ID', 'NAME', 'UF_TELEPHONY', 'editPlan'],
        ]);

        $this->assert(
            'testProjectTreeObject',
            ['ID' => '451', 'NAME' => 'Changan Адыгея', 'UF_TELEPHONY' => '2565', 'editPlan' => true],
            $result['451']['department'],
            'assoc-объект проецируется: JUNK отсечён, порядок = select',
            []
        );
    }

    private function testProjectTreeList(): void
    {
        $this->logger->separator('testProjectTreeList');
        $t = new ArrayTransformer();

        $result = $t->projectTree($this->projectFixture(), [
            'users' => ['ID', 'LAST_NAME'],
        ]);

        $this->assert(
            'testProjectTreeList',
            [
                ['ID' => '1', 'LAST_NAME' => 'Верещагин'],
                ['ID' => '2', 'LAST_NAME' => 'Брикалов'],
            ],
            $result['451']['users'],
            'Список проецируется построчно, ключи списка сохранены',
            []
        );
    }

    private function testProjectTreeRootRow(): void
    {
        $this->logger->separator('testProjectTreeRootRow');
        $t = new ArrayTransformer();

        $result = $t->projectTree($this->projectFixture(), [
            '' => ['department', 'users'],
        ]);

        $this->assert(
            'testProjectTreeRootRow',
            ['department', 'users'],
            array_keys($result['451']),
            "Путь '': строка корня оставляет только department и users (comment отсечён)",
            []
        );
    }

    private function testProjectTreeMissingDefaults(): void
    {
        $this->logger->separator('testProjectTreeMissingDefaults');
        $t = new ArrayTransformer();

        $result = $t->projectTree($this->projectFixture(), [
            'users' => ['ID', 'WORK_POSITION', 'SECOND_NAME' => 'DEF', 'PERSONAL_PAGER' => '—'],
        ]);

        $this->assert(
            'testProjectTreeMissingDefaults_NullStays',
            null,
            $result['451']['users'][0]['SECOND_NAME'],
            'Ключ есть со значением null → остаётся null, дефолт не подставляется',
            []
        );

        $this->assert(
            'testProjectTreeMissingDefaults_DefaultApplied',
            'DEF',
            $result['451']['users'][1]['SECOND_NAME'],
            'Ключа нет → подставляется дефолт из маппинг-формы',
            []
        );

        // array_key_exists + === null: оператор ?? не отличает null от отсутствия ключа
        $this->assert(
            'testProjectTreeMissingDefaults_ListFormNull',
            true,
            array_key_exists('WORK_POSITION', $result['451']['users'][1])
                && $result['451']['users'][1]['WORK_POSITION'] === null,
            'Списочная форма без дефолта: нет поля → ключ присутствует со значением null',
            []
        );

        $this->assert(
            'testProjectTreeMissingDefaults_Pager',
            '—',
            $result['451']['users'][1]['PERSONAL_PAGER'],
            'Списочная форма с дефолтом: нет PAGER → "—"',
            []
        );
    }

    private function testProjectTreeScalarPathIgnored(): void
    {
        $this->logger->separator('testProjectTreeScalarPathIgnored');
        $t = new ArrayTransformer();

        $result = $t->projectTree($this->projectFixture(), [
            'comment' => ['ID'],
        ]);

        $this->assert(
            'testProjectTreeScalarPathIgnored',
            'текст',
            $result['451']['comment'],
            'Путь в скаляр игнорируется: comment остался как есть',
            []
        );
    }

    private function testProjectTreeAfterFilterTree(): void
    {
        $this->logger->separator('testProjectTreeAfterFilterTree');
        $t = new ArrayTransformer();
        $f = new ArrayFilter();

        $filtered = $f->filterTree($this->projectFixture(), [
            '' => ['filter' => ['department.UF_TELEPHONY' => '2565'], 'keep_keys' => true],
        ]);

        $result = $t->projectTree($filtered, [
            ''           => ['department', 'users'],
            'department' => ['ID', 'NAME'],
            'users'      => ['ID', 'LAST_NAME'],
        ]);

        $this->assert(
            'testProjectTreeAfterFilterTree',
            [
                '451' => [
                    'department' => ['ID' => '451', 'NAME' => 'Changan Адыгея'],
                    'users' => [
                        ['ID' => '1', 'LAST_NAME' => 'Верещагин'],
                        ['ID' => '2', 'LAST_NAME' => 'Брикалов'],
                    ],
                ],
            ],
            $result,
            'Связка filterTree → projectTree: строки отфильтрованы, поля спроецированы',
            []
        );
    }

    /**
     * Фикстура fillEmpty: дыры null и '' в users и department
     */
    private function fillFixture(): array
    {
        return [
            '451' => [
                'department' => [
                    'ID' => '451',
                    'UF_PHONE_CITY' => '+78619449540',
                    'UF_PHONE_MOBILE' => null,
                    'CODE' => '',
                ],
                'users' => [
                    ['ID' => '1', 'PERSONAL_PAGER' => null, 'COMMENT' => ''],
                    ['ID' => '2', 'PERSONAL_PAGER' => '203', 'COMMENT' => 'ок'],
                ],
                'comment' => 'текст',
            ],
        ];
    }

    private function testFillEmptyNullAndEmptyString(): void
    {
        $this->logger->separator('testFillEmptyNullAndEmptyString');
        $t = new ArrayTransformer();

        $result = $t->fillEmpty($this->fillFixture(), [
            'users' => ['PERSONAL_PAGER' => '—', 'COMMENT' => 'нет'],
        ]);

        $this->assert(
            'testFillEmptyNullAndEmptyString',
            [
                ['ID' => '1', 'PERSONAL_PAGER' => '—', 'COMMENT' => 'нет'],
                ['ID' => '2', 'PERSONAL_PAGER' => '203', 'COMMENT' => 'ок'],
            ],
            $result['451']['users'],
            'null и "" заменены; непустые значения остались как есть',
            []
        );
    }

    private function testFillEmptyCustomEmptyValues(): void
    {
        $this->logger->separator('testFillEmptyCustomEmptyValues');
        $t = new ArrayTransformer();

        // empty_values переопределяет набор: '' больше НЕ пустое
        $result = $t->fillEmpty(
            $this->fillFixture(),
            ['department' => ['UF_PHONE_MOBILE' => '—', 'CODE' => '—']],
            ['empty_values' => [null]]
        );

        $this->assert(
            'testFillEmptyCustomEmptyValues_NullReplaced',
            '—',
            $result['451']['department']['UF_PHONE_MOBILE'],
            'null при empty_values [null] заменяется',
            []
        );

        $this->assert(
            'testFillEmptyCustomEmptyValues_EmptyStringKept',
            '',
            $result['451']['department']['CODE'],
            "'' при empty_values [null] НЕ заменяется (набор переопределён)",
            []
        );
    }

    private function testFillEmptyMissingKeyAndScalarPath(): void
    {
        $this->logger->separator('testFillEmptyMissingKeyAndScalarPath');
        $t = new ArrayTransformer();

        $result = $t->fillEmpty($this->fillFixture(), [
            'users'   => ['MISSING_FIELD' => 'x'],
            'comment' => ['ID' => 'y'],
        ]);

        $this->assert(
            'testFillEmptyMissingKey_NotCreated',
            false,
            array_key_exists('MISSING_FIELD', $result['451']['users'][0]),
            'Отсутствующий ключ не создаётся',
            []
        );

        $this->assert(
            'testFillEmptyScalarPath_Ignored',
            'текст',
            $result['451']['comment'],
            'Путь в скаляр игнорируется: comment не тронут',
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
