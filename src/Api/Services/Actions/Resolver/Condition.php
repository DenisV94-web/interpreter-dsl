<?php

namespace Api\Services\Actions\Resolver;

use Api\Services\Actions\Context;

/**
 * Class Condition
 * 
 * Вычислитель условий интерпретатора.
 * Отвечает за проверку условий в блоках conditions и filter.
 * 
 * Поддерживаемые форматы условий:
 * 
 * 1. Простое равенство:
 *    ['field:client_type' => 'phone']
 *    → $context['client_type'] == 'phone'
 * 
 * 2. Проверка через PHP-функцию:
 *    ['field:unified_client_id' => 'func:empty']
 *    → empty($context['unified_client_id'])
 * 
 * 3. Отрицание:
 *    ['!field:LEAD.ID' => 'func:empty']
 *    → !empty($context['LEAD']['ID'])
 * 
 * 4. Проверка через метод класса:
 *    ['field:segment_code' => 'method:\Api\Contact\Main->validate']
 *    → (new \Api\Contact\Main())->validate($context['segment_code'])
 * 
 * 5. Логические операторы:
 *    ['logic' => 'OR', 'field:a' => 'func:empty', 'field:b' => 'func:empty']
 *    → empty($a) || empty($b)
 * 
 * 6. Вложенные условия:
 *    ['logic' => 'OR', 'field:a' => 'func:empty', ['logic' => 'AND', ...]]
 * 
 * Логика по умолчанию: AND (как в Битрикс filter)
 * 
 * @package Api\Services\Actions\Resolver
 */
class Condition
{
    /**
     * Ключ для определения логического оператора
     * 
     * @var string
     */
    private const LOGIC_KEY = 'logic';

    /**
     * Префикс отрицания в ключе условия
     * 
     * @var string
     */
    private const NEGATION_PREFIX = '!';

    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей (для разрешения field:, func:, method:)
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Condition constructor.
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
     * Главный метод вычисления условия
     * 
     * Принимает массив условий и возвращает true/false.
     * Рекурсивно обрабатывает вложенные условия.
     * 
     * @param array $filter Массив условий
     * @return bool Результат вычисления
     */
    public function evaluate(array $filter): bool
    {
        $this->context->log('INFO', 'Condition', 'Начало вычисления условия', [
            'filter' => $filter
        ]);

        // Пустой фильтр = истина (нет условий для проверки)
        if (empty($filter)) {
            $this->context->log('INFO', 'Condition', 'Пустой фильтр, возвращаем true');
            return true;
        }

        // Определяем логический оператор (по умолчанию AND)
        $logic = $this->extractLogic($filter);

        $this->context->log('INFO', 'Condition', "Логический оператор: {$logic}");

        // Собираем результаты всех подусловий
        $results = [];

        foreach ($filter as $key => $value) {
            // Пропускаем ключ logic
            if ($key === self::LOGIC_KEY) {
                continue;
            }

            // Если значение - массив с числовым ключом, это вложенное условие
            if (is_int($key) && is_array($value)) {
                $this->context->log('INFO', 'Condition', "Вложенное условие (индекс: {$key})", $value);
                $results[] = $this->evaluate($value);
                continue;
            }

            // Обычное условие ключ => значение
            $result = $this->evaluateSingle($key, $value);
            $results[] = $result;

            $this->context->log('INFO', 'Condition', "Результат условия [{$key}]", [
                'condition' => "{$key} => " . json_encode($value, JSON_UNESCAPED_UNICODE),
                'result' => $result
            ]);
        }

        // Применяем логический оператор к результатам
        $finalResult = $this->applyLogic($logic, $results);

        $this->context->log('SUCCESS', 'Condition', "Итоговый результат условия", [
            'logic' => $logic,
            'results' => $results,
            'final' => $finalResult
        ]);

        return $finalResult;
    }

    /**
     * Извлекает логический оператор из фильтра
     * 
     * @param array $filter Массив условий
     * @return string 'AND' или 'OR'
     */
    private function extractLogic(array $filter): string
    {
        if (isset($filter[self::LOGIC_KEY])) {
            $logic = strtoupper($filter[self::LOGIC_KEY]);
            if (in_array($logic, ['AND', 'OR'])) {
                return $logic;
            }
        }

        // По умолчанию AND (как в Битрикс)
        return 'AND';
    }

    /**
     * Применяет логический оператор к массиву результатов
     * 
     * @param string $logic Логический оператор (AND/OR)
     * @param array $results Массив булевых результатов
     * @return bool Итоговый результат
     */
    private function applyLogic(string $logic, array $results): bool
    {
        if (empty($results)) {
            return true;
        }

        if ($logic === 'OR') {
            // OR: хотя бы один true
            return in_array(true, $results, true);
        }

        // AND: все должны быть true
        return !in_array(false, $results, true);
    }

    /**
     * Вычисляет одиночное условие
     * 
     * @param string $key Ключ условия (может начинаться с !)
     * @param mixed $value Значение для сравнения
     * @return bool Результат
     */
    private function evaluateSingle(string $key, $value): bool
    {
        // Проверяем отрицание
        $negated = false;
        $actualKey = $key;

        if (str_starts_with($key, self::NEGATION_PREFIX)) {
            $negated = true;
            $actualKey = substr($key, strlen(self::NEGATION_PREFIX));
        }

        $this->context->log('INFO', 'Condition', "Вычисление одиночного условия", [
            'key' => $key,
            'actual_key' => $actualKey,
            'negated' => $negated,
            'value' => $value
        ]);

        // Разрешаем ключ (field:...)
        $fieldValue = $this->fieldResolver->resolve($actualKey);

        // Вычисляем условие
        $result = $this->compareValues($fieldValue, $value);

        // Применяем отрицание
        if ($negated) {
            $result = !$result;
            $this->context->log('INFO', 'Condition', "Применено отрицание", [
                'before' => !$result,
                'after' => $result
            ]);
        }

        return $result;
    }

    /**
     * Сравнивает значение поля с ожидаемым
     * 
     * @param mixed $fieldValue Значение из контекста
     * @param mixed $expected Ожидаемое значение или описание проверки
     * @return bool Результат сравнения
     */
    private function compareValues($fieldValue, $expected): bool
    {
        // Если expected - строка с префиксом func:
        if (is_string($expected) && str_starts_with($expected, 'func:')) {
            return $this->evaluateFunction($fieldValue, $expected);
        }

        // Если expected - строка с префиксом method:
        if (is_string($expected) && str_starts_with($expected, 'method:')) {
            return $this->evaluateMethod($fieldValue, $expected);
        }

        // Если expected - массив описания функции (уже распарсенный Field resolver'ом)
        if (is_array($expected) && isset($expected['type']) && $expected['type'] === 'function') {
            return $this->evaluateFunctionByName($fieldValue, $expected['name']);
        }

        // Если expected - массив описания метода (уже распарсенный Field resolver'ом)
        if (is_array($expected) && isset($expected['class']) && isset($expected['method'])) {
            return $this->evaluateMethodByDescription($fieldValue, $expected);
        }

        // Простое сравнение
        // Используем нестрогое сравнение == чтобы '1' == 1
        return $fieldValue == $expected;
    }

    /**
     * Вычисляет условие через PHP-функцию
     * 
     * @param mixed $fieldValue Значение поля
     * @param string $funcExpression Выражение вида "func:empty"
     * @return bool Результат
     */
    private function evaluateFunction($fieldValue, string $funcExpression): bool
    {
        $funcName = substr($funcExpression, strlen('func:'));

        $this->context->log('INFO', 'Condition', "Вычисление функции: {$funcName}", [
            'field_value' => $fieldValue
        ]);

        return $this->evaluateFunctionByName($fieldValue, $funcName);
    }

    /**
     * Вычисляет PHP-функцию по имени
     * 
     * @param mixed $fieldValue Значение поля
     * @param string $funcName Имя функции
     * @return bool Результат
     */
    private function evaluateFunctionByName($fieldValue, string $funcName): bool
    {
        // Обработка специальных функций которые не являются реальными функциями PHP
        switch ($funcName) {
            case 'empty':
                return empty($fieldValue);

            case 'isset':
                return isset($fieldValue);

            case 'null':
            case 'is_null':
                return is_null($fieldValue);

            case 'numeric':
            case 'is_numeric':
                return is_numeric($fieldValue);

            case 'string':
            case 'is_string':
                return is_string($fieldValue);

            case 'array':
            case 'is_array':
                return is_array($fieldValue);

            case 'bool':
            case 'boolean':
            case 'is_bool':
                return is_bool($fieldValue);

            case 'int':
            case 'integer':
            case 'is_int':
                return is_int($fieldValue);

            case 'float':
            case 'double':
            case 'is_float':
                return is_float($fieldValue);

            case 'object':
            case 'is_object':
                return is_object($fieldValue);

            case 'truthy':
                return (bool) $fieldValue;

            case 'falsy':
                return !$fieldValue;

            default:
                // Пробуем вызвать как реальную PHP-функцию
                if (function_exists($funcName)) {
                    $result = $funcName($fieldValue);
                    return (bool) $result;
                }

                $this->context->log('ERROR', 'Condition', "Неизвестная функция: {$funcName}", [
                    'field_value' => $fieldValue
                ]);

                return false;
        }
    }

    /**
     * Вычисляет условие через метод класса
     * 
     * @param mixed $fieldValue Значение поля
     * @param string $methodExpression Выражение вида "method:\Class->method"
     * @return bool Результат
     */
    private function evaluateMethod($fieldValue, string $methodExpression): bool
    {
        $methodDescription = $this->fieldResolver->resolve($methodExpression);

        if (!is_array($methodDescription) || isset($methodDescription['error'])) {
            $this->context->log('ERROR', 'Condition', "Ошибка парсинга метода", [
                'expression' => $methodExpression
            ]);
            return false;
        }

        return $this->evaluateMethodByDescription($fieldValue, $methodDescription);
    }

    /**
     * Вычисляет метод класса по описанию
     * 
     * @param mixed $fieldValue Значение поля (передаётся как параметр в метод)
     * @param array $description Описание метода ['class' => ..., 'method' => ..., 'static' => bool]
     * @return bool Результат
     */
    private function evaluateMethodByDescription($fieldValue, array $description): bool
    {
        $class = $description['class'];
        $method = $description['method'];
        $isStatic = $description['static'];

        $this->context->log('INFO', 'Condition', "Вычисление метода класса", [
            'class' => $class,
            'method' => $method,
            'static' => $isStatic,
            'field_value' => $fieldValue
        ]);

        try {
            // Проверяем существование класса
            if (!class_exists($class)) {
                $this->context->log('ERROR', 'Condition', "Класс не найден: {$class}");
                return false;
            }

            if ($isStatic) {
                // Статический вызов
                if (!method_exists($class, $method)) {
                    $this->context->log('ERROR', 'Condition', "Статический метод не найден: {$class}::{$method}");
                    return false;
                }

                $result = $class::$method($fieldValue);
            } else {
                // Вызов через экземпляр
                if (!method_exists($class, $method)) {
                    $this->context->log('ERROR', 'Condition', "Метод не найден: {$class}->{$method}");
                    return false;
                }

                $instance = new $class();
                $result = $instance->$method($fieldValue);
            }

            $this->context->log('SUCCESS', 'Condition', "Метод выполнен", [
                'class' => $class,
                'method' => $method,
                'result' => $result
            ]);

            return (bool) $result;
        } catch (\Throwable $e) {
            $this->context->log('ERROR', 'Condition', "Ошибка выполнения метода", [
                'class' => $class,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
