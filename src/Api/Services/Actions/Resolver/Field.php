<?php

namespace Api\Services\Actions\Resolver;

use Api\Services\Actions\Context;

/**
 * Class Field
 * 
 * Резолвер полей из контекста.
 * Отвечает за парсинг и разрешение выражений типа:
 * - "field:CONTACT.PHONE" - взять значение из контекста по пути
 * - "field:CONTACT.PHONE|field:COMPANY.PHONE" - цепочка с приоритетом (первое не-null)
 * - "func:empty" - ссылка на PHP-функцию (для условий)
 * - "method:\Class->method" - ссылка на метод класса (для условий)
 * - "result" или "result:ID" - результат последнего действия
 * - Простые строки без префикса возвращаются как есть
 * 
 * Формат выражений:
 * - field:path.to.value - доступ к данным контекста
 * - field:path|field:other.path - цепочка альтернатив (??)
 * - func:function_name - PHP-функция
 * - method:ClassName->methodName - метод класса
 * - result - результат последнего вызова метода
 * - result:key - значение из результата по ключу
 * 
 * @package Api\Services\Actions\Resolver
 */
class Field
{
    /**
     * Префикс для полей контекста
     * 
     * @var string
     */
    private const PREFIX_FIELD = 'field:';

    /**
     * Префикс для PHP-функций
     * 
     * @var string
     */
    private const PREFIX_FUNC = 'func:';

    /**
     * Префикс для методов классов
     * 
     * @var string
     */
    private const PREFIX_METHOD = 'method:';

    /**
     * Префикс для результата последнего действия
     * 
     * @var string
     */
    private const PREFIX_RESULT = 'result';

    /**
     * Разделитель альтернатив (цепочка приоритетов)
     * 
     * @var string
     */
    private const ALTERNATIVE_SEPARATOR = '|';

    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Результат последнего выполненного действия
     * Используется для подстановки "result" и "result:key"
     * 
     * @var mixed
     */
    private $lastResult = null;

    /**
     * Открывающий маркер шаблонного плейсхолдера
     * 
     * @var string
     */
    private const TEMPLATE_OPEN = '{{';

    /**
     * Внутренность плейсхолдера {{ }}:
     * - field:... / result... — полная форма (скобки и | разрешены);
     * - голый путь с опциональными [скобками] — шорткат (v1.7.0).
     * 
     * @var string
     */
    private const TEMPLATE_INNER = 'field:[^}]+|result(?::[^}]+)?|[A-Za-z_А-Яа-я][A-Za-z0-9_А-Яа-я.]*(?:\[[^\]}]*\])*';

    /**
     * Паттерн плейсхолдера шаблона
     * 
     * @var string
     */
    private const TEMPLATE_PATTERN = '/\{\{\s*(' . self::TEMPLATE_INNER . ')\s*\}\}/u';


    /**
     * Field constructor.
     * 
     * @param Context $context Контекст выполнения
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Приводит внутренность плейсхолдера к полному выражению
     * 
     * @param string $inner Внутренность {{ }}
     * @return string
     */
    private function normalizeInner(string $inner): string
    {
        $inner = trim($inner);

        // Полная форма — как есть
        if ($this->startsWithKnownPrefix($inner)) {
            return $inner;
        }

        // Шорткат: голый путь → field:путь
        return self::PREFIX_FIELD . $inner;
    }

    /**
     * Устанавливает результат последнего действия
     * 
     * @param mixed $result Результат
     * @return void
     */
    public function setLastResult($result): void
    {
        $this->lastResult = $result;
        $this->context->log('INFO', 'Field', 'Установлен lastResult', $result);
    }

    /**
     * Возвращает результат последнего действия
     * 
     * @return mixed
     */
    public function getLastResult()
    {
        return $this->lastResult;
    }

    /**
     * Главный метод разрешения выражения
     * 
     * Определяет тип выражения и делегирует соответствующему обработчику:
     * - field:... → resolveField()
     * - func:... → возвращает имя функции (для Condition)
     * - method:... → возвращает описание метода (для Condition)
     * - result → resolveResult()
     * - Обычная строка → возвращается как есть
     * 
     * @param mixed $expression Выражение для разрешения
     * @return mixed Разрешённое значение
     */
    public function resolve($expression)
    {
        $this->context->log('INFO', 'Field', 'Начало разрешения выражения', [
            'expression' => $expression,
            'type' => gettype($expression)
        ]);

        // Если не строка, возвращаем как есть (числа, массивы, null)
        if (!is_string($expression)) {
            return $expression;
        }

        // СТАРАЯ СИНТАКСИС: чистые выражения и цепочки |
        if ($this->startsWithKnownPrefix($expression)) {
            if (strpos($expression, self::ALTERNATIVE_SEPARATOR) !== false) {
                return $this->resolveAlternatives($expression);
            }

            return $this->resolvePrefixed($expression);
        }

        // НОВАЯ СИНТАКСИС: шаблоны {{ }}
        if (
            strpos($expression, self::TEMPLATE_OPEN) !== false
            && preg_match(self::TEMPLATE_PATTERN, $expression)
        ) {
            return $this->resolveTemplate($expression);
        }

        // Обычная строка — возвращаем как есть
        $this->context->log('INFO', 'Field', 'Обычная строка, возвращаем как есть', $expression);
        return $expression;
    }

    /**
     * Проверяет, начинается ли строка с известного префикса выражения
     * 
     * @param string $expression Выражение
     * @return bool
     */
    private function startsWithKnownPrefix(string $expression): bool
    {
        return $this->startsWith($expression, self::PREFIX_FIELD)
            || $this->startsWith($expression, self::PREFIX_FUNC)
            || $this->startsWith($expression, self::PREFIX_METHOD)
            || $expression === self::PREFIX_RESULT
            || $this->startsWith($expression, self::PREFIX_RESULT . ':');
    }

    /**
     * Разрешает выражение с известным префиксом (старая логика)
     * 
     * @param string $expression Выражение
     * @return mixed
     */
    private function resolvePrefixed(string $expression)
    {
        if ($this->startsWith($expression, self::PREFIX_FIELD)) {
            return $this->resolveField($expression);
        }

        if ($this->startsWith($expression, self::PREFIX_FUNC)) {
            $funcName = substr($expression, strlen(self::PREFIX_FUNC));
            return ['type' => 'function', 'name' => $funcName];
        }

        if ($this->startsWith($expression, self::PREFIX_METHOD)) {
            return $this->resolveMethodReference($expression);
        }

        return $this->resolveResult($expression);
    }

    /**
     * Разрешает шаблон: текст с плейсхолдерами {{ }}
     * 
     * Семантика null:
     * - Вся строка — один плейсхолдер → сырое значение (null/число/массив)
     * - В смешанном тексте null → '' (как Twig/Blade)
     * 
     * @param string $expression Шаблон
     * @return mixed
     */
    private function resolveTemplate(string $expression)
    {
        // Вся строка — один плейсхолдер: сырое значение
        if (preg_match('/^\{\{\s*(' . self::TEMPLATE_INNER . ')\s*\}\}$/u', $expression, $m)) {
            return $this->resolve($this->normalizeInner($m[1]));
        }

        $result = preg_replace_callback(
            self::TEMPLATE_PATTERN,
            function (array $matches) {
                $resolved = $this->resolve($this->normalizeInner($matches[1]));

                if ($resolved === null) {
                    return '';
                }

                if (is_array($resolved) || is_object($resolved)) {
                    $this->context->log('ERROR', 'Field', 'Массив/объект в шаблоне заменён пустой строкой', [
                        'inner' => $matches[1]
                    ]);
                    return '';
                }

                if (is_bool($resolved)) {
                    return $resolved ? '1' : '0';
                }

                return (string) $resolved;
            },
            $expression
        );

        $this->context->log('SUCCESS', 'Field', 'Шаблон разрешён', [
            'template' => $expression,
            'result' => $result
        ]);

        return $result;
    }

    /**
     * Разрешает цепочку альтернатив
     * 
     * Пример: "field:CONTACT.PHONE|field:COMPANY.PHONE|field:phone"
     * Возвращает первое не-null значение
     * 
     * @param string $expression Выражение с разделителями |
     * @return mixed Первое найденное не-null значение или null
     */
    private function resolveAlternatives(string $expression)
    {
        $parts = $this->splitTopLevel($expression, self::ALTERNATIVE_SEPARATOR);

        $this->context->log('INFO', 'Field', 'Разрешение цепочки альтернатив', [
            'expression' => $expression,
            'parts_count' => count($parts)
        ]);

        foreach ($parts as $index => $part) {
            $part = trim($part);

            $this->context->log('INFO', 'Field', "Проверка альтернативы #{$index}", [
                'part' => $part
            ]);

            $value = $this->resolve($part);

            // Если значение найдено (не null), возвращаем его
            if ($value !== null) {
                $this->context->log('SUCCESS', 'Field', "Найдено значение в альтернативе #{$index}", [
                    'part' => $part,
                    'value' => $value
                ]);
                return $value;
            }
        }

        $this->context->log('INFO', 'Field', 'Ни одна альтернатива не вернула значение', [
            'expression' => $expression
        ]);

        return null;
    }

    /**
     * Разрешает ссылку на поле контекста
     * 
     * Пример: "field:CONTACT.PHONE" → $context->get('CONTACT.PHONE')
     * 
     * @param string $expression Выражение вида "field:path.to.value"
     * @return mixed Значение из контекста или null
     */
    private function resolveField(string $expression)
    {
        $path = substr($expression, strlen(self::PREFIX_FIELD));

        $this->context->log('INFO', 'Field', "Разрешение поля: {$path}");

        // v1.7.0 dynamic-key-access: путь со скобками — новый walker
        if (strpos($path, '[') !== false) {
            return $this->resolveFieldPath($path);
        }

        if (!$this->context->has($path)) {
            $this->context->log('INFO', 'Field', "Поле не найдено: {$path}", [
                'available_keys' => array_keys($this->context->data)
            ]);
            return null;
        }

        $value = $this->context->get($path);

        $this->context->log('SUCCESS', 'Field', "Поле разрешено: {$path}", [
            'value' => $value
        ]);

        return $value;
    }

    /**
     * Разрешает результат последнего действия
     * 
     * Примеры:
     * - "result" → весь результат последнего метода
     * - "result:ID" → значение по ключу ID из результата
     * 
     * @param string $expression Выражение вида "result" или "result:key"
     * @return mixed Значение результата или null
     */
    private function resolveResult(string $expression)
    {
        $this->context->log('INFO', 'Field', 'Разрешение результата', [
            'expression' => $expression,
            'lastResult' => $this->lastResult
        ]);

        // Просто "result" - возвращаем весь результат
        if ($expression === self::PREFIX_RESULT) {
            return $this->lastResult;
        }

        // "result:key" - возвращаем значение по ключу
        $key = substr($expression, strlen(self::PREFIX_RESULT . ':'));

        if (is_array($this->lastResult) && array_key_exists($key, $this->lastResult)) {
            $value = $this->lastResult[$key];
            $this->context->log('SUCCESS', 'Field', "Результат по ключу '{$key}'", $value);
            return $value;
        }

        // Если результат - объект, пробуем получить свойство
        if (is_object($this->lastResult) && property_exists($this->lastResult, $key)) {
            $value = $this->lastResult->{$key};
            $this->context->log('SUCCESS', 'Field', "Результат по свойству '{$key}'", $value);
            return $value;
        }

        $this->context->log('INFO', 'Field', "Ключ '{$key}' не найден в результате");
        return null;
    }

    /**
     * Парсит ссылку на метод класса
     * 
     * Формат: "method:\Namespace\Class->methodName"
     * или: "method:\Namespace\Class::methodName" (статический)
     * 
     * @param string $expression Выражение вида "method:Class->method"
     * @return array Описание метода ['class' => ..., 'method' => ..., 'static' => bool]
     */
    private function resolveMethodReference(string $expression): array
    {
        $methodString = substr($expression, strlen(self::PREFIX_METHOD));

        $this->context->log('INFO', 'Field', "Парсинг ссылки на метод", [
            'expression' => $expression,
            'method_string' => $methodString
        ]);

        // Проверяем статический вызов (::)
        if (strpos($methodString, '::') !== false) {
            [$class, $method] = explode('::', $methodString, 2);
            $isStatic = true;
        }
        // Проверяем вызов через объект (->)
        elseif (strpos($methodString, '->') !== false) {
            [$class, $method] = explode('->', $methodString, 2);
            $isStatic = false;
        } else {
            $this->context->log('ERROR', 'Field', "Неверный формат метода", [
                'expression' => $expression
            ]);
            return ['class' => null, 'method' => null, 'static' => false, 'error' => 'Invalid method format'];
        }

        $result = [
            'class' => trim($class),
            'method' => trim($method),
            'static' => $isStatic
        ];

        $this->context->log('SUCCESS', 'Field', "Метод распарсен", $result);

        return $result;
    }

    /**
     * Разрешает путь поля с динамическими ключами (v1.7.0).
     * 
     * Примеры: pr_hl[field:index_code], a.b[field:k].name, m[field:r][field:c]
     * Отсутствующий ключ → null (семантика всего DSL).
     * 
     * @param string $path Путь после префикса field:
     * @return mixed Значение или null
     */
    private function resolveFieldPath(string $path)
    {
        $segments = $this->parsePathSegments($path);

        $current = null;
        $started = false;

        foreach ($segments as $segment) {
            if ($segment['type'] === 'key') {
                $key = $segment['value'];
            } else {
                $key = $this->resolveBracketKey($segment['value']);

                if ($key === null) {
                    $this->context->log('INFO', 'Field', "Динамический ключ разрешился в null: {$path}");
                    return null;
                }
            }

            if (!$started) {
                $current = $this->context->get((string) $key);
                $started = true;
            } else {
                $current = is_array($current) ? ($current[$key] ?? null) : null;
            }

            if ($current === null) {
                $this->context->log('INFO', 'Field', "Ключ не найден в пути: {$path}", ['key' => $key]);
                return null;
            }
        }

        $this->context->log('SUCCESS', 'Field', "Путь с динамическими ключами разрешён: {$path}", [
            'value' => $current
        ]);

        return $current;
    }

    /**
     * Разбирает путь на сегменты: литеральные ключи и [выражения].
     * Учитывает вложенность скобок и кавычки.
     * 
     * @param string $path Путь после field:
     * @return array Сегменты ['type' => 'key'|'expr', 'value' => string]
     * @throws \RuntimeException При непарной скобке
     */
    private function parsePathSegments(string $path): array
    {
        $segments = [];
        $name = '';
        $len = strlen($path);
        $i = 0;

        $flush = function () use (&$name, &$segments): void {
            if ($name !== '') {
                $segments[] = ['type' => 'key', 'value' => $name];
                $name = '';
            }
        };

        while ($i < $len) {
            $ch = $path[$i];

            if ($ch === '.') {
                $flush();
                $i++;
                continue;
            }

            if ($ch !== '[') {
                $name .= $ch;
                $i++;
                continue;
            }

            // Скобка: ищем парную с учётом вложенности и кавычек
            $flush();
            $depth = 1;
            $inQuote = null;
            $j = $i + 1;

            while ($j < $len && $depth > 0) {
                $c = $path[$j];

                if ($inQuote !== null) {
                    if ($c === $inQuote) {
                        $inQuote = null;
                    }
                } elseif ($c === '\'' || $c === '"') {
                    $inQuote = $c;
                } elseif ($c === '[') {
                    $depth++;
                } elseif ($c === ']') {
                    $depth--;
                }

                $j++;
            }

            if ($depth !== 0) {
                throw new \RuntimeException("Непарная скобка в выражении field:{$path}");
            }

            $segments[] = [
                'type'  => 'expr',
                'value' => substr($path, $i + 1, $j - $i - 2),
            ];
            $i = $j;
        }

        $flush();

        return $segments;
    }

    /**
     * Разрешает содержимое [...] (v1.7.0):
     * - 'str' / "str" — литерал в кавычках;
     * - число — числовой индекс;
     * - field:/result:... — выражение (рекурсивно, включая цепочки |);
     * - голое имя — литеральный ключ.
     * 
     * @param string $inner Содержимое скобок
     * @return mixed Ключ или null
     */
    private function resolveBracketKey(string $inner)
    {
        $inner = trim($inner);

        // Литерал в кавычках
        if (
            strlen($inner) >= 2
            && ($inner[0] === '\'' || $inner[0] === '"')
            && substr($inner, -1) === $inner[0]
        ) {
            return substr($inner, 1, -1);
        }

        // Числовой индекс
        if (is_numeric($inner)) {
            return (strpos($inner, '.') === false) ? (int) $inner : $inner;
        }

        // Выражение (field:, result:, цепочки)
        if ($this->startsWithKnownPrefix($inner)) {
            return $this->resolve($inner);
        }

        // Голое имя — литеральный ключ
        return $inner;
    }

    /**
     * Разбивает строку по разделителю ТОЛЬКО на верхнем уровне:
     * разделители внутри [...] и внутри кавычек не учитываются (v1.7.0).
     * 
     * @param string $expression Строка
     * @param string $separator Разделитель
     * @return array Части
     */
    private function splitTopLevel(string $expression, string $separator = '|'): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inQuote = null;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expression[$i];

            if ($inQuote !== null) {
                if ($ch === $inQuote) {
                    $inQuote = null;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $inQuote = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '[') {
                $depth++;
                $current .= $ch;
                continue;
            }

            if ($ch === ']') {
                $depth--;
                $current .= $ch;
                continue;
            }

            if ($depth === 0 && $ch === $separator) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Разрешает массив параметров
     * 
     * Рекурсивно обходит массив и разрешает все field:... выражения
     * 
     * @param array $params Массив параметров
     * @return array Разрешённый массив параметров
     */
    public function resolveParams(array $params): array
    {
        $this->context->log('INFO', 'Field', 'Начало разрешения параметров', [
            'params_count' => count($params)
        ]);

        $resolved = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // Рекурсивно обрабатываем вложенные массивы
                $resolved[$key] = $this->resolveParams($value);
            } else {
                $resolved[$key] = $this->resolve($value);
            }
        }

        $this->context->log('SUCCESS', 'Field', 'Параметры разрешены', [
            'resolved' => $resolved
        ]);

        return $resolved;
    }

    /**
     * Проверяет, начинается ли строка с префикса
     * 
     * @param string $haystack Строка для проверки
     * @param string $needle Префикс
     * @return bool
     */
    private function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
