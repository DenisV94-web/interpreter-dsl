<?php

namespace Api\Services\Actions\Executor;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\Services\Actions\Resolver\Method;
use Api\Services\Actions\Exception\Execution as ExecutionException;

/**
 * Class Execute
 * 
 * Экзекьютор блока execute конфигурации интерпретатора.
 * Обрабатывает цепочку if/elseif/else условий и выполняет действия.
 * 
 * Что делает:
 * 1. Обходит массив execute (цепочка if/elseif/else)
 * 2. Для каждого блока проверяет условие (filter или method)
 * 3. Если условие истинно — выполняет actions и останавливается
 * 4. Actions могут быть: skip, method call с conditions
 * 5. Собирает response из блоков response
 * 
 * Формат конфигурации:
 * [
 *     [
 *         'check' => 'if',
 *         'filter' => [...],        // или 'method' + 'class' + 'params'
 *         'actions' => [...]
 *     ],
 *     [
 *         'check' => 'elseif',
 *         'filter' => [...],
 *         'actions' => [...]
 *     ],
 *     [
 *         'check' => 'else',
 *         'actions' => [...]
 *     ]
 * ]
 * 
 * Формат action:
 * [
 *     'skip' => true,                          // Пропустить итерацию
 *     // ИЛИ
 *     'method' => 'update',
 *     'class' => \Api\Lead\Main::class,
 *     'params' => [...],
 *     'conditions' => [...],                   // Опционально
 *     'response' => ['lead_id' => 'result']   // Опционально
 * ]
 * 
 * @package Api\Services\Actions\Executor
 */
class Execute
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
     * Конфигурация транзакций
     * 
     * @var array
     */
    private array $transactionConfig;

    /**
     * Execute constructor.
     * 
     * @param Context $context Контекст выполнения
     * @param Field $fieldResolver Резолвер полей
     * @param Condition $conditionResolver Вычислитель условий
     * @param Method $methodResolver Исполнитель методов
     * @param array $transactionConfig Конфигурация транзакций
     */
    public function __construct(
        Context $context,
        Field $fieldResolver,
        Condition $conditionResolver,
        Method $methodResolver,
        array $transactionConfig = []
    ) {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
        $this->conditionResolver = $conditionResolver;
        $this->methodResolver = $methodResolver;
        $this->transactionConfig = $transactionConfig;
    }

    /**
     * Выполняет обработку блока execute
     * 
     * @param array $config Конфигурация блока execute (массив if/elseif/else блоков)
     * @return array Собранный response
     */
    public function execute(array $config): array
    {
        $this->context->log('INFO', 'Execute', '=== НАЧАЛО ОБРАБОТКИ EXECUTE ===', [
            'blocks_count' => count($config),
            'iteration' => $this->context->iterationIndex
        ]);

        // Проверяем что это массив блоков (не ассоциативный)
        if ($this->isAssociativeArray($config)) {
            // Если передан один блок, оборачиваем в массив
            $config = [$config];
        }

        // Флаг: был ли выполнен какой-то блок в цепочке
        $executed = false;

        foreach ($config as $index => $block) {
            $check = $block['check'] ?? 'if';

            $this->context->log('INFO', 'Execute', "Обработка блока #{$index} (check: {$check})", [
                'block' => $block
            ]);

            // Если предыдущий блок уже выполнен — пропускаем остальные (логика if/elseif/else)
            if ($executed) {
                $this->context->log('INFO', 'Execute', "Блок #{$index} пропущен (предыдущий блок выполнен)");
                continue;
            }

            // Определяем нужно ли проверять условие
            $shouldExecute = false;

            if ($check === 'else') {
                // else всегда выполняется если предыдущие не сработали
                $shouldExecute = true;
                $this->context->log('INFO', 'Execute', "Блок else — выполняем без условий");
            } else {
                // if / elseif — проверяем условие
                $shouldExecute = $this->evaluateBlockCondition($block);
                $this->context->log('INFO', 'Execute', "Результат условия для блока #{$index}", [
                    'check' => $check,
                    'result' => $shouldExecute
                ]);
            }

            if ($shouldExecute) {
                $executed = true;

                $this->context->log('SUCCESS', 'Execute', "Условие блока #{$index} истинно, выполняем actions");

                // Выполняем действия блока
                $this->executeActions($block['actions'] ?? []);

                // Если произошла ошибка — прерываем
                if ($this->context->hasError()) {
                    $this->context->log('ERROR', 'Execute', "Ошибка в блоке #{$index}, прерывание execute");
                    break;
                }
            }
        }

        // Если ни один блок не выполнен
        if (!$executed) {
            $this->context->log('INFO', 'Execute', 'Ни один блок не был выполнен');
        }

        $this->context->log('SUCCESS', 'Execute', '=== ОБРАБОТКА EXECUTE ЗАВЕРШЕНА ===', [
            'response' => $this->context->response
        ]);

        return $this->context->response;
    }

    /**
     * Вычисляет условие блока (filter или method)
     * 
     * @param array $block Конфигурация блока
     * @return bool Результат условия
     */
    private function evaluateBlockCondition(array $block): bool
    {
        // Если есть filter — используем Condition Resolver
        if (isset($block['filter']) && !empty($block['filter'])) {
            return $this->conditionResolver->evaluate($block['filter']);
        }

        // Если есть method — вызываем метод и проверяем результат
        if (isset($block['method'])) {
            return $this->evaluateMethodCondition($block);
        }

        // Если нет ни filter ни method — считаем условие истинным
        $this->context->log('INFO', 'Execute', 'Блок без условий, считаем истинным');
        return true;
    }

    /**
     * Вычисляет условие через метод класса
     * 
     * @param array $block Конфигурация блока
     * @return bool Результат
     */
    private function evaluateMethodCondition(array $block): bool
    {
        $this->context->log('INFO', 'Execute', 'Вычисление условия через метод', [
            'method' => $block['method'],
            'class' => $block['class'] ?? null
        ]);

        try {
            // Разрешаем параметры
            $params = $this->fieldResolver->resolveParams($block['params'] ?? []);

            $className = $block['class'] ?? null;
            $methodName = $block['method'];

            if ($className === null) {
                $this->context->log('ERROR', 'Execute', 'Для метода условия не указан класс');
                return false;
            }

            if (!class_exists($className)) {
                $this->context->log('ERROR', 'Execute', "Класс не найден: {$className}");
                return false;
            }

            if (!method_exists($className, $methodName)) {
                $this->context->log('ERROR', 'Execute', "Метод не найден: {$className}::{$methodName}");
                return false;
            }

            // Определяем статичность
            $reflection = new \ReflectionMethod($className, $methodName);
            $isStatic = $reflection->isStatic();

            if ($isStatic) {
                $result = $className::$methodName(...$params);
            } else {
                $instance = new $className();
                $result = $instance->$methodName(...$params);
            }

            $this->context->log('SUCCESS', 'Execute', 'Метод условия выполнен', [
                'class' => $className,
                'method' => $methodName,
                'result' => $result
            ]);

            return (bool) $result;
        } catch (\Throwable $e) {
            $this->context->log('ERROR', 'Execute', 'Ошибка вычисления метода условия', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Выполняет массив действий
     * 
     * @param array $actions Массив действий
     * @return void
     */
    private function executeActions(array $actions): void
    {
        $this->context->log('INFO', 'Execute', 'Начало выполнения actions', [
            'actions_count' => count($actions)
        ]);

        foreach ($actions as $actionIndex => $action) {
            $this->context->log('INFO', 'Execute', "Обработка action #{$actionIndex}", [
                'action' => $action
            ]);

            // Проверяем skip
            if (isset($action['skip']) && $action['skip'] === true) {
                $this->context->log('INFO', 'Execute', "Action #{$actionIndex}: SKIP");

                // Записываем в response информацию о пропуске
                $this->context->addResponse([
                    'status' => 'SKIPPED',
                    'iteration' => $this->context->iterationIndex
                ]);

                // Прерываем выполнение остальных actions в этом блоке
                return;
            }

            // Проверяем conditions внутри action
            if (isset($action['conditions']) && !empty($action['conditions'])) {
                $conditionResult = $this->conditionResolver->evaluate($action['conditions']);

                $this->context->log('INFO', 'Execute', "Conditions для action #{$actionIndex}", [
                    'result' => $conditionResult
                ]);

                if (!$conditionResult) {
                    $this->context->log('INFO', 'Execute', "Action #{$actionIndex} пропущен (условие false)");
                    continue;
                }
            }

            // ДОБАВЛЕНО: действие только с response (без method/skip/curl) —
            // формирует запись в response без вызова метода.
            // Кейс: ошибка валидации с task_id, когда лид создавать нельзя.
            if (!isset($action['method']) && !isset($action['curl']) && !isset($action['skip'])) {
                if (isset($action['response']) && !empty($action['response'])) {
                    $this->processResponse($action['response'], null);

                    $this->context->log(
                        'SUCCESS',
                        'Execute',
                        "Response-действие #{$actionIndex} записано без вызова метода"
                    );
                } else {
                    $this->context->log(
                        'WARNING',
                        'Execute',
                        "Действие #{$actionIndex} пустое (нет method/skip/curl/response) — пропущено"
                    );
                }
                continue;
            }

            // ДОБАВЛЕНО: cURL-действие
            if (isset($action['curl'])) {
                $this->executeCurlAction($action, $actionIndex);

                if ($this->context->hasError()) {
                    break;
                }
                continue;
            }

            // Если есть method — вызываем
            if (isset($action['method'])) {
                $this->executeMethodAction($action, $actionIndex);

                // Если произошла ошибка — прерываем actions
                if ($this->context->hasError()) {
                    break;
                }
            }
        }
    }

    /**
     * Выполняет действие с методом
     * 
     * @param array $action Конфигурация действия
     * @param int $actionIndex Индекс действия
     * @return void
     */
    private function executeMethodAction(array $action, int $actionIndex): void
    {
        $this->context->log('INFO', 'Execute', "Выполнение метода action #{$actionIndex}", [
            'method' => $action['method'],
            'class' => $action['class'] ?? null
        ]);

        try {
            // Выполняем метод через Method Resolver
            $result = $this->methodResolver->execute($action);

            $this->context->log('SUCCESS', 'Execute', "Метод action #{$actionIndex} выполнен", [
                'result' => $result
            ]);

            // Обрабатываем response
            if (isset($action['response']) && !empty($action['response'])) {
                $this->processResponse($action['response'], $result);
            }
        } catch (\Throwable $e) {
            $this->context->log('ERROR', 'Execute', "Ошибка выполнения action #{$actionIndex}", [
                'error' => $e->getMessage(),
                'action' => $action
            ]);

            $errorContext = ($e instanceof ExecutionException)
                ? $e->getErrorContext()
                : null;

            $this->context->setError(
                "execute.actions[{$actionIndex}].method",
                "Ошибка выполнения действия",
                $e->getMessage(),
                null,
                $errorContext
            );
        }
    }

    /**
     * Обрабатывает блок response
     * 
     * Формат response:
     * [
     *     'lead_id' => 'result',           // Весь результат
     *     'lead_id' => 'result:ID',        // По ключу из результата
     *     'date_create' => 'field:datetime_now'  // Из контекста
     * ]
     * 
     * @param array $responseConfig Конфигурация response
     * @param mixed $methodResult Результат метода
     * @return void
     */
    private function processResponse(array $responseConfig, $methodResult): void
    {
        $this->context->log('INFO', 'Execute', 'Обработка response', [
            'response_config' => $responseConfig,
            'method_result' => $methodResult
        ]);

        // Устанавливаем lastResult для разрешения result и result:key
        $this->fieldResolver->setLastResult($methodResult);

        $responseData = [];

        foreach ($responseConfig as $key => $expression) {
            $resolvedValue = $this->fieldResolver->resolve($expression);
            $responseData[$key] = $resolvedValue;

            $this->context->log('INFO', 'Execute', "response поле: {$key}", [
                'expression' => $expression,
                'resolved' => $resolvedValue
            ]);
        }

        // Добавляем в response контекста
        $this->context->addResponse($responseData);

        $this->context->log('SUCCESS', 'Execute', 'response сформирован', [
            'response_data' => $responseData
        ]);
    }

    /**
     * Проверяет, является ли массив ассоциативным
     * 
     * @param array $arr Массив для проверки
     * @return bool True если ассоциативный
     */
    private function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Выполняет cURL-действие внутри execute
     * 
     * Результат становится lastResult — доступен в response
     * через 'result' и 'result:key' (например 'result:data.id')
     * 
     * @param array $action Конфигурация действия с ключом 'curl'
     * @param int $actionIndex Индекс действия
     * @return void
     */
    private function executeCurlAction(array $action, int $actionIndex): void
    {
        $this->context->log('INFO', 'Execute', "cURL-действие #{$actionIndex}");

        try {
            $curlExecutor = new Curl(
                $this->context,
                $this->fieldResolver,
                $this->conditionResolver
            );

            $result = $curlExecutor->executeSingle($action['curl']);

            // Обработка ERROR
            if (($result['status']['code'] ?? '') === 'ERROR') {
                if (array_key_exists('on_error', $action['curl'])) {
                    $result = $action['curl']['on_error'];
                } else {
                    $this->context->setError(
                        "execute.actions[{$actionIndex}].curl",
                        'cURL запрос вернул ERROR',
                        $result['status']['message'] ?? ''
                    );
                    return;
                }
            }

            // Результат доступен в response через result / result:key
            $this->fieldResolver->setLastResult($result);

            if (isset($action['response']) && !empty($action['response'])) {
                $this->processResponse($action['response'], $result);
            }
        } catch (\Throwable $e) {
            $this->context->setError(
                "execute.actions[{$actionIndex}].curl",
                'Ошибка выполнения cURL-действия',
                $e->getMessage()
            );
        }
    }
}
