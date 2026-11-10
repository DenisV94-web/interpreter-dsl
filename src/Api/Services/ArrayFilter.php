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

    // ========================================================
    // FILTERTREE — МНОГОМЕРНАЯ ФИЛЬТРАЦИЯ (v1.23.0)
    // ========================================================

    /**
     * Фильтрует многомерную коллекцию по спецификации уровней (v1.23.0).
     * 
     * Спека — карта путь => опции уровня:
     * - '' (пустая строка) — корневая коллекция;
     * - 'users', 'users.tags' — точечный путь до дочерней коллекции
     *   от элемента корня.
     * 
     * Опции уровня:
     * - 'filter' — условия грамматикой Condition (logic AND/OR, вложенные
     *   группы, Bitrix-операторы в ключах); скоуп — элемент коллекции;
     *   '_key' — псевдополе: ключ элемента в его коллекции;
     * - 'keep_keys' — сохранить ключи отфильтрованной коллекции
     *   (дефолт false → reindex в список);
     * - 'drop_empty' — удалить владельца коллекции, ставшей пустой
     *   после фильтра (дефолт false); каскада между уровнями нет.
     * 
     * Уровни применяются по возрастанию глубины пути (корень первым);
     * порядок одноуровневых путей = порядок конфига.
     * Правая часть условий резолвится ДО вызова (resolveParams),
     * т.е. 'field:x' справа = значение из контекста.
     * Нет поля у элемента → условие false → строка исключается
     * (семантика ArrayFilter).
     * 
     * @param array $source Исходная коллекция (карта или список)
     * @param array $spec Спецификация уровней
     * @return array Отфильтрованная коллекция
     */
    public function filterTree(array $source, array $spec): array
    {
        $levels = [];
        foreach ($spec as $path => $level) {
            $path = (string) $path;
            $levels[] = [
                'segments' => $path === '' ? [] : explode('.', $path),
                'level'    => is_array($level) ? $level : [],
            ];
        }

        // Корень первым; PHP 8 usort стабилен — порядок равных глубин = порядок конфига
        usort($levels, static fn(array $a, array $b): int => count($a['segments']) <=> count($b['segments']));

        $result = $source;
        foreach ($levels as $entry) {
            if (empty($entry['segments'])) {
                $result = $this->treeFilterCollection($result, $entry['level']);
                continue;
            }
            $result = $this->treeFilterAtPath($result, $entry['segments'], $entry['level']);
        }

        return $result;
    }

    /**
     * Фильтрует одну коллекцию по условиям уровня.
     * 
     * @param array $items Коллекция строк
     * @param array $level Опции уровня
     * @return array Отфильтрованная коллекция
     */
    private function treeFilterCollection(array $items, array $level): array
    {
        $filter = $level['filter'] ?? [];
        $out = [];

        foreach ($items as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->treeMatchesGroup($row, $filter, $key)) {
                $out[$key] = $row;
            }
        }

        return ($level['keep_keys'] ?? false) ? $out : array_values($out);
    }

    /**
     * Спускается по пути и фильтрует дочерние коллекции внутри элементов.
     * 
     * @param array $collection Коллекция-владелец
     * @param array $segments Оставшиеся сегменты пути
     * @param array $level Опции уровня
     * @return array Коллекция-владелец после обработки
     */
    private function treeFilterAtPath(array $collection, array $segments, array $level): array
    {
        $head = array_shift($segments);
        $out = [];

        foreach ($collection as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            // Нет дочерней коллекции: drop_empty убирает владельца, иначе не трогаем
            if (!isset($item[$head]) || !is_array($item[$head])) {
                if (!empty($level['drop_empty'])) {
                    continue;
                }
                $out[$key] = $item;
                continue;
            }

            if (empty($segments)) {
                // Последний сегмент: фильтруем саму дочернюю коллекцию
                $filtered = $this->treeFilterCollection($item[$head], $level);
                if (count($filtered) === 0 && !empty($level['drop_empty'])) {
                    continue;
                }
                $item[$head] = $filtered;
                $out[$key] = $item;
                continue;
            }

            // Промежуточный сегмент: рекурсия вглубь
            $newSub = $this->treeFilterAtPath($item[$head], $segments, $level);
            if (count($newSub) === 0 && !empty($level['drop_empty'])) {
                continue;
            }
            $item[$head] = $newSub;
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Группа условий (грамматика Condition, скоуп — элемент):
     * 'logic' => 'AND'|'OR', строковые ключи — условия,
     * числовые ключи с массивами — вложенные группы.
     * 
     * @param array $row Элемент коллекции
     * @param array $group Группа условий
     * @param mixed $rowKey Ключ элемента в его коллекции (для '_key')
     * @return bool
     */
    private function treeMatchesGroup(array $row, array $group, $rowKey): bool
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
                $results[] = $this->treeMatchesGroup($row, $condition, $rowKey);
                continue;
            }

            if (is_string($key)) {
                $results[] = $this->treeMatchCondition($row, $key, $condition, $rowKey);
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
     * Одно условие: оператор-Bitrix в ключе + точечный путь или '_key'.
     * 
     * @param array $row Элемент
     * @param string $key Ключ условия с оператором
     * @param mixed $expected Ожидаемое значение (уже зарезолвлено)
     * @param mixed $rowKey Ключ элемента
     * @return bool
     */
    private function treeMatchCondition(array $row, string $key, $expected, $rowKey): bool
    {
        $operator = '=';
        $path = $key;

        // Двухсимвольные раньше однсимвольных; '!' после '!@' и '!='
        foreach (['!@', '<=', '>=', '!=', '@', '!', '<', '>', '%'] as $op) {
            if (strncmp($key, $op, strlen($op)) === 0) {
                $operator = $op;
                $path = substr($key, strlen($op));
                break;
            }
        }

        if ($path === '_key') {
            $actual = $rowKey;
        } else {
            [$found, $actual] = $this->treeGetValue($row, $path);
            if (!$found) {
                return false;   // нет поля → строка исключается
            }
        }

        switch ($operator) {
            case '@':
                return $this->treeIn($actual, $expected);
            case '!@':
                return !$this->treeIn($actual, $expected);
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
     * IN с учётом мульти-полей: если значение элемента — массив,
     * достаточно пересечения с одним элементом списка (как в Битрикс).
     * Сравнение нестрогое ('451' попадает в [451]).
     * 
     * @param mixed $actual Значение поля
     * @param mixed $expected Скаляр или список
     * @return bool
     */
    private function treeIn($actual, $expected): bool
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
     * Значение по точечному пути из элемента.
     * 
     * @param array $row Элемент
     * @param string $path Точечный путь
     * @return array [найдено, значение]
     */
    private function treeGetValue(array $row, string $path): array
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
