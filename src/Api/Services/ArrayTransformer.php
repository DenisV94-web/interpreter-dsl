<?php

namespace Api\Services;

/**
 * Class ArrayTransformer
 * 
 * Трансформация списков объектов: перегруппировка, переименование полей,
 * декодирование JSON-строк внутри полей.
 * 
 * @package Api\Services
 */
class ArrayTransformer
{
    /**
     * Превращает список объектов в список опций для select/select2.
     * 
     * Формат маппинга:
     * - строка 'ИМЯ_ПОЛЯ' — копия поля
     * - массив ['fn' => 'func' | ['Class','method'], 'args' => [...]] —
     *   применить функцию/метод. В args доступно 'item:X' = $item[X].
     * 
     * @param array $items Исходный список
     * @param array $mapping Маппинг выходных полей
     * @return array
     */
    public function toSelectOptions(array $items, array $mapping): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($mapping as $outKey => $config) {
                $row[$outKey] = $this->applyMappingRule($config, $item);
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Собирает карту «ключ → значение» из списка (v1.20.0).
     * 
     * Декларативный аналог JS:
     * let map = {}; list.forEach(v => { map[v[keyField]] = v[valueField]; });
     * 
     * - строка без поля-ключа → пропускается;
     * - дубли ключа → побеждает последний (как присваивание в JS);
     * - нет поля-значения → null под этим ключом.
     * 
     * @param array $items Исходный список
     * @param string $keyField Поле-ключ
     * @param string $valueField Поле-значение
     * @return array Карта
     */
    public function toMap(array $items, string $keyField, string $valueField): array
    {
        $map = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = $item[$keyField] ?? null;

            if ($key === null) {
                continue;
            }

            $map[$key] = $item[$valueField] ?? null;
        }

        return $map;
    }

    /**
     * Применяет одно правило маппинга к строке.
     * 
     * @param mixed $config Правило: строка или массив с fn/args
     * @param array $item Текущая строка
     * @return mixed Значение для выходного поля
     */
    private function applyMappingRule($config, array $item)
    {
        // Строка — прямой доступ к полю
        if (is_string($config)) {
            // v1.18.2: шаблон с {{item:X}} — резолвим как текст с плейсхолдерами
            if (strpos($config, '{{item:') !== false) {
                return $this->resolveItemTemplate($config, $item);
            }

            // Обычная строка — прямой доступ к полю
            return $item[$config] ?? null;
        }

        // Массив с fn + args — трансформация через функцию/метод
        if (is_array($config) && isset($config['fn'])) {
            $resolvedArgs = $this->resolveArgsForItem($config['args'] ?? [], $item);

            try {
                return call_user_func($config['fn'], ...$resolvedArgs);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Неизвестная форма — null
        return null;
    }

    /**
     * Резолвит аргументы для одной итерации.
     * 
     * - 'item:X' → $item[X]
     * - массив с fn+args → рекурсивно (для вложенных трансформаций)
     * - остальное — как есть (литералы)
     * 
     * @param array $args Массив аргументов
     * @param array $item Текущая строка
     * @return array Разрешённые аргументы
     */
    private function resolveArgsForItem(array $args, array $item): array
    {
        $resolved = [];
        foreach ($args as $arg) {
            // item:X — доступ к полю текущей строки (v1.11.0)
            // Не путать с result:X — это DSL-семантика "из lastResult"
            if (is_string($arg) && strpos($arg, 'item:') === 0) {
                $key = substr($arg, 5);
                $resolved[] = $item[$key] ?? null;
                continue;
            }

            if (is_array($arg) && isset($arg['fn'])) {
                $resolved[] = $this->applyMappingRule($arg, $item);
                continue;
            }

            $resolved[] = $arg;
        }
        return $resolved;
    }

    /**
     * Резолвит строковый шаблон с {{item:X}} по текущей строке (v1.18.2).
     * 
     * Семантика:
     * - {{item:PATH}} → значение по пути (поддержка точечной нотации item:A.B);
     * - null → '' (Twig-семантика);
     * - итог trim'ится, чтобы убрать хвостовые пробелы от null-полей
     *   (например 'LAST_NAME NAME ' без отчества → 'LAST_NAME NAME').
     * 
     * @param string $template Шаблон с плейсхолдерами
     * @param array $item Текущая строка
     * @return string Разрешённая и обрезанная строка
     */
    private function resolveItemTemplate(string $template, array $item): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*item:([A-Za-z0-9_А-Яа-я.]+)\s*\}\}/u',
            function (array $matches) use ($item) {
                $path = $matches[1];
                $value = $this->getItemValueByPath($item, $path);

                if ($value === null) {
                    return '';
                }

                if (is_array($value) || is_object($value)) {
                    return '';
                }

                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }

                return (string) $value;
            },
            $template
        );

        return trim($result);
    }

    /**
     * Берёт значение из item по пути с поддержкой точечной нотации.
     * 
     * @param array $item Текущая строка
     * @param string $path Путь (напр. 'LAST_NAME' или 'DEPT.NAME')
     * @return mixed Значение или null
     */
    private function getItemValueByPath(array $item, string $path)
    {
        if (strpos($path, '.') === false) {
            return $item[$path] ?? null;
        }

        $current = $item;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Применяет инструкции изменения полей лида (v1.14.0).
     * 
     * Типы инструкций (значения декодированного UF_JSON_MAPPING_FIELDS):
     * - 'FIELD++'  — инкремент: (int) current[FIELD] + 1;
     * - 'field:x'  — значение из массива values (значения контекста);
     * - литерал    — как есть ('1', 'строка').
     * 
     * @param array|null $instructions Декодированный маппинг {поле => инструкция}
     * @param array $current Текущие значения лида (для ++)
     * @param array $values Значения для инструкций field:*
     * @return array Массив для обновления
     */
    public function applyInstructions(?array $instructions, array $current, array $values = []): array
    {
        $update = [];

        foreach ((array) $instructions as $field => $instruction) {
            if (!is_string($instruction)) {
                $update[$field] = $instruction;
                continue;
            }

            // Инкремент: "UF_CALL_COUNT++"
            if (substr($instruction, -2) === '++') {
                $base = substr($instruction, 0, -2);
                $update[$field] = ((int) ($current[$base] ?? 0)) + 1;
                continue;
            }

            // Значение из контекста: "field:next_action_at"
            if (strpos($instruction, 'field:') === 0) {
                $key = substr($instruction, strlen('field:'));
                $update[$field] = $values[$key] ?? null;
                continue;
            }

            // Литерал
            $update[$field] = $instruction;
        }

        return $update;
    }

    /**
     * Переименовывает ключи у каждого объекта в списке.
     * Удобно, когда нужно просто алиасы: ['old' => 'new'].
     * 
     * @param array $items Исходный список
     * @param array $aliases Маппинг старых ключей на новые
     * @return array
     */
    public function renameKeys(array $items, array $aliases): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($item as $key => $value) {
                $newKey = $aliases[$key] ?? $key;
                $row[$newKey] = $value;
            }
            $result[] = $row;
        }
        return $result;
    }
}
