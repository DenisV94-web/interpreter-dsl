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
