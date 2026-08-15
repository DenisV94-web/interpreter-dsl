<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Executor\Compose;

/**
 * Class ComposeTest
 * 
 * Тесты для класса Compose (Executor).
 * 
 * Compose Executor — финальная сборка ответа из готовых данных контекста.
 * Декларативно описывает ЛЮБУЮ итоговую структуру:
 * - Вложенные объекты (рекурсивный обход)
 * - Списки (через field: на замаппленные источники)
 * - Шаблоны {{ }} и литералы
 * 
 * Результат записывается:
 * - В context.response (становится ответом клиенту)
 * - В context.compose (доступен через field:compose.xxx)
 * 
 * Лог: Compose_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 2
 * 
 * @package Api\Services\Actions\Testing
 */
class ComposeTest
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
     * ComposeTest constructor.
     * 
     * Создаёт логгер с префиксом 'Compose' — все записи идут
     * в файл Compose_YYYY-MM-DD_HH-II-SS.log
     */
    public function __construct()
    {
        $this->logger = new TestLogger('Compose');
    }

    /**
     * Запускает все тесты Compose Executor
     * 
     * Порядок:
     * - Простая сборка (1)
     * - Вложенная сборка со списками (2)
     * 
     * @return void
     */
    public function runAll(): void
    {
        $this->logger->info('ComposeTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Compose ===');

        $this->testSimpleCompose();
        $this->testNestedComposeWithLists();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "COMPOSE TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }
    
    // ========================================================
    // ПРОСТАЯ СБОРКА (тест 1)
    // ========================================================

    /**
     * Тест 1: Простая сборка с field-ссылками и литералами
     * 
     * Контекст: current_user (ID, UF_DEPARTMENT), business_lines.
     * Конфиг: user.id = field:current_user.ID,
     * user.department = field:current_user.UF_DEPARTMENT,
     * business_lines = field:business_lines, static_text = литерал.
     * Ожидание:
     * - структура собирается корректно (вложенный объект user)
     * - response записывается в context.response
     * 
     * @return void
     */
    private function testSimpleCompose(): void
    {
        $this->logger->separator('testSimpleCompose');

        $context = new Context([
            'current_user' => ['ID' => 42, 'UF_DEPARTMENT' => [4]],
            'business_lines' => ['NEW_CAR' => 'Новый автомобиль']
        ]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $compose = new Compose($context, $field);

        $config = [
            'user' => [
                'id' => 'field:current_user.ID',
                'department' => 'field:current_user.UF_DEPARTMENT'
            ],
            'business_lines' => 'field:business_lines',
            'static_text' => 'literal value'
        ];

        $expected = [
            'user' => ['id' => 42, 'department' => [4]],
            'business_lines' => ['NEW_CAR' => 'Новый автомобиль'],
            'static_text' => 'literal value'
        ];

        $result = $compose->execute($config);

        $this->assert(
            'testSimple_Result',
            $expected,
            $result,
            'Структура должна собраться (вложенность + field + литерал)',
            ['config' => $config]
        );

        $this->assert(
            'testSimple_Response',
            $expected,
            $context->response,
            'Response должен равняться результату сборки',
            ['check' => 'context.response']
        );
    }
    
    // ========================================================
    // ВЛОЖЕННАЯ СБОРКА СО СПИСКАМИ (тест 2)
    // ========================================================

    /**
     * Тест 2: Вложенная сборка со списками (tabs-кейс get_dashboard_data)
     * 
     * Контекст: mapped_tasks_new (список), brand (список).
     * Конфиг: brand = field:brand,
     * tabs.nav-new-task = {tab-name: литерал, tasks: field:mapped_tasks_new}.
     * Ожидание: вложенная структура tabs собирается,
     * списки подставляются целиком через field:.
     * 
     * @return void
     */
    private function testNestedComposeWithLists(): void
    {
        $this->logger->separator('testNestedComposeWithLists');

        $context = new Context([
            'mapped_tasks_new' => [
                ['id' => 1, 'client_name' => 'Иванов Иван'],
                ['id' => 2, 'client_name' => 'Петров Пётр']
            ],
            'brand' => [['NAME' => 'Tenet']]
        ]);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $compose = new Compose($context, $field);

        $config = [
            'brand' => 'field:brand',
            'tabs' => [
                'nav-new-task' => [
                    'tab-name' => 'nav-new-task',
                    'tasks' => 'field:mapped_tasks_new'
                ]
            ]
        ];

        $expected = [
            'brand' => [['NAME' => 'Tenet']],
            'tabs' => [
                'nav-new-task' => [
                    'tab-name' => 'nav-new-task',
                    'tasks' => [
                        ['id' => 1, 'client_name' => 'Иванов Иван'],
                        ['id' => 2, 'client_name' => 'Петров Пётр']
                    ]
                ]
            ]
        ];

        $this->assert(
            'testNested_Result',
            $expected,
            $compose->execute($config),
            'Tabs должны собраться со списками (field: подставляет массив целиком)',
            ['config' => 'tabs с вложенными списками']
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
            $this->logger->success('ComposeTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('ComposeTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
