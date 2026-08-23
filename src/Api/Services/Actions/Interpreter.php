<?php

namespace Api\Services\Actions;

use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Resolver\Method;
use Api\Services\Actions\Executor\Request;
use Api\Services\Actions\Executor\Mapping;
use Api\Services\Actions\Executor\Execute;
use Api\Services\Actions\Executor\Compose;
use Api\Services\Actions\Exception\Config as ConfigException;
use Api\Services\Actions\Exception\Execution as ExecutionException;
use Api\Services\ResponseHandler;
use Api\Services\Logger;

/**
 * Class Interpreter
 * 
 * Главный оркестратор интерпретатора конфигурации.
 * Принимает конфиг действия, endpoint и параметры, выполняет шаги
 * action_logic (request → mapping → execute → compose) и возвращает
 * готовый ResponseHandler.
 * 
 * Режимы:
 * - Одиночный: данные обрабатываются один раз
 * - Итерационный: request.array — цикл по массиву, response накапливается,
 *   итерационные ошибки (partial) не прерывают цикл
 * 
 * Параметры run(..., $params):
 * - Доступны во всех блоках через field:params.xxx / {{params.xxx}}
 * - Переживают сброс итераций (resetForIteration)
 * - Видны в логе buildLogRequest().params
 * 
 * Логирование:
 * - setSection: 'Interpreter:DesktopManager' (endpoint в CamelCase)
 * - setMethod: 'CreateLead' (action в CamelCase)
 * - setRequest: data + params + computed (всегда) + error (при ошибках)
 * 
 * @package Api\Services\Actions
 */
class Interpreter
{
    /**
     * Массив конфигураций всех действий (эндпоинтов)
     * 
     * @var array
     */
    private array $config;

    /**
     * Endpoint (модуль API), например 'desktop_manager'
     * 
     * @var string
     */
    private string $endpoint = '';

    /**
     * Имя текущего действия
     * 
     * @var string
     */
    private string $currentActionName = '';

    /**
     * Исходные данные запроса (для логирования)
     * 
     * @var array
     */
    private array $rawRequestData = [];

    /**
     * Параметры запуска (4-й аргумент run)
     * 
     * @var array
     */
    private array $runParams = [];

    /**
     * Флаг тестового режима
     * 
     * @var bool
     */
    private bool $testMode = false;

    /**
     * Данные тестового режима
     * 
     * @var array|null
     */
    private ?array $testInputData = null;

    /**
     * Контекст выполнения
     * 
     * @var Context|null
     */
    private ?Context $context = null;

    /**
     * Резолвер полей
     * 
     * @var Field|null
     */
    private ?Field $fieldResolver = null;

    /**
     * Вычислитель условий
     * 
     * @var Condition|null
     */
    private ?Condition $conditionResolver = null;

    /**
     * Исполнитель методов
     * 
     * @var Method|null
     */
    private ?Method $methodResolver = null;

    /**
     * Экзекьютор request (static, extra, query, curl)
     * 
     * @var Request|null
     */
    private ?Request $requestExecutor = null;

    /**
     * Экзекьютор mapping (одиночный + именованные списочные)
     * 
     * @var Mapping|null
     */
    private ?Mapping $mappingExecutor = null;

    /**
     * Экзекьютор execute (if/elseif/else, actions, curl-actions)
     * 
     * @var Execute|null
     */
    private ?Execute $executeExecutor = null;

    /**
     * Экзекьютор compose (финальная сборка ответа)
     * 
     * @var Compose|null
     */
    private ?Compose $composeExecutor = null;

    /**
     * Логгер продакшена
     * 
     * @var Logger|null
     */
    private ?Logger $logger = null;

    /**
     * Флаг: глобальная ошибка уже залогирована в текущем run().
     * Защищает logResult от дублирования критической записи.
     * 
     * @var bool
     */
    private bool $globalErrorLogged = false;

    /**
     * Ключ массива итераций текущего run() (для log_response auto).
     * null в одиночном режиме.
     * 
     * @var string|null
     */
    private ?string $currentArrayKey = null;

    /**
     * ResponseHandler последнего run() — для записи response в лог
     * 
     * @var ResponseHandler|null
     */
    private ?ResponseHandler $lastResponseHandler = null;

    /**
     * Interpreter constructor.
     * 
     * @param array $config Массив конфигураций всех действий
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Включает тестовый режим (данные вместо php://input)
     * 
     * @param array $inputData Данные для обработки
     * @return self
     */
    public function setTestMode(array $inputData): self
    {
        $this->testMode = true;
        $this->testInputData = $inputData;
        return $this;
    }

    /**
     * Устанавливает логгер продакшена
     * 
     * @param Logger $logger Логгер
     * @return self
     */
    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Выполняет действие по имени
     * 
     * @param string $actionName Имя действия (create_lead)
     * @param string $endpoint Endpoint (desktop_manager)
     * @param array|null $inputData Данные (приоритет над testMode)
     * @param array|null $params Параметры: field:params.xxx во всех блоках
     * @return ResponseHandler
     */
    public function run(string $actionName, string $endpoint = '', ?array $inputData = null, ?array $params = null): ResponseHandler
    {
        $this->currentActionName = $actionName;
        $this->endpoint = $endpoint;
        $this->runParams = $params ?? [];
        $this->globalErrorLogged = false;
        $this->currentArrayKey = null;

        $responseHandler = new ResponseHandler();
        $this->lastResponseHandler = $responseHandler;

        try {
            // 1. Валидация наличия действия
            if (!isset($this->config[$actionName])) {
                throw new ConfigException(
                    "Действие '{$actionName}' не найдено в конфигурации",
                    $actionName
                );
            }

            $actionConfig = $this->config[$actionName];

            // 2. Валидация структуры конфига
            $this->validateConfig($actionName, $actionConfig);

            // 3. Получение данных: явные → testMode → php://input/суперглобальные
            if ($inputData !== null) {
                $rawData = $inputData;
            } elseif ($this->testMode && $this->testInputData !== null) {
                $rawData = $this->testInputData;
            } else {
                $rawData = $this->getInputData($actionConfig);
            }

            $this->rawRequestData = $rawData;

            // 4. Режим: одиночный или итерационный
            $arrayKey = $actionConfig['request']['array'] ?? null;
            $this->currentArrayKey = $arrayKey;

            if ($arrayKey !== null) {
                $this->runIterative($actionName, $actionConfig, $rawData, $arrayKey);
            } else {
                $this->runSingle($actionConfig, $rawData);
            }

            // 5. Итог: ошибка контекста или response
            if ($this->context->hasError()) {
                $error = $this->context->error;
                $responseHandler->setError(
                    $error['message'],
                    $error['detailed_message'] . ' | Config path: ' . $error['config_path']
                );
            } else {
                $responseHandler->setResponse($this->context->response ?? []);
            }
        } catch (ConfigException $e) {
            $responseHandler->setError(
                'Ошибка конфигурации: ' . $e->getMessage(),
                'Config path: ' . $e->getConfigPath()
            );
            $this->logGlobalError($e->getMessage());
        } catch (ExecutionException $e) {
            $responseHandler->setError(
                'Ошибка выполнения: ' . $e->getMessage(),
                'Config path: ' . $e->getConfigPath()
            );
            $this->logGlobalError($e->getMessage());
        } catch (\Throwable $e) {
            $responseHandler->setError(
                'Непредвиденная ошибка: ' . $e->getMessage(),
                $e->getFile() . ':' . $e->getLine()
            );
            $this->logGlobalError($e->getMessage());
        }

        // 6. Логирование результата
        // - Критическая ошибка: уже записана в logGlobalError — всегда,
        //   независимо от logging, и не дублируется здесь
        // - logging: false глушит ТОЛЬКО чистый SUCCESS.
        //   Любой ERROR-исход пишется всегда:
        //   * status ERROR (ошибка контекста: query/extra/execute)
        //   * ошибки итераций в partial (даже при общем SUCCESS)
        if (!$this->globalErrorLogged) {
            $actionConfig = $this->config[$actionName] ?? [];
            $shouldLog = $actionConfig['logging'] ?? true;

            $hasErrorOutcome =
                $responseHandler->status === 'ERROR'
                || ($this->context !== null && !empty($this->context->iterationErrors));

            if ($shouldLog || $hasErrorOutcome) {
                $this->logResult($responseHandler);
            }
        }

        return $responseHandler;
    }

    /**
     * Валидирует структуру конфига действия
     * 
     * @param string $actionName Имя действия
     * @param array $config Конфигурация действия
     * @throws ConfigException Если конфиг невалидный
     * @return void
     */
    private function validateConfig(string $actionName, array $config): void
    {
        if (!isset($config['request'])) {
            throw new ConfigException(
                "Отсутствует обязательный блок 'request'",
                "{$actionName}.request"
            );
        }

        if (!isset($config['request']['main'])) {
            throw new ConfigException(
                "Отсутствует обязательный параметр 'main' в блоке request",
                "{$actionName}.request.main"
            );
        }

        if (!isset($config['action_logic']) || empty($config['action_logic'])) {
            throw new ConfigException(
                "Отсутствует обязательный блок 'action_logic'",
                "{$actionName}.action_logic"
            );
        }

        foreach ($config['action_logic'] as $step) {
            if ($step !== 'request' && !isset($config[$step])) {
                throw new ConfigException(
                    "Шаг '{$step}' указан в action_logic, но отсутствует в конфигурации",
                    "{$actionName}.action_logic.{$step}"
                );
            }
        }
    }

    /**
     * Инициализирует контекст и все компоненты
     * 
     * ВАЖНО: setParams вызывается ПОСЛЕ создания Context,
     * иначе параметры сотрутся при пересоздании контекста.
     * 
     * @param array $rawData Исходные данные запроса
     * @return void
     */
    private function initializeComponents(array $rawData): void
    {
        $this->context = new Context($rawData);

        // params доступны во всех блоках и переживают сброс итераций
        if (!empty($this->runParams)) {
            $this->context->setParams($this->runParams);
        }

        $this->fieldResolver = new Field($this->context);
        $this->conditionResolver = new Condition($this->context, $this->fieldResolver);
        $this->methodResolver = new Method($this->context, $this->fieldResolver);

        $this->requestExecutor = new Request(
            $this->context,
            $this->fieldResolver,
            $this->conditionResolver,
            $this->methodResolver
        );

        $this->mappingExecutor = new Mapping(
            $this->context,
            $this->fieldResolver,
            $this->conditionResolver
        );

        $this->executeExecutor = new Execute(
            $this->context,
            $this->fieldResolver,
            $this->conditionResolver,
            $this->methodResolver
        );

        $this->composeExecutor = new Compose(
            $this->context,
            $this->fieldResolver
        );
    }

    /**
     * Получает входные данные (продакшен)
     * 
     * @param array $actionConfig Конфигурация действия
     * @return array
     */
    private function getInputData(array $actionConfig): array
    {
        $rawInput = file_get_contents("php://input");

        if (!empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                return $jsonData;
            }
        }

        $source = strtolower($actionConfig['request']['main']);

        switch ($source) {
            case 'post':
                return $_POST;
            case 'get':
                return $_GET;
            case 'request':
                return $_REQUEST;
            default:
                return $_REQUEST;
        }
    }

    /**
     * Одиночный режим выполнения
     * 
     * @param array $actionConfig Конфигурация действия
     * @param array $rawData Данные запроса
     * @return void
     */
    private function runSingle(array $actionConfig, array $rawData): void
    {
        $this->initializeComponents($rawData);

        foreach ($actionConfig['action_logic'] as $step) {
            if ($this->context->hasError()) {
                break;
            }

            $this->executeStep($step, $actionConfig, $rawData);
        }
    }

    /**
     * Итерационный режим выполнения
     * 
     * Итерационные ошибки (partial) записываются в response[i]
     * и НЕ прерывают цикл; all_or_nothing прерывает всё.
     * 
     * @param string $actionName Имя действия
     * @param array $actionConfig Конфигурация действия
     * @param array $rawData Данные запроса
     * @param string $arrayKey Ключ массива итераций
     * @return void
     */
    private function runIterative(string $actionName, array $actionConfig, array $rawData, string $arrayKey): void
    {
        $this->initializeComponents($rawData);

        if (!isset($rawData[$arrayKey]) || !is_array($rawData[$arrayKey])) {
            $this->context->setError(
                "{$actionName}.request.array",
                "Массив '{$arrayKey}' не найден в данных запроса",
                "Array key '{$arrayKey}' not found or is not an array"
            );
            return;
        }

        $iterations = $rawData[$arrayKey];
        $this->context->iterationTotal = count($iterations);

        // Общие данные (вне массива) — в контекст
        foreach ($rawData as $key => $value) {
            if ($key !== $arrayKey) {
                $this->context->set($key, $value);
            }
        }

        $transactionMode = $actionConfig['transaction']['mode'] ?? 'partial';

        foreach ($iterations as $index => $iterationData) {
            // Сброс состояния итерации (params сохраняются внутри Context)
            $this->context->resetForIteration($iterationData, $index);

            foreach ($actionConfig['action_logic'] as $step) {
                if ($this->context->hasError()) {
                    break;
                }

                $this->executeStep($step, $actionConfig, $iterationData);
            }

            // Снимок вычисленных данных итерации (для лога).
            // snapshot_mode определяет, какие итерации сохраняются:
            // 'all' — все (по умолчанию, обратная совместимость)
            // 'errors_only' — только с ошибкой
            // 'first_last' — первая + последняя + ошибки
            $snapshotMode = $actionConfig['transaction']['snapshot_mode'] ?? 'all';
            $isFirst = ($index === 0);
            $isLast = ($index === count($iterations) - 1);
            $hasError = $this->context->hasError();

            $shouldSnapshot = match ($snapshotMode) {
                'errors_only' => $hasError,
                'first_last' => $isFirst || $isLast || $hasError,
                default => true,
            };

            if ($shouldSnapshot) {
                $this->context->snapshotIteration($index);
            }

            // Обработка ошибки итерации
            if ($this->context->hasError()) {
                $error = $this->context->error;

                $this->context->recordIterationError($index);

                $errorEntry = [
                    'status' => 'ERROR',
                    'iteration' => $index,
                    'message' => $error['message'],
                    'detailed_message' => $error['detailed_message'],
                    'config_path' => $error['config_path']
                ];

                if (!empty($error['error_context'])) {
                    $errorEntry['error_context'] = $error['error_context'];
                }

                // ДОБАВЛЕНО: свои поля в error-записи (опционально)
                if (isset($actionConfig['error_response']) && is_array($actionConfig['error_response'])) {
                    foreach ($actionConfig['error_response'] as $key => $expression) {
                        $errorEntry[$key] = $this->fieldResolver->resolve($expression);
                    }
                }

                $this->context->addResponse($errorEntry);

                if ($transactionMode === 'all_or_nothing') {
                    break;
                }

                // partial: сбрасываем ошибку, идём дальше
                $this->context->error = null;
            }
        }
    }

    /**
     * Выполняет один шаг action_logic
     * 
     * @param string $step Имя шага (request/mapping/execute/compose)
     * @param array $actionConfig Конфигурация действия
     * @param array $data Данные текущей итерации (или весь запрос)
     * @return void
     */
    private function executeStep(string $step, array $actionConfig, array $data): void
    {
        switch ($step) {
            case 'request':
                // array убираем — итерациями управляет Interpreter
                $requestConfig = $actionConfig['request'];
                unset($requestConfig['array']);
                $this->requestExecutor->execute($requestConfig, $data);
                break;

            case 'mapping':
                if (isset($actionConfig['mapping'])) {
                    $this->mappingExecutor->execute($actionConfig['mapping']);
                }
                break;

            case 'execute':
                if (isset($actionConfig['execute'])) {
                    $mergeResponse = (bool) ($actionConfig['merge_response'] ?? false);
                    $this->executeExecutor->execute($actionConfig['execute'], $mergeResponse);
                }
                break;

            case 'compose':
                if (isset($actionConfig['compose'])) {
                    $this->composeExecutor->execute($actionConfig['compose']);
                }
                break;
        }
    }

    /**
     * Конвертирует snake_case в CamelCase
     * 
     * 'desktop_manager' → 'DesktopManager'
     * 'create_lead' → 'CreateLead'
     * 
     * @param string $snake Строка в snake_case
     * @return string
     */
    private function snakeToCamel(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }

    /**
     * Имя секции для логгера: Interpreter:EndpointCamel
     * 
     * @return string
     */
    private function getLogSection(): string
    {
        $endpointCamel = $this->snakeToCamel($this->endpoint);

        return $endpointCamel !== ''
            ? "Interpreter:{$endpointCamel}"
            : 'Interpreter';
    }

    /**
     * Имя метода для логгера: ActionCamel
     * 
     * @return string
     */
    private function getLogMethod(): string
    {
        return $this->snakeToCamel($this->currentActionName);
    }

    /**
     * Собирает payload «строки запроса»: что успели собрать до ошибки.
     * data + params + computed. Ошибка и response живут в buildLogResponse().
     * 
     * @return array
     */
    public function buildLogRequest(): array
    {
        $log = [
            'data' => $this->rawRequestData
        ];

        if (!empty($this->runParams)) {
            $log['params'] = $this->runParams;
        }

        $context = $this->context;

        if ($context !== null) {
            if (!empty($context->iterationSnapshots)) {
                $log['computed'] = [];
                foreach ($context->iterationSnapshots as $index => $snapshot) {
                    $log['computed']['iteration_' . $index] = $snapshot;
                }
            } else {
                $computed = $context->getComputedData();
                if (!empty($computed)) {
                    $log['computed'] = $computed;
                }
            }
        }

        $maxSize = (int) ($this->config[$this->currentActionName]['max_log_size'] ?? 65535);

        return $this->limitPayload($log, $maxSize);
    }

    /**
     * Собирает payload «строки ответа»: что получил клиент / сама ошибка.
     * 
     * Порядок:
     * 1. Одиночная ошибка контекста — объект ошибки с error_context;
     * 2. Итерации — массив записей response (успешные + ERROR-записи);
     *    одиночный успех — response только при log_response;
     * 3. Глобальная ошибка (ConfigException и т.п.) — status + message;
     * 4. Одиночный успех без log_response — компактно {"status": "SUCCESS"}.
     * 
     * @return array
     */
    public function buildLogResponse(): array
    {
        $context = $this->context;
        $actionConfig = $this->config[$this->currentActionName] ?? [];
        $maxSize = (int) ($actionConfig['max_log_size'] ?? 65535);

        // 1. Одиночная ошибка — сама ошибка с error_context
        if ($context !== null && $context->hasError()) {
            return $this->limitPayload(
                $this->buildErrorEntry($context->error, $context->iterationIndex),
                $maxSize
            );
        }

        // 2. Что ушло клиенту
        $logResponseFlag = $actionConfig['log_response'] ?? null;
        $logResponse = ($logResponseFlag === null)
            ? ($this->currentArrayKey !== null)
            : (bool) $logResponseFlag;

        if ($context !== null && $logResponse && !empty($context->response)) {
            return $this->limitPayload($context->response, $maxSize);
        }

        // 3. Глобальная ошибка (ConfigException и т.п.)
        if (
            $this->lastResponseHandler !== null
            && $this->lastResponseHandler->status === 'ERROR'
        ) {
            return [
                'status'  => 'ERROR',
                'message' => $this->lastResponseHandler->message,
            ];
        }

        // 4. Одиночный успех без log_response — компактно
        return [
            'status' => $this->lastResponseHandler !== null
                ? $this->lastResponseHandler->status
                : 'SUCCESS'
        ];
    }

    /**
     * Формирует объект ошибки для строки ответа
     * 
     * @param array $error Ошибка контекста
     * @param int|null $iteration Индекс итерации
     * @return array
     */
    private function buildErrorEntry(array $error, ?int $iteration): array
    {
        $entry = [
            'iteration'        => $iteration,
            'config_path'      => $error['config_path'],
            'message'          => $error['message'],
            'detailed_message' => $error['detailed_message'],
        ];

        if (!empty($error['error_context'])) {
            $entry['error_context'] = $error['error_context'];
        }

        return $entry;
    }

    /**
     * Ограничивает payload размером max_log_size.
     * 
     * Для объекта (request-строка): убрать computed → обрезать data.
     * Для списка (response итераций): оставить первую, последнюю и ERROR-записи.
     * 
     * @param array $payload Payload
     * @param int $maxSize Лимит в байтах
     * @return array Ограниченный payload
     */
    private function limitPayload(array $payload, int $maxSize): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) <= $maxSize) {
            return $payload;
        }

        $originalSize = strlen($encoded);

        // Длинный список (response итераций): первая + последняя + ERROR
        if (array_is_list($payload)) {
            $kept = [];
            $lastIndex = count($payload) - 1;

            foreach ($payload as $index => $entry) {
                $isError = is_array($entry) && ($entry['status'] ?? '') === 'ERROR';

                if ($index === 0 || $index === $lastIndex || $isError) {
                    $kept[] = $entry;
                }
            }

            return $kept;
        }

        $payload['_truncated'] = ['original_size' => $originalSize, 'max_size' => $maxSize];

        // 1. Убрать computed (request payload)
        if (isset($payload['computed'])) {
            unset($payload['computed']);
            $payload['_truncated']['reason'] = 'computed_removed';
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) <= $maxSize) {
                return $payload;
            }
        }

        // 2. Обрезать data
        if (isset($payload['data'])) {
            $payload['data'] = $this->truncateValue($payload['data'], (int) ($maxSize * 0.5));
            $payload['_truncated']['reason'] = 'data_truncated';
        }

        return $payload;
    }

    /**
     * Рекурсивно обрезает значение до заданного размера JSON.
     * Массивы: оставляем структуру, но обрезаем длинные строки и глубокие вложения.
     * Скаляры: длинные строки обрезаем.
     * 
     * @param mixed $value Значение
     * @param int $maxBytes Целевой размер
     * @return mixed Обрезанное значение
     */
    private function truncateValue($value, int $maxBytes)
    {
        if (is_string($value)) {
            if (strlen($value) > $maxBytes) {
                return substr($value, 0, $maxBytes) . '... [truncated, original length: ' . strlen($value) . ']';
            }
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->truncateValue($item, (int)($maxBytes / max(count($value), 1)));
            }
            return $result;
        }

        return $value;
    }

    /**
     * Логирует ГЛОБАЛЬНУЮ ошибку (catch-блок).
     * 
     * Критические ошибки пишутся ВСЕГДА, независимо от флага logging:
     * logging: false глушит только штатную работу и ошибки итераций.
     * 
     * @param string $message Текст ошибки
     * @return void
     */
    private function logGlobalError(string $message): void
    {
        $this->globalErrorLogged = true;

        $this->emitLog('ERROR', $message);
    }

    /**
     * Логирует результат выполнения (SUCCESS или ERROR контекста).
     * Вызывается только для штатных исходов; критика идёт через logGlobalError.
     * 
     * @param ResponseHandler $responseHandler Обработчик ответа
     * @return void
     */
    private function logResult(ResponseHandler $responseHandler): void
    {
        $this->emitLog(
            $responseHandler->status,
            $responseHandler->message ?: 'OK'
        );
    }

    /**
     * Единственная точка записи в Logger.
     * 
     * Строка запроса  ← setRequest(json buildLogRequest)
     * Строка ответа   ← setInfo(json buildLogResponse)
     *   (корпоративный Logger заполняет колонку ответа из info;
     *    человекочитаемый message при этом живёт внутри JSON)
     * 
     * @param string $status Статус (SUCCESS / ERROR)
     * @param string $info Сообщение (для совместимости сигнатуры)
     * @return void
     */
    protected function emitLog(string $status, string $info): void
    {
        if ($this->logger === null) {
            return;
        }

        $requestPayload  = $this->buildLogRequest();
        $responsePayload = $this->buildLogResponse();

        $this->logger->setSection($this->getLogSection());
        $this->logger->setMethod($this->getLogMethod());

        // Строка запроса: data + params + computed
        $this->logger->setRequest(json_encode(
            $requestPayload,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));

        // Строка ответа: ошибка с error_context / response клиента.
        // Корпоративный Logger пишет в колонку ответа именно setInfo.
        $this->logger->setInfo(json_encode(
            $responsePayload,
            JSON_UNESCAPED_UNICODE
        ));

        $this->logger->setStatus($status);
        $this->logger->log();
    }

    /**
     * Возвращает контекст (для тестов и отладки)
     * 
     * @return Context|null
     */
    public function getContext(): ?Context
    {
        return $this->context;
    }
}
