<?php

namespace Api\Services;

/**
 * Class ArrayFilter
 * 
 * Фильтрация массива объектов по фильтру в стиле Битрикс.
 * 
 * Поддерживаемые операторы:
 * - '=' или отсутствие префикса — точное совпадение
 * - '!' или '!=' — не равно
 * - '>' — больше
 * - '<' — меньше
 * - '>=' — больше или равно
 * - '<=' — меньше или равно
 * - '%' — LIKE (подстрока, регистронезависимо)
 * - '@' — IN (значение в массиве)
 * - '!@' — NOT IN
 * 
 * Пример использования в DSL:
 * ```php
 * 'filtered_items' => [
 *     'method' => 'filter',
 *     'class' => \Api\Services\ArrayFilter::class,
 *     'params' => [
 *         'field:items',
 *         [
 *             '!UF_CHECK_DEPARTMENT' => 1,
 *             'UF_REASON_REFUSAL' => 0,
 *         ],
 *     ],
 * ],
 * ```
 * 
 * @package Api\Services
 */
class ArrayFilter
{
    /**
     * Фильтрует массив объектов по фильтру в стиле Битрикс
     * 
     * @param array $items Массив объектов (ассоциативных массивов)
     * @param array $filter Фильтр: ['поле' => значение, '!поле' => значение, ...]
     * @return array Отфильтрованный массив
     */
    public function filter(array $items, array $filter): array
    {
        if (empty($filter)) {
            return $items;
        }

        $result = [];

        foreach ($items as $item) {
            if ($this->matchesFilter($item, $filter)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Проверяет, соответствует ли объект фильтру
     * 
     * @param array $item Объект
     * @param array $filter Фильтр
     * @return bool
     */
    private function matchesFilter(array $item, array $filter): bool
    {
        foreach ($filter as $key => $expectedValue) {
            [$operator, $field] = $this->parseOperator($key);

            if (!array_key_exists($field, $item)) {
                return false;
            }

            $actualValue = $item[$field];

            if (!$this->matchesCondition($actualValue, $operator, $expectedValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Парсит оператор из ключа фильтра
     * 
     * @param string $key Ключ фильтра (например, '!UF_CHECK_DEPARTMENT')
     * @return array [$operator, $field]
     */
    private function parseOperator(string $key): array
    {
        // Порядок важен: сначала длинные операторы, потом короткие
        $operators = ['!@', '!=', '>=', '<=', '>', '<', '%', '@', '!'];

        foreach ($operators as $op) {
            if (strpos($key, $op) === 0) {
                return [$op, substr($key, strlen($op))];
            }
        }

        // Нет оператора — точное совпадение
        return ['=', $key];
    }

    /**
     * Проверяет условие
     * 
     * @param mixed $actual Фактическое значение
     * @param string $operator Оператор
     * @param mixed $expected Ожидаемое значение
     * @return bool
     */
    private function matchesCondition($actual, string $operator, $expected): bool
    {
        switch ($operator) {
            case '=':
                return $actual == $expected;

            case '!':
            case '!=':
                return $actual != $expected;

            case '>':
                return $actual > $expected;

            case '<':
                return $actual < $expected;

            case '>=':
                return $actual >= $expected;

            case '<=':
                return $actual <= $expected;

            case '%':
                // LIKE: подстрока (регистронезависимо)
                return stripos((string) $actual, (string) $expected) !== false;

            case '@':
                // IN: значение в массиве
                if (!is_array($expected)) {
                    return false;
                }
                return in_array($actual, $expected, false);

            case '!@':
                // NOT IN: значение НЕ в массиве
                if (!is_array($expected)) {
                    return true;
                }
                return !in_array($actual, $expected, false);

            default:
                return false;
        }
    }
}
