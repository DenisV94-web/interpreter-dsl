<?php

namespace Api\Services\Actions\Testing;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Formula;

/**
 * Class FormulaTest
 * 
 * Тесты вычислителя Formula (v1.18.0).
 * Постфиксная арифметика на field:/result:-выражениях.
 * 
 * Лог: Formula_YYYY-MM-DD_HH-II-SS.log
 * 
 * Всего тестов: 11
 * 
 * @package Api\Services\Actions\Testing
 */
class FormulaTest
{
    private TestLogger $logger;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->logger = new TestLogger('Formula');
    }

    public function runAll(): void
    {
        $this->logger->info('FormulaTest', 'runAll', '=== ЗАПУСК ТЕСТОВ Formula ===');

        $this->testIncrement();
        $this->testDecrement();
        $this->testPlusN();
        $this->testMinusNWithSpace();
        $this->testFloatDelta();
        $this->testNullOperand();
        $this->testNonNumericOperand();
        $this->testChainOperand();
        $this->testBracketOperand();
        $this->testPlainFieldNoRegression();
        $this->testLiteralEscape();

        $this->logger->summary($this->passed, $this->failed);

        echo "\n";
        echo "========================================\n";
        echo "FORMULA TESTS COMPLETED\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Log file: {$this->logger->getLogFile()}\n";
        echo "========================================\n";
    }

    /**
     * Собирает Field с инжектнутой Formula (как в бою)
     */
    private function makeResolver(array $data): Field
    {
        $context = new Context($data);
        $context->setTestLogger($this->logger);
        $field = new Field($context);
        $field->setFormulaResolver(new Formula($context, $field));
        return $field;
    }

    private function testIncrement(): void
    {
        $this->logger->separator('testIncrement');
        $resolver = $this->makeResolver(['lead' => ['UF_CALL_COUNT' => '4']]);

        $this->assert(
            'testIncrement',
            5,
            $resolver->resolve('field:lead.UF_CALL_COUNT++'),
            'field:lead.UF_CALL_COUNT++ → 5 (int из строки "4")',
            []
        );
    }

    private function testDecrement(): void
    {
        $this->logger->separator('testDecrement');
        $resolver = $this->makeResolver(['n' => 10]);

        $this->assert(
            'testDecrement',
            9,
            $resolver->resolve('field:n--'),
            'field:n-- → 9',
            []
        );
    }

    private function testPlusN(): void
    {
        $this->logger->separator('testPlusN');
        $resolver = $this->makeResolver(['num' => 4]);

        $this->assert(
            'testPlusN',
            6,
            $resolver->resolve('field:num+2'),
            'field:num+2 → 6',
            []
        );
    }

    private function testMinusNWithSpace(): void
    {
        $this->logger->separator('testMinusNWithSpace');
        $resolver = $this->makeResolver(['num' => 10]);

        $this->assert(
            'testMinusNWithSpace',
            5,
            $resolver->resolve('field:num - 5'),
            'field:num - 5 → 5 (пробелы допустимы)',
            []
        );
    }

    private function testFloatDelta(): void
    {
        $this->logger->separator('testFloatDelta');
        $resolver = $this->makeResolver(['price' => '10.5']);

        $this->assert(
            'testFloatDelta',
            11.0,
            $resolver->resolve('field:price+0.5'),
            'field:price+0.5 → 11.0 (float)',
            []
        );
    }

    private function testNullOperand(): void
    {
        $this->logger->separator('testNullOperand');
        $resolver = $this->makeResolver([]);

        $this->assert(
            'testNullOperand',
            null,
            $resolver->resolve('field:missing++'),
            'null-операнд → null (строгая семантика)',
            []
        );
    }

    private function testNonNumericOperand(): void
    {
        $this->logger->separator('testNonNumericOperand');
        $resolver = $this->makeResolver(['x' => 'abc']);

        $this->assert(
            'testNonNumericOperand',
            null,
            $resolver->resolve('field:x++'),
            'Нечисловой операнд → null',
            []
        );
    }

    private function testChainOperand(): void
    {
        $this->logger->separator('testChainOperand');
        $resolver = $this->makeResolver(['a' => null, 'b' => 7]);

        $this->assert(
            'testChainOperand',
            8,
            $resolver->resolve('field:a|field:b++'),
            'Постфикс применяется к результату всей цепочки',
            []
        );
    }

    private function testBracketOperand(): void
    {
        $this->logger->separator('testBracketOperand');
        $resolver = $this->makeResolver(['arr' => ['k' => 3], 'key' => 'k']);

        $this->assert(
            'testBracketOperand',
            4,
            $resolver->resolve('field:arr[field:key]++'),
            'Постфикс после динамического ключа работает',
            []
        );
    }

    private function testPlainFieldNoRegression(): void
    {
        $this->logger->separator('testPlainFieldNoRegression');
        $resolver = $this->makeResolver(['x' => 5]);

        $this->assert(
            'testPlainFieldNoRegression',
            5,
            $resolver->resolve('field:x'),
            'Обычное поле без постфикса не тронуто',
            []
        );
    }

    private function testLiteralEscape(): void
    {
        $this->logger->separator('testLiteralEscape');
        $resolver = $this->makeResolver(['x' => 5]);

        $this->assert(
            'testLiteralEscape',
            'field:x++',
            $resolver->resolve('literal:field:x++'),
            'literal:field:x++ → строка как есть',
            []
        );
    }

    private function assert(string $testName, $expected, $actual, string $message, array $contextData = []): void
    {
        if ($expected === $actual) {
            $this->passed++;
            $this->logger->success('FormulaTest', $testName, "✓ PASSED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        } else {
            $this->failed++;
            $this->logger->error('FormulaTest', $testName, "✗ FAILED: {$message}", [
                'expected' => $expected,
                'actual' => $actual,
                'context' => $contextData
            ]);
        }
    }
}
