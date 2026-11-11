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

    // ========================================================
    // PROJECTTREE — ПРОЕКЦИЯ (SELECT) ПОЛЕЙ ДЕРЕВА (v1.24.0)
    // ========================================================

    /**
     * Проекция полей многомерной коллекции: оставляет только указанные
     * поля на каждом уровне дерева (v1.24.0).
     * 
     * Спека — карта путь => select:
     * - '' (пустая строка) — проекция строк корневой коллекции;
     * - 'department' — проекция assoc-объекта внутри каждой строки корня;
     * - 'users' — проекция каждой строки списка внутри строки корня;
     * - 'users.tags' — точечные пути любой глубины.
     * 
     * Формы select:
     * - список: ['ID', 'NAME'] — отсутствующее поле → null;
     * - маппинг: ['ID' => null, 'NAME' => 'Аноним'] — значение = дефолт
     *   при ОТСУТСТВИИ ключа (ключ с null остаётся null);
     * - смешанная: ['ID', 'NAME' => 'Аноним'].
     * Порядок полей выхода = порядок select.
     * 
     * Правила обхода:
     * - путь → assoc-массив: проецируется как строка;
     * - путь → список: проецируется каждая строка, ключи списка сохраняются;
     * - путь → скаляр или отсутствует: не трогается;
     * - уровни применяются по возрастанию глубины (сначала '' , потом
     *   'users', потом 'users.tags'): вложенный путь должен пережить
     *   select родительского уровня.
     * 
     * @param array $source Исходная коллекция (карта или список)
     * @param array $spec Спецификация проекции
     * @return array Коллекция после проекции
     */
    public function projectTree(array $source, array $spec): array
    {
        $levels = [];
        foreach ($spec as $path => $select) {
            $path = (string) $path;
            $levels[] = [
                'segments' => $path === '' ? [] : explode('.', $path),
                'select'   => $this->normalizeSelect($select),
            ];
        }

        // Мелкие пути первыми; PHP 8 usort стабилен — порядок равных = порядок конфига
        usort($levels, static fn(array $a, array $b): int => count($a['segments']) <=> count($b['segments']));

        $result = $source;
        foreach ($levels as $level) {
            if (empty($level['segments'])) {
                $out = [];
                foreach ($result as $key => $row) {
                    $out[$key] = is_array($row) ? $this->projectRow($row, $level['select']) : $row;
                }
                $result = $out;
                continue;
            }
            $result = $this->projectAtPath($result, $level['segments'], $level['select']);
        }

        return $result;
    }

    /**
     * Спускается по пути и проецирует найденные коллекции/объекты.
     * 
     * @param array $collection Коллекция-владелец
     * @param array $segments Сегменты пути
     * @param array $select Нормализованный select
     * @return array
     */
    private function projectAtPath(array $collection, array $segments, array $select): array
    {
        $head = array_shift($segments);
        $out = [];

        foreach ($collection as $key => $item) {
            if (!is_array($item) || !isset($item[$head]) || !is_array($item[$head])) {
                // скаляр или отсутствие пути — не трогаем
                $out[$key] = $item;
                continue;
            }

            if (empty($segments)) {
                $target = $item[$head];
                if (array_is_list($target)) {
                    $projected = [];
                    foreach ($target as $rowKey => $row) {
                        $projected[$rowKey] = is_array($row) ? $this->projectRow($row, $select) : $row;
                    }
                    $item[$head] = $projected;
                } else {
                    $item[$head] = $this->projectRow($target, $select);
                }
            } else {
                $item[$head] = $this->projectAtPath($item[$head], $segments, $select);
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Проецирует одну строку: оставляет поля select в порядке спеки.
     * Дефолт подставляется ТОЛЬКО при отсутствии ключа.
     * 
     * @param array $row Строка
     * @param array $select Нормализованный select: field => default
     * @return array
     */
    private function projectRow(array $row, array $select): array
    {
        $out = [];
        foreach ($select as $field => $default) {
            $out[$field] = array_key_exists($field, $row) ? $row[$field] : $default;
        }
        return $out;
    }

    /**
     * Нормализует select к виду field => default:
     * списочная форма ('ID') → default null; маппинг ('ID' => x) → default x.
     * 
     * @param mixed $select Спека select
     * @return array
     */
    private function normalizeSelect($select): array
    {
        $normalized = [];
        foreach ((array) $select as $key => $value) {
            if (is_int($key)) {
                $normalized[(string) $value] = null;
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    // ========================================================
    // FILLEMPTY — ЗАМЕНА ПУСТЫХ ЗНАЧЕНИЙ (v1.25.0)
    // ========================================================

    /**
     * Заменяет пустые значения указанных полей в строках дерева (v1.25.0).
     * 
     * В отличие от projectTree НЕ проецирует поля: строка сохраняется
     * целиком, подменяются только значения указанных полей, если они пусты.
     * 
     * Спека — путь => маппинг поле => замена:
     * - '' — поля строк корневой коллекции;
     * - 'users' — поля каждой строки списка (или assoc-объекта) по пути;
     * - точечные пути любой глубины ('users.tags').
     * 
     * Триггеры пустоты: дефолт [null, '']; опция 'empty_values'
     * ПЕРЕОПРЕДЕЛЯЕТ набор целиком (не дополняет).
     * 
     * Правила:
     * - замена только если ключ присутствует и значение пусто;
     *   отсутствующий ключ НЕ создаётся;
     * - сравнение строгое (in_array strict): 0 и '0' пустыми не считаются;
     * - замены резолвятся как выражения (field:, {{ }}) на этапе резолва
     *   параметров;
     * - путь в скаляр или отсутствующий путь — игнорируется;
     * - уровни применяются по возрастанию глубины пути.
     * 
     * @param array $source Исходная коллекция (карта или список)
     * @param array $spec Спека: путь => [поле => замена]
     * @param array $options Опции: 'empty_values' => список пустых значений
     * @return array Коллекция после замен
     */
    public function fillEmpty(array $source, array $spec, array $options = []): array
    {
        $emptyValues = array_key_exists('empty_values', $options)
            ? (array) $options['empty_values']
            : [null, ''];

        $levels = [];
        foreach ($spec as $path => $fills) {
            $path = (string) $path;
            $levels[] = [
                'segments' => $path === '' ? [] : explode('.', $path),
                'fills'    => is_array($fills) ? $fills : [],
            ];
        }

        // Мелкие пути первыми; PHP 8 usort стабилен
        usort($levels, static fn(array $a, array $b): int => count($a['segments']) <=> count($b['segments']));

        $result = $source;
        foreach ($levels as $level) {
            if (empty($level['segments'])) {
                $out = [];
                foreach ($result as $key => $row) {
                    $out[$key] = is_array($row)
                        ? $this->fillRow($row, $level['fills'], $emptyValues)
                        : $row;
                }
                $result = $out;
                continue;
            }
            $result = $this->fillAtPath($result, $level['segments'], $level['fills'], $emptyValues);
        }

        return $result;
    }

    /**
     * Спускается по пути и заменяет пустые значения в строках.
     * 
     * @param array $collection Коллекция-владелец
     * @param array $segments Сегменты пути
     * @param array $fills Маппинг поле => замена
     * @param array $emptyValues Список пустых значений
     * @return array
     */
    private function fillAtPath(array $collection, array $segments, array $fills, array $emptyValues): array
    {
        $head = array_shift($segments);
        $out = [];

        foreach ($collection as $key => $item) {
            if (!is_array($item) || !isset($item[$head]) || !is_array($item[$head])) {
                // скаляр или отсутствие пути — не трогаем
                $out[$key] = $item;
                continue;
            }

            if (empty($segments)) {
                $target = $item[$head];
                if (array_is_list($target)) {
                    $filled = [];
                    foreach ($target as $rowKey => $row) {
                        $filled[$rowKey] = is_array($row)
                            ? $this->fillRow($row, $fills, $emptyValues)
                            : $row;
                    }
                    $item[$head] = $filled;
                } else {
                    $item[$head] = $this->fillRow($target, $fills, $emptyValues);
                }
            } else {
                $item[$head] = $this->fillAtPath($item[$head], $segments, $fills, $emptyValues);
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Заменяет пустые значения полей в одной строке.
     * Ключ отсутствует → не трогаем (не создаём).
     * 
     * @param array $row Строка
     * @param array $fills Маппинг поле => замена
     * @param array $emptyValues Список пустых значений
     * @return array
     */
    private function fillRow(array $row, array $fills, array $emptyValues): array
    {
        foreach ($fills as $field => $replacement) {
            if (array_key_exists($field, $row) && in_array($row[$field], $emptyValues, true)) {
                $row[$field] = $replacement;
            }
        }
        return $row;
    }

    // ========================================================
    // COLLECT — СБОРКА НОВОГО МАССИВА ИЗ ДЕРЕВА (v1.26.0)
    // ========================================================

    /**
     * Собирает новый массив (одномерный или многомерный) из дерева (v1.26.0).
     * 
     * Спека:
     * - 'from'       — точечный путь до итерируемой коллекции внутри строки
     *                  корня; '' (дефолт) — итерировать сами строки корня;
     * - 'value'      — что собирать: точечный путь по строке, '_key' (ключ
     *                  строки), '@self' (сам элемент), '{{item:X}}'-шаблон,
     *                  вложенная спека collect (массив) — рекурсия;
     * - 'group_by'   — строка|массив путей по строке-владельцу (корневой
     *                  строке при from != ''); '_key' — ключ владельца;
     *                  массив путей = вложенные группы (многомерность);
     * - 'key_by'     — поле итерируемой строки: внутренний уровень становится
     *                  картой key_by-значение => value вместо списка;
     * - 'skip_empty' — дефолт true: не собирать значения из набора пустот;
     * - 'unique'     — дефолт false: дедупликация значений (строгое сравнение)
     *                  внутри каждой итоговой коллекции.
     * 
     * Опции (3-й аргумент):
     * - 'empty_values' — набор пустот (дефолт [null, '']), переопределяет целиком.
     * 
     * Правила:
     * - пустые группы не создаются: группа появляется, только когда в неё
     *   легло хотя бы одно значение;
     * - ключ группы null/отсутствует/массив → значение пропускается;
     * - key_by null/отсутствует/массив → значение пропускается;
     * - порядок групп — по первому появлению, значений — по порядку обхода;
     * - числовые ключи нормализуются PHP в int (как везде в DSL).
     * 
     * @param array $source Исходная коллекция (карта или список)
     * @param array $spec Спека сбора
     * @param array $options Опции: 'empty_values'
     * @return array Собранный массив
     */
    public function collect(array $source, array $spec, array $options = []): array
    {
        $emptyValues = array_key_exists('empty_values', $options)
            ? (array) $options['empty_values']
            : [null, ''];
        $skipEmpty = (bool) ($spec['skip_empty'] ?? true);
        $unique    = (bool) ($spec['unique'] ?? false);
        $from      = (string) ($spec['from'] ?? '');
        $valueSpec = $spec['value'] ?? null;
        $groupBy   = $spec['group_by'] ?? null;
        $keyBy     = $spec['key_by'] ?? null;

        $collector = [];

        foreach ($source as $ownerKey => $ownerRow) {
            if (!is_array($ownerRow)) {
                continue;
            }

            if ($from === '') {
                $rows = [$ownerKey => $ownerRow];
            } else {
                $target = $this->collectGetPath($ownerRow, $from);
                if (!is_array($target)) {
                    continue;
                }
                $rows = $target;
            }

            // Группа определяется владельцем — считаем один раз на владельца
            $groupKeys = $this->collectGroupKeys($ownerRow, $ownerKey, $groupBy);
            if ($groupKeys === null) {
                continue;
            }

            foreach ($rows as $rowKey => $row) {
                $value = $this->collectValue($row, $rowKey, $valueSpec, $options);

                if ($skipEmpty && (in_array($value, $emptyValues, true) || (is_array($value) && $value === []))) {
                    continue;
                }

                if ($keyBy !== null) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $keyValue = $row[$keyBy] ?? null;
                    if ($keyValue === null || is_array($keyValue)) {
                        continue;
                    }
                    $collector = $this->collectInsert($collector, $groupKeys, $keyValue, $value, $unique, true);
                    continue;
                }

                $collector = $this->collectInsert($collector, $groupKeys, null, $value, $unique, false);
            }
        }

        return $collector;
    }

    /**
     * Значение для сбора из одной строки.
     * 
     * @param mixed $row Строка (или скаляр при итерации скалярных списков)
     * @param mixed $rowKey Ключ строки
     * @param mixed $valueSpec Спека значения
     * @param array $options Опции collect (для вложенной спеки)
     * @return mixed
     */
    private function collectValue($row, $rowKey, $valueSpec, array $options)
    {
        // Вложенная спека collect: строка становится источником (рекурсия)
        if (is_array($valueSpec)) {
            return is_array($row) ? $this->collect([$row], $valueSpec, $options) : null;
        }

        if ($valueSpec === '@self') {
            return $row;
        }

        if ($valueSpec === '_key') {
            return $rowKey;
        }

        if (is_string($valueSpec) && strpos($valueSpec, '{{item:') !== false) {
            return is_array($row) ? $this->resolveItemTemplate($valueSpec, $row) : null;
        }

        if (is_string($valueSpec)) {
            return is_array($row) ? $this->collectGetPath($row, $valueSpec) : null;
        }

        return null;
    }

    /**
     * Ключи группировки из строки-владельца.
     * null в ответе = значение пропускается целиком.
     * 
     * @param array $ownerRow Строка-владелец
     * @param mixed $ownerKey Ключ владельца
     * @param mixed $groupBy Спека group_by
     * @return array|null
     */
    private function collectGroupKeys(array $ownerRow, $ownerKey, $groupBy): ?array
    {
        if ($groupBy === null || $groupBy === []) {
            return [];
        }

        $paths = is_array($groupBy) ? $groupBy : [$groupBy];
        $keys = [];

        foreach ($paths as $path) {
            $value = ($path === '_key')
                ? $ownerKey
                : $this->collectGetPath($ownerRow, (string) $path);

            if ($value === null || is_array($value)) {
                return null;
            }
            $keys[] = $value;
        }

        return $keys;
    }

    /**
     * Вставка значения в коллектор по цепочке групп (+ key_by/unique).
     * 
     * @param array $node Текущий узел коллектора
     * @param array $groupKeys Оставшиеся ключи групп
     * @param mixed $keyValue Значение key_by (или null)
     * @param mixed $value Собираемое значение
     * @param bool $unique Дедупликация
     * @param bool $hasKeyBy Режим карты
     * @return array
     */
    private function collectInsert(array $node, array $groupKeys, $keyValue, $value, bool $unique, bool $hasKeyBy): array
    {
        if (empty($groupKeys)) {
            if ($hasKeyBy) {
                $node[$keyValue] = $value;
                return $node;
            }
            if ($unique && in_array($value, $node, true)) {
                return $node;
            }
            $node[] = $value;
            return $node;
        }

        $head = array_shift($groupKeys);
        if (!isset($node[$head]) || !is_array($node[$head])) {
            $node[$head] = [];
        }
        $node[$head] = $this->collectInsert($node[$head], $groupKeys, $keyValue, $value, $unique, $hasKeyBy);

        return $node;
    }

    /**
     * Значение по точечному пути из строки; нет пути → null.
     * 
     * @param array $row Строка
     * @param string $path Точечный путь
     * @return mixed
     */
    private function collectGetPath(array $row, string $path)
    {
        $current = $row;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    // ========================================================
    // PATCHTREE — УСЛОВНЫЙ ПАТЧИНГ СТРОК ДЕРЕВА (v1.27.0)
    // ========================================================

    /**
     * Условный патчинг строк дерева: поиск по условию + добавление
     * или перезапись полей (v1.27.0).
     * 
     * Спека — упорядоченный список правил; правило:
     * - 'from'  — точечный путь до итерируемой коллекции внутри строки
     *             корня ('' — сами строки корня);
     * - 'where' — условие грамматикой Condition по строке: logic AND/OR,
     *             вложенные группы, операторы Битрикс в ключах,
     *             func:-проверки whitelist, '_key';
     *             пусто/отсутствует → правило бьёт по всем строкам;
     * - 'set'   — маппинг поле => значение: ключа нет → добавляется,
     *             есть → перезаписывается.
     * 
     * Значения set:
     * - литералы и всё, что резолвится об глобальный контекст ЕЩЁ до
     *   вызова (field:, date:, {{ }}) — через resolveParams;
     * - '{{item:X}}' — шаблон по полям текущей строки;
     * - 'item:X' — сырое копирование значения поля строки с типом.
     * 
     * Правила применяются последовательно: следующее правило видит
     * изменения предыдущего (каскады).
     * 
     * @param array $source Исходная коллекция (карта или список)
     * @param array $rules Список правил
     * @return array Коллекция после патчинга
     */
    public function patchTree(array $source, array $rules): array
    {
        $result = $source;

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $from  = (string) ($rule['from'] ?? '');
            $where = is_array($rule['where'] ?? null) ? $rule['where'] : [];
            $set   = is_array($rule['set'] ?? null) ? $rule['set'] : [];

            if (empty($set)) {
                continue;
            }

            if ($from === '') {
                foreach ($result as $key => $row) {
                    if (is_array($row) && TreeConditionMatcher::matchGroup($row, $where, $key)) {
                        $result[$key] = $this->patchRow($row, $set);
                    }
                }
                continue;
            }

            $result = $this->patchAtPath($result, explode('.', $from), $where, $set);
        }

        return $result;
    }

    /**
     * Спускается по пути и патчит строки найденных коллекций.
     * 
     * @param array $collection Коллекция-владелец
     * @param array $segments Сегменты пути
     * @param array $where Условие
     * @param array $set Маппинг замен
     * @return array
     */
    private function patchAtPath(array $collection, array $segments, array $where, array $set): array
    {
        $head = array_shift($segments);
        $out = [];

        foreach ($collection as $key => $item) {
            if (!is_array($item) || !isset($item[$head]) || !is_array($item[$head])) {
                $out[$key] = $item;
                continue;
            }

            if (empty($segments)) {
                $target = $item[$head];

                // УБРАЛИ array_is_list — всегда итерируем как список строк
                // Это работает и для числовых ключей (0,1,2) и для строковых ("0","1","2")
                $patched = [];
                foreach ($target as $rowKey => $row) {
                    $patched[$rowKey] = (is_array($row) && TreeConditionMatcher::matchGroup($row, $where, $rowKey))
                        ? $this->patchRow($row, $set)
                        : $row;
                }
                $item[$head] = $patched;
            } else {
                $item[$head] = $this->patchAtPath($item[$head], $segments, $where, $set);
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Применяет set к одной строке: добавление/перезапись полей.
     * 
     * @param array $row Строка
     * @param array $set Маппинг поле => значение
     * @return array
     */
    private function patchRow(array $row, array $set): array
    {
        foreach ($set as $field => $value) {
            // Сырое копирование поля строки с типом
            if (is_string($value) && strncmp($value, 'item:', 5) === 0) {
                $row[$field] = $row[substr($value, 5)] ?? null;
                continue;
            }

            // Шаблон по полям текущей строки
            if (is_string($value) && strpos($value, '{{item:') !== false) {
                $row[$field] = $this->resolveItemTemplate($value, $row);
                continue;
            }

            // Литерал / зарезолвленное об контекст значение
            $row[$field] = $value;
        }

        return $row;
    }
}
