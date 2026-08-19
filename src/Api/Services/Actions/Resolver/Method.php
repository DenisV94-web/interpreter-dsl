<?php

namespace Api\Services\Actions\Resolver;

use Api\Services\Actions\Context;
use Api\Services\Actions\Exception\Execution as ExecutionException;

/**
 * Class Method
 * 
 * Исполнитель методов и функций интерпретатора.
 * Отвечает за вызов PHP-функций и методов классов на основе конфигурации.
 * 
 * Логика определения что вызывать:
 * - Если есть ключ 'class' → вызываем метод класса
 * - Если нет ключа 'class' → вызываем PHP-функцию
 * - Если есть ключ 'element' → извлекаем элемент из результата-массива
 * 
 * Специальные значения в params:
 * - 'mapping' → подставляется весь массив mapping из контекста
 * 
 * Определение статичности метода класса:
 * - Используется ReflectionMethod::isStatic()
 * - Если статический → ClassName::method()
 * - Если нестатический → (new ClassName())->method()
 * 
 * Формат конфигурации:
 * [
 *     'method' => 'explode',           // Имя функции или метода
 *     'class' => \Api\...\Class::class, // (опционально) Класс
 *     'params' => [...],               // Параметры (разрешаются через Field)
 *     'element' => 1                   // (опционально) Ключ результата
 * ]
 * 
 * @package Api\Services\Actions\Resolver
 */
class Method
{
    /**
     * Специальное значение для подстановки всего mapping массива
     * 
     * @var string
     */
    private const MAPPING_PLACEHOLDER = 'mapping';

    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей (для разрешения параметров)
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Method constructor.
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
     * Выполняет метод/функцию на основе конфигурации
     * 
     * Поддерживает 'on_error' — fallback-значение, которое используется,
     * если метод бросил исключение или вернул false (сущность не найдена).
     * Без 'on_error' поведение прежнее — исключение прерывает действие.
     * 
     * @param array $config Конфигурация метода
     * @return mixed Результат выполнения
     * @throws \RuntimeException Если функция/метод не найдены и on_error не задан
     */
    public function execute(array $config)
    {
        $methodName = $config['method'] ?? null;
        $className = $config['class'] ?? null;
        $params = $config['params'] ?? [];
        $element = $config['element'] ?? null;
        $hasOnError = array_key_exists('on_error', $config);
        $onError = $config['on_error'] ?? null;

        $this->context->log('INFO', 'Method', 'Начало выполнения', [
            'method' => $methodName,
            'class' => $className,
            'params_raw' => $params,
            'element' => $element,
            'on_error' => $hasOnError ? $onError : null
        ]);

        if (empty($methodName)) {
            $this->context->log('ERROR', 'Method', 'Не указано имя метода');
            throw new \RuntimeException('Method name is required');
        }

        // Разрешаем параметры через Field Resolver
        $resolvedParams = $this->resolveParams($params);

        $this->context->log('INFO', 'Method', 'Параметры разрешены', [
            'resolved_params' => $resolvedParams
        ]);

        // Выполняем вызов с поддержкой on_error.
        // При исключении без on_error бросаем ExecutionException с errorContext
        // (class, method, resolved_params) — он попадает в лог.
        try {
            if ($className !== null) {
                $result = $this->callClassMethod($className, $methodName, $resolvedParams);
            } else {
                $result = $this->callPhpFunction($methodName, $resolvedParams);
            }
        } catch (\Throwable $e) {
            if (!$hasOnError) {
                $errorContext = [
                    'class' => $className,
                    'method' => $methodName,
                    'resolved_params' => $this->truncateForContext($resolvedParams),
                ];
                throw new ExecutionException(
                    $e->getMessage(),
                    '',    // путь установит вызывающий (Request/Execute)
                    null,
                    0,
                    $e,
                    $errorContext
                );
            }

            $this->context->log('INFO', 'Method', 'Исключение подавлено через on_error', [
                'error' => $e->getMessage(),
                'on_error' => $onError
            ]);

            $result = $onError;
        }

        // Если метод вернул false (сущность не найдена) — подставляем on_error
        if ($result === false && $hasOnError) {
            $this->context->log('INFO', 'Method', 'Метод вернул false, подставляем on_error', [
                'on_error' => $onError
            ]);

            $result = $onError;
        }

        $this->context->log('INFO', 'Method', 'Результат выполнения', [
            'result' => $result,
            'result_type' => gettype($result)
        ]);

        // ДОБАВЛЕНО: постобработка полей результата
        if (isset($config['change_values']) && !empty($config['change_values'])) {
            try {
                $result = $this->applyChangeValues($result, $config['change_values']);
            } catch (\Throwable $e) {
                if (!$hasOnError) {
                    throw $e;
                }

                $this->context->log('INFO', 'Method', 'Ошибка change_values подавлена через on_error', [
                    'error' => $e->getMessage(),
                    'on_error' => $onError
                ]);

                $result = $onError;
            }
        }

        // Если указан element, извлекаем элемент из результата
        if ($element !== null && is_array($result)) {
            $extracted = $this->extractElement($result, $element);

            $this->context->log('INFO', 'Method', "Извлечён элемент [{$element}]", [
                'element' => $element,
                'extracted' => $extracted
            ]);

            return $extracted;
        }

        // Обновляем lastResult в Field Resolver
        $this->fieldResolver->setLastResult($result);

        return $result;
    }

    /**
     * Применяет change_values к результату запроса
     * 
     * Если результат — список строк (getList), трансформируется КАЖДАЯ строка.
     * Если ассоциативный массив (getById) — применяется напрямую.
     * 
     * @param mixed $result Результат запроса
     * @param array $changeValues Конфигурация change_values
     * @return mixed Трансформированный результат
     * @throws \RuntimeException Если трансформация упала (и нет on_error)
     */
    private function applyChangeValues($result, array $changeValues)
    {
        if (!is_array($result)) {
            return $result;
        }

        // Список строк — применяем к каждой строке
        if (array_is_list($result)) {
            foreach ($result as $index => $row) {
                if (is_array($row)) {
                    $result[$index] = $this->applyChangeValuesToRow($row, $changeValues);
                }
            }
            return $result;
        }

        // Ассоциативный массив — применяем напрямую
        return $this->applyChangeValuesToRow($result, $changeValues);
    }

    /**
     * Трансформирует поля одной строки результата
     * 
     * Формат элемента change_values:
     * [
     *     'name' => 'DATE_CREATE',   // (опционально) поле-источник;
     *                                 // если нет — источник = целевое поле
     *     'method' => 'format',      // метод/функция
     *     'class' => 'self',         // 'self' = вызвать метод на самом значении-объекте;
     *                                 // имя класса = вызвать метод класса;
     *                                 // нет = PHP-функция
     *     'params' => [...],         // параметры (source передаётся ПЕРВЫМ аргументом
     *                                 // для class/func; для 'self' source = сам объект)
     *     'on_error' => ...          // (опционально) fallback при ошибке
     * ]
     * 
     * @param array $row Строка результата
     * @param array $changeValues Конфигурация
     * @return array Трансформированная строка
     */
    private function applyChangeValuesToRow(array $row, array $changeValues): array
    {
        foreach ($changeValues as $targetKey => $cvConfig) {
            $sourceKey = $cvConfig['name'] ?? $targetKey;
            $sourceValue = $row[$sourceKey] ?? null;

            $this->context->log('INFO', 'Method', "change_values: {$targetKey}", [
                'source_key' => $sourceKey,
                'source_type' => gettype($sourceValue),
                'config' => $cvConfig
            ]);

            try {
                $row[$targetKey] = $this->executeChangeValue($sourceValue, $cvConfig);
            } catch (\Throwable $e) {
                if (array_key_exists('on_error', $cvConfig)) {
                    $this->context->log('INFO', 'Method', "change_values {$targetKey}: ошибка подавлена", [
                        'error' => $e->getMessage(),
                        'on_error' => $cvConfig['on_error']
                    ]);
                    $row[$targetKey] = $cvConfig['on_error'];
                } else {
                    throw new \RuntimeException(
                        "change_values '{$targetKey}': " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        return $row;
    }

    /**
     * Выполняет одну трансформацию change_values
     * 
     * @param mixed $sourceValue Значение поля-источника
     * @param array $cvConfig Конфигурация элемента
     * @return mixed Результат трансформации
     * @throws \RuntimeException Если method не указан или вызов невозможен
     */
    private function executeChangeValue($sourceValue, array $cvConfig)
    {
        $methodName = $cvConfig['method'] ?? null;
        $className = $cvConfig['class'] ?? null;
        $params = $this->resolveParams($cvConfig['params'] ?? []);

        if (empty($methodName)) {
            throw new \RuntimeException('change_values: "method" is required');
        }

        // Источник null — не вызываем ничего, пишем null
        if ($sourceValue === null) {
            $this->context->log('INFO', 'Method', 'change_values: источник null → null');
            return null;
        }

        // 'self' — значение само является объектом, вызываем метод на нём
        if ($className === 'self') {
            if (!is_object($sourceValue)) {
                throw new \RuntimeException(
                    'class "self" требует объект, получен ' . gettype($sourceValue)
                );
            }

            if (!method_exists($sourceValue, $methodName)) {
                throw new \RuntimeException(
                    'Метод ' . $methodName . '() не найден в объекте ' . get_class($sourceValue)
                );
            }

            $result = $sourceValue->$methodName(...$params);

            $this->context->log('SUCCESS', 'Method', 'change_values self-метод выполнен', [
                'class' => get_class($sourceValue),
                'method' => $methodName,
                'result' => $result
            ]);

            return $result;
        }

        // Класс или PHP-функция: source передаётся ПЕРВЫМ аргументом
        if ($className !== null) {
            return $this->callClassMethod($className, $methodName, array_merge([$sourceValue], $params));
        }

        return $this->callPhpFunction($methodName, array_merge([$sourceValue], $params));
    }

    /**
     * Разрешает массив параметров
     * 
     * Обрабатывает:
     * - field:... через Field Resolver
     * - Специальное значение 'mapping' → подставляет массив mapping из контекста
     * - Рекурсивно обходит вложенные массивы
     * 
     * @param array $params Массив параметров
     * @return array Разрешённые параметры
     */
    private function resolveParams(array $params): array
    {
        $resolved = [];

        foreach ($params as $key => $value) {
            // Специальная обработка: строка 'mapping' подставляется как массив
            if (is_string($value) && $value === self::MAPPING_PLACEHOLDER) {
                $mapping = $this->context->get(self::MAPPING_PLACEHOLDER, []);

                $this->context->log('INFO', 'Method', "Подстановка массива mapping", [
                    'mapping_keys' => array_keys($mapping),
                    'mapping_count' => count($mapping)
                ]);

                $resolved[$key] = $mapping;
                continue;
            }

            // Рекурсивная обработка вложенных массивов
            if (is_array($value)) {
                $resolved[$key] = $this->resolveParams($value);
                continue;
            }

            // Обычное разрешение через Field Resolver
            $resolved[$key] = $this->fieldResolver->resolve($value);
        }

        return $resolved;
    }

    /**
     * Вызывает PHP-функцию
     * 
     * @param string $functionName Имя функции
     * @param array $params Параметры
     * @return mixed Результат
     * @throws \RuntimeException Если функция не существует
     */
    private function callPhpFunction(string $functionName, array $params)
    {
        $this->context->log('INFO', 'Method', "Вызов PHP-функции: {$functionName}", [
            'params' => $params
        ]);

        // Специальная обработка для языковых конструкций
        if ($functionName === 'empty') {
            $value = $params[0] ?? null;
            return empty($value);
        }

        if ($functionName === 'isset') {
            $value = $params[0] ?? null;
            return isset($value);
        }

        if ($functionName === 'unset') {
            return null;
        }

        // Проверяем существование функции
        if (!function_exists($functionName)) {
            $this->context->log('ERROR', 'Method', "PHP-функция не найдена: {$functionName}");
            throw new \RuntimeException("PHP function not found: {$functionName}");
        }

        try {
            $result = call_user_func_array($functionName, $params);

            $this->context->log('SUCCESS', 'Method', "PHP-функция выполнена: {$functionName}", [
                'result' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->context->log('ERROR', 'Method', "Ошибка выполнения PHP-функции: {$functionName}", [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            throw new \RuntimeException(
                "Error calling PHP function '{$functionName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Вызывает метод класса (статический или через экземпляр)
     * 
     * @param string $className Имя класса
     * @param string $methodName Имя метода
     * @param array $params Параметры
     * @return mixed Результат
     * @throws \RuntimeException Если класс или метод не найдены
     */
    private function callClassMethod(string $className, string $methodName, array $params)
    {
        $this->context->log('INFO', 'Method', "Вызов метода класса", [
            'class' => $className,
            'method' => $methodName,
            'params' => $params
        ]);

        // Проверяем существование класса
        if (!class_exists($className)) {
            $this->context->log('ERROR', 'Method', "Класс не найден: {$className}");
            throw new \RuntimeException("Class not found: {$className}");
        }

        // Проверяем существование метода
        if (!method_exists($className, $methodName)) {
            $this->context->log('ERROR', 'Method', "Метод не найден: {$className}::{$methodName}");
            throw new \RuntimeException("Method not found: {$className}::{$methodName}");
        }

        try {
            // Определяем статичность метода через Reflection
            $reflection = new \ReflectionMethod($className, $methodName);
            $isStatic = $reflection->isStatic();

            $this->context->log('INFO', 'Method', "Метод " . ($isStatic ? 'статический' : 'нестатический'), [
                'class' => $className,
                'method' => $methodName,
                'static' => $isStatic
            ]);

            if ($isStatic) {
                // Статический вызов: ClassName::method()
                $result = $className::$methodName(...$params);
            } else {
                // Вызов через экземпляр: (new ClassName())->method()
                $instance = new $className();
                $result = $instance->$methodName(...$params);
            }

            $this->context->log('SUCCESS', 'Method', "Метод класса выполнен", [
                'class' => $className,
                'method' => $methodName,
                'result' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->context->log('ERROR', 'Method', "Ошибка выполнения метода класса", [
                'class' => $className,
                'method' => $methodName,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw new \RuntimeException(
                "Error calling {$className}::{$methodName}(): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Извлекает элемент из массива результата
     * 
     * Поддерживает:
     * - Простые ключи: element = 1, element = 'ID'
     * - Многоуровневые пути: element = '0.NAME' → $result[0]['NAME']
     * 
     * @param array $result Массив результата
     * @param int|string $element Ключ или путь
     * @return mixed Значение или null
     */
    private function extractElement(array $result, $element)
    {
        // Многоуровневый путь: "0.NAME"
        if (is_string($element) && strpos($element, '.') !== false) {
            $current = $result;

            foreach (explode('.', $element) as $key) {
                if (is_array($current) && array_key_exists($key, $current)) {
                    $current = $current[$key];
                } else {
                    $this->context->log('INFO', 'Method', "Путь элемента [{$element}] прерван", [
                        'missing_key' => $key
                    ]);
                    return null;
                }
            }

            $this->context->log('INFO', 'Method', "Извлечён элемент по пути [{$element}]", [
                'extracted' => $current
            ]);

            return $current;
        }

        // Простой ключ
        if (array_key_exists($element, $result)) {
            return $result[$element];
        }

        $this->context->log('INFO', 'Method', "Элемент [{$element}] не найден в результате", [
            'available_keys' => array_keys($result)
        ]);

        return null;
    }

    /**
     * Обрезает параметры для включения в error_context,
     * чтобы большой массив/объект не раздул лог.
     * 
     * Скаляры оставляем как есть; массивы/объекты сокращаем до:
     * - массивы: длина + первые 5 элементов (рекурсивно не спускаемся);
     * - объекты: class + краткое описание.
     * 
     * @param array $params Разрешённые параметры
     * @return array Усечённые параметры
     */
    private function truncateForContext(array $params): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $result[$key] = [
                    '__array__' => true,
                    'count' => count($value),
                    'sample' => array_slice($value, 0, 5, true),
                ];
            } elseif (is_object($value)) {
                $result[$key] = [
                    '__object__' => true,
                    'class' => get_class($value),
                ];
            } else {
                $result[$key] = '__' . gettype($value) . '__';
            }
        }
        return $result;
    }

    /**
     * Проверяет, является ли конфигурация вызовом метода
     * 
     * @param array $config Конфигурация
     * @return bool True если это вызов метода/функции
     */
    public static function isMethodCall(array $config): bool
    {
        return isset($config['method']);
    }

    /**
     * Проверяет, есть ли условия в конфигурации
     * 
     * @param array $config Конфигурация
     * @return bool True если есть conditions
     */
    public static function hasConditions(array $config): bool
    {
        return isset($config['conditions']) && !empty($config['conditions']);
    }
}
