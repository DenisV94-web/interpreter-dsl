<?php

namespace Api\Services;

/**
 * Class TreeConditionMatcher
 * 
 * Общий оценатор условий над строками дерева (v1.27.0).
 * Вынесен из ArrayFilter, чтобы Condition-грамматику переиспользовали
 * и filterTree (фильтрация), и patchTree (условный патчинг).
 * 
 * Грамматика where (скоуп — строка коллекции, не контекст):
 * - 'logic' => 'AND'|'OR' + вложенные группы (числовые ключи с массивами);
 * - операторы Битрикс в ключах: =, !=, !, <, <=, >, >=, % (LIKE),
 *   @ (IN), !@ (NOT IN); точечные пути по строке;
 * - '_key' — ключ строки в её коллекции;
 * - func:-проверки из whitelist в правой части:
 *   empty, blank, is_null, is_numeric, is_array, is_string;
 *   неизвестный func: → условие false;
 * - отсутствующее поле: для func:-проверок = null (семантика Condition),
 *   для литеральных/операторных условий → строка исключается
 *   (поведение filterTree 1.23.0 сохранено).
 * 
 * @package Api\Services
 */
class TreeConditionMatcher
{
    /**
     * Группа условий: logic + условия + вложенные группы.
     * 
     * @param array $row Строка коллекции
     * @param array $group Группа условий
     * @param mixed $rowKey Ключ строки в её коллекции (для '_key')
     * @return bool
     */
    public static function matchGroup(array $row, array $group, $rowKey): bool
    {
        if (empty($group)) {
            return true;
        }

        $logic = (isset($group['logic']) && strtoupper((string) $group['logic']) === 'OR')
            ? 'OR'
            : 'AND';

        $results = [];
        foreach ($group as $key => $condition) {
            if ($key === 'logic') {
                continue;
            }

            if (is_int($key) && is_array($condition)) {
                $results[] = self::matchGroup($row, $condition, $rowKey);
                continue;
            }

            if (is_string($key)) {
                $results[] = self::matchCondition($row, $key, $condition, $rowKey);
                continue;
            }

            // Мусор в конфиге (числовой ключ, не массив) — не влияем
            $results[] = true;
        }

        if (empty($results)) {
            return true;
        }

        return $logic === 'OR'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * Одно условие: оператор в ключе + путь/'_key' слева,
     * литерал/func:-проверка справа.
     * 
     * @param array $row Строка коллекции
     * @param string $key Ключ условия с оператором
     * @param mixed $expected Ожидаемое значение или 'func:...'
     * @param mixed $rowKey Ключ строки
     * @return bool
     */
    public static function matchCondition(array $row, string $key, $expected, $rowKey): bool
    {
        $operator = '=';
        $path = $key;

        // Двухсимвольные раньше односимвольных; '!' после '!@' и '!='
        foreach (['!@', '<=', '>=', '!=', '@', '!', '<', '>', '%'] as $op) {
            if (strncmp($key, $op, strlen($op)) === 0) {
                $operator = $op;
                $path = substr($key, strlen($op));
                break;
            }
        }

        $isFunc = is_string($expected) && strncmp($expected, 'func:', 5) === 0;

        if ($path === '_key') {
            $actual = $rowKey;
        } else {
            [$found, $actual] = self::getValue($row, $path);

            if (!$found && !$isFunc) {
                return false;   // нет поля → строка исключается (семантика filterTree)
            }
            if (!$found) {
                $actual = null; // для func:-проверок отсутствующее поле = null
            }
        }

        // func:-проверка из whitelist
        if ($isFunc) {
            $checkResult = self::runFuncCheck(substr($expected, 5), $actual);

            if ($checkResult === null) {
                return false;   // неизвестный func: → false
            }

            return ($operator === '!' || $operator === '!=')
                ? !$checkResult
                : $checkResult;
        }

        switch ($operator) {
            case '@':
                return self::inCheck($actual, $expected);
            case '!@':
                return !self::inCheck($actual, $expected);
            case '%':
                return is_scalar($actual) && is_scalar($expected)
                    && mb_stripos((string) $actual, (string) $expected) !== false;
            case '<=':
                return $actual <= $expected;
            case '>=':
                return $actual >= $expected;
            case '<':
                return $actual < $expected;
            case '>':
                return $actual > $expected;
            case '!=':
            case '!':
                return $actual != $expected;
            default:
                return $actual == $expected;
        }
    }

    /**
     * Whitelist func:-проверок (одна строка кода на новую проверку).
     * Неизвестная проверка → null (вызывающий вернёт false).
     * 
     * @param string $check Имя проверки без префикса
     * @param mixed $value Значение поля
     * @return bool|null
     */
    private static function runFuncCheck(string $check, $value): ?bool
    {
        switch ($check) {
            case 'empty':
                return empty($value);
            case 'blank':
                return $value === null || $value === '';
            case 'is_null':
                return $value === null;
            case 'is_numeric':
                return is_numeric($value);
            case 'is_array':
                return is_array($value);
            case 'is_string':
                return is_string($value);
            default:
                return null;
        }
    }

    /**
     * IN с учётом мульти-полей: если значение строки — массив,
     * достаточно пересечения с одним элементом списка (как в Битрикс).
     * Сравнение нестрогое ('451' попадает в [451]).
     * 
     * @param mixed $actual Значение поля
     * @param mixed $expected Скаляр или список
     * @return bool
     */
    public static function inCheck($actual, $expected): bool
    {
        $expectedList = is_array($expected) ? $expected : [$expected];

        if (is_array($actual)) {
            foreach ($actual as $value) {
                if (in_array($value, $expectedList, false)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($actual, $expectedList, false);
    }

    /**
     * Значение по точечному пути из строки.
     * 
     * @param array $row Строка
     * @param string $path Точечный путь
     * @return array [найдено, значение]
     */
    public static function getValue(array $row, string $path): array
    {
        $current = $row;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }
        return [true, $current];
    }
}
