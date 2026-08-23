<?php

namespace Api\Services\Actions;

use Api\Services\Actions\Testing\TestLogger;

/**
 * Class Context
 * 
 * Контейнер состояния интерпретатора.
 * Хранит все данные между шагами выполнения (request, mapping, execute).
 * 
 * Структура данных внутри Context:
 * - rawRequest: Исходные данные из $_POST/$_GET (не изменяются)
 * - data: Текущий рабочий массив, в который записываются результаты всех шагов
 *   - extra поля
 *   - query результаты
 *   - mapping
 * - response: Накопленные ответы для возврата
 * - iteration: Текущая итерация (если request.array указан)
 * - logs: Логи выполнения для отладки
 * - error: Текущая ошибка (если есть)
 * 
 * @package Api\Services\Actions
 */
class Context
{
    /**
     * Исходные данные запроса ($_POST, $_GET и т.д.)
     * Не изменяются в процессе выполнения
     * 
     * @var array
     */
    public array $rawRequest = [];

    /**
     * Текущий рабочий массив данных
     * Содержит все вычисленные значения (extra, query, mapping)
     * 
     * @var array
     */
    public array $data = [];

    /**
     * Накопленные ответы для возврата
     * 
     * @var array
     */
    public array $response = [];

    /**
     * Текущий индекс итерации (при обработке массива данных)
     * null если обработка одиночного запроса
     * 
     * @var int|null
     */
    public ?int $iterationIndex = null;

    /**
     * Общее количество итераций
     * 
     * @var int
     */
    public int $iterationTotal = 0;

    /**
     * Логи выполнения для отладки
     * 
     * @var array
     */
    public array $logs = [];

    /**
     * Текущая ошибка (если есть)
     * 
     * @var array|null
     */
    public ?array $error = null;

    /**
     * Логгер для тестирования
     * 
     * @var TestLogger|null
     */
    private ?TestLogger $testLogger = null;

    /**
     * Данные текущей итерации
     * Нужны для вычисления diff: какие поля добавил интерпретатор
     * 
     * @var array
     */
    private array $currentIterationData = [];

    /**
     * Снимки вычисленных полей по итерациям (снимаются в момент ошибки)
     * Формат: [index => ['client_id' => ..., 'client_type' => ..., ...]]
     * 
     * @var array
     */
    public array $iterationSnapshots = [];

    /**
     * Ошибки по итерациям
     * Формат: [index => error-массив]
     * 
     * @var array
     */
    public array $iterationErrors = [];

    /**
     * Дополнительные параметры, переданные в Interpreter::run()
     * Доступны в любом месте конфига: field:params.xxx / {{params.xxx}}
     * 
     * @var array
     */
    private array $params = [];

    /**
     * Ключи, загруженные через блок static (не попадают в лог computed)
     * 
     * @var array
     */
    private array $staticKeys = [];

    /**
     * Ключи, скрытые из лога по флагу no_log (не попадают в computed и снимки)
     * Значения доступны через field: во всех блоках
     * 
     * @var array
     */
    private array $hiddenKeys = [];

    /**
     * Индекс, с которого начинается запись текущей итерации в response.
     * Используется для merge response внутри итерации (v1.16.0).
     * 
     * @var int
     */
    private int $iterationResponseStartIndex = 0;

    /**
     * Context constructor.
     * 
     * @param array $rawRequest Исходные данные запроса
     */
    public function __construct(array $rawRequest = [])
    {
        $this->rawRequest = $rawRequest;
        $this->data = $rawRequest;
    }

    /**
     * Устанавливает тестовый логгер
     * 
     * @param TestLogger $logger Логгер
     * @return self
     */
    public function setTestLogger(TestLogger $logger): self
    {
        $this->testLogger = $logger;
        return $this;
    }

    /**
     * Возвращает тестовый логгер
     * 
     * @return TestLogger|null
     */
    public function getTestLogger(): ?TestLogger
    {
        return $this->testLogger;
    }

    /**
     * Записывает лог выполнения
     * 
     * @param string $level Уровень (INFO, ERROR, SUCCESS)
     * @param string $component Компонент (Request, Field и т.д.)
     * @param string $message Сообщение
     * @param mixed $data Дополнительные данные
     * @return void
     */
    public function log(string $level, string $component, string $message, $data = null): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s.u'),
            'level' => $level,
            'component' => $component,
            'message' => $message,
            'data' => $data
        ];

        $this->logs[] = $logEntry;

        // Если есть тестовый логгер, пишем и туда
        if ($this->testLogger !== null) {
            switch ($level) {
                case 'ERROR':
                    $this->testLogger->error($component, 'context', $message, $data);
                    break;
                case 'SUCCESS':
                    $this->testLogger->success($component, 'context', $message, $data);
                    break;
                default:
                    $this->testLogger->info($component, 'context', $message, $data);
                    break;
            }
        }
    }

    /**
     * Устанавливает ошибку выполнения
     * 
     * @param string $configPath Путь в конфиге где произошла ошибка
     * @param string $message Текст ошибки
     * @param string $detailedDetails Подробные детали (exception message)
     * @param array|null $trace Укороченный стектрейс
     * @param array|null $errorContext Контекст ошибки (class/method/resolved_params)
     * @return void
     */
    public function setError(
        string $configPath,
        string $message,
        string $detailedDetails = '',
        ?array $trace = null,
        ?array $errorContext = null
    ): void {
        $this->error = [
            'config_path' => $configPath,
            'message' => $message,
            'detailed_message' => $detailedDetails,
            'trace' => $trace ?? $this->getShortTrace(),
            'error_context' => $errorContext,
            'iteration' => $this->iterationIndex,
            'timestamp' => date('Y-m-d H:i:s.u')
        ];

        $this->log('ERROR', 'Context', "Ошибка: {$message}", $this->error);
    }

    /**
     * Проверяет, есть ли ошибка
     * 
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Возвращает короткий стектрейс (без фанатизма)
     * 
     * @param int $maxLines Максимальное количество строк
     * @return array
     */
    private function getShortTrace(int $maxLines = 5): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $maxLines + 2);

        // Убираем первые 2 элемента (сам getShortTrace и setError)
        array_shift($trace);
        array_shift($trace);

        return array_map(function ($item) {
            return [
                'file' => $item['file'] ?? 'unknown',
                'line' => $item['line'] ?? 0,
                'function' => ($item['class'] ?? '') . '::' . ($item['function'] ?? '')
            ];
        }, $trace);
    }

    /**
     * Устанавливает значение в data по точечной нотации
     * 
     * Пример: set('CONTACT.PHONE', '+79991234567')
     * Создаст: $this->data['CONTACT']['PHONE'] = '+79991234567'
     * 
     * @param string $path Путь в точечной нотации
     * @param mixed $value Значение
     * @return void
     */
    public function set(string $path, $value): void
    {
        $keys = explode('.', $path);
        $target = &$this->data;

        foreach ($keys as $key) {
            if (!isset($target[$key]) || !is_array($target[$key])) {
                $target[$key] = [];
            }
            $target = &$target[$key];
        }

        $target = $value;

        $this->log('INFO', 'Context', "Установлено значение: {$path}", $value);
    }

    /**
     * Получает значение из data по точечной нотации
     * 
     * Пример: get('CONTACT.PHONE') вернёт $this->data['CONTACT']['PHONE']
     * 
     * @param string $path Путь в точечной нотации
     * @param mixed $default Значение по умолчанию
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $keys = explode('.', $path);
        $current = $this->data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Проверяет существование пути в data
     * 
     * @param string $path Путь в точечной нотации
     * @return bool
     */
    public function has(string $path): bool
    {
        $keys = explode('.', $path);
        $current = $this->data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }

    /**
     * Добавляет ответ в response
     * 
     * @param array $data Данные ответа
     * @return void
     */
    public function addResponse(array $data): void
    {
        $this->response[] = $data;
        $this->log('INFO', 'Context', 'Добавлен ответ', $data);
    }

    /**
     * Устанавливает параметры запуска
     * 
     * @param array $params Параметры
     * @return void
     */
    public function setParams(array $params): void
    {
        $this->params = $params;

        if (!empty($params)) {
            $this->set('params', $params);
        }

        $this->log('INFO', 'Context', 'Установлены params', $params);
    }

    /**
     * Возвращает параметры запуска
     * 
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Сбрасывает состояние для новой итерации
     * (ОБНОВЛЁННЫЙ МЕТОД — добавлено сохранение currentIterationData)
     * 
     * @param array $iterationData Данные текущей итерации
     * @param int $index Индекс итерации
     * @return void
     */
    public function resetForIteration(array $iterationData, int $index): void
    {
        $this->data = array_merge($this->rawRequest, $iterationData);

        // params доступны во всех итерациях
        if (!empty($this->params)) {
            $this->data['params'] = $this->params;
        }

        $this->iterationIndex = $index;
        $this->currentIterationData = $iterationData;
        $this->error = null;

        // v1.16.0: запоминаем, с какого индекса начинаются записи этой итерации
        $this->iterationResponseStartIndex = count($this->response);

        $this->log('INFO', 'Context', "Начало итерации #{$index}", $iterationData);
    }

    /**
     * Помечает ключ как статический (будет исключён из лога computed)
     * 
     * @param string $key Имя ключа
     * @return void
     */
    public function markAsStatic(string $key): void
    {
        if (!in_array($key, $this->staticKeys, true)) {
            $this->staticKeys[] = $key;
        }
    }

    /**
     * Помечает ключ как скрытый из log.computed и снимков итераций.
     * Значение остаётся доступным через field: во всех блоках.
     * 
     * @param string $key Имя ключа
     * @return void
     */
    public function markAsHidden(string $key): void
    {
        if (!in_array($key, $this->hiddenKeys, true)) {
            $this->hiddenKeys[] = $key;
        }
    }

    /**
     * Скрыт ли ключ из лога
     * 
     * @param string $key Имя ключа
     * @return bool
     */
    public function isHidden(string $key): bool
    {
        return in_array($key, $this->hiddenKeys, true);
    }

    /**
     * Объединяет данные с последней записью response (для одиночного режима).
     * Если response пустой — создаёт первую запись.
     * 
     * @param array $data Данные для объединения
     * @return void
     */
    public function mergeResponse(array $data): void
    {
        if (empty($this->response)) {
            $this->response[] = $data;
            return;
        }

        // Мержим в последнюю запись (в одиночном режиме она единственная)
        $lastIndex = count($this->response) - 1;
        $this->response[$lastIndex] = array_merge($this->response[$lastIndex], $data);
    }

    /**
     * Объединяет данные с записью текущей итерации (для итерационного режима).
     * Если для текущей итерации ещё нет записи — создаёт.
     * Если есть — мержит в неё.
     * 
     * @param array $data Данные для объединения
     * @return void
     */
    public function mergeIterationResponse(array $data): void
    {
        if (!isset($this->response[$this->iterationResponseStartIndex])) {
            $this->response[$this->iterationResponseStartIndex] = $data;
            return;
        }

        $this->response[$this->iterationResponseStartIndex] = array_merge(
            $this->response[$this->iterationResponseStartIndex],
            $data
        );
    }
    /**
     * Возвращает только вычисленные поля (extra, query, mapping)
     * 
     * Это diff: всё что интерпретатор добавил СВЕРХ
     * сырого запроса и данных текущей итерации.
     * 
     * @return array Вычисленные поля
     */
    public function getComputedData(): array
    {
        $computed = array_diff_key(
            $this->data,
            $this->rawRequest,
            $this->currentIterationData
        );

        // params логируются отдельно, не в computed
        unset($computed['params']);

        // Исключаем static-ключи (справочники не логируем)
        foreach ($this->staticKeys as $staticKey) {
            unset($computed[$staticKey]);
        }

        // Исключаем hidden-ключи (no_log: большие обогащённые массивы)
        foreach ($this->hiddenKeys as $hiddenKey) {
            unset($computed[$hiddenKey]);
        }

        return $computed;
    }

    /**
     * Сохраняет снимок вычисленных полей итерации
     * Вызывается в момент ошибки
     * 
     * @param int $index Индекс итерации
     * @return void
     */
    public function snapshotIteration(int $index): void
    {
        $this->iterationSnapshots[$index] = $this->getComputedData();

        $this->log('INFO', 'Context', "Снимок вычисленных данных итерации #{$index}", [
            'snapshot' => $this->iterationSnapshots[$index]
        ]);
    }

    /**
     * Сохраняет текущую ошибку итерации для логирования
     * Вызывается в момент ошибки (до сброса error)
     * 
     * @param int $index Индекс итерации
     * @return void
     */
    public function recordIterationError(int $index): void
    {
        if ($this->error !== null) {
            $this->iterationErrors[$index] = $this->error;
        }
    }

    /**
     * Возвращает текущее состояние для отладки
     * 
     * @return array
     */
    public function debug(): array
    {
        return [
            'rawRequest' => $this->rawRequest,
            'data' => $this->data,
            'response' => $this->response,
            'iterationIndex' => $this->iterationIndex,
            'iterationTotal' => $this->iterationTotal,
            'error' => $this->error,
            'logs_count' => count($this->logs)
        ];
    }
}
