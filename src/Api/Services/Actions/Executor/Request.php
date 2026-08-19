<?php

namespace Api\Services\Actions\Executor;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Resolver\Method;
use Api\Services\Actions\Exception\Execution as ExecutionException;

/**
 * Class Request
 * 
 * Экзекьютор блока request конфигурации интерпретатора.
 * Отвечает за подготовку начальных данных для дальнейшей обработки.
 * 
 * Что делает:
 * 1. Определяет источник данных (JSON body, $_POST, $_GET и т.д.)
 * 2. Если указан 'array' — разбивает данные на итерации
 * 3. Выполняет 'extra' поля (вычисляемые значения)
 * 4. Выполняет 'query' запросы к БД/внешним системам
 * 5. Записывает все результаты в Context
 * 
 * Приоритет получения данных:
 * - Для POST: сначала php://input (JSON), потом $_POST (form-data)
 * - Для GET: $_GET
 * - Для других: соответствующие суперглобальные массивы
 * 
 * Формат конфигурации:
 * [
 *     'main' => 'post',          // Источник данных (обязательно)
 *     'array' => 'lead',         // Ключ массива для итераций (опционально)
 *     'extra' => [...],          // Вычисляемые поля (опционально)
 *     'query' => [...]           // Запросы к БД (опционально)
 * ]
 * 
 * @package Api\Services\Actions\Executor
 */
class Request
{
    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Вычислитель условий
     * 
     * @var Condition
     */
    private Condition $conditionResolver;

    /**
     * Исполнитель методов
     * 
     * @var Method
     */
    private Method $methodResolver;

    /**
     * Request constructor.
     * 
     * @param Context $context Контекст выполнения
     * @param Field $fieldResolver Резолвер полей
     * @param Condition $conditionResolver Вычислитель условий
     * @param Method $methodResolver Исполнитель методов
     */
    public function __construct(
        Context $context,
        Field $fieldResolver,
        Condition $conditionResolver,
        Method $methodResolver
    ) {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
        $this->conditionResolver = $conditionResolver;
        $this->methodResolver = $methodResolver;
    }

    /**
     * Выполняет обработку блока request
     * 
     * @param array $config Конфигурация блока request
     * @param array|null $inputData Данные для обработки (опционально, для тестов)
     *                              Если null — данные берутся из php://input или суперглобальных массивов
     * @return void
     * @throws \RuntimeException Если не указан обязательный параметр main
     */
    public function execute(array $config, ?array $inputData = null): void
    {
        $this->context->log('INFO', 'Request', '=== НАЧАЛО ОБРАБОТКИ REQUEST ===', [
            'config_keys' => array_keys($config),
            'input_data_provided' => $inputData !== null
        ]);

        // 1. Валидация обязательных параметров
        if (!isset($config['main'])) {
            $this->context->log('ERROR', 'Request', 'Не указан обязательный параметр "main"');
            throw new \RuntimeException('Request config must have "main" parameter');
        }

        // 2. Получаем исходные данные
        // Если данные переданы явно (для тестов) — используем их
        // Иначе — получаем из php://input или суперглобальных массивов
        if ($inputData !== null) {
            $rawData = $inputData;
            $this->context->log('INFO', 'Request', 'Используем переданные inputData (тестовый режим)', [
                'data_keys' => array_keys($rawData),
                'data_count' => count($rawData)
            ]);
        } else {
            $rawData = $this->getRawData($config['main']);
            $this->context->log('INFO', 'Request', "Получены данные из {$config['main']}", [
                'data_keys' => array_keys($rawData),
                'data_count' => count($rawData)
            ]);
        }

        // 3. Определяем режим работы: одиночный или итерационный
        if (isset($config['array'])) {
            $this->executeIterative($config, $rawData);
        } else {
            $this->executeSingle($config, $rawData);
        }

        $this->context->log('SUCCESS', 'Request', '=== ОБРАБОТКА REQUEST ЗАВЕРШЕНА ===');
    }

    /**
     * Получает данные из указанного источника
     * 
     * Приоритет для POST:
     * 1. Пробуем получить JSON из php://input
     * 2. Если JSON невалидный или пустой — используем $_POST
     * 
     * @param string $source Источник (post, get, request, cookie, server)
     * @return array Данные
     */
    private function getRawData(string $source): array
    {
        $source = strtolower($source);

        switch ($source) {
            case 'post':
                return $this->getPostData();
            case 'get':
                return $_GET;
            case 'request':
                return $_REQUEST;
            case 'cookie':
                return $_COOKIE;
            case 'server':
                return $_SERVER;
            default:
                $this->context->log('INFO', 'Request', "Неизвестный источник: {$source}, используем \$_REQUEST");
                return $_REQUEST;
        }
    }

    /**
     * Получает POST-данные с приоритетом JSON из body
     * 
     * Логика:
     * 1. Читаем php://input (raw body)
     * 2. Пытаемся декодировать как JSON
     * 3. Если JSON валидный и не пустой — возвращаем его
     * 4. Иначе — возвращаем $_POST (form-data)
     * 
     * @return array Данные запроса
     */
    private function getPostData(): array
    {
        // Читаем raw body
        $rawInput = file_get_contents("php://input");

        $this->context->log('INFO', 'Request', 'Получен raw body', [
            'raw_length' => strlen($rawInput),
            'raw_preview' => mb_substr($rawInput, 0, 200)
        ]);

        // Если есть данные в body
        if (!empty($rawInput)) {
            // Пытаемся декодировать как JSON
            $jsonData = json_decode($rawInput, true);

            // Проверяем валидность JSON
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $this->context->log('SUCCESS', 'Request', 'Успешно декодирован JSON из php://input', [
                    'json_keys' => array_keys($jsonData),
                    'json_count' => count($jsonData)
                ]);

                return $jsonData;
            }

            // JSON невалидный — логируем и идём в $_POST
            $this->context->log('INFO', 'Request', 'Невалидный JSON в php://input, используем $_POST', [
                'json_error' => json_last_error_msg()
            ]);
        }

        // Fallback на $_POST
        $this->context->log('INFO', 'Request', 'Используем $_POST', [
            'post_keys' => array_keys($_POST),
            'post_count' => count($_POST)
        ]);

        return $_POST;
    }

    /**
     * Одиночный режим выполнения (без array)
     * 
     * @param array $config Конфигурация
     * @param array $rawData Исходные данные
     * @return void
     */
    private function executeSingle(array $config, array $rawData): void
    {
        $this->context->log('INFO', 'Request', 'Одиночный режим выполнения');

        foreach ($rawData as $key => $value) {
            $this->context->set($key, $value);
        }

        // Порядок шагов: кастомный или дефолтный
        $order = $this->resolveRequestOrder($config);

        foreach ($order as $step) {
            if ($this->context->hasError()) {
                break;
            }

            switch ($step) {
                case 'static':
                    if (isset($config['static']) && !empty($config['static'])) {
                        $this->executeStatic($config['static']);
                    }
                    break;

                case 'extra':
                    if (isset($config['extra']) && !empty($config['extra'])) {
                        $this->executeExtra($config['extra']);
                    }
                    break;

                case 'query':
                    if (isset($config['query']) && !empty($config['query'])) {
                        $this->executeQuery($config['query']);
                    }
                    break;

                case 'curl':
                    if (isset($config['curl']) && !empty($config['curl'])) {
                        $this->executeCurl($config['curl']);
                    }
                    break;
            }
        }
    }

    /**
     * Итерационный режим выполнения (с array)
     * 
     * @param array $config Конфигурация
     * @param array $rawData Исходные данные
     * @return void
     */
    private function executeIterative(array $config, array $rawData): void
    {
        $arrayKey = $config['array'];

        $this->context->log('INFO', 'Request', "Итерационный режим, ключ массива: {$arrayKey}");

        // Проверяем наличие массива в данных
        if (!isset($rawData[$arrayKey]) || !is_array($rawData[$arrayKey])) {
            $this->context->log('ERROR', 'Request', "Массив '{$arrayKey}' не найден в данных");
            throw new \RuntimeException("Array key '{$arrayKey}' not found in request data");
        }

        $iterations = $rawData[$arrayKey];
        $this->context->iterationTotal = count($iterations);

        $this->context->log('INFO', 'Request', "Количество итераций: {$this->context->iterationTotal}");

        // Сохраняем общие данные (вне массива) в контекст
        foreach ($rawData as $key => $value) {
            if ($key !== $arrayKey) {
                $this->context->set($key, $value);
            }
        }

        // Порядок шагов: кастомный или дефолтный
        $order = $this->resolveRequestOrder($config);

        // Обработка каждой итерации
        foreach ($iterations as $index => $iterationData) {
            $this->context->resetForIteration($iterationData, $index);

            $this->context->log('INFO', 'Request', "Обработка итерации #{$index}", [
                'iteration_data' => $iterationData
            ]);

            // Записываем данные итерации в контекст
            foreach ($iterationData as $key => $value) {
                $this->context->set($key, $value);
            }

            foreach ($order as $step) {
                if ($this->context->hasError()) {
                    break;
                }

                switch ($step) {
                    case 'static':
                        if (isset($config['static']) && !empty($config['static'])) {
                            $this->executeStatic($config['static']);
                        }
                        break;

                    case 'extra':
                        if (isset($config['extra']) && !empty($config['extra'])) {
                            $this->executeExtra($config['extra']);
                        }
                        break;

                    case 'query':
                        if (isset($config['query']) && !empty($config['query'])) {
                            $this->executeQuery($config['query']);
                        }
                        break;

                    case 'curl':
                        if (isset($config['curl']) && !empty($config['curl'])) {
                            $this->executeCurl($config['curl']);
                        }
                        break;
                }
            }

            // Если произошла ошибка — прерываем итерации
            if ($this->context->hasError()) {
                $this->context->log('ERROR', 'Request', "Ошибка на итерации #{$index}, прерывание");
                break;
            }
        }
    }

    /**
     * Выполняет блок extra: вычисляемые поля
     * 
     * Поддерживаемые типы выражений:
     * - conditions + check_true/check_false — условное значение
     *   (ветки могут быть method-выражением, массивом или field:)
     * - method + class/params — вызов метода/функции
     * - массив без спец-ключей — содержимое резолвится РЕКУРСИВНО
     *   (field: внутри массива теперь работают)
     * - строка/число — field:, шаблон {{ }}, литерал
     * 
     * Поля пишутся в контекст СРАЗУ после вычисления,
     * поэтому следующие extra-поля могут зависеть от предыдущих
     * (цепочки зависимостей, как client_string → client_id).
     * 
     * @param array $extraConfig Конфигурация блока extra
     * @return void
     */
    private function executeExtra(array $extraConfig): void
    {
        $this->context->log('INFO', 'Request', 'Начало обработки extra', [
            'fields' => array_keys($extraConfig)
        ]);

        foreach ($extraConfig as $key => $expression) {
            try {
                // 1. Условное значение: conditions + check_true/check_false
                if (is_array($expression) && isset($expression['conditions'])) {
                    $conditionResult = $this->conditionResolver->evaluate($expression['conditions']);

                    $this->context->log('INFO', 'Request', "Условие extra {$key}: " . ($conditionResult ? 'true' : 'false'));

                    $branch = $conditionResult
                        ? ($expression['check_true'] ?? null)
                        : ($expression['check_false'] ?? null);

                    $resolved = $this->resolveExtraExpression($branch);
                }
                // 2. Любое другое выражение (method / массив / строка)
                else {
                    $resolved = $this->resolveExtraExpression($expression);
                }

                // Пишем в контекст сразу — следующие extra могут зависеть
                $this->context->set($key, $resolved);
                // Флаг no_log: значение остаётся доступным через field:,
                // но исключается из log.computed и снимков итераций
                if (is_array($expression) && ($expression['no_log'] ?? false) === true) {
                    $this->context->markAsHidden($key);
                }

                $this->context->log('SUCCESS', 'Request', "Extra поле вычислено: {$key}", [
                    'value' => $resolved
                ]);
            } catch (\Throwable $e) {
                $this->context->log('ERROR', 'Request', "Ошибка вычисления extra поля: {$key}", [
                    'error' => $e->getMessage()
                ]);

                // Пробрасываем error_context из ExecutionException (если он)
                $errorContext = ($e instanceof ExecutionException)
                    ? $e->getErrorContext()
                    : null;

                $this->context->setError(
                    "request.extra.{$key}",
                    "Ошибка вычисления extra поля '{$key}'",
                    $e->getMessage(),
                    null,
                    $errorContext
                );
                break;
            }
        }
    }

    /**
     * Разрешает выражение extra любого типа
     * 
     * Порядок проверки:
     * 1. method-выражение → вызов через Method Resolver
     * 2. Массив без спец-ключей → resolveParams (рекурсивный резолв field:)
     * 3. Всё остальное → resolve (field:, шаблон, литерал)
     * 
     * @param mixed $expression Выражение (ветка check_true/check_false или значение)
     * @return mixed Разрешённое значение
     */
    private function resolveExtraExpression($expression)
    {
        // Method-выражение: ['method' => ..., 'class' => ..., 'params' => ...]
        if (is_array($expression) && isset($expression['method'])) {
            return $this->methodResolver->execute($expression);
        }

        // ДОБАВЛЕНО: массив без спец-ключей — резолвим СОДЕРЖИМОЕ рекурсивно.
        // Кейс: additional_fields = ['unified_client_id_decrypt' => 'field:client_string']
        if (is_array($expression)) {
            return $this->fieldResolver->resolveParams($expression);
        }

        // Обычное выражение: field:, {{ }}, литерал, число, null
        return $this->fieldResolver->resolve($expression);
    }

    /**
     * Разрешает значение check_true / check_false
     * 
     * @param mixed $value Значение для разрешения
     * @return mixed Разрешённое значение
     */
    private function resolveCheckValue($value)
    {
        if ($value === null) {
            return null;
        }

        return $this->fieldResolver->resolve($value);
    }

    /**
     * Выполняет query запросы
     * 
     * Каждый query может иметь conditions — запрос выполняется только если условия истинны.
     * 
     * @param array $queryConfig Конфигурация query запросов
     * @return void
     */
    private function executeQuery(array $queryConfig): void
    {
        $this->context->log('INFO', 'Request', 'Начало выполнения query запросов', [
            'queries' => array_keys($queryConfig)
        ]);

        foreach ($queryConfig as $queryName => $queryConfigItem) {
            $this->context->log('INFO', 'Request', "Обработка query: {$queryName}", [
                'config' => $queryConfigItem
            ]);

            try {
                // Проверяем условия выполнения запроса
                if (isset($queryConfigItem['conditions']) && !empty($queryConfigItem['conditions'])) {
                    $conditionResult = $this->conditionResolver->evaluate($queryConfigItem['conditions']);

                    $this->context->log('INFO', 'Request', "Условие для query {$queryName}", [
                        'result' => $conditionResult
                    ]);

                    if (!$conditionResult) {
                        $this->context->set($queryName, []);
                        $this->context->log('INFO', 'Request', "Query {$queryName} пропущен (условие false)", [
                            'set_value' => []
                        ]);
                        continue;
                    }
                }

                // Выполняем запрос через Method Resolver
                $result = $this->methodResolver->execute($queryConfigItem);

                // Записываем результат в контекст
                $this->context->set($queryName, $result);

                $this->context->log('SUCCESS', 'Request', "Query выполнен: {$queryName}", [
                    'result' => $result
                ]);
            } catch (\Throwable $e) {
                $this->context->log('ERROR', 'Request', "Ошибка выполнения query: {$queryName}", [
                    'error' => $e->getMessage(),
                    'config' => $queryConfigItem
                ]);

                $errorContext = ($e instanceof ExecutionException)
                    ? $e->getErrorContext()
                    : null;

                $this->context->setError(
                    "request.query.{$queryName}",
                    "Ошибка выполнения запроса '{$queryName}'",
                    $e->getMessage(),
                    null,
                    $errorContext
                );

                break;
            }
        }
    }

    /**
     * Выполняет блок static: статичные данные для запроса
     * 
     * Поддерживает три формы значения:
     * - Сырой массив/скаляр → записывается как есть
     * - Строка '\Class::method' → вызов статического метода
     * - Массив с method/class/params → через Method resolver
     * 
     * ВАЖНО: класс нельзя назвать Static (зарезервировано),
     * используйте например \DesktopManager\StaticData
     * 
     * @param array $staticConfig Конфигурация static
     * @return void
     */
    private function executeStatic(array $staticConfig): void
    {
        $this->context->log('INFO', 'Request', 'Начало загрузки static', [
            'keys' => array_keys($staticConfig)
        ]);

        foreach ($staticConfig as $key => $value) {
            try {
                if (is_array($value) && isset($value['method'])) {
                    // Полная форма: method/class/params
                    $resolved = $this->methodResolver->execute($value);
                } elseif (is_string($value) && strpos($value, '::') !== false) {
                    // Короткая форма: '\Class::method'
                    [$class, $method] = explode('::', $value, 2);
                    $resolved = $this->methodResolver->execute([
                        'method' => $method,
                        'class' => $class
                    ]);
                } else {
                    // Сырые данные
                    $resolved = $value;
                }

                $this->context->set($key, $resolved);
                $this->context->markAsStatic($key);

                $this->context->log('SUCCESS', 'Request', "Static загружен: {$key}");
            } catch (\Throwable $e) {
                $this->context->log('ERROR', 'Request', "Ошибка загрузки static: {$key}", [
                    'error' => $e->getMessage()
                ]);

                $errorContext = ($e instanceof ExecutionException)
                    ? $e->getErrorContext()
                    : null;

                $this->context->setError(
                    "request.static.{$key}",
                    "Ошибка загрузки статичных данных '{$key}'",
                    $e->getMessage(),
                    null,
                    $errorContext
                );
                break;
            }
        }
    }

    /**
     * Выполняет блок curl через отдельный экзекьютор
     * 
     * @param array $curlConfig Конфигурация curl
     * @return void
     */
    private function executeCurl(array $curlConfig): void
    {
        $curlExecutor = new Curl(
            $this->context,
            $this->fieldResolver,
            $this->conditionResolver
        );

        $curlExecutor->execute($curlConfig, 'request.curl');
    }


    /**
     * Определяет порядок шагов для блока request.
     * 
     * Если задан request_logic — используется он.
     * Иначе — дефолтный порядок: static → extra → query → curl
     * 
     * main всегда в начале (обрабатывается отдельно до шагов).
     * 
     * @param array $config Конфигурация request
     * @return array Список шагов в порядке выполнения
     */
    private function resolveRequestOrder(array $config): array
    {
        $defaultOrder = ['static', 'extra', 'query', 'curl'];

        if (isset($config['request_logic']) && is_array($config['request_logic'])) {
            $logic = $config['request_logic'];

            // Фильтруем только валидные шаги (static/extra/query/curl)
            $validSteps = ['static', 'extra', 'query', 'curl'];
            $logic = array_values(array_intersect($logic, $validSteps));

            $this->context->log('INFO', 'Request', 'Используется кастомный request_logic', [
                'order' => $logic
            ]);

            return $logic;
        }

        return $defaultOrder;
    }
}
