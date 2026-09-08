<?php

namespace Api\Services\Actions\Resolver;

use Api\Services\Actions\Context;

/**
 * Class Formula
 * 
 * Вычислитель выражений интерпретатора.
 * 
 * Фаза 1 (v1.18.0): постфиксная арифметика:
 * - field:x++ / field:x-- / field:x+2 / field:x - 5
 * 
 * Фаза 2 (v1.19.0): бинарная арифметика + - * / с приоритетами,
 * скобки, унарные +/-, рекурсивный спуск.
 * - явный префикс: formula:(field:a + field:b) * 2
 * - автодетект: field:price * 1.18, field:num - 5
 *   (только * / или " + "/" - " с пробелами; field:a-b остаётся путём)
 * 
 * Семантика ошибок: деление на ноль, null/нечисловой операнд,
 * синтаксическая ошибка → null + WARNING/ERROR в лог.
 * 
 * @package Api\Services\Actions\Resolver
 */
class Formula
{
    /**
     * Постфикс-оператор фазы 1 в конце выражения
     * 
     * @var string
     */
    private const POSTFIX_PATTERN = '/^(field:.+?|result(?::.+?)?)\s*(\+\+|--|[+-]\s*\d+(?:\.\d+)?)$/su';

    /**
     * Явный префикс формулы
     * 
     * @var string
     */
    private const PREFIX_FORMULA = 'formula:';

    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей (операнды)
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Состояние парсера (на время evaluate)
     * 
     * @var string
     */
    private string $src = '';

    /**
     * @var int
     */
    private int $pos = 0;

    /**
     * @var int
     */
    private int $len = 0;

    /**
     * @var bool
     */
    private bool $hasError = false;

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
     * Является ли строка формулой.
     * Грамматика живёт только здесь — Field лишь спрашивает и делегирует.
     * 
     * @param string $expression Строка
     * @return bool
     */
    public static function isFormula(string $expression): bool
    {
        // Явный префикс
        if (strncmp($expression, self::PREFIX_FORMULA, strlen(self::PREFIX_FORMULA)) === 0) {
            return true;
        }

        // Фаза 1: постфикс
        if (preg_match(self::POSTFIX_PATTERN, $expression)) {
            return true;
        }

        // Автодетект только для field:/result:
        if (!preg_match('/^(?:field:|result)/u', $expression)) {
            return false;
        }

        return self::hasTopLevelArithmetic($expression);
    }

    /**
     * Топ-уровневая арифметика для автодетекта:
     * * или / без пробелов; + / - только с пробелами с обеих сторон.
     * Скобки [...] и кавычки учитываются (не заходим внутрь).
     * 
     * @param string $s Строка
     * @return bool
     */
    private static function hasTopLevelArithmetic(string $s): bool
    {
        $len = strlen($s);
        $depth = 0;
        $inQuote = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($inQuote !== null) {
                if ($ch === $inQuote) {
                    $inQuote = null;
                }
                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $inQuote = $ch;
                continue;
            }

            if ($ch === '[') {
                $depth++;
                continue;
            }

            if ($ch === ']') {
                $depth--;
                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if ($ch === '*' || $ch === '/') {
                return true;
            }

            if (
                ($ch === '+' || $ch === '-')
                && $i > 0 && $s[$i - 1] === ' '
                && $i + 1 < $len && $s[$i + 1] === ' '
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Вычисляет формулу.
     * 
     * @param string $expression Выражение
     * @return mixed Результат (int/float) или null при ошибке
     */
    public function evaluate(string $expression)
    {
        $expr = $expression;

        if (strncmp($expr, self::PREFIX_FORMULA, strlen(self::PREFIX_FORMULA)) === 0) {
            $expr = substr($expr, strlen(self::PREFIX_FORMULA));
        }

        $expr = trim($expr);

        // Фаза 1: постфикс ++/--/+N/-N
        if (preg_match(self::POSTFIX_PATTERN, $expr, $m)) {
            return $this->evaluatePostfix($expr, $m);
        }

        // Фаза 2: бинарная арифметика
        return $this->evaluateBinary($expr);
    }

    // ========================================================
    // ФАЗА 1: ПОСТФИКС
    // ========================================================

    /**
     * Постфикс-оператор на резолвнутом операнде
     * 
     * @param string $expression Выражение
     * @param array $m Совпадения POSTFIX_PATTERN
     * @return mixed
     */
    private function evaluatePostfix(string $expression, array $m)
    {
        $operandExpression = $m[1];
        $operator = $m[2];

        $this->context->log('INFO', 'Formula', 'Вычисление формулы (постфикс)', [
            'expression' => $expression,
            'operand' => $operandExpression,
            'operator' => $operator,
        ]);

        $base = $this->resolveOperand($operandExpression, true);

        if ($base === null) {
            return null;
        }

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
                $delta = strpos($deltaRaw, '.') !== false ? (float) $deltaRaw : (int) $deltaRaw;
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

    // ========================================================
    // ФАЗА 2: БИНАРНАЯ АРИФМЕТИКА (рекурсивный спуск)
    // ========================================================

    /**
     * Парсинг и вычисление бинарного выражения
     * 
     * @param string $expr Выражение
     * @return mixed
     */
    private function evaluateBinary(string $expr)
    {
        $this->src = $expr;
        $this->pos = 0;
        $this->len = strlen($expr);
        $this->hasError = false;

        $value = $this->parseExpression();

        $this->skipWs();

        if ($this->pos < $this->len) {
            $this->fail('Лишний символ в формуле', ['at' => substr($this->src, $this->pos)]);
            return null;
        }

        if ($this->hasError) {
            return null;
        }

        $this->context->log('SUCCESS', 'Formula', 'Формула вычислена', [
            'expression' => $expr,
            'result' => $value,
        ]);

        return $value;
    }

    /**
     * expression := term (('+'|'-') term)*
     * 
     * @return mixed
     */
    private function parseExpression()
    {
        $value = $this->parseTerm();

        while (!$this->hasError) {
            $this->skipWs();
            $op = $this->peekChar();

            if ($op !== '+' && $op !== '-') {
                break;
            }

            $this->pos++;
            $value = $this->applyOp($op, $value, $this->parseTerm());
        }

        return $value;
    }

    /**
     * term := factor (('*'|'/') factor)*
     * 
     * @return mixed
     */
    private function parseTerm()
    {
        $value = $this->parseFactor();

        while (!$this->hasError) {
            $this->skipWs();
            $op = $this->peekChar();

            if ($op !== '*' && $op !== '/') {
                break;
            }

            $this->pos++;
            $value = $this->applyOp($op, $value, $this->parseFactor());
        }

        return $value;
    }

    /**
     * factor := ('+'|'-') factor | '(' expression ')' | primary
     * 
     * @return mixed
     */
    private function parseFactor()
    {
        $this->skipWs();
        $ch = $this->peekChar();

        if ($ch === null) {
            $this->fail('Неожиданный конец формулы');
            return null;
        }

        // Унарные +/-
        if ($ch === '+' || $ch === '-') {
            $this->pos++;
            $value = $this->parseFactor();

            if ($value === null) {
                return null;
            }

            return $ch === '-' ? -$value : $value;
        }

        // Скобки
        if ($ch === '(') {
            $this->pos++;
            $value = $this->parseExpression();
            $this->skipWs();

            if ($this->peekChar() !== ')') {
                $this->fail('Непарная скобка');
                return null;
            }

            $this->pos++;
            return $value;
        }

        return $this->parsePrimary();
    }

    /**
     * primary := число | field:/result:-операнд
     * 
     * @return mixed
     */
    private function parsePrimary()
    {
        $this->skipWs();

        // Число
        if (preg_match('/\G\d+(?:\.\d+)?/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $raw = $m[0];
            return strpos($raw, '.') !== false ? (float) $raw : (int) $raw;
        }

        // Операнд field:... / result...
        $rest = substr($this->src, $this->pos);

        if (strncmp($rest, 'field:', 6) === 0 || strncmp($rest, 'result', 6) === 0) {
            $ref = $this->consumeOperand();
            return $this->resolveOperand($ref, false);
        }

        $this->fail('Неожиданный токен', ['at' => $rest]);
        return null;
    }

    /**
     * Съедает field:/result:-операнд до топ-уровневого оператора/скобки.
     * [...] и кавычки учитываются; | внутри операнда разрешён (цепочки).
     * 
     * @return string
     */
    private function consumeOperand(): string
    {
        $start = $this->pos;
        $depth = 0;
        $inQuote = null;

        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];

            if ($inQuote !== null) {
                if ($ch === $inQuote) {
                    $inQuote = null;
                }
                $this->pos++;
                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $inQuote = $ch;
                $this->pos++;
                continue;
            }

            if ($ch === '[') {
                $depth++;
                $this->pos++;
                continue;
            }

            if ($ch === ']') {
                $depth--;
                $this->pos++;
                continue;
            }

            if ($depth === 0 && ($ch === '+' || $ch === '-' || $ch === '*' || $ch === '/' || $ch === ')')) {
                break;
            }

            $this->pos++;
        }

        return trim(substr($this->src, $start, $this->pos - $start));
    }

    /**
     * Резолвит операнд и приводит к числу.
     * 
     * @param string $ref Выражение операнда
     * @param bool $warnLevel true — WARNING (постфикс), false — WARNING (бинарный)
     * @return int|float|null
     */
    private function resolveOperand(string $ref, bool $warnLevel)
    {
        $value = $this->fieldResolver->resolve($ref);

        if ($value === null) {
            $this->context->log('WARNING', 'Formula', 'Операнд формулы null — результат null', [
                'operand' => $ref,
            ]);
            $this->hasError = true;
            return null;
        }

        if (!is_numeric($value)) {
            $this->context->log('WARNING', 'Formula', 'Операнд формулы нечисловой — результат null', [
                'operand' => $ref,
                'value' => $value,
            ]);
            $this->hasError = true;
            return null;
        }

        return $value + 0;
    }

    /**
     * Применяет бинарный оператор. Деление на ноль → null.
     * 
     * @param string $op Оператор
     * @param mixed $a Левый операнд
     * @param mixed $b Правый операнд
     * @return mixed
     */
    private function applyOp(string $op, $a, $b)
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($op === '/') {
            if ($b == 0) {
                $this->fail('Деление на ноль', [], 'WARNING');
                return null;
            }
            return $a / $b;
        }

        switch ($op) {
            case '+':
                return $a + $b;
            case '-':
                return $a - $b;
            case '*':
                return $a * $b;
        }

        return null;
    }

    // ========================================================
    // УТИЛИТЫ ПАРСЕРА
    // ========================================================

    /**
     * @return void
     */
    private function skipWs(): void
    {
        while ($this->pos < $this->len && ($this->src[$this->pos] === ' ' || $this->src[$this->pos] === "\t")) {
            $this->pos++;
        }
    }

    /**
     * @return string|null
     */
    private function peekChar(): ?string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : null;
    }

    /**
     * Ошибка парсинга/вычисления (логируется один раз)
     * 
     * @param string $message Сообщение
     * @param array $data Данные
     * @param string $level Уровень лога
     * @return void
     */
    private function fail(string $message, array $data = [], string $level = 'ERROR'): void
    {
        if (!$this->hasError) {
            $this->context->log($level, 'Formula', $message, $data + ['expression' => $this->src]);
        }

        $this->hasError = true;
    }
}
