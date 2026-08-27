<?php

namespace Api\Services\Actions\Resolver;

use Api\Services\Actions\Context;

/**
 * Class Formula
 * 
 * Вычислитель выражений интерпретатора (v1.18.0).
 * Фаза 1: постфиксная арифметика на field:/result:-выражениях.
 * 
 * Поддерживаемые формы:
 * - field:x++      → значение + 1
 * - field:x--      → значение − 1
 * - field:x+2      → значение + 2 (пробелы допустимы: field:x + 2)
 * - field:x-5      → значение − 5
 * - field:price+0.5 → значение + 0.5
 * 
 * Постфикс применяется к результату ВСЕГО операнда:
 * цепочки |, скобки [] и result: поддерживаются.
 * 
 * Семантика:
 * - ++/-- = ±1 без побочных эффектов (вычисляет новое значение);
 * - операнд null → null + WARNING (строгая семантика DSL);
 * - нечисловой операнд → null + WARNING;
 * - числовые строки ("4") приводятся к числу;
 * - целый результат → int, дробный → float.
 * 
 * Грамматика живёт ТОЛЬКО здесь (Formula::isFormula) —
 * Field лишь спрашивает и делегирует.
 * 
 * @package Api\Services\Actions\Resolver
 */
class Formula
{
    /**
     * Постфикс-оператор в конце выражения:
     * ++ | -- | ±число (целое/дробное), пробел перед оператором допустим
     * 
     * @var string
     */
    private const POSTFIX_PATTERN = '/^(field:.+?|result(?::.+?)?)\s*(\+\+|--|[+-]\s*\d+(?:\.\d+)?)$/su';

    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей (для разрешения операнда)
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Formula constructor.
     * 
     * @param Context $context Контекст выполнения
     * @param Field $fieldResolver Резолвер полей
     */
    public function __construct(Context $context, Field $fieldResolver)
    {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
    }

    /**
     * Проверяет, является ли строка формулой (Фаза 1).
     * 
     * @param string $expression Строка
     * @return bool
     */
    public static function isFormula(string $expression): bool
    {
        return (bool) preg_match(self::POSTFIX_PATTERN, $expression);
    }

    /**
     * Вычисляет формулу: резолвит операнд и применяет постфикс-оператор.
     * 
     * @param string $expression Выражение вида field:x++ / field:x+2 / result:key--
     * @return mixed Результат (int/float) или null при ошибке
     */
    public function evaluate(string $expression)
    {
        if (!preg_match(self::POSTFIX_PATTERN, $expression, $m)) {
            $this->context->log('ERROR', 'Formula', 'Нераспознанная формула', [
                'expression' => $expression,
            ]);
            return null;
        }

        $operandExpression = $m[1];
        $operator = $m[2];

        $this->context->log('INFO', 'Formula', 'Вычисление формулы', [
            'expression' => $expression,
            'operand' => $operandExpression,
            'operator' => $operator,
        ]);

        // Операнд резолвится обычным резолвером (цепочки, скобки, result:)
        $operandValue = $this->fieldResolver->resolve($operandExpression);

        // Строгая семантика: null → null
        if ($operandValue === null) {
            $this->context->log('WARNING', 'Formula', 'Операнд формулы null — результат null', [
                'expression' => $expression,
                'operand' => $operandExpression,
            ]);
            return null;
        }

        // Нечисловой операнд → null
        if (!is_numeric($operandValue)) {
            $this->context->log('WARNING', 'Formula', 'Операнд формулы нечисловой — результат null', [
                'expression' => $expression,
                'operand_value' => $operandValue,
            ]);
            return null;
        }

        // Приведение к числу: "4" → int 4, "10.5" → float 10.5
        $base = $operandValue + 0;

        switch ($operator) {
            case '++':
                $result = $base + 1;
                break;

            case '--':
                $result = $base - 1;
                break;

            default:
                $sign = $operator[0] === '-' ? -1 : 1;
                $deltaRaw = str_replace(' ', '', substr($operator, 1));
                $delta = strpos($deltaRaw, '.') !== false
                    ? (float) $deltaRaw
                    : (int) $deltaRaw;
                $result = $base + $sign * $delta;
                break;
        }

        $this->context->log('SUCCESS', 'Formula', 'Формула вычислена', [
            'expression' => $expression,
            'base' => $base,
            'result' => $result,
        ]);

        return $result;
    }
}
