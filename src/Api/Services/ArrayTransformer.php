<?php

namespace Api\Services;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Method;

/**
 * Class ArrayTransformer
 * 
 * Трансформация списков объектов: перегруппировка, переименование полей,
 * декларативные трансформации через fn+args, query-запросы к внешним сервисам.
 * 
 * @package Api\Services
 */
class ArrayTransformer
{
    /**
     * Превращает список объектов в список опций для select/select2.
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
     * Применяет схему отображения к строке или списку строк (v1.21.0, v1.22.0).
     * 
     * Схема: упорядоченный маппинг dataKey => rule.
     * rule может содержать:
     * - title: заголовок поля
     * - variant: словарь подмены raw → отображаемое
     * - format: формат даты для strtotime+date
     * - query: запрос к внешнему сервису (v1.22.0)
     * 
     * Выход: ассоциатив dataKey => ['title' => ..., 'value' => ...]
     * (порядок = порядок схемы); для списка строк — список таких объектов.
     * 
     * @param array $rows Строка или список строк (авто-детект)
     * @param array $schema Схема отображения
     * @param array $options Опции: 'blank' => список значений-пустот
     * @param Method|null $resolver Резолвер методов (для query, v1.22.0)
     * @param Context|null $context Контекст (для логирования query)
     * @return array
     */
    public function applySchema(
        array $rows,
        array $schema,
        array $options = [],
        ?Method $resolver = null,
        ?Context $context = null
    ): array {
        $blank = array_key_exists('blank', $options) ? $options['blank'] : [null, ''];

        // v1.22.0: если есть resolver, собираем query-кэш
        $queryCache = [];
        if ($resolver !== null && $context !== null) {
            $queryCache = $this->buildQueryCache($rows, $schema, $resolver, $context);
        }

        if ($this->isListOfRows($rows)) {
            $result = [];
            foreach ($rows as $row) {
                $result[] = $this->applySchemaRow($row, $schema, $blank, $queryCache, $context);
            }
            return $result;
        }

        return $this->applySchemaRow($rows, $schema, $blank, $queryCache, $context);
    }

    /**
     * Строит кэш результатов query для всех полей схемы (v1.22.0).
     * 
     * Трёхфазная обработка:
     * 1. Сбор уникальных значений для каждого query-поля
     * 2. Выполнение query (один запрос на поле)
     * 3. Построение lookup-таблиц: cache[field][raw] = value
     * 
     * @param array $rows Строки данных
     * @param array $schema Схема
     * @param Method $resolver Резолвер методов
     * @param Context $context Контекст
     * @return array Кэш: [fieldName => [rawValue => resultValue]]
     */
    private function buildQueryCache(
        array $rows,
        array $schema,
        Method $resolver,
        Context $context
    ): array {
        $cache = [];
        $rowsList = $this->isListOfRows($rows) ? $rows : [$rows];

        // Фаза 1: сбор уникальных значений для каждого query-поля
        $collected = [];
        foreach ($schema as $field => $rule) {
            if (!is_array($rule) || !isset($rule['query'])) {
                continue;
            }

            $collected[$field] = [];
            foreach ($rowsList as $row) {
                if (is_array($row) && isset($row[$field])) {
                    $value = $row[$field];
                    if ($value !== null && !in_array($value, $collected[$field], true)) {
                        $collected[$field][] = $value;
                    }
                }
            }
        }

        // Фаза 2: выполнение query
        foreach ($collected as $field => $values) {
            if (empty($values)) {
                // Пустой batch → пропустить query
                $cache[$field] = [];
                continue;
            }

            $rule = $schema[$field];
            $queryConfig = $rule['query'];
            $onError = $queryConfig['on_error'] ?? 'raw';

            try {
                // Заменяем @batch в params
                $params = $this->replaceBatchInParams($queryConfig['params'] ?? [], $values);

                // Вызываем через Method Resolver синтетическим action.
                // 'element' и 'on_error' НЕ прокидываем: element исказил бы
                // список для lookup-таблицы, а on_error Method'а перехватил бы
                // исключение мимо нашего catch.
                $result = $resolver->execute([
                    'method'    => $queryConfig['method'],
                    'class'     => $queryConfig['class'],
                    'params'    => $params,   // @batch уже заменён на список ID
                    'construct' => $queryConfig['construct'] ?? null,
                ]);

                // Строим lookup-таблицу
                $keyField = $queryConfig['key_field'] ?? 'ID';
                $valueField = $queryConfig['value_field'] ?? null;

                $lookup = [];
                if (is_array($result)) {
                    foreach ($result as $item) {
                        if (is_array($item) && isset($item[$keyField])) {
                            $key = $item[$keyField];

                            if ($valueField !== null) {
                                // value_field — массив: берём первый элемент
                                if (is_array($valueField)) {
                                    $firstField = reset($valueField);
                                    $lookup[$key] = $item[$firstField] ?? null;
                                } else {
                                    $lookup[$key] = $item[$valueField] ?? null;
                                }
                            } else {
                                // Без value_field: весь элемент
                                $lookup[$key] = $item;
                            }
                        }
                    }
                }

                $cache[$field] = $lookup;

                $context->log('INFO', 'ArrayTransformer', "Query выполнен для поля {$field}", [
                    'field' => $field,
                    'batch_size' => count($values),
                    'result_count' => count($lookup),
                ]);
            } catch (\Throwable $e) {
                $context->log('ERROR', 'ArrayTransformer', "Query упал для поля {$field}", [
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]);

                // Ошибка query → пустой кэш (fallback в applySchemaRow)
                $cache[$field] = [];
            }
        }

        return $cache;
    }

    /**
     * Рекурсивно заменяет '@batch' в params на список значений (v1.22.0).
     * 
     * @param mixed $params Параметры (может быть вложенным массивом)
     * @param array $values Список значений для замены
     * @return mixed Параметры с заменённым @batch
     */
    private function replaceBatchInParams($params, array $values)
    {
        if ($params === '@batch') {
            return $values;
        }

        if (is_array($params)) {
            $result = [];
            foreach ($params as $key => $value) {
                $result[$key] = $this->replaceBatchInParams($value, $values);
            }
            return $result;
        }

        return $params;
    }

    /**
     * Список строк: все элементы первого уровня — массивы
     * (пустой массив считаем пустым списком).
     * 
     * @param array $rows Вход
     * @return bool
     */
    private function isListOfRows(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        foreach ($rows as $element) {
            if (!is_array($element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Применяет схему к одной строке (v1.21.0, v1.22.0).
     * 
     * @param array $row Строка данных
     * @param array $schema Схема
     * @param array $blank Список значений-пустот
     * @param array $queryCache Кэш результатов query
     * @param Context|null $context Контекст для логирования
     * @return array keyed-форма {field: {title, value}}
     */
    private function applySchemaRow(
        array $row,
        array $schema,
        array $blank,
        array $queryCache = [],
        ?Context $context = null
    ): array {
        $out = [];

        foreach ($schema as $key => $rule) {
            $title = is_array($rule) ? ($rule['title'] ?? (string) $key) : (string) $rule;
            $variant = is_array($rule) ? ($rule['variant'] ?? null) : null;
            $format = is_array($rule) ? ($rule['format'] ?? null) : null;
            $query = is_array($rule) ? ($rule['query'] ?? null) : null;

            $raw = $row[$key] ?? null;

            // Query-поле (v1.22.0)
            if ($query !== null && !empty($queryCache[$key])) {
                if (array_key_exists($raw, $queryCache[$key])) {
                    $value = $queryCache[$key][$raw];
                } else {
                    // Значения нет в кэше → fallback
                    $onError = $query['on_error'] ?? 'raw';
                    if ($onError === 'raw') {
                        $value = $raw;
                        if ($context) {
                            $context->log('WARNING', 'ArrayTransformer', "Query: значение не найдено в кэше для поля {$key}", [
                                'field' => $key,
                                'raw' => $raw,
                            ]);
                        }
                    } else {
                        $value = null;
                        if ($context) {
                            $context->log('ERROR', 'ArrayTransformer', "Query: значение не найдено в кэше для поля {$key}", [
                                'field' => $key,
                                'raw' => $raw,
                            ]);
                        }
                    }
                }
            }
            // Обычные правила (variant/format/blank)
            elseif (in_array($raw, $blank, true)) {
                $value = '';
            } elseif (
                is_array($variant)
                && (is_int($raw) || is_string($raw))
                && array_key_exists($raw, $variant)
            ) {
                $value = $variant[$raw];
            } elseif (!empty($format)) {
                $value = date($format, strtotime($raw));
            } else {
                $value = $raw;
            }

            // Убираем служебные ключи из rule
            if (is_array($rule)) {
                unset($rule['title'], $rule['variant'], $rule['format'], $rule['query']);
                $out[$key] = array_merge($rule, ['title' => $title, 'value' => $value]);
            } else {
                $out[$key] = ['title' => $title, 'value' => $value];
            }
        }

        return $out;
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
     * @param array $args Массив аргументов
     * @param array $item Текущая строка
     * @return array Разрешённые аргументы
     */
    private function resolveArgsForItem(array $args, array $item): array
    {
        $resolved = [];
        foreach ($args as $arg) {
            // item:X — доступ к полю текущей строки (v1.11.0)
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
                if (!empty($values[$key])) {
                    $update[$field] = $values[$key] ?? null;
                }
                continue;
            }

            // Литерал
            $update[$field] = $instruction;
        }

        return $update;
    }

    /**
     * Переименовывает ключи у каждого объекта в списке.
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
